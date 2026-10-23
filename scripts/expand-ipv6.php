<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Explicit post-launch command. It never creates instances, VCNs or IPv4s.
$root = dirname(__DIR__);
$options = getopt('', ['instance-id:', 'target:', 'label:', 'env:', 'state-dir:', 'status-file:', 'ipv6-subnet-cidr:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/expand-ipv6.php --instance-id ocid1.instance... --target 1..32 [--label NAME]\n";
    echo "  --env FILE                 PHP helper environment (default: oci-arm-host-capacity-fixed/.env)\n";
    echo "  --ipv6-subnet-cidr PREFIX   Required when the primary subnet has multiple IPv6 prefixes\n";
    echo "  --state-dir DIR            Private retry journal outside the served repo (default: user state directory)\n";
    echo "  --status-file FILE         Dashboard snapshot (default: public/ipv6-status.json)\n";
    exit(0);
}

$instanceId = $options['instance-id'] ?? '';
$target = $options['target'] ?? '';
if (!is_string($instanceId) || !preg_match('/^ocid1\.instance\.[a-zA-Z0-9._-]+$/D', $instanceId)
    || !is_string($target) || !preg_match('/^([1-9]|[12][0-9]|3[0-2])$/D', $target)) {
    fwrite(STDERR, "An --instance-id OCID and integer --target from 1 through 32 are required.\n");
    exit(2);
}
foreach (['label', 'env', 'state-dir', 'status-file', 'ipv6-subnet-cidr'] as $name) {
    if (isset($options[$name]) && (!is_string($options[$name]) || $options[$name] === '')) {
        fwrite(STDERR, "Invalid --$name option.\n");
        exit(2);
    }
}

require_once $root . '/oci-arm-host-capacity-fixed/src/Ipv6Expansion.php';

/** Atomic snapshots, including the private intent written before every POST. */
function ipv6WriteJson(string $path, array $data, int $mode): void
{
    $temporary = tempnam(dirname($path), '.ipv6-');
    if ($temporary === false) {
        throw new RuntimeException('Could not allocate IPv6 state file.');
    }
    try {
        if (!chmod($temporary, $mode)
            || file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n") === false
            || !rename($temporary, $path)) {
            throw new RuntimeException('Could not persist IPv6 state.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

$result = ['instance_id' => $instanceId, 'requested' => (int)$target, 'current' => null, 'created' => 0, 'status' => 'error'];
$expansion = null;
$lock = null;
$locked = false;
$exitCode = 1;
try {
    $stateBase = getenv('XDG_STATE_HOME') ?: (getenv('HOME') ? rtrim((string)getenv('HOME'), '/') . '/.local/state' : '');
    $stateDir = $options['state-dir'] ?? ($stateBase !== '' ? $stateBase . '/oci-arm-host-capacity/ipv6-expansion' : '');
    if ($stateDir === '') {
        throw new RuntimeException('Set --state-dir to a private directory outside the served repository.');
    }
    if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
        throw new RuntimeException('Could not create private IPv6 state directory.');
    }
    $canonicalState = realpath($stateDir);
    $canonicalRoot = realpath($root);
    if ($canonicalState === false || $canonicalRoot === false || $canonicalState === $canonicalRoot
        || strpos($canonicalState, $canonicalRoot . DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('Private IPv6 state must be outside the served repository.');
    }
    $statePath = $stateDir . '/' . hash('sha256', $instanceId) . '.json';
    $lock = fopen($statePath . '.lock', 'c');
    if ($lock === false || !chmod($statePath . '.lock', 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('An IPv6 expansion for this instance is already running or cannot be locked.');
    }
    $locked = true;
    $journal = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : [];
    if (!is_array($journal)) {
        throw new RuntimeException('Invalid private IPv6 state; reconcile it before retrying.');
    }

    $autoload = $root . '/oci-arm-host-capacity-fixed/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Install the PHP helper dependencies before using IPv6 expansion.');
    }
    require_once $autoload;
    $envFile = $options['env'] ?? $root . '/oci-arm-host-capacity-fixed/.env';
    // Read one tenant file in isolation. Exported OCI_* values must not mix a
    // previous tenant's account/key with the explicitly selected environment.
    $environment = Dotenv\Dotenv::createArrayBacked(dirname($envFile), basename($envFile))->load();
    foreach (['OCI_REGION', 'OCI_USER_ID', 'OCI_TENANCY_ID', 'OCI_KEY_FINGERPRINT', 'OCI_PRIVATE_KEY_FILENAME'] as $required) {
        if (!is_string($environment[$required] ?? null) || trim($environment[$required]) === '') {
            throw new RuntimeException('The selected environment is missing ' . $required . '.');
        }
    }
    $config = new Hitrov\OciConfig(
        $environment['OCI_REGION'], $environment['OCI_USER_ID'], $environment['OCI_TENANCY_ID'],
        $environment['OCI_KEY_FINGERPRINT'], $environment['OCI_PRIVATE_KEY_FILENAME'], null, '', ''
    );
    if (!preg_match('/^[a-z][a-z0-9-]+$/D', $config->region)) {
        throw new RuntimeException('A valid OCI region is required.');
    }
    $api = new Hitrov\OciApi();
    $request = static function (string $method, string $path, ?array $body, array $params, array $headers) use ($api, $config): array {
        $data = $api->call(
            $config, 'https://iaas.' . $config->region . '.oraclecloud.com/20160918' . $path,
            $method, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), $params, $headers
        );
        return ['data' => $data, 'next_page' => $api->getResponseHeaders()['opc-next-page'] ?? ''];
    };
    $expansion = new Hitrov\Ipv6Expansion($request, $journal, static function (array $state) use ($statePath): void {
        ipv6WriteJson($statePath, $state, 0600);
    });
    $result = $expansion->expand($instanceId, (int)$target, $options['ipv6-subnet-cidr'] ?? null);
    $exitCode = 0;
} catch (Throwable $error) {
    if ($expansion !== null && $expansion->status() !== []) {
        $result = $expansion->status();
    }
    $result['status'] = 'error';
    // Provider diagnostics stay on the local CLI; never publish them in the dashboard.
    fwrite(STDERR, $error->getMessage() . "\n");
}

$result['label'] = substr($options['label'] ?? $instanceId, 0, 200);
$result['updated_at'] = gmdate('c');
try {
    if (!$locked) {
        throw new RuntimeException('No IPv6 status was published because the private operation lock was not acquired.');
    }
    $statusFile = $options['status-file'] ?? $root . '/public/ipv6-status.json';
    if (!is_dir(dirname($statusFile)) && !mkdir(dirname($statusFile), 0755, true) && !is_dir(dirname($statusFile))) {
        throw new RuntimeException('Could not create IPv6 dashboard directory.');
    }
    $statusLock = fopen($statusFile . '.lock', 'c');
    if ($statusLock === false || !flock($statusLock, LOCK_EX)) {
        throw new RuntimeException('Could not lock IPv6 dashboard status.');
    }
    try {
        $statuses = is_file($statusFile) ? json_decode((string)file_get_contents($statusFile), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($statuses)) {
            throw new RuntimeException('Invalid IPv6 dashboard status.');
        }
        // Publish a fixed allowlist, never the retry token, journal, credentials or API error.
        $statuses[$instanceId] = array_intersect_key($result, array_flip(['instance_id', 'label', 'requested', 'current', 'created', 'status', 'updated_at']));
        ipv6WriteJson($statusFile, $statuses, 0644);
    } finally {
        flock($statusLock, LOCK_UN);
        fclose($statusLock);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
}

if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

echo json_encode(array_intersect_key($result, array_flip(['instance_id', 'label', 'requested', 'current', 'created', 'status', 'updated_at'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit($exitCode);
