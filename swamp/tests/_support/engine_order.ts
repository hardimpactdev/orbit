/**
 * The order in which a workflow job's steps run, under the two level rules Swamp has had. Both run a job's levels one after another
 * (TopologicalSortService); they differ inside a level:
 *  - `level`: Swamp up to 20261103.000636.0-sha.db3f6373. The smallest step `concurrency` of a level throttles the whole level. With
 *    a limit of 1 every step and every forEach iteration of that level runs one at a time, steps in name order (the sort's
 *    localeCompare).
 *  - `step`: Swamp from 20261114.210101.0-sha.537e03d1 (swamp-club#4357). A forEach step's `concurrency` gates only its own
 *    iterations; other steps share the job's limit; `concurrency` on a step without forEach has no effect.
 * A workflow whose order is the same under both rules runs the same on both engines. `order_test` and the native
 * `workflow_engine_semantics_native_test` hold Orbit's workflows to that.
 */
import { TopologicalSortService } from "./swamp_workflow.ts";

export type OrderStep = { name: string; dependsOn?: { step: string }[]; forEach?: unknown; concurrency?: number; guard?: string };
/** A job's `concurrency`, or else the workflow's; set it before calling when the workflow sets one. */
export type OrderJob = { name: string; steps: OrderStep[]; concurrency?: number };
export type Order = { before: Set<string>; serialIterations: Set<string>; levels: string[][]; uncovered: string[] };

const VARIANTS = ["production", "development", "container"];
const CONTAINER_DESTROY = '"container_destroy" in inputs.intent && inputs.intent.container_destroy != null';
/**
 * The paths a step can run on, in each dimension where Orbit's guards split a run into paths that never meet:
 * - the deploy variant: a guard that starts with `inputs.intent.deployment.variant != "X"` skips every variant but X;
 * - the destroy path: a guard that starts with `(<container_destroy set>) ||` skips the container path, one that starts with
 *   `!(<container_destroy set>)` skips the host path.
 */
const paths = (step: OrderStep): { variant: string[]; destroy: string[] } => {
    const guard = (step.guard ?? "").replace(/\s+/g, " ");
    const variant = /^\$\{\{\s*\(?\s*inputs\.intent\.deployment\.variant != "(\w+)"/.exec(guard);
    const destroy = guard.startsWith(`\${{ !(${CONTAINER_DESTROY})`) ? ["container"]
        : guard.startsWith(`\${{ (${CONTAINER_DESTROY}) ||`) ? ["host"] : ["container", "host"];
    return { variant: variant ? [variant[1]] : VARIANTS, destroy };
};
/** Whether two steps can run in one run: steps of different deploy variants, or of different destroy paths, never do. */
export const coRun = (a: OrderStep, b: OrderStep) => {
    const pa = paths(a), pb = paths(b);
    return pa.variant.some((v) => pb.variant.includes(v)) && pa.destroy.some((d) => pb.destroy.includes(d));
};

/** `a -> b`: a has ended before b starts. Only pairs that can run in one run are kept. */
export function order(job: OrderJob, rule: "level" | "step"): Order {
    const by = new Map(job.steps.map((step) => [step.name, step]));
    const before = new Set<string>();
    const serialIterations = new Set<string>();
    const uncovered: string[] = [];
    const levels = new TopologicalSortService().sort(job.steps.map((step) => ({ name: step.name, weight: 0,
        dependencies: (step.dependsOn ?? []).map((d) => d.step) }))).levels as string[][];
    const jobLimit = typeof job.concurrency === "number" && job.concurrency > 0 ? job.concurrency : Infinity;
    const add = (a: string, b: string) => {
        if (coRun(by.get(a)!, by.get(b)!)) before.add(`${a} -> ${b}`);
    };
    levels.forEach((level, index) => {
        for (const later of levels.slice(index + 1)) for (const a of level) for (const b of later) add(a, b);
        const limits = level.map((name) => by.get(name)!.concurrency).filter((c): c is number => typeof c === "number");
        // db3f6373 takes the level's smallest step limit when one is set, else the job's (`levelStepConc ?? job.concurrency`).
        const limit = limits.length > 0 ? Math.min(...limits) : jobLimit;
        // A limit above 1 over more than one step throttles differently under the two rules in a way this model does not follow.
        if (limit > 1 && limit < Infinity && level.length > 1) uncovered.push(`${job.name}: ${level.join(" ; ")}`);
        if (rule === "level") {
            if (limit === 1) {
                const sorted = [...level].sort((a, b) => a.localeCompare(b));
                sorted.forEach((a, k) => sorted.slice(k + 1).forEach((b) => add(a, b)));
                for (const name of level) if (by.get(name)!.forEach) serialIterations.add(name);
            }
        } else {
            for (const name of level) {
                const step = by.get(name)!;
                if (step.forEach && (step.concurrency === 1 || (step.concurrency === undefined && jobLimit === 1))) serialIterations.add(name);
            }
            if (jobLimit === 1) {
                const shared = level.filter((name) => !(by.get(name)!.forEach && typeof by.get(name)!.concurrency === "number"));
                if (shared.length > 1) uncovered.push(`${job.name}: job limit 1 over ${shared.join(" ; ")}`);
            }
            if (jobLimit > 1 && jobLimit < Infinity && level.length > 1) {
                uncovered.push(`${job.name}: job limit ${jobLimit} over ${level.join(" ; ")}`);
            }
        }
    });
    return { before, serialIterations, levels, uncovered };
}

/** Whether a step's forEach list is a literal list in its expression, so it is never empty at run time. */
const constantList = (step: OrderStep) => /^\$\{\{\s*\[\s*[^\]\s][^\]]*\]\s*\}\}$/.test(String((step.forEach as { in?: unknown }).in ?? "").trim());

/**
 * The run-time shapes of a job: both engines drop a forEach whose list is empty from the graph before they sort it, so its dependents
 * lose that edge and may move to another level. Lists with the same expression are empty together. Every subset of the list groups is
 * a shape, every list full included (a job has at most 14 groups, so at most 16,384 shapes).
 */
export function scenarios(job: OrderJob): { empty: string[]; job: OrderJob }[] {
    const groups = new Map<string, string[]>();
    for (const step of job.steps) {
        if (!step.forEach || constantList(step)) continue;
        const key = String((step.forEach as { in?: unknown }).in);
        groups.set(key, [...(groups.get(key) ?? []), step.name]);
    }
    const lists = [...groups.values()];
    if (lists.length > 16) throw new Error(`${job.name}: ${lists.length} list groups; the shapes would be too many to check`);
    const shapes: { empty: string[]; job: OrderJob }[] = [];
    for (let mask = 0; mask < 1 << lists.length; mask++) {
        const empty = lists.filter((_, i) => mask & (1 << i)).flat();
        shapes.push({ empty, job: { ...job, steps: job.steps.filter((step) => !empty.includes(step.name)) } });
    }
    return shapes;
}

/** The pairs and iteration sets where the two rules differ for one job, in every run-time shape. */
export function engineDifferences(job: OrderJob): string[] {
    return scenarios(job).flatMap(({ empty, job: shape }) =>
        shapeDifferences(shape).map((text) => empty.length === 0 ? text : `[empty: ${empty.join(", ")}] ${text}`));
}

function shapeDifferences(job: OrderJob): string[] {
    const level = order(job, "level"), step = order(job, "step");
    return [
        ...[...level.before].filter((pair) => !step.before.has(pair)).map((pair) => `only db3f6373 orders ${pair}`),
        ...[...step.before].filter((pair) => !level.before.has(pair)).map((pair) => `only 537e03d1 orders ${pair}`),
        ...[...level.serialIterations].filter((name) => !step.serialIterations.has(name)).map((name) => `only db3f6373 runs ${name} one at a time`),
        ...[...step.serialIterations].filter((name) => !level.serialIterations.has(name)).map((name) => `only 537e03d1 runs ${name} one at a time`),
        ...[...new Set([...level.uncovered, ...step.uncovered])].map((text) => `not modelled: ${text}`),
    ];
}
