"""The root disk device of an Instance container (docs/reference/development-containers.md#the-root-disk).

The one rule of the root device that every writer runs: create, the Ansible and native clones, the grow of a start, and the start
guard's admitted root. The intent's device keeps its `size` on every pool but a btrfs one. Incus applies a btrfs root `size` with
qgroups, which an unprivileged nested Node refuses (`btrfs qgroup limit` exits 255), and the copy's own volume already bounds that
pool; Orbit enables no qgroups.

Run on the Node: `python3 -c <this file> '<device JSON>' <pool driver>`, the device with `type`, `path`, `pool` and `size` and the
driver of its pool as Incus reports it; prints the device to write as JSON.
"""

import json
import sys

# The pool drivers whose root device carries no `size`.
UNSIZED = ("btrfs",)


def root_device(device, driver):
    return {key: value for key, value in device.items() if key != "size" or driver not in UNSIZED}


if __name__ == "__main__":
    print(json.dumps(root_device(json.loads(sys.argv[1]), sys.argv[2])))
