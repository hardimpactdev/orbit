import { z } from "npm:zod@4.4.3";
import { bootstrapProbeResult } from "./probe_result.ts";

const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Sha = z.string().regex(/^[a-f0-9]{64}$/);
const Packages = z.object({
    releases: z.array(z.string().regex(/^[a-z]+$/)).min(1),
    packages: z.array(z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/)).min(1),
}).strict();
const Artifact = z.object({
    url: z.url().refine((value) => {
        const url = new URL(value);
        return url.protocol === "https:" && !url.username && !url.password;
    }),
    sha256: Sha,
}).strict();
const Command = z.object({
    user: User,
    public_key: z.string().min(1).max(16384).regex(/^[^\r\n\0]+$/),
    releases: Packages.shape.releases,
    packages: Packages.shape.packages,
    dns_policy: z.enum(["managed", "preserve"]),
}).strict();
const Action = z.enum([
    "packages",
    "account",
    "authorized_key",
    "sudo",
    "observer",
    "verify",
]);
const Args = z.object({
    action: Action,
    user: User,
    command: Command,
    artifact: Artifact,
}).strict();
type Args = z.infer<typeof Args>;
export function bootstrapActionInput(raw: unknown) {
    const { action, user, command, artifact } = Args.parse(raw);
    if (command.user !== user || user === "root") {
        throw new Error("node.bootstrap_invalid");
    }
    return {
        packages: { packages: command.packages, releases: command.releases },
        account: { user },
        authorized_key: { user, public_key: command.public_key },
        sudo: { user },
        observer: artifact,
        verify: { user, sha256: artifact.sha256 },
    }[action];
}
const Observation = z.object({
    bootstrap: z.object({
        node_id: z.number().int().positive(),
        platform: z.enum(["linux", "macos"]),
        expected_architecture: z.string().nullable(),
        target: z.object({ fingerprint: z.string().min(1) }),
    }),
    observation: z.object({ stdout: z.string(), exitCode: z.literal(0) }),
    host_key: z.object({
        algorithm: z.string(),
        publicKey: z.string(),
        fingerprint: z.string(),
    }),
}).strict();
const PinInput = z.object({
    expected_fingerprint: z.string().min(1),
    host_key: Observation.shape.host_key,
}).strict();
const Pin = z.object({
    host_keys: z.array(z.string().min(1)).min(1),
    host_type: z.string().min(1),
    public_key: z.string().min(1),
    host_key_fingerprint: z.string().min(1),
}).strict();
export function enrollmentBootstrapPin(raw: unknown) {
    const { expected_fingerprint, host_key } = PinInput.parse(raw);
    if (host_key.fingerprint !== expected_fingerprint) {
        throw new Error("node.ssh_host_key_mismatch");
    }
    const [algorithm, key] = host_key.publicKey.trim().split(/\s+/);
    if (algorithm !== host_key.algorithm || !key) {
        throw new Error("node.ssh_host_key_invalid");
    }
    return Pin.parse({
        host_keys: [algorithm + " " + key],
        host_type: algorithm,
        public_key: key,
        host_key_fingerprint: host_key.fingerprint,
    });
}
export function enrollmentOperatingSystem(stdout: string) {
    const release = Object.fromEntries(
        stdout.trim().split("\n").map((line) => {
            const index = line.indexOf("=");
            return [
                line.slice(0, index),
                line.slice(index + 1).replace(/^"|"$/g, ""),
            ];
        }),
    );
    if (release.ID !== "ubuntu" || release.VERSION_CODENAME !== "resolute") {
        throw new Error("node.platform_unsupported");
    }
    return { operating_system_ready: true as const };
}
export function enrollmentBootstrapObservation(raw: unknown) {
    const input = Observation.parse(raw);
    if (input.host_key.fingerprint !== input.bootstrap.target.fingerprint) {
        throw new Error("node.ssh_host_key_mismatch");
    }
    const result = JSON.parse(bootstrapProbeResult(input).stdout);
    return {
        ...result,
        node_id: input.bootstrap.node_id,
        host_keys: [input.host_key.publicKey],
    };
}
const Assets = z.object({ script_body: z.string().max(1048576) }).strict();
const PublicObservation = z.object({
    probed: z.literal(true),
    node_id: z.number().int().positive(),
    architecture: z.enum(["x86_64", "arm64"]),
    host_type: z.string(),
    public_key: z.string(),
    host_key_fingerprint: z.string(),
    host_keys: z.array(z.string()).min(1),
}).strict();
const Completion = z.object({
    ready: z.literal(true),
    observation: PublicObservation,
    account: z.object({
        user: User,
        group: User,
        home: z.string().startsWith("/"),
    }).nullable(),
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
async function script(action: string, input: unknown, ctx: Context) {
    const source = await Deno.readTextFile(
        ctx.extensionFile("scripts/bootstrap_" + action + ".py"),
    );
    const result = Assets.parse({
        script_body: source.replace(
            "BOOTSTRAP_INPUT = None",
            "BOOTSTRAP_INPUT = json.loads(" +
                JSON.stringify(JSON.stringify(input)) + ")",
        ),
    });
    await ctx.writeResource("assets", "assets", result);
    return result;
}
/** Public asset delivery and decoding. Official SSH owns every connection. */
export const behavior = {
    type: "@nckrtl/node-bootstrap-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        pin: { schema: Pin, lifetime: "1d", garbageCollection: 10 },
        operating_system: {
            schema: z.object({ operating_system_ready: z.literal(true) }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        observer_identity: {
            schema: z.object({
                observer_ready: z.literal(true),
                observer_digest: Sha,
            }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        completion: {
            schema: Completion,
            lifetime: "1d",
            garbageCollection: 10,
        },
        observation: {
            schema: PublicObservation,
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        complete: {
            description:
                "Require the candidate account and pinned observer readiness",
            arguments: z.object({
                bootstrap: Observation.shape.bootstrap.extend({
                    managed_user: User,
                    address: z.ipv4(),
                    artifacts: z.record(z.string(), Artifact),
                }),
                observation: PublicObservation,
                linux: z.string().nullable(),
                macos: z.object({
                    user: z.string(),
                    addresses: z.string(),
                    digest: z.string(),
                }).nullable(),
            }).strict(),
            execute: async (
                args: {
                    bootstrap: {
                        platform: "linux" | "macos";
                        managed_user: string;
                        address: string;
                        artifacts: Record<string, z.infer<typeof Artifact>>;
                    };
                    observation: z.infer<typeof PublicObservation>;
                    linux: string | null;
                    macos:
                        | { user: string; addresses: string; digest: string }
                        | null;
                },
                ctx: Context,
            ) => {
                let account = null;
                if (args.bootstrap.platform === "linux") {
                    const result = z.object({
                        bootstrap_verified: z.literal(true),
                        account: z.object({
                            user: User,
                            group: User,
                            home: z.string().startsWith("/"),
                        }),
                    }).parse(JSON.parse(args.linux ?? "null"));
                    if (result.account.user !== args.bootstrap.managed_user) {
                        throw new Error("node.bootstrap_account_mismatch");
                    }
                    account = result.account;
                } else {
                    const mac = args.macos;
                    const artifact = args.bootstrap
                        .artifacts[
                            args.observation.architecture === "arm64"
                                ? "macos-aarch64"
                                : "macos-x86_64"
                        ];
                    if (
                        !mac ||
                        mac.user.trim() !== args.bootstrap.managed_user ||
                        !artifact ||
                        mac.digest.trim().split(/\s+/)[0] !== artifact.sha256
                    ) throw new Error("node.bootstrap_verification_failed");
                    const addresses = Array.from(
                        mac.addresses.matchAll(
                            /(?:^|\n)\s*inet\s+([0-9.]+)\s/g,
                        ),
                        (m) => m[1],
                    );
                    if (!addresses.includes(args.bootstrap.address)) {
                        throw new Error("node.macos_wireguard_required");
                    }
                }
                const result = Completion.parse({
                    ready: true,
                    observation: args.observation,
                    account,
                });
                await ctx.writeResource("completion", "completion", result);
                return result;
            },
        },
        pin: {
            description:
                "Compare the admitted fingerprint before platform reads or mutation",
            arguments: PinInput,
            execute: async (args: z.infer<typeof PinInput>, ctx: Context) => {
                const result = enrollmentBootstrapPin(args);
                await ctx.writeResource("pin", "pin", result);
                return result;
            },
        },
        operating_system: {
            description: "Require the managed Linux release before mutation",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = enrollmentOperatingSystem(args.stdout);
                await ctx.writeResource(
                    "operating_system",
                    "operating_system",
                    result,
                );
                return result;
            },
        },
        observer_identity: {
            description:
                "Read the fixed observer identity action without credential input",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: Context) => {
                const result = Assets.parse({
                    script_body: await Deno.readTextFile(
                        ctx.extensionFile(
                            "scripts/bootstrap_observer_identity.py",
                        ),
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        observer_identity_result: {
            description:
                "Read the public one-way observer digest from its action",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = z.object({
                    observer_ready: z.literal(true),
                    observer_digest: Sha,
                }).strict().parse(JSON.parse(args.stdout));
                await ctx.writeResource(
                    "observer_identity",
                    "observer_identity",
                    result,
                );
                return result;
            },
        },
        macos_observer: {
            description: "Read the pinned macOS observer file action",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: Context) => {
                const result = Assets.parse({
                    script_body: await Deno.readTextFile(
                        ctx.extensionFile(
                            "scripts/bootstrap_macos_observer.sh",
                        ),
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        observer: {
            description: "Read the canonical pinned observer delivery action",
            arguments: Artifact,
            execute: (args: z.infer<typeof Artifact>, ctx: Context) =>
                script("observer", Artifact.parse(args), ctx),
        },
        authorized_key: {
            description: "Read the tracked managed-account SSH access action",
            arguments: z.object({
                user: User,
                public_key: Command.shape.public_key,
            }).strict(),
            execute: (
                args: { user: string; public_key: string },
                ctx: Context,
            ) => {
                const input = z.object({
                    user: User,
                    public_key: Command.shape.public_key,
                }).strict().parse(args);
                return script("authorized_key", input, ctx);
            },
        },
        packages: {
            description:
                "Read the canonical package action for a role's admitted prerequisites",
            arguments: Packages,
            execute: (args: z.infer<typeof Packages>, ctx: Context) =>
                script("packages", Packages.parse(args), ctx),
        },
        observe: {
            description:
                "Check the admitted fingerprint and public platform observation",
            arguments: Observation,
            execute: async (
                args: z.infer<typeof Observation>,
                ctx: Context,
            ) => {
                const result = PublicObservation.parse(
                    enrollmentBootstrapObservation(args),
                );
                await ctx.writeResource("observation", "observation", result);
                return result;
            },
        },
        read: {
            description: "Read one shipped bootstrap action with public input",
            arguments: Args,
            execute: async (args: Args, ctx: Context) => {
                return await script(
                    args.action,
                    bootstrapActionInput(args),
                    ctx,
                );
            },
        },
    },
};
