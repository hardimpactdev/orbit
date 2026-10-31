import { z } from "npm:zod@4.4.3";

export const WorkerConfig = z.object({
    node_id: z.number().int().positive(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/)
        .refine((v) => !["root", "orbit-swamp-package"].includes(v)),
}).strict();
export type WorkerConfig = z.infer<typeof WorkerConfig>;
export type WorkerCredentials = {
    enrollment_token: string;
    server_token: string;
};
export const Swamp = {
    version: "20261031.063736.0-sha.e459d219",
    sha256: "bfd72a112412ce48d4c11e8e80a35caefa811683146f7e30795d498382b20768",
    bytes: 405875640,
    headroom: 256 * 1024 * 1024,
};
export const WorkerCaFile = "/usr/local/share/ca-certificates/orbit-root.crt";
export const binary = "/usr/local/bin/swamp";
export const root = "/var/lib/orbit-node-worker";
export const credentialsRoot = "/etc/orbit/swamp-worker";
export const unit = "orbit-node-worker.service";
export const unitPath = `/etc/systemd/system/${unit}`;
export const checker = "/usr/local/libexec/orbit-node-worker-check";
export function availableMemory(
    input: string,
    artifactBytes = Swamp.bytes,
): number {
    if (!Number.isSafeInteger(artifactBytes) || artifactBytes <= 0) {
        throw new Error("swamp.node_worker_memory_low");
    }
    const rows = input.split("\n").filter((s) => s.startsWith("MemAvailable:"));
    const match = rows.length === 1 &&
        /^MemAvailable:\s+([0-9]+)\s+kB\s*$/.exec(rows[0]);
    const bytes = match ? Number(match[1]) * 1024 : NaN;
    if (
        !Number.isSafeInteger(bytes) || bytes < artifactBytes + Swamp.headroom
    ) {
        throw new Error("swamp.node_worker_memory_low");
    }
    return bytes;
}
export function workerUnit(config: WorkerConfig): string {
    const input = WorkerConfig.parse(config);
    const proxyIdentity = input.node_id === 209
        ? "LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519\n"
        : "";
    return `[Unit]\nDescription=Orbit Swamp worker for Node ${input.node_id}\nAfter=network-online.target\nWants=network-online.target\nStartLimitIntervalSec=0\n\n[Service]\nType=exec\nUser=${input.user}\nWorkingDirectory=${root}\nEnvironment=HOME=${root} SWAMP_HOME=${root}/home SWAMP_CONFIG_DIR=${root}/config\nLoadCredential=enrollment:${credentialsRoot}/enrollment.token\nLoadCredential=server:${credentialsRoot}/server.token\n${proxyIdentity}ExecStartPre=+${checker}\nExecStart=${binary} worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert=${WorkerCaFile} --cache-dir=${root}/cache --label node=${input.node_id}\nRestart=on-failure\nRestartSec=15\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nUMask=0077\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target\n`;
}
