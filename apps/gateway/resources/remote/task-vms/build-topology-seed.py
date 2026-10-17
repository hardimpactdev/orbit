# Program: task-vms.build-topology-seed
# Uses: lib/program.py
# Outcomes: built
# Timeout: 600
# Input: {"project": "<Incus project>", "pool": "<host pool>", "vm": "<smoke VM>", "device": "topology-seed",
#   "volume": "orbit-topology-seed-<12 hex>-new", "size": "45GiB", "fingerprint": "<base image>"}
# Result: built: {"seconds": {"<step>": seconds, ...}}, the time of each step.
# Builds a topology seed on a task VM host, as root (ADR 0222). It creates the block volume `volume` in the host's
# project and pool, attaches it to the running smoke VM `vm` as the disk `device`, and copies the base image
# `fingerprint` into the VM. In the VM, Incus creates the btrfs pool `topology-seed` on the disk, imports the image,
# creates the stopped VM `node-template` from it with no profiles and its own root disk on that pool, and deletes the
# image. Then Incus stops in the VM, which unmounts the pool. The volume of an earlier run is deleted first; its VM
# may hold that run's Incus state, so the caller gives the program a fresh VM whenever the volume exists. The caller
# deletes the VM and then renames the volume, so a finished seed never has its build name. The volume must end in
# `-new`, so the program never deletes a finished seed or a clone.
# The program creates the volume before it changes anything in the VM.
# Every command reads no standard input: the program and its input already used it.
import json
import re
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

GUEST_POOL = 'topology-seed'
TEMPLATE = 'node-template'
GUEST_ARCHIVE = '/var/tmp/orbit-topology-seed.tar'
DEVICE_SECONDS = 60

NAME = re.compile(r'[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?')
FINGERPRINT = re.compile(r'[a-f0-9]{64}')


def incus(project, *arguments, check=True):
    completed = subprocess.run(['incus', '--project', project, *arguments], stdin=subprocess.DEVNULL,
                               capture_output=True, text=True, check=False)
    if check and completed.returncode != 0:
        log(f'incus {" ".join(arguments)} failed with exit code {completed.returncode}: {completed.stderr.strip()}')
        raise SystemExit(1)
    return completed


def guest(project, vm, *command, check=True):
    return incus(project, 'exec', vm, '--', *command, check=check)


def guest_device(device):
    """Incus names a VM disk's SCSI serial `incus_<device>` with each `-` doubled, and udev links it by that serial."""
    return '/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_' + device.replace('-', '--')


def validated(payload):
    keys = ('project', 'pool', 'vm', 'device', 'volume', 'size', 'fingerprint')
    values = {key: payload.get(key) for key in keys}
    if not all(isinstance(value, str) for value in values.values()) \
            or not all(NAME.fullmatch(values[key]) for key in ('project', 'pool', 'vm', 'device', 'volume')) \
            or not values['volume'].endswith('-new') \
            or not FINGERPRINT.fullmatch(values['fingerprint']) \
            or not re.fullmatch(r'[1-9][0-9]{0,4}GiB', values['size']):
        raise SystemExit('build-topology-seed: the input is invalid.')
    return values


def forget_earlier_run(project, pool, volume):
    if incus(project, 'storage', 'volume', 'show', pool, volume, check=False).returncode == 0:
        incus(project, 'storage', 'volume', 'delete', pool, volume)


def await_device(project, vm, path):
    deadline = time.monotonic() + DEVICE_SECONDS
    while guest(project, vm, 'test', '-b', path, check=False).returncode != 0:
        if time.monotonic() > deadline:
            log(f'The disk did not appear as {path} in {vm}.')
            raise SystemExit(1)
        time.sleep(1)


def copy_image(project, vm, fingerprint):
    work = Path(tempfile.mkdtemp(prefix='orbit-topology-seed.', dir='/var/tmp'))
    try:
        incus(project, 'image', 'export', fingerprint, str(work / 'base'))
        archive = work / 'base.tar'
        if not archive.is_file() or archive.is_symlink():
            log(f'The image {fingerprint} did not export as one archive.')
            raise SystemExit(1)
        incus(project, 'file', 'push', str(archive), vm + GUEST_ARCHIVE, '--mode=0600')
    finally:
        shutil.rmtree(work)


def build_template(project, vm, path, fingerprint):
    guest(project, vm, 'incus', 'storage', 'create', GUEST_POOL, 'btrfs', 'source=' + path)
    guest(project, vm, 'incus', 'image', 'import', GUEST_ARCHIVE)
    guest(project, vm, 'rm', '-f', '--', GUEST_ARCHIVE)
    guest(project, vm, 'incus', 'init', fingerprint, TEMPLATE, '--vm', '--no-profiles', '--storage', GUEST_POOL)
    guest(project, vm, 'incus', 'image', 'delete', fingerprint)
    instances = json.loads(guest(project, vm, 'incus', 'list', '--format', 'json').stdout)
    template = [instance for instance in instances if instance.get('name') == TEMPLATE]
    root = template[0].get('devices', {}).get('root', {}) if len(template) == 1 else {}
    if len(instances) != 1 or template[0].get('status') != 'Stopped' or template[0].get('profiles') != [] \
            or root.get('pool') != GUEST_POOL or template[0].get('type') != 'virtual-machine':
        log(f'The VM {TEMPLATE} is not the one stopped VM with no profiles and its root disk on {GUEST_POOL}.')
        raise SystemExit(1)
    # Stopping Incus unmounts the pool, so the btrfs file system is clean before the caller deletes the VM.
    guest(project, vm, 'systemctl', 'stop', 'incus.socket', 'incus-user.socket', 'incus.service', 'incus-user.service')
    guest(project, vm, 'sync')


def main(payload):
    values = validated(payload)
    project, pool, vm, device, volume = (values[key] for key in ('project', 'pool', 'vm', 'device', 'volume'))
    fingerprint, path = values['fingerprint'], guest_device(values['device'])
    seconds = {}
    started = time.monotonic()

    def step(name):
        nonlocal started
        now = time.monotonic()
        seconds[name] = round(now - started, 3)
        started = now
        log(f'build-topology-seed: {name} took {seconds[name]} s')

    forget_earlier_run(project, pool, volume)
    incus(project, 'storage', 'volume', 'create', pool, volume, '--type=block', 'size=' + values['size'])
    incus(project, 'config', 'device', 'add', vm, device, 'disk', 'pool=' + pool, 'source=' + volume)
    step('volume')
    copy_image(project, vm, fingerprint)
    step('copy')
    await_device(project, vm, path)
    build_template(project, vm, path, fingerprint)
    step('template')
    return 'built', {'seconds': seconds}


run_program(main)
