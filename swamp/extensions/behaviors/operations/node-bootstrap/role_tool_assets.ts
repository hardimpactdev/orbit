import { z } from "npm:zod@4.4.3";

const Pin = z.object({
    version: z.string().min(1),
    url: z.url().refine((url) => new URL(url).protocol === "https:"),
    algorithm: z.enum(["sha256", "sha512"]),
    digest: z.string().regex(/^[a-f0-9]{64}(?:[a-f0-9]{64})?$/),
});
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
    user !== "root"
);
const Javascript = z.object({
    user: User,
    tools: z.object({
        bun: Pin,
        node: Pin,
        pnpm: Pin,
        "vp-cli": Pin,
        vp: Pin.extend({ lock_sha256: z.string().regex(/^[a-f0-9]{64}$/) }),
    }).strict(),
    vp_lock: z.string(),
}).strict();
export const RoleTools = z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    user: User,
    recipe_identity: z.string().min(1),
    apt: z.object({
        snapshot: z.string().regex(/^[0-9]{8}T[0-9]{6}Z$/),
        packages: z.array(
            z.object({
                name: z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/),
                version: z.string().regex(/^[0-9][0-9A-Za-z.+:~_-]*$/),
                artifact: Pin.omit({ version: true }).nullable(),
            }).strict(),
        ).min(1),
    }).strict(),
    javascript: Javascript.nullable(),
    browser: Pin.nullable(),
    composer: z.object({ user: User, version: z.string().min(1) }).strict()
        .nullable(),
    caddy: z.object({
        release: z.string().min(1),
        minimum_version: z.string().min(1),
        amd64_sha512: z.string().regex(/^[a-f0-9]{128}$/),
    }).strict().nullable(),
}).strict().refine((value) =>
    (value.javascript === null || value.javascript.user === value.user) &&
    (value.composer === null || value.composer.user === value.user)
);
const Assets = z.object({
    scripts_directory: z.string(),
    apt_json: z.string(),
    javascript_json: z.string().nullable(),
    composer_json: z.string().nullable(),
    browser_json: z.string().nullable(),
}).strict();
const Receipt = z.object({
    ready: z.literal(true),
    node_id: z.number().int().positive(),
    packages: z.array(z.string()),
}).strict();
type Context = {
    extensionFile(path: string): string | Promise<string>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const resource = <T>(schema: T) => ({
    schema,
    lifetime: "1d",
    garbageCollection: 10,
});

export const behavior = {
    type: "@nckrtl/node-enrollment-tools",
    version: "2026.10.31.1",
    resources: {
        assets: resource(Assets),
        completion: resource(z.object({ receipt: Receipt }).strict()),
    },
    methods: {
        read: {
            description: "Resolve the fixed enrollment tool installers",
            arguments: z.object({ intent: RoleTools }).strict(),
            execute: async (
                args: { intent: z.infer<typeof RoleTools> },
                ctx: Context,
            ) => {
                const intent = RoleTools.parse(args.intent);
                const script = await ctx.extensionFile(
                    "tool_scripts/install_apt.py",
                );
                const result = Assets.parse({
                    scripts_directory: script.replace(/\/[^/]+$/, ""),
                    apt_json: JSON.stringify(intent.apt),
                    javascript_json: intent.javascript === null
                        ? null
                        : JSON.stringify(intent.javascript),
                    browser_json: intent.browser === null
                        ? null
                        : JSON.stringify(intent.browser),
                    composer_json: intent.composer === null
                        ? null
                        : JSON.stringify(intent.composer),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        result: {
            description:
                "Require each selected installer and expose the parent tool contribution",
            arguments: z.object({
                intent: RoleTools,
                apt: z.string(),
                javascript: z.string().nullable(),
                composer: z.string().nullable(),
                browser: z.string().nullable(),
                caddy: z.object({ caddy_installed: z.literal(true) })
                    .passthrough().nullable(),
            }).strict(),
            execute: async (
                raw: {
                    intent: z.infer<typeof RoleTools>;
                    apt: string;
                    javascript: string | null;
                    composer: string | null;
                    browser: string | null;
                    caddy: { caddy_installed: true } | null;
                },
                ctx: Context,
            ) => {
                const intent = RoleTools.parse(raw.intent);
                const check = (stdout: string | null, field: string) => {
                    if (stdout === null || JSON.parse(stdout)[field] !== true) {
                        throw new Error("node.tool_readiness_unproved");
                    }
                };
                check(raw.apt, "apt_ready");
                if (intent.javascript !== null) {
                    check(raw.javascript, "javascript_ready");
                }
                if (intent.browser !== null) {
                    check(raw.browser, "browser_ready");
                }
                if (intent.composer !== null) {
                    check(raw.composer, "composer_ready");
                }
                if (
                    intent.caddy !== null && raw.caddy?.caddy_installed !== true
                ) {
                    throw new Error("node.tool_readiness_unproved");
                }
                const receipt = Receipt.parse({
                    ready: true,
                    node_id: intent.node_id,
                    packages: [
                        ...intent.apt.packages.map((item) => item.name),
                        ...(intent.caddy ? ["caddy"] : []),
                        ...(intent.browser ? ["chrome-headless-shell"] : []),
                    ].sort(),
                });
                const result = { receipt };
                await ctx.writeResource("completion", "completion", result);
                return result;
            },
        },
    },
};
