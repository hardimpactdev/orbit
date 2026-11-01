import { z } from "npm:zod@4.4.3";
import { Input, safeVersion, ToolRefusal, validateConstraint, verifyInstalled } from "./contract.ts";
import { homebrewBlock } from "./adopt_homebrew.ts";

export const AdoptInput = Input.omit({ materialize: true, intent_only: true }).extend({
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) => v !== "root"),
    package: z.string().min(1).max(255),
}).refine((v) =>
    !(v.platform === "macos" && ["apt", "composer"].includes(v.manager)) &&
    !(v.platform === "linux" && v.manager === "brew-cask")
);
export const Observation = z.object({ stdout: z.string().max(4 * 1024 * 1024), exitCode: z.literal(0) });
export const Facts = z.object({
    error_code: z.literal("tool.version_probe_failed").optional(),
    scope: z.enum(["present", "absent", "conflict"]).default("present"),
    manager_version: z.string().optional(),
    versions: z.array(z.string().nullable()).max(4096).optional(),
    complete: z.boolean().optional(),
    roots: z.array(z.string()).optional(),
}).passthrough();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function repositoryError(raw: unknown): string | null {
    try {
        const result = z.object({ ready: z.boolean(), error_code: z.string().regex(/^tool\.repository_[a-z_]+$/).optional() }).parse(JSON.parse(Observation.parse(raw).stdout));
        return result.ready ? null : result.error_code ?? "tool.repository_failed";
    } catch { return "tool.repository_failed"; }
}
export async function queryScript(input: z.infer<typeof AdoptInput>, ctx: Pick<Context, "extensionFile">) {
    const files = [
        "support", ...(input.manager.startsWith("brew") ? ["brew_query"] : []),
        input.manager.replace("-", "_"),
    ];
    const sources = await Promise.all(files.map((name) =>
        Deno.readTextFile(ctx.extensionFile(`queries/${name}.py`))
    ));
    const script_body = sources.map((s) =>
        s.replace(/^from (?:support|brew_query) import [^\n]*\n/gm, "")
    ).join("\n").replace("QUERY_INPUT = None", "QUERY_INPUT = " + JSON.stringify(JSON.stringify(input)));
    return script_body;
}
export function adoptionResult(input: z.infer<typeof AdoptInput>, raw: unknown, repositoryObservation?: unknown) {
    let managerVersion: string | null = null;
    try {
        if (input.repository) {
            const code = repositoryError(repositoryObservation);
            if (code) throw new ToolRefusal(code);
        }
        const intent = { ...input, intent_only: false, materialize: null };
        validateConstraint(intent);
        const facts = Facts.parse(JSON.parse(Observation.parse(raw).stdout));
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
export const behavior = {
    type: "@nckrtl/tool-adopt-command", version: "2026.11.01.2",
    resources: {
        assets: { schema: z.object({ script_body: z.string(), repository_body: z.string(), repository_json: z.string() }), lifetime: "1d", garbageCollection: 10 },
        result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description: "Read the selected manager query and bind public intent",
            arguments: z.object({ input: AdoptInput }),
            execute: async ({ input }: { input: z.infer<typeof AdoptInput> }, ctx: Context) => {
                const script_body = await queryScript(input, ctx);
                const result = { script_body, repository_body: await Deno.readTextFile(ctx.extensionFile("apt_repository.py")), repository_json: JSON.stringify(input.repository) };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        result: {
            description: "Check selected ownership against native manager facts",
            arguments: z.object({ input: AdoptInput, observation: Observation, repository_observation: Observation.nullable().optional() }),
            execute: async (
                args: { input: z.infer<typeof AdoptInput>; observation: z.infer<typeof Observation>; repository_observation?: unknown }, ctx: Context,
            ) => {
                const result = adoptionResult(args.input, args.observation, args.repository_observation);
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
