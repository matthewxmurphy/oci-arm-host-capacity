<?php
declare(strict_types=1);

// Exercise the real CLI with a temporary, fixture-only helper installation.
// No repository .env, tenant directory, signing key or network is accessed.
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/ipv6-cli-' . bin2hex(random_bytes(8));
mkdir($fixture . '/scripts', 0700, true);
mkdir($fixture . '/oci-arm-host-capacity-fixed/src', 0700, true);
mkdir($fixture . '/oci-arm-host-capacity-fixed/vendor', 0700, true);
copy($root . '/scripts/expand-ipv6.php', $fixture . '/scripts/expand-ipv6.php');
foreach (['Ipv6Expansion', 'Ipv6StatePaths', 'OciConfig'] as $file) copy($root . '/oci-arm-host-capacity-fixed/src/' . $file . '.php', $fixture . '/oci-arm-host-capacity-fixed/src/' . $file . '.php');
copy(__DIR__ . '/fixtures/ipv6-expansion.json', $fixture . '/inventory.json');
// Compare only hashes inside the disposable fixture; never write inherited
// identity or proxy/CA values into its diagnostics.
file_put_contents($fixture . '/environment-hashes.json', json_encode(array_map(
    static fn(string $value): string => hash('sha256', $value), cliEnvironment()
), JSON_THROW_ON_ERROR));
file_put_contents($fixture . '/selected.env', "OCI_REGION=fixture-region\nOCI_USER_ID=file-user\nOCI_TENANCY_ID=file-tenancy\nOCI_KEY_FINGERPRINT=file-fingerprint\nOCI_PRIVATE_KEY_FILENAME=/unused-fixture-file-key\n");
file_put_contents($fixture . '/oci-arm-host-capacity-fixed/vendor/autoload.php', <<<'PHP'
<?php
namespace Dotenv {
    foreach (json_decode(file_get_contents(dirname(__DIR__, 2) . '/environment-hashes.json'), true, 512, JSON_THROW_ON_ERROR) as $name => $expected) {
        $actual = getenv($name);
        if ($actual === false || !hash_equals($expected, hash('sha256', $actual))) {
            throw new \RuntimeException('Fixture child lost its inherited identity or transport guards.');
        }
    }
    if (getenv('IPV6_TEST_DOTENV_AUTOLOAD')) require getenv('IPV6_TEST_DOTENV_AUTOLOAD');
    if (!class_exists(Dotenv::class)) {
        class Dotenv {
            private string $path;
            public static function createArrayBacked(string $directory, string $file): self { $self = new self; $self->path = $directory . '/' . $file; return $self; }
            public function load(): array { return parse_ini_file($this->path, false, INI_SCANNER_RAW); }
        }
    }
}
namespace Hitrov {
    require dirname(__DIR__) . '/src/OciConfig.php';
    class OciApi {
        public function getResponseHeaders(): array { return []; }
        public function call($config, $url, $method, $body, $params, $headers): array {
            if ($config->region !== 'fixture-region' || $config->ociUserId !== 'file-user' || $config->tenancyId !== 'file-tenancy'
                || $config->keyFingerPrint !== 'file-fingerprint' || $config->privateKeyFilename !== '/unused-fixture-file-key') {
                throw new \RuntimeException('Selected tenant environment was mixed with inherited values.');
            }
            $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/inventory.json'), true);
            $path = substr(parse_url($url, PHP_URL_PATH), strlen('/20160918'));
            file_put_contents(getenv('IPV6_FIXTURE_CALLS'), json_encode([$method, $path, $body, $headers]) . "\n", FILE_APPEND);
            if ($method === 'POST') {
                if (getenv('IPV6_FIXTURE_FAIL')) throw new \RuntimeException('PRIVATE_PROVIDER_DIAGNOSTIC_123');
                $request = json_decode($body, true);
                return ['id' => 'ocid1.ipv6.oc1.created', 'vnicId' => $request['vnicId'], 'displayName' => $request['displayName'], 'lifecycleState' => 'PROVISIONING'];
            }
            if (strpos($path, '/instances/') === 0) return $data['instance'];
            if ($path === '/vnicAttachments') return $data['attachments'];
            if (strpos($path, '/vnics/') === 0) return $data['vnics'][substr($path, 7)];
            if ($path === '/ipv6') return $data['objects'];
            if (strpos($path, '/subnets/') === 0) return $data['subnet'];
            throw new \RuntimeException('Unexpected fixture URL');
        }
    }
}
PHP);

function cliCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function cliPhpCommand(): array
{
    return php_ini_loaded_file() ? [PHP_BINARY, '-c', php_ini_loaded_file()] : [PHP_BINARY];
}
function cliEnvironment(array $overrides = []): array
{
    // Keep identity, owned temporary storage and transport trust while excluding
    // inherited OCI credentials from both fixture child processes.
    $keep = ['PATH', 'HOME', 'home', 'CODEX_HOME', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'TMP', 'TEMP',
        'LD_LIBRARY_PATH', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'REQUESTS_CA_BUNDLE', 'CURL_CA_BUNDLE',
        'PIP_CERT', 'NODE_EXTRA_CA_CERTS', 'GRPC_DEFAULT_SSL_ROOTS_FILE_PATH', 'GIT_SSL_CAINFO', 'GIT_SSL_CAPATH', 'AWS_CA_BUNDLE'];
    $environment = [];
    foreach (getenv() as $name => $value) {
        if (in_array($name, $keep, true) || strpos($name, 'CODEX_') === 0
            || strpos($name, 'GRPC_') === 0
            || preg_match('/proxy|cert|trust|_ca|ssl/i', $name)) {
            $environment[$name] = $value;
        }
    }
    return array_replace($environment, $overrides);
}
function cliProcessEnvironment(array $environment): array
{
    // proc_open skips keyed empty values; NAME= entries preserve their presence.
    foreach ($environment as $name => $value) {
        if ($value === '') {
            unset($environment[$name]);
            $environment[] = $name . '=';
        }
    }
    return $environment;
}
function cliRun(string $fixture, string $target, bool $fail = false, ?string $stateDir = null): array
{
    $command = array_merge(cliPhpCommand(), [$fixture . '/scripts/expand-ipv6.php', '--instance-id', 'ocid1.instance.oc1.fixture', '--target', $target,
        '--env', $fixture . '/selected.env', '--label', '<img src=x>', '--state-dir', $stateDir ?? $fixture . '-private', '--status-file', $fixture . '/public/status.json']);
    $environment = cliEnvironment(['OCI_REGION' => 'wrong-region', 'OCI_USER_ID' => 'wrong-user', 'OCI_TENANCY_ID' => 'wrong-tenancy',
        'OCI_KEY_FINGERPRINT' => 'wrong-fingerprint', 'OCI_PRIVATE_KEY_FILENAME' => '/wrong-inherited-key', 'IPV6_FIXTURE_CALLS' => $fixture . '/calls.jsonl', 'IPV6_FIXTURE_FAIL' => $fail ? '1' : '',
        'IPV6_TEST_DOTENV_AUTOLOAD' => (string)getenv('IPV6_TEST_DOTENV_AUTOLOAD')]);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $fixture, cliProcessEnvironment($environment));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}
function cliRemove(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $file) {
        if ($file->isDir()) cliRemove($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($directory);
}
try {
    foreach (['0', '33'] as $invalidTarget) {
        [$exit, $output, $error] = cliRun($fixture, $invalidTarget);
        cliCheck($exit === 2 && !file_exists($fixture . '/calls.jsonl') && !is_dir($fixture . '-private'), 'CLI rejects invalid targets before credentials/state/API: ' . $exit . ' ' . $output . $error);
    }
    [$exit, $output, $error] = cliRun($fixture, '2', true);
    cliCheck($exit === 1 && strpos($error, 'PRIVATE_PROVIDER_DIAGNOSTIC_123') !== false, 'CLI reports a local provider failure: ' . $exit . ' ' . $output . $error);
    $snapshot = file_get_contents($fixture . '/public/status.json');
    cliCheck(strpos($snapshot, 'PRIVATE_PROVIDER') === false && strpos($snapshot, 'token') === false && strpos($snapshot, 'fixture-key') === false && strpos($snapshot, 'vnic') === false, 'Public status excludes diagnostic/token/key/VNIC state.');
    $status = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['ocid1.instance.oc1.fixture'];
    cliCheck($status['status'] === 'error' && $status['requested'] === 2 && $status['current'] === 1, 'Publish only confirmed counts after uncertain failure.');
    $statePath = $fixture . '-private/' . hash('sha256', 'ocid1.instance.oc1.fixture') . '.json';
    $intent = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    cliCheck(strlen($intent['pending']['token']) === 64 && (fileperms($statePath) & 0777) === 0600 && (fileperms($fixture . '-private') & 0777) === 0700, 'Persist retry intent privately outside the served repo before POST.');
    [$exit, $output, $error] = cliRun($fixture, '2');
    cliCheck($exit === 0 && $error === '', 'CLI safely resumes with the saved intent.');
    $calls = array_map(static fn(string $line): array => json_decode($line, true), file($fixture . '/calls.jsonl', FILE_IGNORE_NEW_LINES));
    $posts = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'POST'));
    cliCheck(count($posts) === 2 && $posts[0] === $posts[1], 'Cross-process retry reuses the exact persisted body and token.');
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    cliCheck($result['status'] === 'ready' && $result['current'] === 2 && !isset($result['vnic_id']), 'CLI result remains sanitized after success.');

    $before = hash_file('sha256', $fixture . '/calls.jsonl');
    $selectedEnvironment = file_get_contents($fixture . '/selected.env');
    file_put_contents($fixture . '/selected.env', str_replace("OCI_USER_ID=file-user\n", '', $selectedEnvironment));
    [$exit, , $error] = cliRun($fixture, '2');
    cliCheck($exit === 1 && strpos($error, 'missing OCI_USER_ID') !== false
        && hash_file('sha256', $fixture . '/calls.jsonl') === $before, 'Missing selected-file credentials cannot fall back to inherited account values.');
    file_put_contents($fixture . '/selected.env', $selectedEnvironment);
    [$exit, , $error] = cliRun($fixture, '2', false, $fixture . '/public/private-state');
    cliCheck($exit === 1 && strpos($error, 'outside the served repository') !== false
        && hash_file('sha256', $fixture . '/calls.jsonl') === $before, 'Reject journals inside the served repo before credentials/API.');
    symlink($fixture . '/public', $fixture . '-link');
    try {
        [$exit, , $error] = cliRun($fixture, '2', false, $fixture . '-link');
        cliCheck($exit === 1 && strpos($error, 'outside the served repository') !== false, 'Canonical path checks reject symlinks into the served repo.');
    } finally { unlink($fixture . '-link'); }

    // Mirror deploy/publish-live-dashboard.sh: a same-UID web server serves a
    // symlink to the entire runtime. The CLI must remain inaccessible over HTTP.
    mkdir($fixture . '-site', 0700);
    symlink($fixture, $fixture . '-site/retry');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    cliCheck($socket !== false, 'Allocate loopback HTTP fixture.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $web = proc_open(array_merge(cliPhpCommand(), ['-S', $address, '-t', $fixture . '-site']),
        [['file', '/dev/null', 'r'], ['file', $fixture . '/web.log', 'w'], ['file', $fixture . '/web.log', 'a']], $pipes, $fixture,
        cliProcessEnvironment(cliEnvironment()));
    cliCheck(is_resource($web), 'Start loopback web fixture.');
    try {
        $httpCode = 0; $response = '';
        for ($attempt = 0; $attempt < 30 && $httpCode === 0; $attempt++) {
            $curl = curl_init('http://' . $address . '/retry/scripts/expand-ipv6.php?--instance-id+ocid1.instance.oc1.fixture+--target+32');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 100, CURLOPT_TIMEOUT => 2]);
            $response = curl_exec($curl); $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($httpCode === 0) usleep(50000);
        }
        cliCheck($httpCode === 404 && $response === '' && hash_file('sha256', $fixture . '/calls.jsonl') === $before, 'Non-CLI requests fail closed before state/credentials/provider calls: ' . $httpCode . ' ' . var_export($response, true) . ' ' . file_get_contents($fixture . '/web.log'));
    } finally {
        proc_terminate($web); proc_close($web);
        unlink($fixture . '-site/retry'); rmdir($fixture . '-site');
    }
    echo "IPv6 CLI fixtures passed (temporary installation, no credentials or provider calls).\n";
} finally {
    cliRemove($fixture);
    if (is_dir($fixture . '-private')) cliRemove($fixture . '-private');
}
