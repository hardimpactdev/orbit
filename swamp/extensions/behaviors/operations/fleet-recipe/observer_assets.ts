import { CliInstallInput, CliInstallResult } from "../fleet/cli_install.ts";
import { z } from "npm:zod@4.4.3";
export const RoleConvergeIntent = z.object({
    schema_version: z.literal(1).optional(),
    node_id: z.number().int().positive(),
    assignment_id: z.number().int().positive(),
    role: z.enum(["database", "ingress", "websocket", "app-prod"]),
    created: z.boolean(),
    prerequisites: z.object({
        packages: z.array(z.string()),
        releases: z.array(z.string()),
    }).passthrough(),
}).passthrough();
export type RoleConvergeIntent = z.infer<typeof RoleConvergeIntent>;
const Path = z.string().startsWith("/").max(4096);
const FileSnapshot = z.object({ path: Path, run_id: z.uuid() }).strict();
const Inputs = {
    file_snapshot: FileSnapshot,
    file_restore: FileSnapshot,
    observer_identity: z.object({}).strict(),
    observer_checkout: z.object({ checkout: Path }).strict(),
    file: z.object({
        path: Path,
        mode: z.string().regex(/^[0-7]{3,4}$/),
        owner: z.string().regex(
            /^[a-zA-Z0-9_][a-zA-Z0-9_.-]*:[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/,
        ),
        content: z.string().nullable(),
        material_field: z.literal("websocket_environment").optional(),
        marker: z.string().nullable(),
    }).strict(),
};
const Args = z.object({
    action: z.enum([
        "file",
        "observer_identity",
        "observer_checkout",
        "file_snapshot",
        "file_restore",
    ]),
    input: z.record(z.string(), z.unknown()),
}).strict();
export type RoleBaselineAction = keyof typeof Inputs;
const Assets = z.object({ script_body: z.string() }).strict();
const ObserverFilesInput = z.object({
    configuration: z.string(),
    certificate: z.string(),
    unit: z.string(),
    apps_root: Path.nullable(),
    home: Path,
}).strict();
const ObserverFiles = z.object({
    configuration: z.string(),
    certificate: z.string(),
    unit: z.string(),
}).strict();
const ObserverDelivery = z.object({
    installed: z.literal(true),
    changed: z.boolean(),
    sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();

export const observerHomePolicy = {
    protected_prefixes: ["/home/", "/root/"],
    protection: "ProtectHome=tmpfs",
    binding: "BindReadOnlyPaths=-",
};
/** The same checkout access policy as NodeAgentSshExecutor, resolved from the observed account. */
export function observerFiles(raw: unknown) {
    const args = ObserverFilesInput.parse(raw);
    const root = args.apps_root ?? args.home + "/apps";
    const access = observerHomePolicy.protection +
        (observerHomePolicy.protected_prefixes.some((prefix) =>
                root.startsWith(prefix)
            )
            ? "\n" + observerHomePolicy.binding + root
            : "");
    return ObserverFiles.parse({
        configuration: args.configuration,
        certificate: args.certificate,
        unit: args.unit.replace(/^ProtectHome=yes$/m, access),
    });
}
const Result = z.object({ ready: z.literal(true), changed: z.boolean() })
    .strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Read and shape public assets only. All effects run in official SSH steps. */
export const behavior = {
    type: "@nckrtl/node-role-converge-command",
    version: "2026.11.01.4",
    resources: {
        cli: {
            schema: CliInstallResult,
            lifetime: "1d",
            garbageCollection: 10,
        },
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        observer_files: {
            schema: ObserverFiles,
            lifetime: "1d",
            garbageCollection: 10,
        },
        observer_delivery: {
            schema: ObserverDelivery,
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        plan: {
            description:
                "Validate supported immutable role input before effects",
            arguments: z.object({ intent: RoleConvergeIntent }).strict(),
            execute: async (
                args: { intent: z.infer<typeof RoleConvergeIntent> },
                ctx: Context,
            ) => {
                RoleConvergeIntent.parse(args.intent);
                const result = { ready: true, changed: false };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },

        prepared_tools: {
            description: "Verify same-Node OS prerequisite coverage",
            arguments: z.object({
                node_id: z.number().int().positive(),
                required_packages: z.array(z.string()),
                contribution: z.object({
                    ready: z.literal(true),
                    node_id: z.number().int().positive(),
                    packages: z.array(z.string()),
                }).strict(),
            }).strict(),
            execute: async (
                args: {
                    node_id: number;
                    required_packages: string[];
                    contribution: {
                        ready: true;
                        node_id: number;
                        packages: string[];
                    };
                },
                ctx: Context,
            ) => {
                if (
                    args.node_id !== args.contribution.node_id ||
                    args.required_packages.some((p) =>
                        !args.contribution.packages.includes(p)
                    )
                ) throw new Error("Incomplete prepared tools");
                const result = { ready: true, changed: false };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },

        cli: {
            description:
                "Bind the canonical CLI installation policy to admitted public input",
            arguments: CliInstallInput,
            execute: async (
                args: z.infer<typeof CliInstallInput>,
                ctx: Context,
            ) => {
                const input = CliInstallInput.parse(args);
                const result = {
                    script_body: (await Deno.readTextFile(
                        ctx.extensionFile("scripts/cli.py"),
                    )).replace(
                        "json.load(sys.stdin)",
                        "json.loads(" +
                            JSON.stringify(JSON.stringify(input)) +
                            ")",
                    ),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        cli_result: {
            description: "Decode the canonical public CLI outcome",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = CliInstallResult.parse(JSON.parse(args.stdout));
                await ctx.writeResource("cli", "cli", result);
                return result;
            },
        },
        observer_delivery: {
            description:
                "Prove the canonical observer delivery matches the admitted pin",
            arguments: z.object({
                sha256: ObserverDelivery.shape.sha256,
                stdout: z.string(),
            }).strict(),
            execute: async (
                args: { sha256: string; stdout: string },
                ctx: Context,
            ) => {
                const result = ObserverDelivery.parse(JSON.parse(args.stdout));
                if (result.sha256 !== args.sha256) {
                    throw new Error("agent.version_mismatch");
                }
                await ctx.writeResource(
                    "observer_delivery",
                    "observer_delivery",
                    result,
                );
                return result;
            },
        },
        observer_files: {
            description:
                "Resolve public observer templates from the admitted root and observed account",
            arguments: ObserverFilesInput,
            execute: async (
                args: z.infer<typeof ObserverFilesInput>,
                ctx: Context,
            ) => {
                const files = observerFiles(args);
                await ctx.writeResource(
                    "observer_files",
                    "observer_files",
                    files,
                );
                return files;
            },
        },
        read: {
            description: "Read one public role baseline action",
            arguments: Args,
            execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                const args = Args.parse(raw);
                const input = Inputs[args.action].parse(args.input);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                const result = Assets.parse({
                    script_body: source.replace(
                        "ROLE_INPUT = None",
                        "ROLE_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decode: {
            description: "Decode the public baseline action result",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = Result.parse(JSON.parse(args.stdout));
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
