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
        observation: {
            schema: PublicObservation,
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
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
