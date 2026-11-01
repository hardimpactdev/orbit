import { z } from "npm:zod@4.4.3";
import { Setup } from "../node-bootstrap/tool_setup_assets.ts";
import { AdoptInput, Facts, Observation, queryScript, repositoryError } from "./adopt.ts";
import { removalProbe } from "./remove.ts";
import { allows, normalizeVersion, safeVersion, ToolRefusal, verifyInstalled } from "./contract.ts";
import { homebrewBlock, homebrewCandidateVersion } from "./adopt_homebrew.ts";
const WriteShape = AdoptInput.safeExtend({ package: z.string().max(255), kind: z.enum(["tool.install", "tool.update", "tool.manager_converge"]), setup: Setup.nullable().default(null) }).refine((v) => v.kind === "tool.manager_converge" ? v.package === "" : v.package.length > 0);
export const WriteInput = z.preprocess((value) => {
  if (value && typeof value === "object" && "kind" in value && value.kind === "tool.manager_converge" && !("package" in value)) return { ...value, package: "" };
  return value;
}, WriteShape);
type Input = z.infer<typeof WriteInput>;
type Context = { extensionFile(name: string): string; writeResource(spec: string, name: string, value: unknown): Promise<unknown> };
const Public = z.record(z.string(), z.unknown());
const Step = z.object({ status: z.string(), outputs: Public.optional() }).passthrough();
type Check = { ready: boolean; error_code?: string; setup_required?: boolean; package_required?: boolean; facts?: z.infer<typeof Facts>; version?: string; allowed?: boolean; mutate?: boolean; outcome?: string };
const refusal = (error: unknown, fallback = "tool.version_probe_failed") => ({ ready: false, error_code: error instanceof ToolRefusal ? error.code : fallback });
export function inspect(input: Input, observation: unknown): Check {
  try {
    const facts = Facts.parse(JSON.parse(Observation.parse(observation).stdout));
    return { ready: true, setup_required: input.kind !== "tool.update" && input.platform === "linux" && facts.scope === "absent" && input.setup !== null, facts };
  } catch (error) { return refusal(error); }
}
export function before(input: Input, observation: unknown, final = false): Check {
  try {
    const value = removalProbe({ ...input, intent_only: false }, observation);
    if (!value.ready || !value.facts) throw new ToolRefusal(value.error_code ?? "tool.version_probe_failed");
    const facts = value.facts;
    if (input.kind === "tool.manager_converge") return { ready: true, package_required: false, facts };
    if (!final && input.kind === "tool.install" && input.tool_id === null && value.present) throw new ToolRefusal("tool.package_already_installed");
    if (input.kind === "tool.update" && !value.present) throw new ToolRefusal("tool.version_probe_failed");
    if (value.present) verifyInstalled({ ...input, materialize: null, intent_only: false }, facts.versions![0]!);
    return { ready: true, package_required: !(input.kind === "tool.install" && value.present), facts };
  } catch (error) { return refusal(error); }
}
export function candidate(input: Input, observation: unknown): Check {
  try {
    const raw = Public.parse(JSON.parse(Observation.parse(observation).stdout));
    const version = input.manager.startsWith("brew") ? homebrewCandidateVersion(input, raw) : raw.version;
    if (input.manager.startsWith("brew") && homebrewBlock(input, raw, true)) throw new ToolRefusal("tool.candidate_version_probe_failed");
    if (!safeVersion(version)) throw new ToolRefusal("tool.candidate_version_unavailable");
    if (input.version_constraint !== null && normalizeVersion(version, input.manager) === null) throw new ToolRefusal("tool.candidate_version_unparseable");
    const allowed = allows({ ...input, materialize: null, intent_only: false }, version);
    if (!allowed && input.kind === "tool.install") throw new ToolRefusal("tool.version_constraint_blocked");
    return { ready: true, version, allowed };
  } catch (error) { return refusal(error, "tool.candidate_version_probe_failed"); }
}
export function decision(input: Input, previous: unknown, selected: unknown, observation: unknown): Check {
  try {
    const original = Facts.parse(previous), chosen = z.object({ ready: z.literal(true), version: z.string(), allowed: z.boolean() }).parse(selected);
    const current = removalProbe({ ...input, intent_only: false }, observation);
    if (!current.ready || !current.facts) throw new ToolRefusal(current.error_code ?? "tool.version_probe_failed");
    const now = current.facts.versions?.[0] ?? null, was = original.versions?.[0] ?? null;
    // Resume at reconcile_query keeps the original before observation and candidate.
    const completed = safeVersion(now) && allows({ ...input, materialize: null, intent_only: false }, now) && (input.kind === "tool.install" ? was === null : now !== was);
    return { ready: true, mutate: chosen.allowed && !completed, facts: current.facts, outcome: !chosen.allowed ? "blocked_by_constraint" : completed ? "unchanged" : "applied" };
  } catch (error) { return refusal(error); }
}
export function writeResult(input: Input, steps: Record<string, z.infer<typeof Step>>) {
  const output = (name: string) => steps[name + "-0"]?.outputs;
  const success = (name: string) => steps[name + "-0"]?.status === "succeeded";
  const initial = output("inspect"), first = output("before"), choice = output("candidate"), action = output("decision");
  const facts = Facts.safeParse(first?.facts);
  let installed = facts.success ? facts.data.versions?.[0] ?? null : input.installed_version;
  let code: unknown = null, outcome = "unchanged";
  if (!success("inspect") || initial?.ready !== true) code = "tool.version_probe_failed";
  else if (initial.setup_required && (!success("setup_manager") || steps["setup_apt-0"]?.status === "failed" || steps["setup_packages-0"]?.status === "failed" || steps["setup_refresh-0"]?.status === "failed")) code = "tool.manager_provision_failed";
  else if (!success("before") || first?.ready !== true) code = first?.error_code ?? "tool.version_probe_failed";
  else if (input.repository && (!success("repository") || repositoryError(output("repository")))) code = success("repository") ? repositoryError(output("repository")) : "tool.repository_failed";
  else if (first.package_required) {
    if (!success("candidate") || choice?.ready !== true) code = choice?.error_code ?? "tool.candidate_version_probe_failed";
    else if (!success("decision") || action?.ready !== true) code = action?.error_code ?? "tool.version_probe_failed";
    else if (action.mutate && !success("mutate")) code = input.kind + "_failed";
    else if (!success("after") || output("after")?.ready !== true) code = "tool.version_probe_failed";
    else {
      const actual = Facts.parse(output("after")!.facts).versions?.[0];
      if (!safeVersion(actual)) code = "tool.version_probe_failed";
      else { try { verifyInstalled({ ...input, materialize: null, intent_only: false }, actual); installed = actual; outcome = action.outcome === "blocked_by_constraint" ? "blocked_by_constraint" : facts.success && facts.data.versions?.[0] === actual ? "unchanged" : "applied"; } catch (error) { code = error instanceof ToolRefusal ? error.code : "tool.version_probe_failed"; } }
    }
  }
  return { ok: code === null, stdout: JSON.stringify({ manager_version: facts.success ? facts.data.manager_version ?? null : null, installed_version: installed, ...(code ? { error_code: code } : { outcome }) }) };
}
export const behavior = {
  type: "@nckrtl/tool-write-command", version: "2026.11.01.2",
  resources: { assets: { schema: z.object({ script_body: z.string(), repository_body: z.string(), repository_json: z.string() }), lifetime: "1d", garbageCollection: 10 }, observation: { schema: Public, lifetime: "1d", garbageCollection: 10 }, result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 } },
  methods: {
    assets: { description: "Bind public input to one manager query or action", arguments: z.object({ action: z.enum(["query", "candidate", "mutate"]), input: WriteInput, before: Facts.optional(), repository_observation: Observation.nullable().optional(), candidate: Public.optional() }),
      execute: async (args: { action: "query" | "candidate" | "mutate"; input: Input; before?: z.infer<typeof Facts>; candidate?: Record<string, unknown>; repository_observation?: unknown }, ctx: Context) => {
        if (args.action === "candidate" && args.input.repository) {
          const code = repositoryError(args.repository_observation);
          if (code) throw new ToolRefusal(code);
        }
        const { setup: _setup, ...publicInput } = args.input;
        const input = { ...publicInput, manager_only: args.input.kind === "tool.manager_converge", before: args.before, candidate: args.candidate };
        let script_body;
        if (args.action === "query") script_body = await queryScript(input, ctx);
        else {
          const manager = input.manager.startsWith("brew") ? "brew" : input.manager;
          const source = await Deno.readTextFile(ctx.extensionFile((args.action === "candidate" ? "candidates/" : "writes/") + manager + ".py"));
          script_body = (await Deno.readTextFile(ctx.extensionFile("queries/support.py")) + "\n" + source.replace(/^from support import [^\n]*\n/gm, "")).replace("QUERY_INPUT = None", "QUERY_INPUT = " + JSON.stringify(JSON.stringify(input)));
        }
        const result = { script_body, repository_body: await Deno.readTextFile(ctx.extensionFile("apt_repository.py")), repository_json: JSON.stringify(input.repository) }; await ctx.writeResource("assets", "assets", result); return result;
      } },
    inspect: { description: "Select missing Linux manager setup from its native query", arguments: z.object({ input: WriteInput, observation: Observation }), execute: async (args: { input: Input; observation: unknown }, ctx: Context) => { const value = inspect(args.input, args.observation); await ctx.writeResource("observation", "inspect", value); return value; } },
    before: { description: "Check the actual package before any mutation", arguments: z.object({ input: WriteInput, observation: Observation, phase: z.enum(["before", "after"]).default("before") }), execute: async (args: { input: Input; observation: unknown; phase?: "before" | "after" }, ctx: Context) => { const value = before(args.input, args.observation, args.phase === "after"); await ctx.writeResource("observation", "before", value); return value; } },
    candidate: { description: "Check the manager's normal candidate without selecting a version", arguments: z.object({ input: WriteInput, observation: Observation }), execute: async (args: { input: Input; observation: unknown }, ctx: Context) => { const value = candidate(args.input, args.observation); await ctx.writeResource("observation", "candidate", value); return value; } },
    decision: { description: "Reconcile before native mutation or an uncertain reply", arguments: z.object({ input: WriteInput, before: Facts, candidate: Public, observation: Observation }), execute: async (args: { input: Input; before: unknown; candidate: unknown; observation: unknown }, ctx: Context) => { const value = decision(args.input, args.before, args.candidate, args.observation); await ctx.writeResource("observation", "decision", value); return value; } },
    result: { description: "Publish only typed manager and installed package results", arguments: z.object({ input: WriteInput, steps: z.record(z.string(), Step) }), execute: async (args: { input: Input; steps: Record<string, z.infer<typeof Step>> }, ctx: Context) => { const value = writeResult(args.input, args.steps); await ctx.writeResource("result", "result", value); return value; } },
  },
};
