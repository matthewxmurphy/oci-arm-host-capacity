#!/bin/bash
# Copyright (c) 2026 Matthew X Murphy (https://MatthewxMurphy.com).
# Product/support: https://builtnotbegged.com
# SPDX-License-Identifier: MIT
# OCI Instance Launcher with Smart Retry
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Load config if exists
if [ -f "$SCRIPT_DIR/config.sh" ]; then
    source "$SCRIPT_DIR/config.sh"
fi

# Defaults
TENANTS="${TENANTS:-maperryspeaks oci-phoenix-1-omeiyysa oci-sanjose-1-zeqic64q}"
CONFIG_DIR="${CONFIG_DIR:-/opt/aiether/ai-control-panel/data/oci-tenants}"
INSTANCES_DIR="${INSTANCES_DIR:-$SCRIPT_DIR/instances}"
LOG_DIR="${LOG_DIR:-$SCRIPT_DIR/logs}"
WEB_LOG="${WEB_LOG:-$SCRIPT_DIR/public/retry.log}"
LOCK_FILE="${LOCK_FILE:-$SCRIPT_DIR/.launch.lock}"
CONFIGS_FILE="${CONFIGS_FILE:-$SCRIPT_DIR/.retry-configs.txt}"
WEB_CONFIGS_FILE="${WEB_CONFIGS_FILE:-$INSTANCES_DIR/.configs.txt}"
CYCLE_COUNT_FILE="${CYCLE_COUNT_FILE:-$SCRIPT_DIR/.cycle_count}"
TENANT_META_FILE="${TENANT_META_FILE:-$SCRIPT_DIR/public/tenant-meta.json}"

# Retry settings
BASE_SLEEP="${BASE_SLEEP:-180}"
MIN_SLEEP="${MIN_SLEEP:-45}"
SPEED_UP="${SPEED_UP:-15}"
JITTER_MIN="${JITTER_MIN:--20}"
JITTER_MAX="${JITTER_MAX:-20}"
TENANT_DELAY_MIN="${TENANT_DELAY_MIN:-2}"
TENANT_DELAY_MAX="${TENANT_DELAY_MAX:-8}"
INSTANCE_DELAY_MIN="${INSTANCE_DELAY_MIN:-3}"
INSTANCE_DELAY_MAX="${INSTANCE_DELAY_MAX:-12}"
LAUNCH_DELAY_MIN="${LAUNCH_DELAY_MIN:-0}"
LAUNCH_DELAY_MAX="${LAUNCH_DELAY_MAX:-5}"
RATE_LIMIT_PAUSE="${RATE_LIMIT_PAUSE:-30}"
RATE_LIMIT_JITTER_MIN="${RATE_LIMIT_JITTER_MIN:-5}"
RATE_LIMIT_JITTER_MAX="${RATE_LIMIT_JITTER_MAX:-20}"
DEFAULT_SHAPE="${DEFAULT_SHAPE:-VM.Standard.A1.Flex}"
DEFAULT_BOOT_SIZE_GB="${DEFAULT_BOOT_SIZE_GB:-200}"
MIN_BOOT_SIZE_GB="${MIN_BOOT_SIZE_GB:-50}"
DEFAULT_ASSIGN_PUBLIC_IP="${DEFAULT_ASSIGN_PUBLIC_IP:-true}"
DEFAULT_DUAL_STACK="${DEFAULT_DUAL_STACK:-true}"
DEFAULT_AVAILABILITY_DOMAINS="${DEFAULT_AVAILABILITY_DOMAINS:-}"
DEFAULT_FAULT_DOMAINS="${DEFAULT_FAULT_DOMAINS:-}"

mkdir -p "$SCRIPT_DIR/public"
mkdir -p "$INSTANCES_DIR"

# Tenant network config (add more tenants here)
get_public_subnet() {
    case "$1" in
        "maperryspeaks") echo "ocid1.subnet.oc1.us-chicago-1.aaaaaaaaavqoxq7tkzantrsx5mfry5l65xmkb2nsw3j6bgl4hmdpcyzwcsmq" ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.subnet.oc1.phx.aaaaaaaae36l65mttizeudw2rf3olrasquciix2inplxlw6i6tsic5vav52a" ;;
        "oci-sanjose-1-zeqic64q") echo "ocid1.subnet.oc1.us-sanjose-1.aaaaaaaatks6fap5obmn5ndfcjm7k46hg72jplnpcbb6rwwnszmfsaaljbga" ;;
        "rick74") echo "ocid1.subnet.oc1.iad.aaaaaaaa2t4zoiutvulc6lgdo56tnhp5topbu354ld4bmh26xw4wxoctcj5q" ;;
        "barbie68r") echo "ocid1.subnet.oc1.ca-toronto-1.aaaaaaaay6s43xoreyh3s472ylodumvemq5vb2zw24ew4hgsjhtzh5q6lnuq" ;;
        *) echo "" ;;
    esac
}

get_private_subnet() {
    case "$1" in
        "maperryspeaks") echo "ocid1.subnet.oc1.us-chicago-1.aaaaaaaax7252moxqaja3js5vy5ukfgbctjydvvh2jssoexu4vzef2xptqtq" ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.subnet.oc1.phx.aaaaaaaaitby2smd5sqql5ko2xu7uesnahjp6dpxifjkr3t4fg6n4erf5scq" ;;
        "oci-sanjose-1-zeqic64q") echo "ocid1.subnet.oc1.us-sanjose-1.aaaaaaaajwkfj7di3zf2k57i4ozsuyv4auuyvwb2zeqdkis2hfm43kb4pvqa" ;;
        "rick74") echo "ocid1.subnet.oc1.iad.aaaaaaaai42dfjauokwjzjr4whnf5ccw4wk2g4pqvsxh6f3c56wja7s4hsda" ;;
        *) echo "" ;;
    esac
}

get_subnet_ipv6() {
    case "$1" in
        "barbie68r") echo "2603:c021:8:c400::/64" ;;
        *) echo "" ;;
    esac
}

get_image() {
    case "$1" in
        "maperryspeaks") echo "ocid1.image.oc1.us-chicago-1.aaaaaaaahx57wqrsua2bw4oe63vnsvdc7pgx3yllpolzr2psvcq6qwg26yja" ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.image.oc1.phx.aaaaaaaavgzv3uxxskrwd6tmm2jfmkzqx25aq2inzcx7bs5op23ft35qgyfq" ;;
        "oci-sanjose-1-zeqic64q") echo "ocid1.image.oc1.us-sanjose-1.aaaaaaaagg6cb3x6qxcoerzncv7zyrhpnwnijp7wuuot6uxrsiiwvzfhaqfq" ;;
        "barbie68r") echo "ocid1.image.oc1.ca-toronto-1.aaaaaaaaftaneg3mxtzg7alr5jbqyanvdsijmmdynhlm272h4bri5woy3kdq" ;;
        *) echo "" ;;
    esac
}

# Ensure clean state
rm -f "$CONFIGS_FILE"

while [[ $# -gt 0 ]]; do
    case $1 in
        --instances-dir)
            INSTANCES_DIR="$2"
            shift 2
            ;;
        --instances-dir=*)
            INSTANCES_DIR="${1#*=}"
            shift
            ;;
        --tenants)
            TENANTS="$2"
            shift 2
            ;;
        --tenants=*)
            TENANTS="${1#*=}"
            shift
            ;;
        --tenants-list)
            echo "Available tenants: $(echo $TENANTS | tr ' ' '\n' | nl)"
            exit 0
            ;;
        --speed)
            SPEED_UP="$2"
            shift 2
            ;;
        --min-sleep)
            MIN_SLEEP="$2"
            shift 2
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --instances-dir <dir>   Instance configs directory"
            echo "  --tenants <list>      Space-separated tenant list"
            echo "  --tenants-list        Show available tenants"
            echo "  --speed <seconds>     Speed increase per cycle (default: $SPEED_UP)"
            echo "  --min-sleep <sec>    Minimum sleep between cycles (default: $MIN_SLEEP)"
            echo ""
            echo "Config file: config.sh (copy from config.example.sh)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg" | tee -a "$LOG_DIR/launch.log" >> "$WEB_LOG"
}

acquire_lock() {
    if [ -f "$LOCK_FILE" ]; then
        LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
            log "Another instance running (PID $LOCK_PID), exiting"
            exit 0
        fi
        log "Stale lock found, removing"
        rm -f "$LOCK_FILE"
    fi
    echo $$ > "$LOCK_FILE"
}

release_lock() {
    rm -f "$LOCK_FILE"
}

get_ssh_key() {
    local tenant=$1
    local key_file="$CONFIG_DIR/$tenant/key.pem"
    chmod 600 "$key_file"
    ssh-keygen -f "$key_file" -y 2>/dev/null || echo ""
}

get_oci_env() {
    local tenant=$1 shape=$2 image_id=$3 subnet_id=$4 ocpus=$5 memory=$6 boot_size=$7
    local hostname=$8 assign_public_ip=$9 dual_stack=${10} availability_domains=${11}
    local fault_domains=${12}
    local config="$CONFIG_DIR/$tenant/config.config"
    
    local region=$(grep "^region=" "$config" | cut -d'=' -f2)
    local user=$(grep "^user=" "$config" | cut -d'=' -f2)
    local fingerprint=$(grep "^fingerprint=" "$config" | cut -d'=' -f2)
    local tenancy=$(grep "^tenancy=" "$config" | cut -d'=' -f2)
    local key_file="$CONFIG_DIR/$tenant/key.pem"
    local ssh_key=$(get_ssh_key "$tenant")
    
    local ipv6_enabled="false"
    if [ "$dual_stack" = "true" ]; then
        local ipv6_subnet=""
        ipv6_subnet=$(get_subnet_ipv6 "$tenant")
        [ -n "$ipv6_subnet" ] && ipv6_enabled="true"
    fi
    
    cat > "$SCRIPT_DIR/oci-arm-host-capacity-fixed/.env" << ENVEOF
OCI_REGION=$region
OCI_USER_ID=$user
OCI_TENANCY_ID=$tenancy
OCI_KEY_FINGERPRINT=$fingerprint
OCI_PRIVATE_KEY_FILENAME=$key_file
OCI_SUBNET_ID=$subnet_id
OCI_IMAGE_ID=$image_id
OCI_SHAPE=$shape
OCI_OCPUS=$ocpus
OCI_MEMORY_IN_GBS=$memory
OCI_BOOT_VOLUME_SIZE_IN_GBS=$boot_size
OCI_SSH_PUBLIC_KEY="$ssh_key"
OCI_HOSTNAME=$hostname
OCI_ASSIGN_PUBLIC_IP=$assign_public_ip
OCI_IPV6_ENABLED=$ipv6_enabled
ENVEOF

    if [ "$ipv6_enabled" = "true" ] && [ -n "$ipv6_subnet" ]; then
        printf 'OCI_IPV6_SUBNET_ID=%s\n' "$ipv6_subnet" >> "$SCRIPT_DIR/oci-arm-host-capacity-fixed/.env"
    fi

    if [ -n "$availability_domains" ]; then
        printf 'OCI_AVAILABILITY_DOMAIN=%s\n' "$availability_domains" >> "$SCRIPT_DIR/oci-arm-host-capacity-fixed/.env"
    fi

    if [ -n "$fault_domains" ]; then
        printf 'OCI_FAULT_DOMAIN=%s\n' "$fault_domains" >> "$SCRIPT_DIR/oci-arm-host-capacity-fixed/.env"
    fi
}

check_rate_limit() {
    echo "$1" | grep -qiE '"(429|TooManyRequests|Rate.?limit)"' && return 0
    return 1
}

check_success() {
    echo "$1" | grep -q '"display-name"' && return 0
    return 1
}

get_tenant_state() {
    local tenant=$1
    [ -f "$TENANT_META_FILE" ] || { echo "active"; return; }
    jq -r --arg tenant "$tenant" '.[$tenant].state // "active"' "$TENANT_META_FILE" 2>/dev/null
}

should_skip_tenant() {
    local tenant=$1 state
    state=$(get_tenant_state "$tenant")
    case "$state" in
        deployed|paused|support)
            log "SKIP: $tenant is marked $state"
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

random_delay() {
    local min=${1:-1} max=${2:-10}
    local delay=$((RANDOM % (max - min + 1) + min))
    [ $min -eq 0 ] && [ $RANDOM -lt $((RANDOM % 3)) ] && delay=0
    log "Random delay: ${delay}s"
    sleep "$delay"
}

backoff_rate_limit() {
    local jitter=$((RANDOM % (RATE_LIMIT_JITTER_MAX - RATE_LIMIT_JITTER_MIN + 1) + RATE_LIMIT_JITTER_MIN))
    local total=$((RATE_LIMIT_PAUSE + jitter))
    log "RATE LIMIT - backing off ${total}s (+${jitter}s jitter)"
    sleep "$total"
}

launch_instance() {
    local tenant=$1 name=$2 ocpus=$3 memory=$4 boot_size=$5
    local hostname=$6 assign_public_ip=$7 dual_stack=$8 shape=$9 image_id_override=${10}
    local subnet_id_override=${11} availability_domains=${12} fault_domains=${13}
    
    if [ ! -f "$CONFIG_DIR/$tenant/config.config" ] || [ ! -f "$CONFIG_DIR/$tenant/key.pem" ]; then
        log "ERROR: Missing config or key for $tenant"
        return 2
    fi
    
    local image_id="$image_id_override"
    [ -z "$image_id" ] && image_id=$(get_image "$tenant")

    local subnet_id="$subnet_id_override"
    if [ -z "$subnet_id" ]; then
        if [ "$assign_public_ip" = "true" ]; then
            subnet_id=$(get_public_subnet "$tenant")
        else
            subnet_id=$(get_private_subnet "$tenant")
        fi
    fi
    
    [ -z "$subnet_id" ] || [ -z "$image_id" ] && {
        log "ERROR: Missing subnet or image for $tenant"
        return 2
    }
    
    local ipv6_subnet=""
    if [ "$dual_stack" = "true" ]; then
        ipv6_subnet=$(get_subnet_ipv6 "$tenant")
        if [ -z "$ipv6_subnet" ]; then
            log "NOTE: $tenant has no IPv6 subnet configured; launching IPv4-only for now"
            dual_stack="false"
        fi
    fi

    local net_info=""
    [ "$assign_public_ip" = "true" ] && net_info="${net_info}public_ip+"
    [ "$dual_stack" = "true" ] && net_info="${net_info}dual_stack"
    [ -n "$net_info" ] && net_info=" [$net_info]" || net_info=""
    
    log "LAUNCH: $tenant - $name (${ocpus}OCPU, ${memory}GB RAM, ${boot_size}GB disk)$net_info"
    
    random_delay "$LAUNCH_DELAY_MIN" "$LAUNCH_DELAY_MAX"
    
    get_oci_env "$tenant" "$shape" "$image_id" "$subnet_id" "$ocpus" "$memory" "$boot_size" "$hostname" "$assign_public_ip" "$dual_stack" "$availability_domains" "$fault_domains"
    
    local output
    output=$(php "$SCRIPT_DIR/oci-arm-host-capacity-fixed/index.php" 2>&1)
    local status=$?
    echo "$output" >> "$LOG_DIR/launch-$tenant.log"
    echo "$output"

    if [ $status -ne 0 ]; then
        local detail
        detail=$(printf '%s\n' "$output" | awk 'NF { print; exit }')
        [ -n "$detail" ] && log "FAILED DETAIL: $tenant - $detail"
    fi
    
    check_rate_limit "$output" && {
        echo "RATELIMIT" > /tmp/oracle-retry-ratelimit
        return 3
    }

    if check_success "$output"; then
        return 0
    fi

    local detail
    detail=$(printf '%s\n' "$output" | awk 'NF { print; exit }')
    [ -n "$detail" ] && log "FAILED DETAIL: $tenant - $detail"
    return 1
}

load_instances_clean() {
    local dir=$1 instances=()
    
    [ ! -d "$dir" ] && { echo "ERROR: Directory not found: $dir" >&2; exit 1; }
    
    for file in "$dir"/*.json; do
        [ -f "$file" ] || continue
        [[ "$(basename "$file")" == example-* ]] && continue
        
        local name hostname ocpus memory boot_size dual_stack assign_public_ip
        local shape image_id subnet_id availability_domains fault_domains
        name=$(jq -r '.name // empty' "$file" 2>/dev/null)
        hostname=$(jq -r '.hostname // .name // empty' "$file" 2>/dev/null)
        ocpus=$(jq -r '.ocpus // empty' "$file" 2>/dev/null)
        memory=$(jq -r '.memory // empty' "$file" 2>/dev/null)
        boot_size=$(jq -r '.boot_size // empty' "$file" 2>/dev/null)

        shape=$(jq -r '.shape // empty' "$file" 2>/dev/null)
        image_id=$(jq -r '.image_id // empty' "$file" 2>/dev/null)
        subnet_id=$(jq -r '.subnet_id // empty' "$file" 2>/dev/null)
        availability_domains=$(jq -r 'if .availability_domains == null then "" elif (.availability_domains | type) == "array" then (.availability_domains | join(",")) elif (.availability_domains | type) == "string" then .availability_domains else "" end' "$file" 2>/dev/null)
        fault_domains=$(jq -r 'if .fault_domains == null then "" elif (.fault_domains | type) == "array" then (.fault_domains | join(",")) elif (.fault_domains | type) == "string" then .fault_domains else "" end' "$file" 2>/dev/null)

        dual_stack=$(jq -r 'if (.network | type) == "object" and (.network | has("dual_stack")) then (if .network.dual_stack then "true" else "false" end) else "" end' "$file" 2>/dev/null)
        assign_public_ip=$(jq -r 'if (.network | type) == "object" and (.network | has("assign_public_ip")) then (if .network.assign_public_ip then "true" else "false" end) else "" end' "$file" 2>/dev/null)

        [ -z "$shape" ] && shape="$DEFAULT_SHAPE"
        [ -z "$boot_size" ] && boot_size="$DEFAULT_BOOT_SIZE_GB"
        [ -z "$dual_stack" ] && dual_stack="$DEFAULT_DUAL_STACK"
        [ -z "$assign_public_ip" ] && assign_public_ip="$DEFAULT_ASSIGN_PUBLIC_IP"
        [ -z "$availability_domains" ] && availability_domains="$DEFAULT_AVAILABILITY_DOMAINS"
        [ -z "$fault_domains" ] && fault_domains="$DEFAULT_FAULT_DOMAINS"

        if [[ "$boot_size" =~ ^[0-9]+$ ]] && [ "$boot_size" -lt "$MIN_BOOT_SIZE_GB" ]; then
            boot_size="$MIN_BOOT_SIZE_GB"
        fi

        [ -z "$name" ] || [ -z "$ocpus" ] || [ -z "$memory" ] || [ -z "$boot_size" ] && continue
        [ -z "$hostname" ] && hostname="$name"

        instances+=("${name}"$'\t'"${ocpus}"$'\t'"${memory}"$'\t'"${boot_size}"$'\t'"${hostname}"$'\t'"${assign_public_ip}"$'\t'"${dual_stack}"$'\t'"${shape}"$'\t'"${image_id}"$'\t'"${subnet_id}"$'\t'"${availability_domains}"$'\t'"${fault_domains}")
    done
    
    [ ${#instances[@]} -eq 0 ] && { echo "ERROR: No configs found" >&2; exit 1; }
    
    printf '%s\n' "${instances[@]}"
}

# Split literal tabs: IFS whitespace would collapse omitted image/subnet/AD fields.
# Assign only the fixed twelve launcher fields; malformed rows never reach launch.
decode_instance_row() {
    local remaining=$1 field index=0
    local values=()
    while [[ "$remaining" == *$'\t'* ]]; do
        values+=("${remaining%%$'\t'*}")
        remaining="${remaining#*$'\t'}"
    done
    values+=("$remaining")
    [ "${#values[@]}" -eq 12 ] || return 1
    for field in name ocpus memory boot_size hostname assign_public_ip dual_stack shape image_id subnet_id availability_domains fault_domains; do
        printf -v "$field" '%s' "${values[$index]}"
        index=$((index + 1))
    done
}

main() {
    acquire_lock
    trap release_lock EXIT
    
    log "=== Starting Oracle Instance Retry ==="
    log "Tenants: $TENANTS"
    log "Config: base_sleep=${BASE_SLEEP}s, min_sleep=${MIN_SLEEP}s, speed_up=${SPEED_UP}s/cycle"
    
    load_instances_clean "$INSTANCES_DIR" > "$CONFIGS_FILE"
    cp "$CONFIGS_FILE" "$WEB_CONFIGS_FILE"
    
    while IFS= read -r instance_row; do
        decode_instance_row "$instance_row" || { log "ERROR: Invalid instance configuration row"; continue; }
        [ -z "$name" ] && continue
        local net_label="private"
        [ "$assign_public_ip" = "true" ] && net_label="public"
        [ "$dual_stack" = "true" ] && net_label="${net_label} dual-stack"
        log "Loaded: $name (${ocpus}OCPU, ${memory}GB RAM, ${boot_size}GB disk, ${shape}, ${net_label})"
    done < "$CONFIGS_FILE"
    
    local success_count=0 fail_count=0 rate_limit_count=0 cycle_start=$(date +%s) cycle_end=0
    
    while IFS= read -r instance_row; do
        decode_instance_row "$instance_row" || { log "ERROR: Invalid instance configuration row"; continue; }
        [ -z "$name" ] && continue
        
        local launched=false
        for tenant in $TENANTS; do
            should_skip_tenant "$tenant" && continue
            log "Trying $tenant for $name..."
            
            local result
            launch_instance "$tenant" "$name" "$ocpus" "$memory" "$boot_size" "$hostname" "$assign_public_ip" "$dual_stack" "$shape" "$image_id" "$subnet_id" "$availability_domains" "$fault_domains"
            result=$?
            
            if [ $result -eq 0 ]; then
                log "SUCCESS: Launched $name on $tenant"
                launched=true success_count=$((success_count + 1))
                break
            elif [ $result -eq 3 ]; then
                backoff_rate_limit
                rate_limit_count=$((rate_limit_count + 1))
                log "RATE LIMIT: Skipping $name"
                break
            else
                log "FAILED: $tenant failed"
                fail_count=$((fail_count + 1))
                random_delay "$TENANT_DELAY_MIN" "$TENANT_DELAY_MAX"
            fi
        done
        
        [ "$launched" = false ] && log "WARNING: Could not launch $name"
        [ "$launched" = false ] && random_delay "$INSTANCE_DELAY_MIN" "$INSTANCE_DELAY_MAX"
    done < "$CONFIGS_FILE"
    
    local cycle_end
    cycle_end=$(date +%s)
    local cycle_duration=$((cycle_end - cycle_start))
    log "=== Cycle Complete: $success_count success, $fail_count failed, $rate_limit_count rate limited, took ${cycle_duration}s ==="
    
    release_lock
    
    # Adaptive sleep
    local cycle_count=$(cat "$CYCLE_COUNT_FILE" 2>/dev/null || echo 0)
    cycle_count=$((cycle_count + 1))
    echo "$cycle_count" > "$CYCLE_COUNT_FILE"
    
    local adaptive_sleep=$((BASE_SLEEP - (cycle_count * SPEED_UP)))
    [ $adaptive_sleep -lt $MIN_SLEEP ] && adaptive_sleep=$MIN_SLEEP
    
    local jitter=$((RANDOM % (JITTER_MAX - JITTER_MIN + 1) + JITTER_MIN))
    local total_sleep=$((adaptive_sleep + jitter))
    [ $total_sleep -lt $MIN_SLEEP ] && total_sleep=$MIN_SLEEP
    
    log "Cycle $cycle_count - Sleeping ${total_sleep}s (base: ${adaptive_sleep}s, jitter: ${jitter}s)"
    sleep "${total_sleep}"
    
    exec "$0" "$@"
}

main "$@"
