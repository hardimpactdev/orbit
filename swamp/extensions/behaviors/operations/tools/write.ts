import { z } from "npm:zod@4.4.3";
import * as native from "./native.ts";
import { Setup } from "../node-bootstrap/tool_setup_assets.ts";
import { AdoptInput, type Context, Facts, ManagerFields, phaseAssets, platformSupports } from "./adopt.ts";
import { allows, normalizeVersion, safeVersion, Step, ToolRefusal, verifyInstalled } from "./contract.ts";
import { homebrewBlock, homebrewCandidateVersion } from "./adopt_homebrew.ts";
export const WriteInput = AdoptInput.safeExtend({ kind: z.enum(["tool.install", "tool.update"]), setup: Setup.nullable().default(null) });
/** The shared Tool Manager setup reads only the manager, for a Tool install or the development deploy. */
export const ManagerInput = ManagerFields.extend({ setup: Setup.nullable().default(null) }).refine(platformSupports);
type Input = z.infer<typeof WriteInput>;
type Phase = Record<string, unknown> | null;
type StepValue = z.infer<typeof Step> | null;
const Public = z.record(z.string(), z.unknown());
export type Decision = { ready: boolean; mutate: boolean; error_code?: string; native_error_code?: string; outcome?: string; manager_version: string | null; installed_version: string | null; candidate_json: string };
const plain = (input: Input) => ({ ...input, materialize: null, intent_only: false });
/** The manager's package facts: one admitted scope, a readable manager version and at most one installed version. */
export function writeInventory(value: unknown) {
  const facts = Facts.parse(value);
  if (facts.scope !== "present") throw new ToolRefusal("tool.manager_unavailable");
  if (facts.error_code || !safeVersion(facts.manager_version) || facts.complete !== true || !facts.versions ||
    facts.versions.length > 1 || facts.versions.some((version) => !safeVersion(version))) throw new ToolRefusal("tool.version_probe_failed");
  return { facts, present: facts.versions.length === 1 };
}
/** Refusals on the installed package before any package write; false when nothing needs writing. */
export function before(input: Input, facts: z.infer<typeof Facts>, present: boolean): boolean {
  if (input.kind === "tool.install" && input.tool_id === null && present) throw new ToolRefusal("tool.package_already_installed");
  if (input.kind === "tool.update" && !present) throw new ToolRefusal("tool.version_probe_failed");
  if (present) verifyInstalled(plain(input), facts.versions![0]!);
  return !(input.kind === "tool.install" && present);
}
/** The manager's normal candidate, checked against the constraint without selecting a version. */
export function candidate(input: Input, value: unknown): { version: string; allowed: boolean } {
  const raw = Public.safeParse(value);
  if (!raw.success) throw new ToolRefusal("tool.candidate_version_probe_failed");
  const version = input.manager.startsWith("brew") ? homebrewCandidateVersion(input, raw.data) : raw.data.version;
  if (input.manager.startsWith("brew") && homebrewBlock(input, raw.data, true)) throw new ToolRefusal("tool.candidate_version_probe_failed");
  if (raw.data.error_code !== undefined) throw new ToolRefusal("tool.candidate_version_probe_failed");
  if (!safeVersion(version)) throw new ToolRefusal("tool.candidate_version_unavailable");
  if (input.version_constraint !== null && normalizeVersion(version, input.manager) === null) throw new ToolRefusal("tool.candidate_version_unparseable");
  const allowed = allows(plain(input), version);
  if (!allowed && input.kind === "tool.install") throw new ToolRefusal("tool.version_constraint_blocked");
  return { version, allowed };
}
/** Decide from "Check the candidate" whether the package write runs; every refusal happens here, before it (flows/tool.install.mmd, tool.update.mmd). */
export function decide(input: Input, phase: Phase): Decision {
  let managerVersion: string | null = null, installed = input.installed_version;
  const refused = (code: string, native?: string): Decision => ({ ready: false, mutate: false, error_code: code, ...(native ? { native_error_code: native } : {}), manager_version: managerVersion, installed_version: installed, candidate_json: "null" });
  if (phase === null) return refused("tool.version_probe_failed");
  try {
    const scope = Facts.safeParse(phase.facts);
    // A manager that answered is ready, even when its package inventory is unreadable (decision 1).
    if (scope.success && safeVersion(scope.data.manager_version)) managerVersion = scope.data.manager_version;
    if (scope.success && scope.data.scope !== "present") return refused("tool.manager_unavailable", `tool.${input.manager.replace("-", "_")}_scope_${scope.data.scope}`);
    const { facts, present } = writeInventory(phase.facts);
    installed = present ? facts.versions![0]! : installed;
    if (!before(input, facts, present)) return { ready: true, mutate: false, outcome: "unchanged", manager_version: managerVersion, installed_version: present ? installed : null, candidate_json: "null" };
    const chosen = candidate(input, phase.candidate);
    // Update: the same version is nothing to do, a version outside the constraint stops here (flows/tool.update.mmd).
    const outcome = !chosen.allowed ? "blocked_by_constraint" : present && chosen.version === installed ? "unchanged" : "applied";
    return { ready: true, mutate: outcome === "applied", outcome, manager_version: managerVersion, installed_version: present ? installed : null, candidate_json: JSON.stringify(phase.candidate) };
  } catch (error) {
    if (!(error instanceof ToolRefusal) && !(error instanceof z.ZodError)) throw error;
    return refused(error instanceof ToolRefusal ? error.code : "tool.version_probe_failed");
  }
}
export const behavior = {
  type: "@nckrtl/tool-write-command", version: "2026.11.02.5",
  resources: { assets: { schema: Public, lifetime: "1d", garbageCollection: 10 }, decision: { schema: Public, lifetime: "1d", garbageCollection: 10 }, observation: { schema: Public, lifetime: "1d", garbageCollection: 10 } },
  methods: {
    assets: { description: "Shape public command argv for the admitted manager", arguments: z.object({ input: WriteInput }),
      execute: async (args: { input: Input }, ctx: Context) => { const value = native.commands(args.input); await ctx.writeResource("assets", "assets", value); return value; } },
    manager_assets: { description: "Read the phase driver and the manager query for the shared Tool Manager setup", arguments: z.object({ input: ManagerInput }),
      execute: async (args: { input: z.infer<typeof ManagerInput> }, ctx: Context) => {
        const value = await phaseAssets("tool.install", { ...args.input, package: "", repository: null, repository_packages: [], remove_repository: false }, ctx, {});
        await ctx.writeResource("assets", "assets", value); return value;
      } },
    decide: { description: "Check native manager observations and the normal candidate before any write", arguments: z.object({ input: WriteInput, version: Step.nullable(), query: Step.nullable(), os: Step.nullable(), check: Step.nullable() }),
      execute: async (args: { input: Input; version: StepValue; query: StepValue; os: StepValue; check: StepValue }, ctx: Context) => {
        let phase: Phase = null;
        try { const facts = native.inventory(args.input, args.version, args.query, args.os); phase = { facts, candidate: null }; phase.candidate = native.candidate(args.input, args.check, facts); }
        catch (error) { if (!(error instanceof ToolRefusal) && !(error instanceof z.ZodError) && !(error instanceof SyntaxError)) throw error; }
        const value = { ...decide(args.input, phase), version: phase?.candidate ? (phase.candidate as { version?: string }).version ?? "" : "" };
        await ctx.writeResource("decision", "decision", value); return value;
      } },
    observe: { description: "Decode the package state returned by the official end check", arguments: z.object({ input: AdoptInput, after: Public }),
      execute: async (args: { input: z.infer<typeof AdoptInput>; after: Record<string, unknown> }, ctx: Context) => {
        const facts = native.after(args.input, args.after);
        const installed_version = facts.versions.length === 1 ? facts.versions[0] : null;
        if (installed_version !== null && !safeVersion(installed_version)) throw new ToolRefusal("tool.version_probe_failed");
        if (typeof installed_version === "string") verifyInstalled(plain(WriteInput.parse({ ...args.input, kind: "tool.install" })), installed_version);
        const value = { installed_version, present: facts.versions.length === 1, absent: facts.versions.length === 0,
          compliant: args.input.manager !== "apt" || args.after.status === "compliant" };
        await ctx.writeResource("observation", "observation", value); return value;
      } },
  },
};
