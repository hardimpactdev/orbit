import { z } from "npm:zod@4.4.3";
import { Assets as WorkerAssets } from "../node-worker-assets/assets.ts";
import { swampInstallSpec } from "./install_spec.ts";

export const SwampRole = {
    name: "swamp",
    singleton: true,
    mutable: true,
    relocatable: true,
    assignableDuringProvisioning: true,
    conflicts: [],
} as const;
export const Intent = z.object({
    schema_version: z.literal(1),
    role: z.literal("swamp"),
    node_id: z.number().int().positive(),
    assignment_id: z.number().int().positive(),
    created: z.boolean(),
    user: z.literal("orbit"),
    address: z.ipv4(),
    services_proxy_source: z.ipv4(),
    source_sha: z.string().regex(/^[a-f0-9]{40}$/),
    activate: z.boolean(),
}).strict();
export type Intent = z.infer<typeof Intent>;
const checkout = "/home/orbit/apps/orbit-monorepo-staging/checkout";
const state = "/home/orbit/apps/orbit-swamp/default";
const config = "/home/orbit/.config/swamp";
export const phases = [
    "account",
    "install",
    "checkout",
    "configuration",
    "release_load",
    "units",
    "activation",
    "verify",
] as const;
export const activationPhases = ["firewall", "activation", "verify"] as const;
export const allPhases = [...phases.slice(0, 6), ...activationPhases];
export const budgets = {
    account: 30,
    install: 330,
    checkout: 30,
    configuration: 30,
    release_load: 90,
    units: 30,
    activation: 120,
    verify: 30,
};
function unit(
    description: string,
    working: string,
    command: string,
    environment: string[],
    restart = "on-failure",
) {
    return `[Unit]\nDescription=Orbit Swamp ${description}\nAfter=network-online.target\nWants=network-online.target\nRequiresMountsFor=${state}/.swamp ${checkout}/swamp/.swamp ${state}/vaults ${checkout}/swamp/vaults\n\n[Service]\nType=exec\nUser=orbit\nGroup=orbit\nWorkingDirectory=${working}\n${
        environment.map((row) => "Environment=" + row).join("\n")
    }\nExecStart=${command}\nRestart=${restart}\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n`;
}
export function swampRolePlan(
    raw: Intent,
    worker: z.infer<typeof WorkerAssets>,
) {
    const intent = Intent.parse(raw);
    WorkerAssets.parse(worker);
    const files: {
        path: string;
        content: string;
        owner: string;
        mode: string;
        marker: string | null;
    }[] = [];
    const file = (
        path: string,
        content: string,
        owner = "root:root",
        mode = "0644",
        marker: string | null = null,
    ) => files.push({ path, content, owner, mode, marker });
    const env = [
        "HOME=/home/orbit",
        `PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin`,
        "NODE_USE_SYSTEM_CA=1",
        "SWAMP_REMOTE_ONLY=1",
        "SWAMP_HOME=/home/orbit/.swamp",
        `SWAMP_CONFIG_DIR=${config}`,
        "SWAMP_NO_UPDATE_CHECK=1",
        "SWAMP_NO_TELEMETRY=1",
    ];
    file(
        "/etc/systemd/system/orbit-swamp-serve.service",
        unit(
            "serve",
            checkout + "/swamp",
            `/usr/local/bin/swamp serve --repo-dir ${checkout}/swamp --port 9418 --host 127.0.0.1 --auth-mode token --admins user:nckrtl --club-api-key-file ${config}/api-key --trusted-hosts swamp.orbit --trust-proxy --dispatch-env-allow=, --max-concurrent-scheduled-runs 1 --remote-only --hot-reload`,
            env,
        ).replace(
            "ExecStart=",
            "LoadCredential=orbit-worker-enrollment.key:/etc/orbit/swamp-issuer/enrollment-vault.key\nExecStart=",
        ),
    );
    file(
        config + "/serve-tls/Caddyfile",
        `# Managed by Orbit: Swamp role\n{\n admin off\n auto_https off\n}\nhttps://swamp.orbit:9417 {\n bind ${intent.address}\n tls ${config}/serve-tls/cert.pem ${config}/serve-tls/key.pem\n reverse_proxy 127.0.0.1:9418 {\n  header_up Host swamp.orbit\n  header_up X-Forwarded-Proto https\n  header_up X-Forwarded-Host swamp.orbit\n  flush_interval -1\n  transport http {\n   versions 1.1\n   read_timeout 1h\n   response_header_timeout 1h\n  }\n }\n}\n`,
        "orbit:orbit",
        "0600",
        "# Managed by Orbit: Swamp role\n",
    );
    file(
        "/etc/systemd/system/orbit-swamp-tls.service",
        unit(
            "TLS proxy",
            "/home/orbit",
            `/usr/bin/caddy run --config ${config}/serve-tls/Caddyfile --adapter caddyfile`,
            env,
            "always",
        ),
    );
    file(worker.unit_path, worker.unit);
    file(worker.checker_path, worker.checker, "root:root", "0755");
    file(
        worker.unit_path + ".d/80-concurrency.conf",
        "[Service]\nEnvironment=SWAMP_WORKER_CONCURRENCY=7\n",
    );
    const workers = [{
        name: "orbit-node-209",
        unit: "orbit-node-worker.service",
        capacity: 7,
        enrollment: "/etc/orbit/swamp-worker/enrollment.token",
        server: "/etc/orbit/swamp-worker/server.token",
        owner: "root",
    }];
    for (
        const [name, pool] of [["swamp209-migration-a", "migration"], [
            "swamp209-migration-b",
            "migration",
        ], ["swamp209-services", "services-local"]]
    ) {
        const service = `orbit-${name}.service`;
        const directory = `${config}/workers/${name}`;
        const command =
            `/usr/local/bin/swamp worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt --cache-dir=/home/orbit/.cache/swamp-workers/${name} --label pool=${pool} --label node=209`;
        file(
            "/etc/systemd/system/" + service,
            unit("worker " + name, "/home/orbit", command, [
                ...env,
                "SWAMP_WORKER_CONCURRENCY=2",
            ], "always").replace(
                "ExecStart=",
                `LoadCredential=enrollment:${directory}/enrollment\nLoadCredential=server:${directory}/server\nLoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519\nExecStart=`,
            ),
        );
        workers.push({
            name,
            unit: service,
            capacity: 2,
            enrollment: directory + "/enrollment",
            server: directory + "/server",
            owner: "orbit",
        });
    }
    return {
        intent,
        install: swampInstallSpec(),
        checkout,
        state,
        config,
        files,
        workers,
        services: [
            "orbit-swamp-serve.service",
            "orbit-swamp-tls.service",
            ...workers.map((w) => w.unit),
        ],
        mounts: [
            { what: state + "/.swamp", where: checkout + "/swamp/.swamp" },
            { what: state + "/vaults", where: checkout + "/swamp/vaults" },
        ],
        credential_files: [
            { path: config + "/api-key", owner: "orbit" },
            {
                path: "/etc/orbit/swamp-issuer/enrollment-vault.key",
                owner: "root",
            },
            { path: "/etc/orbit/node-proxy/id_ed25519", owner: "root" },
            ...workers.flatMap((
                w,
            ) => [{ path: w.enrollment, owner: w.owner }, {
                path: w.server,
                owner: w.owner,
            }]),
        ],
    };
}
