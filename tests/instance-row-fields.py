#!/usr/bin/env python3
"""Test real launcher main/decoder on synthetic rows, without its bootstrap.

Copyright (c) 2026 Matthew X Murphy (https://MatthewxMurphy.com).
Product/support: https://builtnotbegged.com
SPDX-License-Identifier: MIT

Only the exact main() and optional decode_instance_row() declarations are loaded
into a disposable Bash program. Every operational callee is an inert fixture;
only an owned queue/cycle file is written. No config, credential, OCI helper,
provider transport, real delay, service, lock or launcher re-exec is invoked.
"""
import argparse
import hashlib
import itertools
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile

BASH = '/bin/bash'
STREAM_CAP = 16 * 1024
CHILD_SECONDS = 5


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def declaration(source, name, required=True):
    pattern = r'^' + re.escape(name) + r'\(\) \{\n.*?^\}'
    matches = list(re.finditer(pattern, source, re.MULTILINE | re.DOTALL))
    if len(matches) != 1:
        if not required and not matches:
            return ''
        raise RuntimeError('Expected one exact standalone function: ' + name)
    return matches[0].group(0)


STUBS = r'''
# Fixed fixture boundaries: all of main's external/operational callees are inert.
acquire_lock() { :; }
release_lock() { :; }
log() {
    case "${1-}" in
        Loaded:\ *)
            printf 'loaded\0'
            printf '%s\0' "$name" "$ocpus" "$memory" "$boot_size" "$hostname" "$assign_public_ip" "$dual_stack" "$shape" "$image_id" "$subnet_id" "$availability_domains" "$fault_domains"
            ;;
    esac
}
cp() { :; }
cat() { printf '0\n'; }
date() { printf '1\n'; }
should_skip_tenant() { return 1; }
random_delay() { :; }
backoff_rate_limit() { :; }
load_instances_clean() { printf '%s\n' "$FIXTURE_ROW"; }
launch_instance() { printf 'launch\0'; printf '%s\0' "$@"; return 0; }
# End the one synthetic cycle before the actual launcher's exec/retry entrypoint.
sleep() { exit 0; }
exec() { printf 'Forbidden fixture re-exec\n' >&2; exit 99; }
CONFIGS_FILE="$CASE_DIR/queue.tsv"
WEB_CONFIGS_FILE="$CASE_DIR/web.tsv"
CYCLE_COUNT_FILE="$CASE_DIR/cycle.txt"
INSTANCES_DIR="$CASE_DIR/instances"
TENANTS='fixture-tenant'
BASE_SLEEP=1
SPEED_UP=0
MIN_SLEEP=1
JITTER_MIN=0
JITTER_MAX=0
main
'''


def cases():
    result = []
    options = ['ocid1.image.fixture', 'ocid1.subnet.fixture', 'AD-1,AD-2', r'FD-1\fixture,FD-2']
    for public, dual in [('true', 'true'), ('false', 'false')]:
        for present in itertools.product([False, True], repeat=4):
            fields = ['fixture-server', '2', '12', '100', r'fixture\host', public, dual, 'VM.Standard.A1.Flex']
            fields += [value if include else '' for value, include in zip(options, present)]
            result.append({'name': public + '-' + ''.join('1' if x else '0' for x in present),
                           'fields': fields, 'row': '\t'.join(fields),
                           'expected': ['fixture-tenant'] + fields, 'valid': True})
    fields = ['fixture-server', '2', '12', '100', 'fixture-host', 'true', 'true', 'VM.Standard.A1.Flex', '', '', '', '']
    for name, values in [('short-eleven', fields[:-1]), ('extra-thirteen', fields + ['unexpected'])]:
        malformed = '\t'.join(values)
        result.append({'name': name, 'fields': values, 'row': malformed, 'expected': [], 'valid': False})
        valid = ['fixture-previous', '2', '12', '100', 'previous-host', 'false', 'false', 'VM.Standard.A1.Flex', '', '', '', '']
        result.append({'name': 'after-valid-' + name, 'fields': [valid, values],
                       'row': '\t'.join(valid) + '\n' + malformed,
                       'expected': ['fixture-tenant'] + valid, 'valid': False})
    leading = fields[:]
    leading[0] = ''
    result.append({'name': 'leading-empty-name', 'fields': leading, 'row': '\t'.join(leading),
                   'expected': [], 'valid': False})
    return result


def gone(pid, group=False):
    try:
        (os.killpg if group else os.kill)(pid, 0)
        return False
    except ProcessLookupError:
        return True


def run_case(program, case, root):
    directory = root / case['name']
    directory.mkdir(mode=0o700)
    home, temporary = directory / 'home', directory / 'tmp'
    home.mkdir(mode=0o700)
    temporary.mkdir(mode=0o700)
    env = {'PATH': '/usr/bin:/bin', 'HOME': str(home), 'TMPDIR': str(temporary),
           'LANG': 'C', 'LC_ALL': 'C', 'TZ': 'UTC', 'CASE_DIR': str(directory), 'FIXTURE_ROW': case['row']}
    args = [BASH, '--noprofile', '--norc', str(program)]
    child = subprocess.Popen(args, cwd=directory, env=env, stdin=subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    timeout = False
    try:
        stdout, stderr = child.communicate(timeout=CHILD_SECONDS)
    except subprocess.TimeoutExpired:
        timeout = True
        child.kill()
        stdout, stderr = child.communicate(timeout=2)
    assert len(stdout) <= STREAM_CAP and len(stderr) <= STREAM_CAP, 'Bounded synthetic child output exceeded.'
    tokens = [value.decode('utf-8') for value in stdout.split(b'\0')[:-1]] if stdout else []
    framed = not stdout or stdout.endswith(b'\0')
    observed = {'loaded': [], 'launch': []}
    cursor = 0
    while cursor < len(tokens):
        tag = tokens[cursor]
        width = 12 if tag == 'loaded' else 13 if tag == 'launch' else 0
        if width == 0 or cursor + width >= len(tokens):
            framed = False
            break
        observed[tag].append(tokens[cursor + 1:cursor + 1 + width])
        cursor += width + 1
    expected_loaded = [case['expected'][1:]] if case['expected'] else []
    expected_launches = [case['expected']] if case['expected'] else []
    queue = directory / 'queue.tsv'
    queue_equal = queue.is_file() and queue.read_bytes() == (case['row'] + '\n').encode()
    cycle = directory / 'cycle.txt'
    cycle_equal = cycle.is_file() and cycle.read_bytes() == b'1\n'
    pid_gone = gone(child.pid)
    passed = (not timeout and child.returncode == 0 and not stderr and framed
              and observed['loaded'] == expected_loaded and observed['launch'] == expected_launches and queue_equal and cycle_equal and pid_gone)
    return {'name': case['name'], 'valid_row': case['valid'], 'input_fields': case['fields'],
            'expected_loaded_fields': expected_loaded, 'actual_loaded_fields': observed['loaded'],
            'expected_launch_arguments': expected_launches, 'actual_launch_arguments': observed['launch'],
            'passed': passed, 'command': args, 'cwd': str(directory), 'pid': child.pid,
            'exit_code': child.returncode, 'timeout': timeout, 'pid_gone': pid_gone,
            'inherited_fixture_group': os.getpgrp(), 'group_teardown_scope': 'outer supervisor verifies the whole owned fixture group', 'stdout_bytes': len(stdout), 'stderr_bytes': len(stderr),
            'stdout_sha256': sha(stdout), 'stderr_sha256': sha(stderr),
            'stdout_base64': __import__('base64').b64encode(stdout).decode(),
            'stderr_base64': __import__('base64').b64encode(stderr).decode(),
            'queue_exact_input': queue_equal, 'one_cycle_file': cycle_equal}


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--source', type=Path, default=Path(__file__).resolve().parents[1] / 'launch-instances.sh')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    raw = args.source.read_bytes()
    if len(raw) > 512 * 1024:
        raise RuntimeError('Launcher source exceeds fixture input limit.')
    source = raw.decode('utf-8')
    selected_main = declaration(source, 'main')
    selected_decoder = declaration(source, 'decode_instance_row', required=False)
    program_bytes = ('set -uo pipefail\n' + selected_decoder + '\n' + selected_main + '\n' + STUBS).encode()
    with tempfile.TemporaryDirectory(prefix='oci-row-fields-') as temporary:
        root = Path(temporary)
        program = root / 'selected-main.sh'
        program.write_bytes(program_bytes)
        program.chmod(0o400)
        rows = [run_case(program, case, root) for case in cases()]
        assert program.read_bytes() == program_bytes
    unchanged = args.source.read_bytes() == raw
    report = {'source': str(args.source.resolve()), 'source_sha256': sha(raw),
              'selected_main_sha256': sha(selected_main.encode()),
              'selected_decoder_sha256': sha(selected_decoder.encode()) if selected_decoder else None,
              'program_sha256': sha(program_bytes), 'program_base64': __import__('base64').b64encode(program_bytes).decode(), 'bash': BASH,
              'child_timeout_seconds': CHILD_SECONDS, 'per_stream_cap_bytes': STREAM_CAP,
              'source_unchanged': unchanged, 'total': len(rows),
              'passed': sum(row['passed'] for row in rows), 'failed': sum(not row['passed'] for row in rows),
              'cases': rows, 'scope': 'Extracted actual main/decoder; synthetic rows; all launch/provider/config/lock/delay effects stubbed; no launcher bootstrap.'}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key: report[key] for key in ['source_sha256', 'total', 'passed', 'failed', 'source_unchanged']}))
    return 0 if unchanged and all(row['passed'] for row in rows) else 1


if __name__ == '__main__':
    raise SystemExit(main())
