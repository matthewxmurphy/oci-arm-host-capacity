<?php
declare(strict_types=1);

require dirname(__DIR__) . '/oci-arm-host-capacity-fixed/src/Ipv6Expansion.php';

use Hitrov\Ipv6Expansion;

$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class FixtureOci
{
    public array $data;
    public array $calls = [];
    public array $tokens = [];
    public bool $timeout = false;
    public int $timeoutAt = 0;
    public bool $staleInventory = false;
    public bool $loop = false;
    public int $pageSize = 1000;

    public function __construct()
    {
        $this->data = json_decode(file_get_contents(__DIR__ . '/fixtures/ipv6-expansion.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function request(string $method, string $path, ?array $body, array $params, array $headers): array
    {
        $this->calls[] = compact('method', 'path', 'body', 'params', 'headers');
        if ($method === 'POST') {
            check($path === '/ipv6', 'Only IPv6 objects may be created; never compute or IPv4.');
            check(($body['vnicId'] ?? '') === 'ocid1.vnic.oc1.primary', 'Never mutate the secondary VNIC.');
            check(($body['ipv6SubnetCidr'] ?? '') === '2001:db8:1::/64', 'Use the selected primary subnet prefix.');
            check(count($body) === 3, 'Create only vnicId, ipv6SubnetCidr and displayName.');
            $token = $headers['opc-retry-token'] ?? '';
            check((bool)preg_match('/^[a-f0-9]{64}$/D', $token), 'Use a documented retry token for each create.');
            if (!isset($this->tokens[$token])) {
                $object = ['id' => 'ocid1.ipv6.oc1.created' . count($this->tokens), 'vnicId' => $body['vnicId'], 'displayName' => $body['displayName'], 'lifecycleState' => 'PROVISIONING'];
                $this->tokens[$token] = $object;
                if (!$this->staleInventory) {
                    $this->data['objects'][] = $object;
                }
            }
            if ($this->timeout || $this->timeoutAt === count($this->posts())) {
                $this->timeout = false;
                $this->timeoutAt = 0;
                throw new RuntimeException('Fixture: response lost after OCI accepted the POST.');
            }
            return ['data' => $this->tokens[$token]];
        }
        check($method === 'GET', 'No other mutation method is permitted.');
        if ($path === '/instances/ocid1.instance.oc1.fixture') return ['data' => $this->data['instance']];
        if ($path === '/vnicAttachments') {
            check($params['instanceId'] === $this->data['instance']['id'], 'Scope attachments to the requested instance.');
            check($params['compartmentId'] === $this->data['instance']['compartmentId'], 'Use the actual instance compartment.');
            return $this->page($this->data['attachments'], $params);
        }
        if (strpos($path, '/vnics/') === 0) return ['data' => $this->data['vnics'][substr($path, 7)]];
        if ($path === '/ipv6') {
            check($params['vnicId'] === 'ocid1.vnic.oc1.primary', 'Scope all IPv6 pages to the primary VNIC.');
            return $this->page($this->data['objects'], $params);
        }
        if (strpos($path, '/ipv6/') === 0) {
            $id = substr($path, 6);
            foreach (array_merge($this->data['objects'], array_values($this->tokens)) as $object) {
                if ($object['id'] === $id) return ['data' => $object];
            }
            throw new RuntimeException('Fixture: 404 missing IPv6 object.');
        }
        if ($path === '/subnets/ocid1.subnet.oc1.fixture') return ['data' => $this->data['subnet']];
        throw new RuntimeException('Unexpected fixture API path: ' . $path);
    }

    private function page(array $items, array $params): array
    {
        $offset = (int)($params['page'] ?? 0);
        $next = $offset + $this->pageSize < count($items) ? (string)($offset + $this->pageSize) : '';
        return ['data' => array_slice($items, $offset, $this->pageSize), 'next_page' => $this->loop ? '1' : $next];
    }

    public function posts(): array
    {
        return array_values(array_filter($this->calls, static fn(array $c): bool => $c['method'] === 'POST'));
    }
}

function runFixture(FixtureOci $api, int $target, array &$journal, ?string $prefix = null): array
{
    $service = new Ipv6Expansion([$api, 'request'], $journal, static function (array $state) use (&$journal): void { $journal = $state; });
    try {
        return $service->expand('ocid1.instance.oc1.fixture', $target, $prefix);
    } catch (Throwable $error) {
        $GLOBALS['lastFixtureStatus'] = $service->status();
        throw $error;
    }
}

function rejected(callable $work, string $message): void
{
    try { $work(); } catch (Throwable $error) { check(true, $message); return; }
    check(false, $message);
}

foreach ([0, 33] as $target) {
    $api = new FixtureOci; $journal = [];
    rejected(static function () use ($api, $target, &$journal): void { runFixture($api, $target, $journal); }, 'Reject targets outside 1..32.');
    check($api->calls === [], 'Reject invalid targets before any provider request.');
}

$api = new FixtureOci; $journal = [];
$result = runFixture($api, 1, $journal);
check($result['current'] === 1 && $api->posts() === [], 'The launch-time initial object satisfies target 1.');

$api = new FixtureOci; $api->data['objects'] = []; $journal = [];
check(runFixture($api, 1, $journal)['current'] === 1 && count($api->posts()) === 1, 'An empty VNIC can request exactly one object.');

$api = new FixtureOci; $api->data['objects'] = []; $journal = [];
$result = runFixture($api, 32, $journal);
check($result['current'] === 32 && count($api->posts()) === 32, 'Expand an empty VNIC to exactly 32 objects.');
check($journal['pending'] === null, 'Clear intent after confirmed responses.');
runFixture($api, 32, $journal);
check(count($api->posts()) === 32, 'Rerunning an achieved target creates nothing.');

$api = new FixtureOci; $journal = []; $api->pageSize = 1;
foreach (['PROVISIONING', 'TERMINATING', 'UNKNOWN'] as $state) {
    $api->data['objects'][] = ['id' => 'ocid1.ipv6.oc1.' . $state, 'vnicId' => 'ocid1.vnic.oc1.primary', 'lifecycleState' => $state];
}
$api->data['objects'][] = ['id' => 'ocid1.ipv6.oc1.old', 'vnicId' => 'ocid1.vnic.oc1.primary', 'lifecycleState' => 'TERMINATED'];
$result = runFixture($api, 4, $journal);
check($result['current'] === 4 && $api->posts() === [], 'Read all pages, count in-flight/unknown states, exclude only TERMINATED.');

$api = new FixtureOci; $journal = []; $api->timeout = true; $api->staleInventory = true;
rejected(static function () use ($api, &$journal): void { runFixture($api, 3, $journal); }, 'Retain intent when the POST response is lost.');
$first = $journal['pending'];
$firstJournal = $journal;
check($first !== null && $GLOBALS['lastFixtureStatus']['current'] === 1, 'Keep confirmed count separate from uncertain creation.');
$result = runFixture($api, 3, $journal);
$posts = $api->posts();
check($posts[0]['headers'] === $posts[1]['headers'] && $posts[0]['body'] === $posts[1]['body'], 'Replay an uncertain POST with the same persisted token and exact body.');
check(count($api->tokens) === 2 && $result['current'] === 3, 'Retry does not duplicate the accepted object.');

$api = new FixtureOci; $journal = []; $api->timeout = true;
rejected(static function () use ($api, &$journal): void { runFixture($api, 3, $journal); }, 'Simulate timeout with visible accepted object.');
$result = runFixture($api, 3, $journal);
check(count($api->posts()) === 2 && $result['current'] === 3, 'Reconcile a visible intent before creating the remaining deficit.');

$api = new FixtureOci; $journal = $firstJournal; $journal['pending']['started_at'] = time() - 24 * 3600;
rejected(static function () use ($api, &$journal): void { runFixture($api, 2, $journal); }, 'Stop before replaying a token past its guaranteed lifetime.');
check($api->posts() === [] && $journal['pending'] !== null, 'An expired uncertain intent requires reconciliation.');

$api = new FixtureOci; $journal = $firstJournal; unset($journal['pending']['body']['displayName']);
rejected(static function () use ($api, &$journal): void { runFixture($api, 2, $journal); }, 'Reject a malformed saved body before inventing or replaying an intent.');
check($api->posts() === [] && $journal['pending'] !== null, 'Malformed intent is preserved for reconciliation.');

// Independent acceptance regression: one confirmed create followed by a later
// accepted-but-lost response, while every list still contains only the initial IP.
$api = new FixtureOci; $api->staleInventory = true; $api->timeoutAt = 2; $journal = [];
rejected(static function () use ($api, &$journal): void { runFixture($api, 3, $journal); }, 'Simulate partial success then a later response timeout.');
check($GLOBALS['lastFixtureStatus']['current'] === 2 && count($journal['confirmed']) === 2, 'Persist confirmed objects before clearing each creation intent.');
$result = runFixture($api, 3, $journal);
check($result['current'] === 3 && count($api->tokens) + 1 === 3 && count($api->posts()) === 3, 'Stale inventory cannot trigger a fourth object after partial-success replay.');
runFixture($api, 3, $journal);
check(count($api->posts()) === 3, 'Repeated stale lists keep the confirmed budget and create nothing further.');

$api = new FixtureOci; $api->data['objects'] = []; $journal = [];
runFixture($api, 1, $journal);
$oldId = array_key_first($journal['confirmed']);
$api->data['objects'] = [];
$token = array_key_first($api->tokens);
$api->tokens[$token]['lifecycleState'] = 'TERMINATED';
$result = runFixture($api, 1, $journal);
check($result['current'] === 1 && !isset($journal['confirmed'][$oldId]) && count($api->posts()) === 2, 'A direct confirmed TERMINATED response releases only that saved object budget.');

foreach (['instance', 'vnic', 'object'] as $wrongIdentity) {
    $api = new FixtureOci; $journal = $firstJournal;
    if ($wrongIdentity === 'instance') $journal['instance_id'] = 'ocid1.instance.oc1.other';
    if ($wrongIdentity === 'vnic') $journal['vnic_id'] = 'ocid1.vnic.oc1.secondary';
    if ($wrongIdentity === 'object') $journal['confirmed']['ocid1.ipv6.oc1.initial']['vnicId'] = 'ocid1.vnic.oc1.secondary';
    rejected(static function () use ($api, &$journal): void { runFixture($api, 2, $journal); }, 'Reject identity mismatch in saved journal: ' . $wrongIdentity);
    check($api->posts() === [], 'A journal cannot carry budget or pending work across identities.');
}

$api = new FixtureOci; $journal = $firstJournal;
$api->data['objects'] = [];
rejected(static function () use ($api, &$journal): void { runFixture($api, 2, $journal); }, 'A missing known object with a failed direct lookup requires manual reconciliation.');
check($api->posts() === [], 'Never treat an omitted or 404 known object as free capacity.');

foreach (['wrong-instance', 'wrong-compartment', 'ambiguous-primary', 'wrong-object-vnic', 'page-loop', 'no-prefix', 'multi-prefix'] as $failure) {
    $api = new FixtureOci; $journal = [];
    if ($failure === 'wrong-instance') $api->data['attachments'][0]['instanceId'] = 'ocid1.instance.oc1.other';
    if ($failure === 'wrong-compartment') $api->data['attachments'][0]['compartmentId'] = 'ocid1.compartment.oc1.other';
    if ($failure === 'ambiguous-primary') $api->data['vnics']['ocid1.vnic.oc1.secondary']['isPrimary'] = true;
    if ($failure === 'wrong-object-vnic') $api->data['objects'][0]['vnicId'] = 'ocid1.vnic.oc1.secondary';
    if ($failure === 'page-loop') $api->loop = true;
    if ($failure === 'no-prefix') $api->data['subnet']['ipv6CidrBlocks'] = [];
    if ($failure === 'multi-prefix') $api->data['subnet']['ipv6CidrBlocks'][] = '2001:db8:2::/64';
    rejected(static function () use ($api, &$journal): void { runFixture($api, 2, $journal); }, 'Fail closed: ' . $failure);
    check($api->posts() === [], 'No mutation after ambiguous or incomplete inventory: ' . $failure);
}

$api = new FixtureOci; $journal = []; $api->data['subnet']['ipv6CidrBlocks'][] = '2001:db8:2::/64';
check(runFixture($api, 2, $journal, '2001:db8:1::/64')['current'] === 2, 'An explicit existing prefix resolves a multi-prefix subnet.');

echo "IPv6 expansion fixtures passed ($assertions assertions).\n";
