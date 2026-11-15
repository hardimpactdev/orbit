import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { swampExpression } from "./swamp_expression.ts";
// The pinned Swamp's own level sort (Swamp 20261103.000636.0-sha.db3f6373), so the scheduler below cannot drift from the engine.
import { TopologicalSortService } from "https://raw.githubusercontent.com/swamp-club/swamp/db3f6373207cc41235d2334f19a7901b2b84620f/src/domain/workflows/topological_sort_service.ts";

/** The pinned Swamp's CEL compiler and environment (createExtensionCelEnvironment at db3f6373), with `vault.get` as a stand-in. */
export const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
  .registerFunction("vaultget(dyn, dyn): string", (vault: unknown, key: unknown) => `${vault}/${key}`);

export { TopologicalSortService };

type Node = { op: string; args: unknown };

const isNode = (value: unknown): value is Node =>
  value !== null && typeof value === "object" && !Array.isArray(value) && typeof (value as Node).op === "string";

/** A scalar literal: a `value` node (string, int, uint, double, bytes, bool or null), or unary minus over one. `uint(1)` is a call. */
const isScalarLiteral = (value: unknown): boolean =>
  isNode(value) && (value.op === "value" || (value.op === "-_" && isNode(value.args) && value.args.op === "value"));

/** e459's walk, verbatim: any `in`, or `==` / `!=` whose arguments hold no scalar literal, anywhere in the expression. */
function visit(value: unknown): boolean {
  if (Array.isArray(value)) return value.some(visit);
  if (!isNode(value)) return false;
  if (value.op === "value") return false;
  if (value.op === "in") return true;
  if ((value.op === "==" || value.op === "!=") && Array.isArray(value.args) && !value.args.some(isScalarLiteral)) return true;
  return visit(value.args);
}

/**
 * The pinned Swamp's secret rule (e459 src/infrastructure/cel/container_comparison.ts, ported over the same cel-js parse): it refuses
 * as a whole an expression that reads a secret (`vault.get`) and holds a container comparison: any `in`, or `==` / `!=` with no
 * scalar literal among its arguments, such as `inputs.a == inputs.b` (`secret_operation_unsupported`). `has()` is not one. Serve
 * refuses such an expression when the run starts. Returns the refusal for an expression written with `vault.get(`, or null.
 */
export function secretRefusal(expression: string): string | null {
  if (!expression.includes("vault.get(")) return null;
  const parsed = environment.parse(expression.replaceAll("vault.get(", "vaultget(")) as unknown as { ast: unknown };
  return visit(parsed.ast) ? "secret_operation_unsupported: a vault.get expression with a container comparison" : null;
}

type Workflow = { jobs: { steps: { name: string; task?: unknown }[] }[] };

/**
 * Swamp evaluates a skipped step's inputs too (docs/reference/swamp-command-steps.md), so an expression that reads a vault key or an
 * intent section that a run lacks fails that whole run with `No such key`. This evaluates every step input expression that reads
 * `inputs.execution_secrets` or `inputs.intent.read`, as each kind of run hands it over, and returns the ones that fail.
 * Expressions over `steps.` or a `forEach` item (`self.`) are left out: Swamp evaluates those per item or after the steps they name.
 */
export function failures(workflow: string, inputs: Record<string, unknown>): string[] {
  const parsed = parse(Deno.readTextFileSync(new URL(`../../workflows/${workflow}`, import.meta.url))) as Workflow;
  const failed: string[] = [];
  const evaluate = (step: string, value: unknown): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
        const expression = match[1].trim();
        if (!/execution_secrets|intent\.read/.test(expression) || /\b(self|steps)\./.test(expression)) continue;
        const refused = secretRefusal(expression);
        if (refused !== null) {
          failed.push(`${step}: ${refused}`);
          continue;
        }
        try {
          environment.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs, run: { id: "run-1" } });
        } catch (error) {
          failed.push(`${step}: ${(error as Error).message.split("\n")[0]}`);
        }
      }
    } else if (value !== null && typeof value === "object") {
      for (const item of Object.values(value)) evaluate(step, item);
    }
  };
  for (const job of parsed.jobs) for (const step of job.steps) evaluate(step.name, step.task);
  return failed;
}

export type Condition = { type: string; conditions?: Condition[]; condition?: Condition };
export type GraphStep = {
  name: string;
  guard?: string;
  dependsOn?: { step: string; condition: Condition }[];
  forEach?: { item: string; in: string };
  task: { type: string; behaviorName?: string; inputs?: Record<string, string>; expr?: string };
};
export type GraphJob = { name: string; dependsOn?: { job: string; condition: Condition }[]; steps: GraphStep[] };
export type Ran = { status: string; outputs?: Record<string, unknown>; start: number; end: number };
export type Outcome = { status?: string; duration?: number; outputs?: Record<string, unknown> };

/** The level layout of a workflow file as the pinned engine sorts it: job levels, and the step levels of each job. */
export function levelsOf(workflow: string): { jobs: string[][]; steps: Record<string, string[][]> } {
  const flow = parse(Deno.readTextFileSync(new URL(`../../workflows/${workflow}`, import.meta.url))) as { jobs: GraphJob[] };
  const sort = new TopologicalSortService();
  const jobs = sort.sort(flow.jobs.map((job) => ({ name: job.name, weight: 0, dependencies: (job.dependsOn ?? []).map((d) => d.job) }))).levels;
  const steps = Object.fromEntries(flow.jobs.map((job) => [job.name,
    sort.sort(job.steps.map((step) => ({ name: step.name, weight: 0, dependencies: (step.dependsOn ?? []).map((d) => d.step) }))).levels]));
  return { jobs, steps };
}

/**
 * Runs a workflow file on a clock, with the pinned Swamp's own scheduling rule (execution_service.ts at db3f6373, lines 3866 and 5180):
 * jobs and, inside each job, steps run in the depth levels of its TopologicalSortService. A level starts only when the whole level before
 * it has ended; in a level every step starts at once unless another step holds its behavior lock. A step whose dependency condition fails
 * or whose guard is true is skipped at once, and a job whose dependency condition fails is skipped. Guards, behavior names and `assert`
 * steps are evaluated with the pinned CEL; `finish(step, cel)` gives the outcome of each step named in `results` (`finish` by default).
 */
export function runWorkflow(
  workflow: string,
  kind: string,
  intent: Record<string, unknown>,
  outcomes: Record<string, Outcome>,
  finish: (step: GraphStep, cel: (expression: string) => unknown) => Outcome,
  results: string[] = ["finish"],
): Record<string, Ran> {
  const flow = parse(Deno.readTextFileSync(new URL(`../../workflows/${workflow}`, import.meta.url))) as { jobs: GraphJob[] };
  const sort = new TopologicalSortService();
  const levels = <T extends { name: string }>(nodes: T[], deps: (node: T) => string[]): T[][] =>
    sort.sort(nodes.map((node) => ({ name: node.name, weight: 0, dependencies: deps(node) }))).levels
      .map((level) => level.map((name) => nodes.find((node) => node.name === name)!));
  const jobLevels = levels(flow.jobs, (job) => (job.dependsOn ?? []).map((d) => d.job));
  const ran: Record<string, Ran> = {};
  const locks = new Map<string, string>();
  const jobStatus: Record<string, string> = {};
  const holds = (condition: Condition, status: string): boolean =>
    condition.type === "always" || condition.type === status ||
    (condition.type === "completed" && (status === "succeeded" || status === "failed")) ||
    (condition.type === "or" && condition.conditions!.some((c) => holds(c, status))) ||
    (condition.type === "not" && !holds(condition.condition!, status));
  let now = 0;
  const context = () => ({
    // Swamp hands JSON integers to CEL as int, never double; `instance_id % 4` needs that.
    inputs: { intent: { ...intent, instance_id: BigInt(intent.instance_id as number) }, operation_id: 1n, node_id: 3n, kind },
    steps: Object.fromEntries(Object.entries(ran).filter(([, r]) => r.end <= now).map(([n, r]) => [n, { status: r.status, outputs: r.outputs ?? {} }])),
    run: { id: "run-1" },
  });
  const cel = (expression: string, self?: Record<string, unknown>) =>
    environment.evaluate(swampExpression(expression), self === undefined ? context() : { ...context(), self });
  const ended = (step: GraphStep) => step.name in ran && ran[step.name].end <= now;
  for (const jobLevel of jobLevels) {
    const jobs = jobLevel.filter((job) => {
      const run = (job.dependsOn ?? []).every((d) => holds(d.condition, jobStatus[d.job]));
      jobStatus[job.name] = run ? "running" : "skipped";
      return run;
    });
    const stepLevels = new Map(jobs.map((job) => [job.name, levels(job.steps, (step) => (step.dependsOn ?? []).map((d) => d.step))]));
    const at = new Map(jobs.map((job) => [job.name, 0]));
    for (let rounds = 0; rounds < 10000; rounds++) {
      let moved = false;
      for (const job of jobs) {
        const level = stepLevels.get(job.name)![at.get(job.name)!];
        if (level === undefined) continue;
        for (const step of level) {
          if (step.name in ran) continue;
          const deps = step.dependsOn ?? [];
          // A forEach step's guard that reads its item (`self`) is evaluated for each item, which then runs or is skipped.
          const itemGuard = step.forEach !== undefined && step.guard !== undefined && /\bself\b/.test(step.guard);
          if (!deps.every((d) => holds(d.condition, ran[d.step].status)) || (step.guard !== undefined && !itemGuard && cel(step.guard) === true)) {
            ran[step.name] = { status: "skipped", start: now, end: now };
            moved = true;
            continue;
          }
          if (step.forEach !== undefined) {
            // One run per item, named `<step>-<index>`; the step itself ends with its last item and fails when one item fails.
            const items = cel(step.forEach.in) as unknown[];
            const runs = items.map((value, index) => {
              const skipped = itemGuard && cel(step.guard!, { [step.forEach!.item]: value, _index: BigInt(index) }) === true;
              const outcome = skipped ? { status: "skipped", duration: 0 } : outcomes[`${step.name}-${index}`] ?? {};
              const item = { status: outcome.status ?? "succeeded", outputs: outcome.outputs ?? { stdout: "" }, start: now, end: now + (outcome.duration ?? 1) };
              ran[`${step.name}-${index}`] = item;
              return item;
            });
            // With no item, or every item skipped, the step reads as skipped.
            ran[step.name] = { status: runs.some((r) => r.status === "failed") ? "failed" : runs.every((r) => r.status === "skipped") ? "skipped" : "succeeded",
              start: now, end: Math.max(now, ...runs.map((r) => r.end)) };
            moved = true;
            continue;
          }
          const lock = step.task.type === "behavior" ? String(cel(step.task.behaviorName!)) : null;
          if (lock !== null && locks.has(lock)) continue;
          const outcome: Outcome = results.includes(step.name) ? finish(step, cel)
            : step.task.type === "assert" ? { status: cel("${{ " + step.task.expr + " }}") === true ? "succeeded" : "failed" }
            : outcomes[step.name] ?? {};
          ran[step.name] = { status: outcome.status ?? "succeeded", outputs: outcome.outputs ?? { stdout: "" }, start: now, end: now + (outcome.duration ?? 1) };
          if (lock !== null) locks.set(lock, step.name);
          moved = true;
        }
        if (level.every(ended)) {
          at.set(job.name, at.get(job.name)! + 1);
          moved = true;
        }
      }
      if (moved) continue;
      const busy = Object.values(ran).filter((r) => r.end > now).map((r) => r.end);
      if (busy.length === 0) break;
      now = Math.min(...busy);
      for (const [lock, holder] of [...locks]) if (ran[holder].end <= now) locks.delete(lock);
    }
    for (const job of jobs) {
      assertEquals(at.get(job.name), stepLevels.get(job.name)!.length, `job ${job.name} ends`);
      jobStatus[job.name] = job.steps.some((s) => ran[s.name]?.status === "failed") ? "failed" : "succeeded";
    }
  }
  return ran;
}
