import { z } from "npm:zod@4.4.3";

export const InventoryProfile = z.enum(["router", "services", "swamp"]);

const number = z.number().int().nonnegative();
const id = z.number().int().positive();
const sha = z.string().regex(/^[0-9a-f]{64}$/);
const text = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.+~-]{0,100}$/);
const stamp = z.iso.datetime({ offset: true });
const absent = z.object({ status: z.enum(["missing", "read_error"]) }).strict();

const observed = <T extends z.ZodRawShape>(shape: T) =>
    z.union([z.object({ status: z.literal("ok"), ...shape }).strict(), absent]);
const digest = observed({ sha256: sha, matches_intent: z.boolean().optional() });
const metadata = {
    owner: text, group: text,
    mode: z.string().regex(/^[0-7]{4}$/),
    type: z.enum(["regular", "directory", "symlink", "socket", "other"]),
};
const file = observed(metadata);
const publicFile = observed({ ...metadata, digest });
const unit = observed({
    load: text, active: text, enabled: text.optional(), sub: text.optional(),
    pid: number.optional(), restarts: number.optional(),
    start_monotonic_us: number.optional(), main_start_monotonic_us: number.optional(),
    last_trigger_monotonic_us: number.optional(),
});
const commonFiles = {
    "/usr/local/bin/orbit-agent": publicFile,
    "/etc/orbit/agent/config.toml": publicFile,
    "/etc/orbit/agent/ca.pem": publicFile,
    "/etc/systemd/system/orbit-agent.service": publicFile,
    "/etc/modprobe.d/orbit-no-sctp.conf": publicFile,
    "/etc/sysctl.d/60-orbit-nonlocal-bind.conf": publicFile,
    "/etc/apt/apt.conf.d/20auto-upgrades": publicFile,
    "/etc/apt/apt.conf.d/52orbit-unattended-upgrades": publicFile,
    "/etc/apt/apt.conf.d/53orbit-reboot-due": publicFile,
    "/etc/needrestart/conf.d/50-orbit.conf": publicFile,
    "/usr/local/lib/orbit/reboot-due": publicFile,
    "/etc/orbit/agent": file,
    "/etc/orbit/agent/secret": file,
    "/root/.orbit/config.json": file,
    "/usr/local/libexec/orbit-executor": file,
    "/run/orbit-executor.sock": file,
};
const commonUnits = {
    "orbit-agent.service": unit,
    "orbit-executor.service": unit,
    "apt-daily.timer": unit,
    "apt-daily-upgrade.timer": unit,
    "wg-quick@orbit.service": unit,
};
const group = observed({ id: number });
const sysctl = observed({ value: z.union([z.literal(0), z.literal(1)]), matches_intent: z.boolean() });
const pkg = observed({ installed: z.boolean(), state: text, version: z.string().regex(/^[A-Za-z0-9.+~:-]{1,100}$/) });
const container = observed({
    id: sha, state: text, running: z.boolean(), pid: number, started_at: stamp,
    restarts: number, start_ticks: observed({ value: number }),
});
const workers = observed({
    slots: z.record(z.enum(["a", "b", "c"]), observed({
        state: text, connected_at_us: number, capacity: number, has_instance: z.boolean(),
    })),
});
const base = {
    node_id: id, captured_at: stamp,
    os: observed({ id: text, release: text }),
    architecture: text, kernel: text,
    boot_id: observed({ value: z.uuid() }),
    uptime: observed({ seconds: number }),
    cli: z.object({
        link: observed({
            ...metadata,
            link: observed({
                managed: z.boolean(),
                target: z.string().regex(/^(?:\/usr\/local\/bin\/)?orbit-0[.][1-9][0-9]{0,9}[.]0$/).optional(),
            }).optional(),
        }),
        versions: observed({ files: z.record(z.string().regex(/^\/usr\/local\/bin\/orbit-0[.][1-9][0-9]{0,9}[.]0$/), publicFile) }),
    }).strict(),
    sysctl: z.object({ effective: sysctl, recorded: sysctl }).strict(),
    packages: z.object({ "unattended-upgrades": pkg, needrestart: pkg }).strict(),
    apt_cache: observed({ age_seconds: number }),
    orbit_env_group: group,
    agent_secret_match: observed({ match: z.boolean() }),
    agent_running: digest,
    executor_running: digest,
};
const inventory = z.discriminatedUnion("profile", [
    z.object({
        ...base, profile: z.literal("router"),
        files: z.object(commonFiles).strict(),
        units: z.object({ ...commonUnits, "caddy.service": unit, "orbit-node-worker.service": unit,
            "docker.service": unit, "containerd.service": unit, "incus.service": unit,
            "dnsmasq.service": unit, "systemd-resolved.service": unit, "orbit-cluster-dns.service": unit,
        }).strict(),
        active_services: observed({
            units: z.record(z.string().regex(/^(?:php[0-9.]+-fpm|orbit-process-[0-9]+-[A-Za-z0-9_.@-]+|incus[A-Za-z0-9_.@-]*|orbit-[A-Za-z0-9_.@-]*-(?:dns|ingress)[A-Za-z0-9_.@-]*)[.]service$/), unit),
        }),
        wireguard: digest, firewall: digest, publication: digest,
    }).strict(),
    z.object({
        ...base, profile: z.literal("swamp"),
        files: z.object({ ...commonFiles,
            "/etc/systemd/system/orbit-swamp-worker@.service": publicFile,
            "/etc/systemd/system/orbit-swamp-serve.service": publicFile,
        }).strict(),
        units: z.object({ ...commonUnits, "orbit-swamp-serve.service": unit,
            "orbit-swamp-worker@a.service": unit, "orbit-swamp-worker@b.service": unit, "orbit-swamp-worker@c.service": unit,
            "orbit-swamp-worker-renewal.service": unit, "orbit-swamp-worker-renewal.timer": unit,
        }).strict(),
        serve_ready: observed({ ready: z.boolean() }),
        worker_pool: workers,
    }).strict(),
    z.object({
        ...base, profile: z.literal("services"),
        files: z.object({ ...commonFiles,
            "/usr/local/libexec": file,
            "/etc/orbit/executor": file,
            "/var/lib/orbit/executor": file,
            "/var/lib/orbit/executor/journal": file,
            "/usr/local/libexec/orbit-executor": publicFile,
            "/etc/orbit/executor/gateway.pub": publicFile,
            "/etc/orbit/executor/node": publicFile,
            "/etc/orbit/executor/enabled": publicFile,
            "/etc/orbit/executor/restore-threads": publicFile,
            "/etc/systemd/system/orbit-executor.service": publicFile,
            "/etc/sudoers.d/orbit-executor-relay": publicFile,
            "/etc/systemd/system/orbit-websocket.service": publicFile,
        }).strict(),
        units: z.object({ ...commonUnits, "docker.service": unit, "containerd.service": unit,
            "orbit-websocket.service": unit,
        }).strict(),
        active_services: observed({
            units: z.record(z.string().regex(/^(?:postgresql|mysql|clickhouse|plausible)[A-Za-z0-9_.@-]*[.]service$/), unit),
        }),
        websocket_ready: observed({ ready: z.boolean() }),
        backing_containers: z.record(z.string().regex(/^orbit-process-[1-9][0-9]*-[a-z0-9-]+$/), container),
        executor_relay_user: observed({ id: number, primary_gid: number }),
        executor_relay_group: group,
        powersync: z.record(z.enum([
            "orbit-process-264-powersync-hauzer-dev", "orbit-process-265-powersync-mealou-dev",
            "orbit-process-267-powersync-mealou", "orbit-process-268-powersync-hauzer",
        ]), container),
    }).strict(),
]);

export const InventoryReceiptInput = z.object({
    stdout: z.string().max(128 * 1024), node_id: id, profile: InventoryProfile,
}).strict();

export function inventoryReceipt(raw: z.infer<typeof InventoryReceiptInput>): { stdout: string } {
    try {
        const receipt = inventory.parse(JSON.parse(raw.stdout));
        if (receipt.node_id === raw.node_id && receipt.profile === raw.profile) {
            return { stdout: JSON.stringify(receipt) };
        }
    } catch {
        // Keep invalid transport output out of errors and retained results.
    }
    throw new Error("node.base_inventory_invalid_receipt");
}
