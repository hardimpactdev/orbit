/**
 * Runs a real workflow file on the Swamp binary in `ORBIT_SWAMP_PROOF_BINARY`, with every step's task replaced by one recording stub:
 * the engine evaluates the real levels, dependencies, conditions, guards, forEach lists and step-name expressions. The stub records
 * its step and the start and end of each call, returns the outputs given for that step, and fails when asked to. Child workflow
 * steps become stubs too, so a run needs no Node, vault or child workflow.
 */
import { assert } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeAuth } from "./native_auth.ts";

type RecordValue = Record<string, unknown>;
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);

const stub = `import { z } from "npm:zod@4.4.3";
export const behavior = { type: "@orbit-test/step-stub", version: "2026.11.15.1",
    resources: { out: { schema: z.object({}).passthrough(), lifetime: "infinite", garbageCollection: 10 } },
    methods: { run: { description: "Record a step", arguments: z.object({ step: z.string(), outputs: z.string(), fail: z.boolean(), hold: z.number(), calls: z.string() }),
        execute: async (args: { step: string; outputs: string; fail: boolean; hold: number; calls: string }, ctx: { writeResource(s: string, n: string, d: unknown): Promise<unknown> }) => {
            const start = Date.now() * 1000 + Math.floor(performance.now() * 1000) % 1000;
            if (args.hold > 0) await new Promise((resolve) => setTimeout(resolve, args.hold));
            await Deno.writeTextFile(args.calls, JSON.stringify({ step: args.step, start, end: Date.now() * 1000 + Math.floor(performance.now() * 1000) % 1000, failed: args.fail }) + "\\n", { append: true });
            if (args.fail) throw new Error("stub failure of " + args.step);
            return { dataHandles: [await ctx.writeResource("out", "out", JSON.parse(args.outputs))] };
        } } } };
`;

export type Call = { step: string; start: number; end: number; failed: boolean };
export type RenderedRun = { status: string; steps: Record<string, string>; calls: Call[]; stderr: string };

/**
 * `outputs(step)` gives the outputs of a step (the same for each forEach iteration); `fail` names the steps whose stub fails;
 * `hold` is how long each stub runs, so overlaps show.
 */
export async function renderedRun(file: string, inputs: RecordValue, options: {
    outputs?: (step: string) => RecordValue; fail?: string[]; hold?: number;
} = {}): Promise<RenderedRun> {
    const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY")!;
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-rendered-" });
    const auth = await nativeAuth(folder);
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary, { args: [...args, "--json"], cwd: folder, ...auth }).output();
        return { ok: done.success, stdout: text(done.stdout), stderr: text(done.stderr) };
    };
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    Deno.mkdirSync(`${folder}/extensions/models/step-stub`, { recursive: true });
    Deno.writeTextFileSync(`${folder}/extensions/models/step-stub/mod.ts`, stub);
    const workflow = parse(Deno.readTextFileSync(file.startsWith("/") ? file : new URL(`../../workflows/${file}`, import.meta.url))) as RecordValue;
    const created = JSON.parse((await call("workflow", "create", String(workflow.name))).stdout);
    workflow.id = created.id;
    delete workflow.labels;
    delete workflow.vaults;
    delete workflow.trigger;
    const calls = `${folder}/calls`;
    let index = 0;
    for (const job of workflow.jobs as RecordValue[]) {
        delete job.labels;
        for (const step of job.steps as RecordValue[]) {
            delete step.labels;
            delete step.target;
            const name = String(step.name);
            // A name of its own for each step and each forEach item: Swamp holds a lock per behavior name, and one shared name would run
            // every stub one after another and hide the engine's own order.
            index++;
            const behaviorName = step.forEach ? `\${{ "stub-${index}-" + string(self._index) }}` : `stub-${index}`;
            step.task = { type: "behavior", behaviorType: "@orbit-test/step-stub", behaviorName,
                methodName: "run", inputs: { step: name, outputs: JSON.stringify(options.outputs?.(name) ?? {}), fail: options.fail?.includes(name) ?? false,
                    hold: options.hold ?? 0, calls } };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", String(workflow.name));
    assert(valid.ok, valid.stderr + valid.stdout);
    const child = new Deno.Command(binary, { args: ["workflow", "run", String(workflow.name), "--stdin", "--json"], cwd: folder, ...auth,
        stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(inputs)));
    await writer.close();
    const done = await child.output();
    let parsed: RecordValue = {};
    try {
        parsed = JSON.parse(text(done.stdout));
    } catch {
        parsed = {};
    }
    const steps: Record<string, string> = {};
    for (const job of (parsed.jobs ?? []) as RecordValue[]) {
        for (const step of (job.steps ?? []) as RecordValue[]) steps[`${job.name}/${step.name}`] = String(step.status);
    }
    let recorded: Call[] = [];
    try {
        recorded = Deno.readTextFileSync(calls).trim().split("\n").filter(Boolean).map((line) => JSON.parse(line));
    } catch {
        recorded = [];
    }
    return { status: String(parsed.status), steps, calls: recorded.sort((a, b) => a.start - b.start), stderr: text(done.stderr) };
}

/** Whether two calls overlap in time. */
export const overlap = (a: Call, b: Call) => a.start < b.end && b.start < a.end;
