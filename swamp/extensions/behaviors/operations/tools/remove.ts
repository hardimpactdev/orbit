import { z } from "npm:zod@4.4.3";
import * as native from "./native.ts";
import { AdoptInput, type Context } from "./adopt.ts";
import { homebrewRemovalBlock } from "./adopt_homebrew.ts";
import { Step, ToolRefusal } from "./contract.ts";
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
export const behavior = {
    type: "@nckrtl/tool-remove-command", version: "2026.11.02.3",
    resources: {
        assets: { schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 10 },
        decision: { schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description: "Shape public command argv for the admitted manager", arguments: z.object({ input: RemoveInput }),
            execute: async (args: { input: Input }, ctx: Context) => { const value = native.commands({ ...args.input, kind: "tool.remove" }); await ctx.writeResource("assets", "assets", value); return value; },
        },
        decide: {
            description: "Refuse a native removal plan that removes another package",
            arguments: z.object({ input: RemoveInput, version: Step.nullable(), query: Step.nullable(), os: Step.nullable(), plan: Step.nullable() }),
            execute: async (args: { input: Input; version: StepValue; query: StepValue; os: StepValue; plan: StepValue }, ctx: Context) => {
                let phase = null;
                try { phase = { facts: native.inventory(args.input, args.version, args.query, args.os), ...(["apt", "brew"].includes(args.input.manager) ? { plan: native.removalPlan(args.input, args.plan) } : {}) }; }
                catch (error) { if (!(error instanceof ToolRefusal) && !(error instanceof z.ZodError) && !(error instanceof SyntaxError)) throw error; }
                const value = removalDecision(args.input, phase); await ctx.writeResource("decision", "decision", value); return value;
            },
        },
    },
};
