import { z } from "npm:zod@4.4.3";
import {
    vpRequirements,
    VpRuntime,
} from "../instance-deploy/vp_requirements.ts";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, valid } from "npm:semver@7.7.2";
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Observation = z.object({ stdout: z.string() }).passthrough();
const Link = z.object({ link: z.string(), directory: z.string().startsWith("/") }).strict();
const Captured = z.object({
    package: z.string(),
    pnpm_lock: z.string(),
    npm_lock: z.string(),
    node_version: z.string(),
    nvmrc: z.string(),
    home: z.string(),
    store: z.string(),
    links: z.array(Link),
    workspaces: z.array(z.object({ step: z.string(), stdout: z.string() })),
}).strict();
// A file the Node did not have was read as empty output.
const present = (stdout: string) => stdout === "" ? null : stdout;
function packageJson(stdout: string): Record<string, unknown> {
    const value = JSON.parse(present(stdout) ?? "{}");
    if (value === null || typeof value !== "object" || Array.isArray(value)) throw new Error("tool.project_invalid");
    return value;
}
function usesVitePlus(pkg: Record<string, unknown>) {
    const dependencies = { ...(pkg.dependencies as object ?? {}), ...(pkg.devDependencies as object ?? {}) };
    return Object.entries(dependencies).some(([name, spec]) =>
        name === "vite-plus" || (typeof spec === "string" && spec.startsWith("npm:vite-plus@"))
    );
}
/** The pnpm `link:` targets inside the application that may hold the vite-plus workspace. */
export function workspaceLinks(application: string, pkg: string, pnpmLock: string) {
    if (!usesVitePlus(packageJson(pkg))) return [];
    const root = application.replace(/\/+$/, "");
    const links = new Map<string, string>();
    for (const [, link] of pnpmLock.matchAll(/version: ["']?link:([^\s"']+)/g)) {
        const parts: string[] = [];
        for (const part of (root + "/" + link).split("/").slice(1)) {
            if (part === "..") parts.pop();
            else if (part !== "" && part !== ".") parts.push(part);
        }
        const directory = "/" + parts.join("/");
        if (directory.startsWith(root + "/")) links.set("link:" + link, directory);
    }
    return [...links].map(([link, directory]) => ({ link, directory }));
}
const Candidates = ["/opt/orbit/vite-plus", ".vite-plus", ".local/share/vite-plus"];
/** Builds the selection material from the reads; the Node held no staged copy. */
export function material(raw: z.infer<typeof Captured>) {
    const pkg = packageJson(raw.package);
    const home = z.string().startsWith("/").parse(raw.home.trim());
    const found = new Set(raw.store.split("\n").map((line) => line.trim()).filter(Boolean));
    const scopes = Candidates.map((path) => path.startsWith("/") ? path : home + "/" + path);
    const scope = usesVitePlus(pkg) ? scopes.find((path) => found.has(path + "/bin/vp")) : undefined;
    const workspaces: Record<string, unknown> = {};
    for (const row of raw.workspaces) {
        const link = raw.links[Number(/-(\d+)$/.exec(row.step)?.[1] ?? NaN)];
        const workspace = packageJson(row.stdout);
        if (link && workspace.name === "vite-plus") workspaces[link.link] = workspace.version;
    }
    return {
        store: scope ?? scopes[2],
        manager_required: scope === undefined,
        package: pkg,
        locks: Object.fromEntries(
            [["pnpm-lock.yaml", raw.pnpm_lock], ["package-lock.json", raw.npm_lock]].filter(([, text]) => present(text) !== null),
        ),
        workspaces,
        ".node-version": present(raw.node_version),
        ".nvmrc": present(raw.nvmrc),
    };
}
export const behavior = {
    type: "@nckrtl/instance-vp-requirements",
    version: "2026.11.02.1",
    resources: {
        links: {
            schema: z.object({ links: z.array(Link) }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: z.object({
                runtime: VpRuntime.nullable(),
                manager_required: z.boolean().optional(),
                ready: z.literal(true),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        links: {
            description: "Select the linked workspace folders to read for vite-plus",
            arguments: z.object({ application: z.string().startsWith("/"), package: z.string(), pnpm_lock: z.string() }),
            execute: async (args: { application: string; package: string; pnpm_lock: string }, ctx: Context) => {
                const result = { links: workspaceLinks(args.application, args.package, args.pnpm_lock) };
                await ctx.writeResource("links", "links", result);
                return result;
            },
        },
        capture: {
            description: "Select canonical pinned VP requirements from the read manifests",
            arguments: Captured,
            execute: async (args: z.infer<typeof Captured>, ctx: Context) => {
                const raw = material(Captured.parse(args));
                const result = {
                    ready: true as const,
                    runtime: vpRequirements(raw, raw.store),
                    manager_required: raw.manager_required,
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        result: {
            description:
                "Require the observed no-op or exact CLI installation and version proof",
            arguments: z.object({
                name: z.string(),
                steps: z.record(
                    z.string(),
                    z.object({
                        status: z.string(),
                        outputs: z.record(z.string(), z.unknown()).optional(),
                    }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    name: string;
                    steps: Record<
                        string,
                        { status: string; outputs?: Record<string, unknown> }
                    >;
                },
                ctx: Context,
            ) => {
                const requirements = args.steps[args.name + "-requirements"];
                if (
                    requirements?.status !== "succeeded" ||
                    requirements.outputs?.ready !== true
                ) throw new Error("deployment.vp_requirements_invalid");
                const runtime = VpRuntime.nullable().parse(
                    requirements.outputs.runtime,
                );
                if (
                    runtime !== null &&
                    args.steps[args.name + "-verify-0"]?.status !== "succeeded"
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        cli: {
            description:
                "Require a compatible existing CLI before any project installation",
            arguments: z.object({
                runtime: VpRuntime,
                observation: Observation,
            }),
            execute: async (
                args: {
                    runtime: z.infer<typeof VpRuntime>;
                    observation: { stdout: string };
                },
                ctx: Context,
            ) => {
                const version = valid(
                    args.observation.stdout.trim().replace(/^vp\s+/, ""),
                );
                if (
                    version === null || !gte(version, args.runtime.vp_version)
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime: args.runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        verify: {
            description:
                "Verify exact installed runtimes through the stock read-only inventory",
            arguments: z.object({ runtime: VpRuntime, inventory: Observation }),
            execute: async (
                args: {
                    runtime: z.infer<typeof VpRuntime>;
                    inventory: { stdout: string };
                },
                ctx: Context,
            ) => {
                const list = z.object({
                    node: z.array(z.object({ version: z.string() })),
                    package_managers: z.record(
                        z.string(),
                        z.array(z.object({ version: z.string() })),
                    ),
                }).parse(JSON.parse(args.inventory.stdout));
                if (
                    !list.node.some((row) =>
                        row.version === args.runtime.node_version
                    ) ||
                    !list.package_managers[args.runtime.package_manager]?.some(
                        (row) =>
                            row.version ===
                                args.runtime.package_manager_version,
                    )
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime: args.runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
