<?php
declare(strict_types=1);

namespace Hitrov;

use RuntimeException;

/** Post-launch only: the transport may GET resources and POST /ipv6. */
class Ipv6Expansion
{
    private $request;
    private $checkpoint;
    private array $journal;
    private array $status = [];

    public function __construct(callable $request, array $journal, callable $checkpoint)
    {
        $this->request = $request;
        $this->journal = $journal;
        $this->checkpoint = $checkpoint;
    }

    public function status(): array
    {
        return $this->status;
    }

    public function expand(string $instanceId, int $target, ?string $prefix = null): array
    {
        if (!preg_match('/^ocid1\.instance\.[a-zA-Z0-9._-]+$/D', $instanceId) || $target < 1 || $target > 32) {
            throw new \InvalidArgumentException('An instance OCID and target from 1 through 32 are required.');
        }

        $this->status = ['instance_id' => $instanceId, 'requested' => $target, 'current' => null, 'created' => 0, 'status' => 'pending'];
        $instance = $this->get('/instances/' . rawurlencode($instanceId));
        if (($instance['id'] ?? '') !== $instanceId || empty($instance['compartmentId'])
            || in_array($instance['lifecycleState'] ?? '', ['TERMINATING', 'TERMINATED'], true)) {
            throw new RuntimeException('The requested instance is unavailable.');
        }

        $primaries = [];
        foreach ($this->collection('/vnicAttachments', ['instanceId' => $instanceId, 'compartmentId' => $instance['compartmentId']]) as $attachment) {
            if (($attachment['instanceId'] ?? '') !== $instanceId || ($attachment['compartmentId'] ?? '') !== $instance['compartmentId']) {
                throw new RuntimeException('VNIC attachment scope does not match the requested instance.');
            }
            if (($attachment['lifecycleState'] ?? '') !== 'ATTACHED') {
                continue;
            }
            $vnicId = (string)($attachment['vnicId'] ?? '');
            if ($vnicId === '') {
                throw new RuntimeException('The VNIC attachment is missing its VNIC ID.');
            }
            $vnic = $this->get('/vnics/' . rawurlencode($vnicId));
            if (($vnic['id'] ?? '') !== $vnicId) {
                throw new RuntimeException('The VNIC response does not match its attachment.');
            }
            if (($vnic['isPrimary'] ?? false) === true) {
                $primaries[$vnicId] = $vnic;
            }
        }

        if (count($primaries) !== 1) {
            throw new RuntimeException('Exactly one available primary VNIC is required; retry after attachment completes.');
        }
        $vnic = reset($primaries);
        if (($vnic['lifecycleState'] ?? '') !== 'AVAILABLE') {
            throw new RuntimeException('The primary VNIC is not available yet.');
        }
        $vnicId = $vnic['id'];
        $this->status['vnic_id'] = $vnicId;
        if (empty($vnic['subnetId'])) {
            throw new RuntimeException('The primary VNIC has no subnet.');
        }
        if ($this->journal !== [] && (($this->journal['instance_id'] ?? '') !== $instanceId
            || ($this->journal['vnic_id'] ?? '') !== $vnicId || !is_array($this->journal['confirmed'] ?? null))) {
            throw new RuntimeException('Saved IPv6 inventory belongs to a different instance or primary VNIC, or is malformed.');
        }
        $this->journal['instance_id'] = $instanceId;
        $this->journal['vnic_id'] = $vnicId;
        $objects = $this->journal['confirmed'] ?? [];
        foreach ($objects as $id => $object) {
            if (!is_string($id) || !is_array($object) || ($object['id'] ?? '') !== $id || ($object['vnicId'] ?? '') !== $vnicId) {
                throw new RuntimeException('Saved IPv6 object identity does not match the primary VNIC.');
            }
        }
        $observed = [];
        foreach ($this->collection('/ipv6', ['vnicId' => $vnicId]) as $object) {
            if (($object['vnicId'] ?? '') !== $vnicId || !is_string($object['id'] ?? null) || $object['id'] === '') {
                throw new RuntimeException('IPv6 inventory does not match the primary VNIC.');
            }
            $observed[$object['id']] = true;
            // Provisioning, terminating and unknown states still consume the budget.
            if (($object['lifecycleState'] ?? '') !== 'TERMINATED') {
                $objects[$object['id']] = $object;
            } else {
                unset($objects[$object['id']]);
            }
        }
        foreach ($objects as $id => $object) {
            if (isset($observed[$id])) {
                continue;
            }
            // A stale list cannot erase a previously confirmed object. Reconcile
            // it directly; a failed/404 lookup stops before any further creation.
            $fresh = $this->get('/ipv6/' . rawurlencode($id));
            if (($fresh['id'] ?? '') !== $id || ($fresh['vnicId'] ?? '') !== $vnicId) {
                throw new RuntimeException('Saved IPv6 object could not be reconciled with its primary VNIC.');
            }
            if (($fresh['lifecycleState'] ?? '') === 'TERMINATED') {
                unset($objects[$id]);
            } else {
                $objects[$id] = $fresh;
            }
        }
        $this->journal['confirmed'] = $objects;
        $this->save($this->journal['pending'] ?? null);
        $this->status['current'] = count($objects);
        if (count($objects) >= $target) {
            $this->status['status'] = 'ready';
            return $this->status;
        }

        $subnet = $this->get('/subnets/' . rawurlencode($vnic['subnetId']));
        if (($subnet['id'] ?? '') !== $vnic['subnetId']) {
            throw new RuntimeException('The subnet response does not match the primary VNIC.');
        }
        $prefixes = array_values(array_unique(array_filter(array_merge(
            (array)($subnet['ipv6CidrBlocks'] ?? []), [(string)($subnet['ipv6CidrBlock'] ?? '')]
        ))));
        if ($prefix === null && count($prefixes) === 1) {
            $prefix = $prefixes[0];
        }
        if ($prefix === null || !in_array($prefix, $prefixes, true)) {
            throw new RuntimeException('Choose an IPv6 prefix present on the primary VNIC subnet; multiple prefixes require --ipv6-subnet-cidr.');
        }

        while (count($objects) < $target) {
            $pending = $this->journal['pending'] ?? null;
            if ($pending !== null) {
                if (!is_array($pending) || ($pending['instance_id'] ?? '') !== $instanceId
                    || !is_array($pending['body'] ?? null) || count($pending['body']) !== 3
                    || ($pending['body']['vnicId'] ?? '') !== $vnicId
                    || ($pending['body']['ipv6SubnetCidr'] ?? '') !== $prefix
                    || !preg_match('/^[a-f0-9]{64}$/D', (string)($pending['token'] ?? ''))
                    || ($pending['body']['displayName'] ?? '') !== 'oci-retry-ipv6-' . substr((string)$pending['token'], 0, 16)
                    || !is_int($pending['started_at'] ?? null)) {
                    throw new RuntimeException('Saved IPv6 creation intent does not match this request.');
                }
                $reconciled = false;
                foreach ($objects as $object) {
                    if (($object['displayName'] ?? '') === ($pending['body']['displayName'] ?? '')) {
                        $reconciled = true;
                    }
                }
                if ($reconciled) {
                    $this->save(null);
                    continue;
                }
                // OCI retry tokens expire after 24h. Leave margin and stop rather
                // than replaying a POST whose idempotency guarantee has expired.
                if (time() - $pending['started_at'] >= 23 * 3600 || $pending['started_at'] > time()) {
                    throw new RuntimeException('Uncertain IPv6 creation is too old to replay; reconcile its saved intent with OCI inventory before continuing.');
                }
            } else {
                $token = bin2hex(random_bytes(32));
                $pending = [
                    'instance_id' => $instanceId,
                    'started_at' => time(),
                    'token' => $token,
                    'body' => ['vnicId' => $vnicId, 'ipv6SubnetCidr' => $prefix, 'displayName' => 'oci-retry-ipv6-' . substr($token, 0, 16)],
                ];
                // Persist before sending: a timeout must replay this exact request.
                $this->save($pending);
            }

            $response = ($this->request)('POST', '/ipv6', $pending['body'], [], ['opc-retry-token' => $pending['token']]);
            $created = $response['data'] ?? [];
            if (!is_array($created) || empty($created['id']) || ($created['vnicId'] ?? '') !== $vnicId
                || ($created['displayName'] ?? '') !== $pending['body']['displayName']
                || ($created['lifecycleState'] ?? '') === 'TERMINATED' || isset($objects[$created['id']])) {
                throw new RuntimeException('IPv6 creation could not be reconciled; retain the saved intent for a safe retry.');
            }
            $objects[$created['id']] = $created;
            $this->journal['confirmed'] = $objects;
            $this->status['current'] = count($objects);
            $this->status['created']++;
            $this->save(null);
        }

        $this->status['status'] = 'ready';
        return $this->status;
    }

    private function save(?array $pending): void
    {
        $this->journal['pending'] = $pending;
        ($this->checkpoint)($this->journal);
    }

    private function get(string $path): array
    {
        $response = ($this->request)('GET', $path, null, [], []);
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected OCI response.');
        }
        return $response['data'];
    }

    private function collection(string $path, array $params): array
    {
        $items = [];
        $seen = [];
        do {
            $response = ($this->request)('GET', $path, null, $params + ['limit' => 1000], []);
            $data = $response['data'] ?? null;
            if (!is_array($data) || array_values($data) !== $data) {
                throw new RuntimeException('Unexpected OCI collection response.');
            }
            foreach ($data as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('Unexpected OCI collection item.');
                }
                $items[] = $item;
            }
            $next = (string)($response['next_page'] ?? '');
            if ($next !== '') {
                if (isset($seen[$next]) || count($seen) >= 100) {
                    throw new RuntimeException('OCI pagination did not terminate.');
                }
                $seen[$next] = true;
                $params['page'] = $next;
            }
        } while ($next !== '');

        return $items;
    }
}
