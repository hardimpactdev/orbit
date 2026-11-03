/** Canonical public Incus resources for the CI host role. */
export const project = "orbit-ci";
export const network = "orbitci0";
export const acl = "orbit-ci-egress";
export const pool = "default";
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
