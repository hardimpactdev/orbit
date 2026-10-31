import { z } from "npm:zod@4.4.3";

const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Path = z.string().startsWith("/").max(4096).refine((value) =>
    !value.includes("\0") &&
    !value.split("/").some((part) => part === "." || part === "..")
);
const FileSnapshot = z.object({ path: Path, run_id: z.uuid() }).strict();
const Inputs = {
    file_remove: z.object({ path: Path, marker: z.string().nullable() })
        .strict(),
    file_snapshot: FileSnapshot,
    file_restore: FileSnapshot,
    file_snapshot_cleanup: FileSnapshot,
    observer_identity: z.object({}).strict(),
    observer_checkout: z.object({ checkout: Path }).strict(),
    storage: z.object({ root: Path, user: Account, group: Account }).strict(),
    account_directory: z.object({
        scope: z.enum([
            "apps",
            "composer_parent",
            "composer_home",
            "composer_vendor",
            "composer_bin",
        ]),
        user: Account,
        group: Account,
        home: Path,
    }).strict(),
    composer_manifest: z.object({ user: Account, group: Account }).strict(),
    directory: z.object({
        path: z.enum([
            "/dev/shm/orbit/hibernation",
            "/data/caddy/orbit/hibernation",
        ]),
    }).strict(),
    ci_resource: z.object({
        resource: z.enum(["pool", "project", "acl", "network", "profile"]),
    }).strict(),
    file: z.object({
        path: Path,
        mode: z.string().regex(/^[0-7]{3,4}$/),
        owner: z.string().regex(
            /^[a-zA-Z0-9_][a-zA-Z0-9_.-]*:[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/,
        ),
        content: z.string().max(4194304).nullable(),
        material_field: z.enum([
            "text",
            "configuration",
            "websocket_environment",
        ]).default("text"),
        marker: z.string().max(1024).nullable().default(null),
    }).strict(),
};
const Args = z.object({
    action: z.enum([
        "storage",
        "directory",
        "ci_resource",
        "file",
        "account_directory",
        "composer_manifest",
        "observer_identity",
        "observer_checkout",
        "file_remove",
        "file_snapshot",
        "file_restore",
        "file_snapshot_cleanup",
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

/** The same checkout access policy as NodeAgentSshExecutor, resolved from the observed account. */
export function observerFiles(raw: unknown) {
    const args = ObserverFilesInput.parse(raw);
    const root = args.apps_root ?? args.home + "/apps";
    const access = "ProtectHome=tmpfs" +
        (root.startsWith("/home/") || root.startsWith("/root/")
            ? "\nBindReadOnlyPaths=-" + root
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
    version: "2026.10.31.1",
    resources: {
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
