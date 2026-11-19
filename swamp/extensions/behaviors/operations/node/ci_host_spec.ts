/** Canonical public Incus resources for the CI host role. */
export const project = "orbit-ci";
export const network = "orbitci0";
export const acl = "orbit-ci-egress";
// The role's own pool, on ZFS or btrfs beside the Node's Instance pool and marked as the role's (ansible/files/ci-host-pool.py and
// the agent's ci-runner.host keep the same rule). Never `dir`, never the host's `default` pool.
export const pool = "orbit-ci";
export const sourcePool = "orbit-instances";
export const poolOwner = "user.orbit.ci-runner";
export const projectConfig = {
    "features.images": "true",
    "features.profiles": "true",
    "features.storage.volumes": "true",
    "features.storage.buckets": "true",
    "features.networks": "false",
    restricted: "true",
    "restricted.networks.access": network,
    "restricted.containers.nesting": "block",
    "restricted.containers.privilege": "unprivileged",
    "restricted.virtual-machines.lowlevel": "block",
    "restricted.containers.lowlevel": "allow",
    // The one host disk a runner may mount: the Node's runtime folder, read-only (tools/ci-runners/setup.sh).
    "restricted.devices.disk": "allow",
    "restricted.devices.disk.paths": "/opt/orbit-runtime",
    "limits.virtual-machines": "0",
};
export const networkConfig = {
    "ipv4.nat": "true",
    "ipv6.address": "none",
    "security.acls": acl,
    "security.acls.default.egress.action": "reject",
    "security.acls.default.ingress.action": "reject",
};
export const profile = {
    description: "Orbit CI shard runner",
    config: {
        "boot.autostart": "true",
        "limits.cpu": "6",
        "limits.memory": "12GiB",
        "limits.processes": "8192",
        "security.nesting": "false",
        "security.privileged": "false",
    },
    devices: {
        eth0: { name: "eth0", network, type: "nic" },
        root: { path: "/", pool, type: "disk" },
    },
};
export const egress = [
    {
        action: "reject",
        destination:
            "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3",
        state: "enabled",
    },
    { action: "reject", destination: "::/0", state: "enabled" },
    { action: "allow", destination: "0.0.0.0/0", state: "enabled" },
];
