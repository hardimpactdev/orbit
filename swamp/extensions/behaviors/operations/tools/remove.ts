import { z } from "npm:zod@4.4.3";
import { AdoptInput, type Context, phaseAssets } from "./adopt.ts";
import { homebrewRemovalBlock } from "./adopt_homebrew.ts";
import { printed, Step, ToolRefusal } from "./contract.ts";
import { writeInventory } from "./write.ts";
export const RemoveInput = AdoptInput;
type Input = z.infer<typeof RemoveInput>;
type StepValue = z.infer<typeof Step> | null;
export type RemovalDecision = { ready: boolean; remove: boolean; error_code?: string; manager_version: string | null };
/** Refuse a removal from its plan: unreadable facts, a Composer non-root, an unsafe cask, or an apt or brew plan that removes more. */
export function removalDecision(input: Input, phase: Record<string, unknown> | null): RemovalDecision {
    let managerVersion: string | null = null;
    try {
        if (phase === null) throw new ToolRefusal("tool.version_probe_failed");
        const { facts, present } = writeInventory(phase.facts);
        managerVersion = facts.manager_version!;
        if (present && input.manager === "composer" && !facts.roots?.includes(input.package)) throw new ToolRefusal("tool.remove_failed");
        if (present && homebrewRemovalBlock(input, facts)) throw new ToolRefusal("tool.remove_failed");
        if (present && (input.manager === "apt" || input.manager === "brew")) {
            const plan = z.object({ packages: z.array(z.string()) }).safeParse(phase.plan);
            if (!plan.success) throw new ToolRefusal("tool.remove_failed");
            if (plan.data.packages.length !== 1 || plan.data.packages[0] !== input.package) throw new ToolRefusal("tool.removal_plan_unsafe");
        }
        return { ready: true, remove: present, manager_version: managerVersion };
    } catch (error) {
        if (!(error instanceof ToolRefusal) && !(error instanceof z.ZodError)) throw error;
        return { ready: false, remove: false, error_code: error instanceof ToolRefusal ? error.code : "tool.version_probe_failed", manager_version: managerVersion };
    }
}
/** Only proven absence deletes the Tool. Dropping the last Tool's apt source is best-effort (Nick, 2026-11-01). */
export function removalResult(input: Input, plan: StepValue, decision: StepValue, remove: StepValue, check: StepValue, source: StepValue) {
    const decided = decision?.status === "succeeded" ? decision.outputs as RemovalDecision : removalDecision(input, printed(plan));
    let error = decided.ready ? null : decided.error_code ?? "tool.version_probe_failed";
    if (error === null && decided.remove && (remove?.status !== "succeeded" || check?.status !== "succeeded")) error = "tool.remove_failed";
    return { ok: error === null, stdout: JSON.stringify({ manager_version: decided.manager_version,
        installed_version: error === null ? null : input.installed_version, ...(error ? { error_code: error } : { outcome: "applied" }),
        ...(input.remove_repository ? { repository_removed: source?.status === "succeeded" } : {}) }) };
}
export const behavior = {
    type: "@nckrtl/tool-remove-command", version: "2026.11.02.2",
    resources: {
        assets: { schema: z.object({ driver: z.string(), bundle: z.string() }), lifetime: "1d", garbageCollection: 10 },
        decision: { schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 10 },
        result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description: "Read the phase driver, the manager query, the apt or brew plan and the removal, with the public intent as argv JSON",
            arguments: z.object({ input: RemoveInput }),
            execute: async (args: { input: Input }, ctx: Context) => {
                const scripts: Record<string, string> = { remove: `mutations/${args.input.manager.replace("-", "_")}.py` };
                if (args.input.manager === "apt" || args.input.manager === "brew") scripts.plan = `mutations/${args.input.manager}_plan.py`;
                const value = await phaseAssets("tool.remove", args.input, ctx, scripts);
                await ctx.writeResource("assets", "assets", value); return value;
            },
        },
        decide: {
            description: "Refuse or select the removal from its plan",
            arguments: z.object({ input: RemoveInput, plan: Step.nullable() }),
            execute: async (args: { input: Input; plan: StepValue }, ctx: Context) => {
                const value = removalDecision(args.input, printed(args.plan)); await ctx.writeResource("decision", "decision", value); return value;
            },
        },
        result: {
            description: "Project only proven absence",
            arguments: z.object({ input: RemoveInput, plan: Step.nullable(), decision: Step.nullable(), remove: Step.nullable(), check: Step.nullable(), source: Step.nullable() }),
            execute: async (args: { input: Input; plan: StepValue; decision: StepValue; remove: StepValue; check: StepValue; source: StepValue }, ctx: Context) => {
                const value = removalResult(args.input, args.plan, args.decision, args.remove, args.check, args.source); await ctx.writeResource("result", "result", value); return value;
            },
        },
    },
};
