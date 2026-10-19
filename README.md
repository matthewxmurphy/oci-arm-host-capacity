# OCI ARM Host Capacity Retry

Automated Oracle Cloud Infrastructure instance launcher with smart retry logic for ARM instances.
This repo is the source of truth for the retry tool. After the standalone copy is set up and verified,
you sync the runtime code into `ai-control-panel` instead of editing the embedded copy first.

Current release: `1.2.1`

## Features

- **Directory-based configuration** - Drop JSON files into `instances/` to add instances
- **Multi-tenancy support** - Configure multiple OCI tenancies
- **Smart retry** - Adaptive sleep that speeds up over time (3min → 30s minimum)
- **Rate limiting** - Detects and handles rate limits with backoff
- **Random jitter** - Avoids patterns to maximize success chances
- **Live dashboard** - Watch progress in real-time via `live.html`

## Quick Start

```bash
# One-time standalone setup
./scripts/setup-standalone.sh

# Edit config + instance files
vim config.sh
vim instances/*.json

# Run the launcher from the standalone repo
./launch-instances.sh

# View live dashboard
open http://localhost:8080/
```

## What belongs in this repo

- `launch-instances.sh` - main retry orchestration
- `live.html` - live monitoring dashboard
- `index.html` - stable root entry that opens the live dashboard
- `dashboard.html` - full log view
- `upload-tenant.php` - tenant profile/key upload endpoint used by the live panel
- `remove-instance.php` - archive/remove endpoint for queued instances
- `deploy/` - service and publish helpers for the live retry panel
- `config.example.sh` - template for local runtime config
- `INSTANCE_CONFIG.md` - instance manifest reference
- `instances/*.example` - safe example manifests
- `oci-arm-host-capacity-fixed/` - standalone OCI PHP helper
- `scripts/setup-standalone.sh` - first-time local bootstrap
- `scripts/sync-to-ai-control-panel.sh` - copy the clean runtime code back into AI Control Panel

Do not commit:
- `config.sh`
- OCI tenant private keys or tenant `config.config` secrets
- `.env` inside `oci-arm-host-capacity-fixed/`
- `vendor/`
- logs, locks, or runtime queue files

## Instance Configuration

Create JSON files in `instances/` directory:

```json
{
  "name": "free-arm-core",
  "hostname": "free-arm-core",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 4,
  "memory": 24,
  "boot_size": 200,
  "network": {
    "dual_stack": true,
    "assign_public_ip": true
  }
}
```

Defaults are intentionally biased toward:
- `Ubuntu 24 Minimal ARM64` image mappings per tenant
- `VM.Standard.A1.Flex`
- `public dual-stack` when you do not override the network block
- the full `4 OCPU / 24GB / 200GB` Always Free ARM box when the tenant is empty

## Standalone setup

1. Run `./scripts/setup-standalone.sh`
2. Edit `config.sh`
3. Point `CONFIG_DIR` at the OCI tenant folders that already contain `config.config` and `key.pem`
4. Create or edit `instances/*.json`
5. Run `./launch-instances.sh`

The launcher defaults are now biased toward:
- public IPv4 + IPv6
- Ubuntu 24 Minimal ARM64 tenant image mappings
- `VM.Standard.A1.Flex`
- `200GB` boot volume with a `50GB` floor

## Network target

The intended default network posture for launched hosts is:
- one public IPv4 assigned on launch
- IPv6 enabled on launch
- public dual-stack subnet placement by default

The OCI launcher already requests the public IPv4 + initial IPv6 at launch time. The remaining network goal
is to make the tenant bootstrap and post-launch tooling capable of driving a VNIC up to OCI's documented
IPv6 object ceiling of 32 per VNIC when that is useful for the workload.

The separate post-launch command can expand the primary VNIC to a requested total of 1–32 IPv6 objects:

```bash
php scripts/expand-ipv6.php --instance-id ocid1.instance.oc1.REGION.INSTANCE --target 32 --label free-arm-core
```

Run this after the instance and its primary VNIC are available. It uses the PHP helper's local `.env`
by default; `--env /path/to/tenant.env` selects another tenant. It never creates compute, IPv4 addresses,
VCNs or subnets. The subnet must already have IPv6 enabled. If it has multiple IPv6 prefixes, pass
`--ipv6-subnet-cidr PREFIX` to choose one of its existing prefixes. This command assigns OCI IPv6 address
objects; guest OS address configuration and routing remain separate prerequisites.
The selected file supplies all five OCI account/key fields; inherited exported `OCI_*` credentials are
ignored so they cannot mix a previous tenant into this command. Missing fields stop before API requests.

The command reads every inventory page and creates only the deficit. Provisioning, terminating and unknown
states count toward the total; only confirmed `TERMINATED` objects are excluded. Rerun the same command
after partial progress or a transient failure. “Object target reached” describes the counted object budget,
including objects still provisioning or terminating; it does not assert usable guest networking.
The command saves each creation intent before sending a POST, then
reuses OCI's documented `opc-retry-token` and the exact body after an uncertain response. A pending intent
older than 23 hours stops for manual reconciliation because OCI's retry guarantee expires after 24 hours.
Reconcile the saved intent's display name against current primary-VNIC inventory before removing or
repairing that intent; do not delete journals just to retry an uncertain operation.

The private journal also retains every observed or successfully created object ID, bound to this instance
and its primary VNIC. A later stale inventory list cannot erase that budget. Missing known IDs are checked
with individual `GetIpv6` requests and removed only on an explicit `TERMINATED` response. A failed or 404
lookup stops further creation for manual reconciliation; it does not treat an omitted object as deleted.
The dashboard therefore labels this a counted object budget rather than an exact live address count.

Private retry journals default to `$XDG_STATE_HOME/oci-arm-host-capacity/ipv6-expansion/`, or
`$HOME/.local/state/oci-arm-host-capacity/ipv6-expansion/` (directory mode `0700`, files `0600`). Preserve them
across restarts. `--state-dir` selects another private location outside the served repository; paths inside
the repository are rejected because a web server running as the same user can read mode `0600` files.
The live dashboard reads
`public/ipv6-status.json`, which includes only labels, requested/current counts, status and observation time.
Provider diagnostics stay on the local CLI. `--status-file` selects another snapshot location. Counts are
the last observation, including confirmed create responses, rather than a continuous OCI inventory poll.

The request contract follows Oracle's [CreateIpv6 API](https://docs.oracle.com/iaas/api/#/en/iaas/20160918/Ipv6/CreateIpv6)
and current [official SDK implementation](https://github.com/oracle/oci-python-sdk/blob/master/src/oci/core/virtual_network_client.py):
`POST /ipv6`, camelCase `vnicId`/`ipv6SubnetCidr`, and the documented `opc-retry-token` header.

Tenant network creation remains separate follow-up work: the retry launcher still requires a prepared
subnet and does not create or rebuild VCNs during capacity retries.

Offline validation (PHP with curl and pcntl for the loopback transport fixture, plus Node):

```bash
php tests/ipv6-expansion.php
php tests/ipv6-http-transport.php
php tests/ipv6-cli.php
node tests/ipv6-dashboard.cjs
```

The CLI fixture can also use an installed real phpdotenv parser with
`IPV6_TEST_DOTENV_AUTOLOAD=/path/to/vendor/autoload.php php tests/ipv6-cli.php`; it deliberately exports
conflicting dummy credentials and checks that only the selected fixture file supplies the account.

## Sync into AI Control Panel

Once the standalone copy is working, sync the source-controlled runtime files into AI Control Panel:

```bash
./scripts/sync-to-ai-control-panel.sh
```

That sync now targets `/opt/aietherpanel/oracle-retry` by default so the live Ryzen runtime and this repo stay aligned.

That sync intentionally skips local-only files like `config.sh`, logs, OCI secrets, PHP helper `.env`,
`vendor/`, and the live `instances/*.json` manifests.

## Options

- `--instances-dir <dir>` - Custom instance configs directory
- `--tenants <list>` - Space-separated tenant list

## Architecture

- `launch-instances.sh` - Main retry orchestration
- `oci-arm-host-capacity-fixed/` - Forked OCI API client (with PHP 8.5 fixes)
- `instances/` - Instance configuration files
- `live.html` - Live monitoring dashboard
- `upload-tenant.php` - internal tenant upload endpoint used by the live dashboard Add Tenant tab
- `deploy/oracle-retry-normalize-tenant` - root-owned helper that normalizes uploaded tenant key ownership for the Ubuntu service runner

## License

MIT
