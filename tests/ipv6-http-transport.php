<?php
declare(strict_types=1);

namespace Hitrov\OCI {
    // The real transport is exercised against loopback; no keys/signatures are read.
    class Signer
    {
        public function __construct(...$unused) {}
        public function getHeaders(...$unused): array { return ['Content-Type: application/json', 'Authorization: fixture-only']; }
    }
}

namespace {
    $src = dirname(__DIR__) . '/oci-arm-host-capacity-fixed/src/';
    foreach (['Exception/ApiCallException', 'Exception/CurlException', 'HttpClient', 'OciConfig', 'OciApi'] as $file) {
        require $src . $file . '.php';
    }
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) throw new RuntimeException($error);
    $address = stream_socket_get_name($server, false);
    $capture = tempnam(sys_get_temp_dir(), 'ipv6-http-');
    $child = pcntl_fork();
    if ($child === -1) throw new RuntimeException('Could not start local transport fixture.');
    if ($child === 0) {
        $connection = stream_socket_accept($server, 5);
        if ($connection === false) exit(2);
        $request = '';
        while (($line = fgets($connection)) !== false) {
            $request .= $line;
            if ($line === "\r\n") break;
        }
        if (preg_match('/Content-Length:\s*(\d+)/i', $request, $match)) {
            $request .= fread($connection, (int)$match[1]);
        }
        file_put_contents($capture, $request);
        $body = '[{"id":"fixture"}]';
        fwrite($connection, "HTTP/1.1 100 Continue\r\nOPC-NEXT-PAGE: stale\r\nX-Interim: remove\r\n\r\n");
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nOPC-NEXT-PAGE: next-cursor\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($connection);
        fclose($server);
        exit(0);
    }
    fclose($server);
    $reaped = false;
    try {
        $api = new Hitrov\OciApi;
        $config = new Hitrov\OciConfig('fixture', 'fixture', 'fixture', 'fixture', '/unused-fixture-key', null, '', '');
        $body = '{"vnicId":"fixture-primary"}';
        $result = $api->call($config, 'http://' . $address . '/ipv6', 'POST', $body, ['page' => 'next cursor'], ['opc-retry-token' => str_repeat('a', 64)]);
        pcntl_waitpid($child, $status);
        $reaped = true;
        if (pcntl_wexitstatus($status) !== 0) throw new RuntimeException('Local fixture failed.');
        $request = file_get_contents($capture);
        if ($result !== [['id' => 'fixture']]
            || ($api->getResponseHeaders()['opc-next-page'] ?? '') !== 'next-cursor'
            || isset($api->getResponseHeaders()['x-interim'])
            || strpos($request, 'POST /ipv6?page=next+cursor HTTP/') === false
            || stripos($request, 'opc-retry-token: ' . str_repeat('a', 64)) === false
            || substr($request, -strlen($body)) !== $body) {
            throw new RuntimeException('HTTP transport did not preserve body/token/query or final response pagination headers.');
        }
        $rejected = false;
        try { $api->call($config, 'http://127.0.0.1:1/unused', 'POST', '{}', [], ['opc-retry-token' => "invalid\r\nInjected: bad"]); }
        catch (InvalidArgumentException $error) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Retry header injection was not rejected.');
        echo "IPv6 HTTP transport fixture passed (loopback only).\n";
    } finally {
        if (!$reaped && pcntl_waitpid($child, $status, WNOHANG) === 0) {
            posix_kill($child, SIGTERM);
            pcntl_waitpid($child, $status);
        }
        unlink($capture);
    }
}
