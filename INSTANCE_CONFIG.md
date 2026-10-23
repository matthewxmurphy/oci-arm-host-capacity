# Instance Configuration

Create JSON files in the `instances/` directory. Each file defines one instance to launch.

## Example Configurations

### Full Always Free ARM Box
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

### Public IP Server
```json
{
  "name": "web-server",
  "hostname": "web-server",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 2,
  "memory": 12,
  "boot_size": 100,
  "network": {
    "dual_stack": false,
    "assign_public_ip": true
  }
}
```

### Dual Stack (IPv4 + IPv6)
```json
{
  "name": "dual-stack-server",
  "hostname": "dual-stack-server",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 4,
  "memory": 24,
  "boot_size": 200,
  "network": {
    "dual_stack": true,
    "assign_public_ip": true,
    "ipv6_subnet_id": "ocid1.subnet.oc1..."
  }
}
```

### High Memory Server
```json
{
  "name": "database-server",
  "hostname": "database-server",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 2,
  "memory": 32,
  "boot_size": 500,
  "network": {
    "dual_stack": false,
    "assign_public_ip": false
  }
}
```

## Configuration Options

| Field | Required | Description |
|--------|----------|-------------|
| `name` | Yes | Instance identifier (used for logs) |
| `hostname` | No | Server hostname (defaults to `name`) |
| `shape` | No | OCI shape (default: `VM.Standard.A1.Flex`) |
| `ocpus` | Yes | Number of OCPUs (ARM cores) |
| `memory` | Yes | Memory in GB |
| `boot_size` | No | Boot volume size in GB (default: `200`, floor: `50`) |
| `network.dual_stack` | No | Enable IPv4+IPv6 (default: `true`) |
| `network.assign_public_ip` | No | Assign public IP (default: `true`) |
| `network.ipv6_subnet_id` | No | IPv6 subnet OCID (if using dual_stack) |
| `image_id` | No | Custom image OCID |
| `subnet_id` | No | Custom subnet OCID |
| `availability_domains` | No | One AD or a list of ADs to try in order |
| `fault_domains` | No | One FD or a list of FDs to try in order |

If you omit `shape`, `image_id`, or `subnet_id`, the launcher uses the tenant defaults. The active defaults are meant to bias empty tenancies toward the full Ubuntu 24 Minimal ARM64 public dual-stack box first: `4 OCPU / 24GB / 200GB`.

In the current implementation, the launcher requests:
- one public IPv4 on launch when `network.assign_public_ip` is `true`
- one initial IPv6 on launch when `network.dual_stack` is `true`

After launch, request a total of 1–32 IPv6 address objects on the primary VNIC with the separate command:

```bash
php scripts/expand-ipv6.php --instance-id ocid1.instance.oc1.REGION.INSTANCE --target 32 --label dual-stack-server
```

This does not change the instance JSON or launch-time IPv4/IPv6 behavior. The command requires an available
primary VNIC on an already IPv6-enabled subnet and the PHP helper's configured environment (`--env` can
select another tenant). Use `--ipv6-subnet-cidr PREFIX` when the subnet has multiple prefixes. It adds only
the missing objects, retains a private retry journal, and publishes requested versus last-observed counts
to the live dashboard. Guest OS address configuration is separate. See the README for recovery limits.

## Adding Custom Tenants

1. Create tenant config folder:
```bash
mkdir -p /path/to/tenants/my-new-tenant
```

2. Add credentials:
```bash
# config.config
region=us-phoenix-1
user=ocid1.user.oc1..
tenancy=ocid1.tenancy.oc1..
fingerprint=xx:xx:xx:...
key_file=/path/to/tenants/my-new-tenant/key.pem
```

3. Add private key: `/path/to/tenants/my-new-tenant/key.pem`

4. Edit `config.sh` and add tenant:
```bash
TENANTS="maperryspeaks oci-phoenix-1-omeiyysa my-new-tenant"
CONFIG_DIR="/path/to/tenants"
```

5. Add subnet/image mappings in `launch-instances.sh`:
```bash
get_subnet() {
    case "$1" in
        "maperryspeaks") echo "ocid1.subnet.oc1.us-chicago-1..." ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.subnet.oc1.phx..." ;;
        "my-new-tenant") echo "ocid1.subnet.oc1.phx..." ;;
    esac
}

get_image() {
    case "$1" in
        "maperryspeaks") echo "ocid1.image.oc1.us-chicago-1..." ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.image.oc1.phx..." ;;
        "my-new-tenant") echo "ocid1.image.oc1.phx..." ;;
    esac
}
```

## Timing Configuration

Edit `config.sh` to adjust retry behavior:

```bash
# Faster retries
BASE_SLEEP=120        # 2 minutes instead of 3
MIN_SLEEP=15          # Min 15 seconds
SPEED_UP_PER_CYCLE=20 # Speed up faster

# More randomness
JITTER_MIN=-30
JITTER_MAX=30
```

## Offline launcher field checks

The internal queue keeps twelve tab-separated fields, including empty optional
image, subnet, availability-domain and fault-domain values. Both launcher passes
retain those empty positions; malformed field counts are skipped before launch.
The dashboard's tab-separated input format is unchanged.

Run `python3 tests/instance-row-fields.py`. It tests the actual extracted launcher
main function with synthetic rows and inert launch/lock/log/delay commands. It
does not source the full launcher, load tenant configuration or call OCI.
