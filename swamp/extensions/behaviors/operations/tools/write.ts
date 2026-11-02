import { z } from "npm:zod@4.4.3";
import { Setup } from "../node-bootstrap/tool_setup_assets.ts";
import { AdoptInput, type Context, Facts, ManagerFields, phaseAssets, platformSupports, repositoryError } from "./adopt.ts";
import { allows, normalizeVersion, printed, safeVersion, Step, ToolRefusal, verifyInstalled } from "./contract.ts";
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
/** A Node step that ran but did not do its job: it printed its result and exited nonzero. */
const unfinished = (step: StepValue) => step !== null && step.status !== "skipped" && (step.status !== "succeeded" || step.outputs?.exitCode !== 0);
export type WriteSteps = { setup: StepValue; source: StepValue; check: StepValue; decision: StepValue; write: StepValue };
/** The terminal result: the first chart step that did not finish, or the version the write left behind. */
export function writeResult(input: Input, steps: WriteSteps) {
  const finish = (fields: Record<string, unknown>, code: string | null) => ({ ok: code === null, stdout: JSON.stringify({ ...fields, ...(code ? { error_code: code } : {}) }) });
  // The shared setup child reports the manager's facts; a manager that is not ready fails it (decision 1: no Tool).
  if (unfinished(steps.setup)) {
    const scope = Facts.safeParse(printed(steps.setup)?.facts);
    const conflict = scope.success && scope.data.scope === "conflict";
    return finish({ manager_version: null, installed_version: input.installed_version, ...(conflict ? { native_error_code: `tool.${input.manager.replace("-", "_")}_scope_conflict` } : {}) },
      conflict ? "tool.manager_unavailable" : "tool.manager_provision_failed");
  }
  if (unfinished(steps.source)) return finish({ manager_version: null, installed_version: input.installed_version }, repositoryError(printed(steps.source)) ?? "tool.repository_failed");
  const decided = steps.decision?.status === "succeeded" ? steps.decision.outputs as Decision : decide(input, printed(steps.check));
  let code = decided.ready ? null : decided.error_code ?? "tool.version_probe_failed", installed = decided.installed_version, outcome = decided.outcome ?? "unchanged";
  if (code === null && decided.mutate) {
    const phase = printed(steps.write);
    try {
      if (phase?.written !== true) throw new ToolRefusal(input.kind + "_failed");
      const { facts, present } = writeInventory(phase.facts);
      if (!present) throw new ToolRefusal("tool.version_probe_failed");
      verifyInstalled(plain(input), facts.versions![0]!);
      installed = facts.versions![0]!;
      outcome = installed === decided.installed_version ? "unchanged" : "applied";
    } catch (error) {
      if (!(error instanceof ToolRefusal) && !(error instanceof z.ZodError)) throw error;
      code = error instanceof ToolRefusal ? error.code : "tool.version_probe_failed";
    }
  }
  return finish({ manager_version: decided.manager_version, installed_version: installed, ...(decided.native_error_code ? { native_error_code: decided.native_error_code } : {}), ...(code ? {} : { outcome }) }, code);
}
export const behavior = {
  type: "@nckrtl/tool-write-command", version: "2026.11.02.4",
  resources: { assets: { schema: z.object({ driver: z.string(), bundle: z.string() }), lifetime: "1d", garbageCollection: 10 }, decision: { schema: Public, lifetime: "1d", garbageCollection: 10 }, result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 } },
  methods: {
    assets: { description: "Read the phase driver and the selected manager scripts, with the public intent as argv JSON", arguments: z.object({ input: WriteInput }),
      execute: async (args: { input: Input }, ctx: Context) => {
        const family = args.input.manager.startsWith("brew") ? "brew" : args.input.manager;
        const value = await phaseAssets(args.input.kind, args.input, ctx, { candidate: `candidates/${family}.py`, write: `writes/${family}.py` });
        await ctx.writeResource("assets", "assets", value); return value;
      } },
    manager_assets: { description: "Read the phase driver and the manager query for the shared Tool Manager setup", arguments: z.object({ input: ManagerInput }),
      execute: async (args: { input: z.infer<typeof ManagerInput> }, ctx: Context) => {
        const value = await phaseAssets("tool.install", { ...args.input, package: "", repository: null, repository_packages: [], remove_repository: false }, ctx, {});
        await ctx.writeResource("assets", "assets", value); return value;
      } },
    decide: { description: "Refuse or select the package write from Check the candidate", arguments: z.object({ input: WriteInput, check: Step.nullable() }),
      execute: async (args: { input: Input; check: StepValue }, ctx: Context) => { const value = decide(args.input, printed(args.check)); await ctx.writeResource("decision", "decision", value); return value; } },
    result: { description: "Publish only typed manager and installed package results", arguments: z.object({ input: WriteInput, setup: Step.nullable(), source: Step.nullable(), check: Step.nullable(), decision: Step.nullable(), write: Step.nullable() }),
      execute: async (args: { input: Input } & WriteSteps, ctx: Context) => { const value = writeResult(args.input, args); await ctx.writeResource("result", "result", value); return value; } },
  },
};
