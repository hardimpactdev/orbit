import { z } from "npm:zod@4.4.3";
import { capturedArgv, readPublicTail } from "../../_lib/public_output.ts";
import { sha256 } from "../../_lib/canonical_json.ts";
import { ScriptInput } from "./input.ts";

/*
 * Typed data for tasks.script. The task VM work is one
 * official `@swamp/ssh exec` argv per step; this module only builds those
 * argv lists on Node 209 and shapes their public outputs. It runs nothing.
 * The program writes its output to a file on the VM; official SSH copy
 * downloads it to a private worker directory, and `result` reads, redacts
 * and deletes it, so raw program output never enters native history.
 */

const ObjectValue = z.record(z.string(), z.unknown());
const Kind = z.literal("tasks.script");
const OUTPUT_BYTES = 16384;
/** The file on the VM, and its downloaded copy, that holds the program output. */
export const OUTPUT_FILE = {
    "tasks.script": "output",
};

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
type Snapshot = { head: string; tree: string };
type Steps = Record<string, unknown>;

const output = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

/** The admitted intent for one kind; the workflow input schema already bound the kind. */
export function parseIntent(kind: z.infer<typeof Kind>, intent: unknown) {
    return { kind, input: ScriptInput.parse(intent) };
}

/**
 * The argv list and stdin that the execute step runs in the workspace. `root`
 * is the run's private output directory on the VM.
 */
export async function prepare(args: {
    operation_id: number;
    node_id: number;
    kind: z.infer<typeof Kind>;
    intent: unknown;
    root: string;
}) {
    const { kind, input } = parseIntent(args.kind, args.intent);
    if (input.node_id !== args.node_id) throw new Error("Wrong task VM Node");
    const entrypoint = input.script;
    const environment = [
        "COMPOSER_NO_INTERACTION=1",
        "ORBIT_CHECK_PROCESSES=1",
        ...(input.ssr_port === null ? [] : [
            "INERTIA_SSR_PORT=" + input.ssr_port,
            "INERTIA_SSR_URL=http://127.0.0.1:" + input.ssr_port,
        ]),
    ];
    const execution_id = (await sha256("operation-" + args.operation_id))
        .slice(0, 32);
    return {
        entrypoint,
        execution_id,
        // Keep stdout and stderr in the same private capture.
        argv: capturedArgv(args.root, OUTPUT_FILE[kind], [
            "env",
            "-C",
            input.checkout_path,
            ...environment,
            "./" + entrypoint,
            ...input.args,
        ], "merge"),
        stdin: "",
    };
}

const Run = z.object({
    exitCode: z.number().int().nullable().optional(),
    stdout: z.string().optional(),
    stderr: z.string().optional(),
    startedAt: z.string().optional(),
    finishedAt: z.string().optional(),
    durationMs: z.number().optional(),
}).passthrough();

/** Outputs of one official SSH step, or an empty record when it was skipped. */
function run(steps: Steps, name: string) {
    const step = ObjectValue.safeParse(steps[name]);
    if (!step.success) return Run.parse({});
    const outputs = ObjectValue.safeParse(step.data.outputs);
    return Run.parse(outputs.success ? outputs.data : {});
}

/**
 * `git write-tree` and `git rev-parse HEAD` of one snapshot, from its
 * `snapshot-<phase>-tree` and `snapshot-<phase>-head` steps.
 */
export function snapshot(steps: Steps, phase: string): Snapshot {
    const value = (name: string) => {
        const item = run(steps, `snapshot-${phase}-${name}`);
        const oid = (item.stdout ?? "").trim();
        if (
            item.exitCode !== 0 || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(oid)
        ) {
            throw new Error(`Snapshot ${phase} ${name} is unavailable`);
        }
        return oid;
    };
    return { head: value("head"), tree: value("tree") };
}

/** The tracked executable mode at HEAD, from `git ls-tree HEAD -- <path>`. */
function trackedExecutable(steps: Steps, entrypoint: string): boolean {
    const item = run(steps, "inspect");
    return item.exitCode === 0 &&
        /^100755 blob [a-f0-9]+\t(.+)\n?$/.exec(item.stdout ?? "")?.[1] ===
            entrypoint;
}

/** Refuse before execution: an untracked or non-executable entrypoint, . */
export function gate(args: {
    kind: z.infer<typeof Kind>;
    intent: unknown;
    entrypoint: string;
    steps: Steps;
}) {
    parseIntent(args.kind, args.intent);
    const before = snapshot(args.steps, "before");
    return {
        error_code: trackedExecutable(args.steps, args.entrypoint)
            ? ""
            : "tasks.script_unavailable",
        before,
    };
}

/**
 * tasks.script: a redacted 16 KiB tail of stdout and stderr, read from the
 * downloaded output file in `local`, and the snapshot change.
 */
export async function scriptResult(
    args: { gate: unknown; steps: Steps; local: string },
) {
    const checked = ObjectValue.parse(args.gate);
    if (checked.error_code) return { error_code: String(checked.error_code) };
    const before = checked.before as Snapshot;
    const after = snapshot(args.steps, "after");
    const execution = run(args.steps, "execute");
    const output = await readPublicTail(
        `${args.local}/${OUTPUT_FILE["tasks.script"]}`,
        OUTPUT_BYTES,
    );
    if (typeof execution.exitCode !== "number" || output === null) {
        return { error_code: "tasks.repository_execution_unknown" };
    }
    return {
        outcome: execution.exitCode === 0 ? "passed" : "failed",
        exit_code: execution.exitCode,
        output: output.output,
        output_truncated: output.truncated,
        changed: before.head !== after.head || before.tree !== after.tree,
        ...after,
    };
}

/** Public data for the tasks.script steps; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-repository-program",
    version: "2026.11.02.3",
    resources: {
        public: {
            description: "Public program argv and evidence",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        prepare: {
            description:
                "Build the program argv and stdin from the admitted intent",
            arguments: z.object({
                operation_id: z.number().int().positive(),
                node_id: z.number().int().positive(),
                kind: Kind,
                intent: ObjectValue,
                root: z.string().startsWith("/"),
            }),
            execute: async (
                args: Parameters<typeof prepare>[0],
                ctx: Context,
            ) => output(ctx, await prepare(args)),
        },
        gate: {
            description: "Refuse an unavailable entrypoint ",
            arguments: z.object({
                kind: Kind,
                intent: ObjectValue,
                entrypoint: z.string(),
                steps: ObjectValue,
            }),
            execute: (args: Parameters<typeof gate>[0], ctx: Context) =>
                output(ctx, gate(args)),
        },
        result: {
            description:
                "Read, redact and delete the downloaded program output and shape the public result",
            arguments: z.object({
                operation_id: z.number().int().positive(),
                kind: Kind,
                intent: ObjectValue,
                gate: ObjectValue,
                execution_id: z.string(),
                steps: ObjectValue,
                local: z.string().startsWith("/"),
            }),
            execute: async (
                args: {
                    operation_id: number;
                    kind: z.infer<typeof Kind>;
                    intent: unknown;
                    gate: unknown;
                    execution_id: string;
                    steps: Steps;
                    local: string;
                },
                ctx: Context,
            ) => {
                const result = await scriptResult(args);
                return output(ctx, {
                    result_json: JSON.stringify(result),
                    failed: "error_code" in result,
                });
            },
        },
    },
};
