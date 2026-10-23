<?php
declare(strict_types=1);

/* Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * Product/support: https://builtnotbegged.com | SPDX-License-Identifier: MIT */

// Only the read-only path helper is imported. CLI/provider/bootstrap are DATA.
require dirname(__DIR__) . '/oci-arm-host-capacity-fixed/src/Ipv6StatePaths.php';

use Hitrov\Ipv6StatePaths;

$results = [];
function pathCheck(string $name, bool $passed): void
{
    global $results;
    $results[] = ['name' => $name, 'passed' => $passed];
}
function pathSnapshot(string $root): array
{
    $rows = [];
    foreach (new FilesystemIterator($root) as $entry) {
        $path = $entry->getPathname();
        if (is_link($path)) {
            $rows[$path] = ['link', readlink($path)];
        } elseif (is_dir($path)) {
            $rows[$path] = ['directory', fileperms($path) & 0777];
            $rows += pathSnapshot($path);
        } else {
            $rows[$path] = ['file', fileperms($path) & 0777, hash_file('sha256', $path)];
        }
    }
    ksort($rows);
    return $rows;
}
function pathCase(string $name, string $root, string $journal, string $status, bool $deny): void
{
    $before = pathSnapshot($root);
    $denied = false;
    try {
        Ipv6StatePaths::assertSeparate($journal, $status);
    } catch (RuntimeException $error) {
        $denied = true;
    }
    pathCheck($name, $denied === $deny && pathSnapshot($root) === $before);
}
function pathRemove(string $root): void
{
    foreach (new FilesystemIterator($root) as $entry) {
        $path = $entry->getPathname();
        if (!is_link($path) && is_dir($path)) pathRemove($path); else unlink($path);
    }
    rmdir($root);
}

$cwd = getcwd();
if ($cwd === false) throw new RuntimeException('Could not resolve the fixture working directory.');
$temporary = sys_get_temp_dir() . '/ipv6-state-paths-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Could not create the owned fixture directory.');
try {
    $journal = $temporary . '/private/instance.json';
    $status = $temporary . '/public/status.json';
    pathCase('default-independent-absent-paths', $temporary, $journal, $status, false);
    pathCase('snapshot-is-journal', $temporary, $journal, $journal, true);
    pathCase('snapshot-is-operation-lock', $temporary, $journal, $journal . '.lock', true);
    pathCase('future-dotdot-alias', $temporary, $journal, $temporary . '/private/new/../instance.json', true);
    pathCase('dot-components-alias', $temporary, $journal, $temporary . '/./private/instance.json', true);
    pathCase('safe-absent-nested-path', $temporary, $journal, $temporary . '/future/public/status.json', false);
    pathCase('separate-neighbor-has-no-new-directory-policy', $temporary, $journal, $temporary . '/private/dashboard.json', false);
    chdir($temporary);
    pathCase('relative-alias', $temporary, 'private/instance.json', './private/instance.json', true);
    chdir($cwd);

    mkdir($temporary . '/private', 0700);
    mkdir($temporary . '/private/child', 0700);
    file_put_contents($journal, '{"synthetic_private_state":true}');
    chmod($journal, 0600);
    file_put_contents($journal . '.lock', 'synthetic lock DATA; never locked');
    chmod($journal . '.lock', 0600);
    symlink($temporary . '/private', $temporary . '/outside-alias');
    pathCase('allowed-outside-directory-symlink-alias', $temporary, $journal, $temporary . '/outside-alias/instance.json', true);
    pathCase('allowed-outside-directory-symlink-independent-file', $temporary, $journal, $temporary . '/outside-alias/dashboard.json', false);
    symlink($temporary . '/private/child', $temporary . '/via-child');
    pathCase('symlink-resolves-before-dotdot', $temporary, $journal, $temporary . '/via-child/../instance.json', true);

    symlink($journal, $temporary . '/snapshot-link');
    pathCase('snapshot-symlink-to-journal', $temporary, $journal, $temporary . '/snapshot-link', true);
    symlink($journal . '.lock', $temporary . '/snapshot-lock-link');
    pathCase('snapshot-symlink-to-operation-lock', $temporary, $journal, $temporary . '/snapshot-lock-link', true);
    symlink($journal, $temporary . '/public-lock-journal.lock');
    pathCase('snapshot-lock-symlink-to-journal', $temporary, $journal, $temporary . '/public-lock-journal', true);
    symlink($journal . '.lock', $temporary . '/public-lock-operation.lock');
    pathCase('snapshot-lock-symlink-to-operation-lock', $temporary, $journal, $temporary . '/public-lock-operation', true);
    link($journal, $temporary . '/hard-snapshot');
    pathCase('snapshot-hardlink-to-journal', $temporary, $journal, $temporary . '/hard-snapshot', true);
    link($journal . '.lock', $temporary . '/hard-lock.lock');
    pathCase('snapshot-lock-hardlink-to-operation-lock', $temporary, $journal, $temporary . '/hard-lock', true);
    symlink($temporary . '/missing', $temporary . '/broken');
    pathCase('unresolvable-link-holds', $temporary, $journal, $temporary . '/broken', true);

    $cli = file_get_contents(dirname(__DIR__) . '/scripts/expand-ipv6.php');
    $call = 'Hitrov\\Ipv6StatePaths::assertSeparate($statePath, $statusFile);';
    $position = strpos($cli, $call);
    $following = [strpos($cli, 'if (!is_dir($stateDir)'), strpos($cli, '$lock = fopen('),
        strpos($cli, 'require_once $autoload;'), strpos($cli, 'Dotenv\\Dotenv::createArrayBacked('),
        strpos($cli, '$api = new Hitrov\\OciApi();')];
    pathCheck('guard-wired-before-state-lock-bootstrap-and-provider', $position !== false
        && substr_count($cli, $call) === 1
        && !in_array(false, $following, true)
        && count(array_filter($following, static fn($later): bool => $position < $later)) === count($following));
    pathCheck('CLI-retains-public-and-private-write-modes', strpos($cli, 'ipv6WriteJson($statePath, $state, 0600);') !== false
        && strpos($cli, 'ipv6WriteJson($statusFile, $statuses, 0644);') !== false);
} finally {
    if ($cwd !== false) chdir($cwd);
    pathRemove($temporary);
}

$failed = count(array_filter($results, static fn(array $result): bool => !$result['passed']));
echo json_encode(['checks' => count($results), 'failed' => $failed, 'results' => $results,
    'provider_or_CLI_executed' => false, 'locks_acquired' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
exit($failed === 0 ? 0 : 1);
