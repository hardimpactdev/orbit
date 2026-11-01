import { z } from "npm:zod@4.4.3";
import { Input, Kind, safeVersion, ToolRefusal, validateConstraint, verifyInstalled } from "./contract.ts";
import { homebrewBlock } from "./adopt_homebrew.ts";

export const AdoptInput = Input.omit({ materialize: true, intent_only: true }).extend({
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) => v !== "root"),
    package: z.string().min(1).max(255),
}).refine((v) =>
    !(v.platform === "macos" && ["apt", "composer"].includes(v.manager)) &&
    !(v.platform === "linux" && v.manager === "brew-cask")
);
export const Facts = z.object({
    error_code: z.literal("tool.version_probe_failed").optional(),
    scope: z.enum(["present", "absent", "conflict"]).default("present"),
    manager_version: z.string().optional(),
    versions: z.array(z.string().nullable()).max(4096).optional(),
    complete: z.boolean().optional(),
    roots: z.array(z.string()).optional(),
}).passthrough();
export type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function repositoryError(value: unknown): string | null {
    const result = z.object({ ready: z.boolean(), error_code: z.string().regex(/^tool\.repository_[a-z_]+$/).optional() }).safeParse(value);
    return !result.success ? "tool.repository_failed" : result.data.ready ? null : result.data.error_code ?? "tool.repository_failed";
}
type BundleInput = Pick<z.infer<typeof AdoptInput>, "manager" | "package" | "user" | "platform" | "repository" | "repository_packages" | "remove_repository"> & { setup?: { packages: string[] } | null };
/** The phase driver and its bundle: public input as argv JSON and each manager script composed with its support module. */
export async function phaseAssets(kind: z.infer<typeof Kind>, input: BundleInput, ctx: Pick<Context, "extensionFile">, scripts: Record<string, string>) {
    const read = (file: string) => Deno.readTextFile(ctx.extensionFile(file));
    const compose = async (...files: string[]) => (await Promise.all(["queries/support.py", ...files].map(read)))
        .map((source) => source.replace(/^from (?:support|brew_query) import [^\n]*\n/gm, "")).join("\n");
    const manager = input.manager.replace("-", "_");
    const bound: Record<string, string> = {};
    for (const [name, file] of Object.entries({ query: [...(input.manager.startsWith("brew") ? ["queries/brew_query.py"] : []), `queries/${manager}.py`], ...Object.fromEntries(Object.entries(scripts).map(([name, file]) => [name, [file]])) })) {
        bound[name] = await compose(...file);
    }
    const bundle = {
        input: { kind, manager: input.manager, package: input.package, user: input.user, platform: input.platform, manager_only: kind === "tool.manager_converge" },
        repository: input.repository ? { ...input.repository, packages: input.repository_packages } : null,
        remove_repository: input.remove_repository, root: input.manager === "apt", setup_packages: input.setup?.packages ?? [],
        repository_body: await read("apt_repository.py"), ...bound,
    };
    return { driver: await read("phase.py"), bundle: JSON.stringify(bundle) };
}
/** The adopt phase printed `{facts, repository?}`, or null when it did not finish. */
export function adoptionResult(input: z.infer<typeof AdoptInput>, phase: Record<string, unknown> | null) {
    let managerVersion: string | null = null;
    try {
        if (phase === null) throw new ToolRefusal("tool.version_probe_failed");
        if (input.repository) {
            const code = repositoryError(phase.repository);
            if (code) throw new ToolRefusal(code);
        }
        const intent = { ...input, intent_only: false, materialize: null };
        validateConstraint(intent);
        const facts = Facts.parse(phase.facts);
        if (facts.error_code) throw new ToolRefusal(facts.error_code);
        if (facts.scope !== "present") throw new ToolRefusal("tool.manager_unavailable");
        if (!safeVersion(facts.manager_version) || facts.complete !== true || !facts.versions) {
            throw new ToolRefusal("tool.version_probe_failed");
        }
        managerVersion = facts.manager_version;
        if (facts.versions.length === 0) throw new ToolRefusal("tool.package_absent");
        if (facts.versions.length !== 1 || !safeVersion(facts.versions[0])) {
            throw new ToolRefusal("tool.version_probe_failed");
        }
        if (input.manager.startsWith("brew") && facts.versions[0] === "latest") {
            throw new ToolRefusal("tool.version_probe_failed");
        }
        let block = input.manager.startsWith("brew") ? homebrewBlock(input, facts) : null;
        if (input.manager === "composer") {
            if (!facts.roots) throw new ToolRefusal("tool.version_probe_failed");
            if (!facts.roots.includes(input.package)) block = "dependency";
        }
        if (block) throw new ToolRefusal("tool.adoption_unsupported", { block });
        verifyInstalled(intent, facts.versions[0]);
        return { ok: true, stdout: JSON.stringify({
            manager_version: managerVersion, installed_version: facts.versions[0],
            outcome: input.tool_status === "installed" ? "unchanged" : "applied",
        }) };
    } catch (error) {
        if (error instanceof z.ZodError || error instanceof SyntaxError) error = new ToolRefusal("tool.version_probe_failed");
        if (!(error instanceof ToolRefusal)) throw error;
        return { ok: false, stdout: JSON.stringify({ ...error.result, manager_version: managerVersion, error_code: error.code }) };
    }
}
const Observation = z.object({ stdout: z.string().max(4 * 1024 * 1024), exitCode: z.literal(0) });
const parsed = (raw: unknown): unknown => {
    const observation = Observation.safeParse(raw);
    if (!observation.success) return null;
    try { return JSON.parse(observation.data.stdout); } catch { return null; }
};
/**
 * tool.adopt leaves Swamp: the Gateway will check the agent's observations instead (Nick, 2026-11-01). Its workflow
 * stays unchanged until the observer pass deletes it. That workflow runs the query through the SSH script method,
 * which passes no argv, so this binds the input the shared support module reads from argv.
 */
async function frozenAdoptQuery(input: z.infer<typeof AdoptInput>, ctx: Pick<Context, "extensionFile">) {
    const { bundle } = await phaseAssets("tool.adopt", input, ctx, {});
    const value = JSON.parse(bundle);
    return "QUERY_INPUT = " + JSON.stringify(JSON.stringify(value.input)) + "\nimport sys\nsys.argv = ['-c', QUERY_INPUT]\n" + value.query;
}
export const behavior = {
    type: "@nckrtl/tool-adopt-command", version: "2026.11.02.2",
    resources: {
        assets: { schema: z.object({ script_body: z.string(), repository_body: z.string(), repository_json: z.string() }), lifetime: "1d", garbageCollection: 10 },
        result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description: "Read the selected manager query and bind public intent",
            arguments: z.object({ input: AdoptInput }),
            execute: async ({ input }: { input: z.infer<typeof AdoptInput> }, ctx: Context) => {
                const result = { script_body: await frozenAdoptQuery(input, ctx), repository_body: await Deno.readTextFile(ctx.extensionFile("apt_repository.py")),
                    repository_json: JSON.stringify(input.repository ? { ...input.repository, packages: input.repository_packages } : null) };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        result: {
            description: "Check selected ownership against native manager facts",
            arguments: z.object({ input: AdoptInput, observation: Observation, repository_observation: Observation.nullable().optional() }),
            execute: async (args: { input: z.infer<typeof AdoptInput>; observation: unknown; repository_observation?: unknown }, ctx: Context) => {
                const facts = parsed(args.observation);
                const result = adoptionResult(args.input, facts === null ? null : { facts, repository: parsed(args.repository_observation) });
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        assert: {
            description: "Fail adoption after recording its bounded refusal",
            arguments: z.object({ ok: z.boolean() }),
            execute: ({ ok }: { ok: boolean }) => {
                if (!ok) throw new Error("tool.adoption_failed");
                return { ready: true };
            },
        },
    },
};
