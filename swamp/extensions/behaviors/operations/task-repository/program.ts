import { z } from "npm:zod@4.4.3";
import {
    capturedArgv,
    readCapture,
    readPublicTail,
    redactText,
} from "../../_lib/public_output.ts";
import { REPORT_BYTES, strictJson } from "../../_lib/repository_analysis.ts";
import { AnalysisInput, ScriptInput } from "./input.ts";

/*
 * Typed data for tasks.script and tasks.analyze. The task VM work is one
 * official `@swamp/ssh exec` argv per step; this module only builds those
 * argv lists on Node 209 and shapes their public outputs. It runs nothing.
 * The program writes its output to a file on the VM; official SSH copy
 * downloads it to a private worker directory, and `result` reads, redacts
 * and deletes it, so raw program output never enters native history.
 */

const ObjectValue = z.record(z.string(), z.unknown());
const Kind = z.enum(["tasks.script", "tasks.analyze"]);
const OUTPUT_BYTES = 16384;
/** The file on the VM, and its downloaded copy, that holds the program output. */
export const OUTPUT_FILE = {
    "tasks.script": "output",
    "tasks.analyze": "report",
};
const encoder = new TextEncoder();

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
    return kind === "tasks.script"
        ? { kind, input: ScriptInput.parse(intent) }
        : { kind, input: AnalysisInput.parse(intent) };
}

async function sha256Hex(value: string): Promise<string> {
    const digest = await crypto.subtle.digest("SHA-256", encoder.encode(value));
    return Array.from(
        new Uint8Array(digest),
        (byte) => byte.toString(16).padStart(2, "0"),
    ).join("");
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
    const entrypoint = kind === "tasks.script"
        ? (input as z.infer<typeof ScriptInput>).script
        : "bin/task-analyze";
    const environment = [
        "COMPOSER_NO_INTERACTION=1",
        "ORBIT_CHECK_PROCESSES=1",
        ...(input.ssr_port === null ? [] : [
            "INERTIA_SSR_PORT=" + input.ssr_port,
            "INERTIA_SSR_URL=http://127.0.0.1:" + input.ssr_port,
        ]),
    ];
    const execution_id = (await sha256Hex("operation-" + args.operation_id))
        .slice(0, 32);
    const analysis = kind === "tasks.analyze"
        ? input as z.infer<typeof AnalysisInput>
        : null;
    return {
        entrypoint,
        execution_id,
        // A script keeps stdout and stderr; the analyzer report is stdout only.
        argv: capturedArgv(args.root, OUTPUT_FILE[kind], [
            "env",
            "-C",
            input.checkout_path,
            ...environment,
            "./" + entrypoint,
            ...(kind === "tasks.script"
                ? (input as z.infer<typeof ScriptInput>).args
                : []),
        ], kind === "tasks.script" ? "merge" : "discard"),
        // The analyzer reads its admitted plan and candidate on stdin.
        stdin: analysis === null ? "" : JSON.stringify({
            plan: analysis.plan,
            plan_sha256: analysis.plan_sha256,
            base: analysis.base,
            head: analysis.head,
            tree: analysis.tree,
            execution_id,
        }),
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

/** Refuse before execution: an untracked or non-executable entrypoint, or a moved candidate. */
export function gate(args: {
    kind: z.infer<typeof Kind>;
    intent: unknown;
    entrypoint: string;
    steps: Steps;
}) {
    const { kind, input } = parseIntent(args.kind, args.intent);
    const before = snapshot(args.steps, "before");
    if (kind === "tasks.script") {
        return {
            error_code: trackedExecutable(args.steps, args.entrypoint)
                ? ""
                : "tasks.script_unavailable",
            entrypoint_sha256: "",
            before,
        };
    }
    const digest = run(args.steps, "entrypoint");
    const entrypoint_sha256 = /^([a-f0-9]{64}) /.exec(digest.stdout ?? "")?.[1];
    const analysis = input as z.infer<typeof AnalysisInput>;
    const error_code = !trackedExecutable(args.steps, args.entrypoint) ||
            digest.exitCode !== 0 || entrypoint_sha256 === undefined
        ? "tasks.analysis_unavailable"
        : before.head !== analysis.head || before.tree !== analysis.tree
        ? "tasks.analysis_stale"
        : "";
    return { error_code, entrypoint_sha256: entrypoint_sha256 ?? "", before };
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

function fields(value: unknown, names: string[]): Record<string, unknown> {
    const item = ObjectValue.parse(value);
    const keys = Object.keys(item);
    if (keys.length !== names.length || !names.every((k) => keys.includes(k))) {
        throw new Error("Invalid object fields");
    }
    return item;
}

const isInteger = (value: unknown): value is number =>
    typeof value === "number" && Number.isSafeInteger(value);

const tooLarge = () => ({
    error_code: "tasks.analysis_report_too_large",
    schema: 1,
    error: { code: "tasks.analysis_report_too_large", required_bytes: null },
});

/** Validate the bounded analyzer stdout; a closed failure object returns its code. */
export function report(
    raw: string,
    identity: Record<string, string>,
    code: number,
): Record<string, unknown> {
    if (encoder.encode(raw).length > REPORT_BYTES) return tooLarge();
    const value = strictJson(raw, REPORT_BYTES);
    if (
        code !== 0 && value !== null && typeof value === "object" &&
        !Array.isArray(value) && "error" in value
    ) {
        const failure = fields(value, ["schema", "error"]);
        const error = fields(failure.error, ["code", "required_bytes"]);
        if (failure.schema !== 1) throw new Error("Invalid version");
        if (
            error.code === "tasks.analysis_unavailable" &&
            error.required_bytes === null
        ) return { ...failure, error_code: error.code };
        if (
            error.code === "tasks.analysis_report_too_large" &&
            isInteger(error.required_bytes) &&
            error.required_bytes > REPORT_BYTES
        ) return { ...failure, error_code: error.code };
        throw new Error("Invalid failure");
    }
    const envelope = fields(value, [
        "schema",
        "execution_id",
        "plan_sha256",
        "base",
        "head",
        "tree",
        "status",
        "diagnostics",
        "report",
    ]);
    if (
        envelope.schema !== 1 ||
        !["ready", "action_required", "blocked"].includes(
            String(envelope.status),
        )
    ) throw new Error("Invalid report version or status");
    for (const key of ["execution_id", "plan_sha256", "base", "head", "tree"]) {
        if (envelope[key] !== identity[key]) {
            throw new Error("Different report identity");
        }
    }
    if (!Array.isArray(envelope.diagnostics)) {
        throw new Error("Invalid diagnostics");
    }
    for (const item of envelope.diagnostics) {
        const diagnostic = fields(item, ["code", "message"]);
        if (
            typeof diagnostic.code !== "string" || !diagnostic.code.trim() ||
            typeof diagnostic.message !== "string"
        ) throw new Error("Invalid diagnostic");
    }
    const data = fields(envelope.report, ["schema", "data"]);
    if (
        typeof data.schema !== "string" || !data.schema.trim() ||
        !ObjectValue.safeParse(data.data).success || Array.isArray(data.data)
    ) throw new Error("Invalid repository report");
    // Structured evidence is refused, never silently changed by redaction.
    // It keeps its SHA-256 identities and digests, so the free-text 64-hex
    // credential rule does not apply.
    const serialized = JSON.stringify(envelope);
    const redact = (text: string) => redactText(text, { credentialHex: false });
    if (redact(raw) !== raw || redact(serialized) !== serialized) {
        throw new Error("Private report data");
    }
    return envelope;
}

/**
 * tasks.analyze: the complete artifact, read from the downloaded report file
 * in `local`, and native provenance, or a refusal code.
 */
export async function analysisResult(args: {
    operation_id: number;
    intent: unknown;
    gate: unknown;
    execution_id: string;
    steps: Steps;
    local: string;
}) {
    const input = AnalysisInput.parse(args.intent);
    const checked = ObjectValue.parse(args.gate);
    if (checked.error_code) return { error_code: String(checked.error_code) };
    const before = checked.before as Snapshot;
    const after = snapshot(args.steps, "after");
    const execution = run(args.steps, "execute");
    // One byte over the bound is enough to refuse an oversized report.
    const read = await readCapture(
        `${args.local}/${OUTPUT_FILE["tasks.analyze"]}`,
        REPORT_BYTES + 1,
    );
    if (typeof execution.exitCode !== "number" || read === null) {
        return { error_code: "tasks.repository_execution_unknown" };
    }
    if (read.size > REPORT_BYTES) return tooLarge();
    const raw = new TextDecoder().decode(read.bytes);
    let parsed: Record<string, unknown>;
    try {
        parsed = report(raw, {
            execution_id: args.execution_id,
            plan_sha256: input.plan_sha256,
            base: input.base,
            head: input.head,
            tree: input.tree,
        }, execution.exitCode);
    } catch {
        return { error_code: "tasks.analysis_report_invalid" };
    }
    if (typeof parsed.error_code === "string") return parsed;
    const changed = before.head !== after.head || before.tree !== after.tree;
    if (!changed && execution.exitCode !== 0) {
        return { error_code: "tasks.analysis_failed" };
    }
    return {
        outcome: changed ? "changed" : parsed.status,
        artifact: {
            sha256: await sha256Hex(raw),
            bytes: encoder.encode(raw).length,
            content: raw,
        },
        provenance: {
            operation_id: args.operation_id,
            group: input.group_id,
            subtask: input.subtask,
            repository: input.repository,
            branch: input.branch,
            instance_id: input.instance_id,
            node_id: input.node_id,
            plan_id: input.plan.id,
            plan_revision: input.plan.revision,
            plan_sha256: input.plan_sha256,
            base: input.base,
            head: before.head,
            tree: before.tree,
            before,
            after,
            execution_id: args.execution_id,
            entrypoint_sha256: String(checked.entrypoint_sha256),
            // Measured on Node 209 around the official SSH exec.
            started_at: execution.startedAt,
            finished_at: execution.finishedAt,
            duration_ms: Math.round(execution.durationMs ?? 0),
            exit_code: execution.exitCode,
            mechanical_llm_calls: 0,
        },
    };
}

/** Public data for the tasks.script and tasks.analyze steps; no execution or HTTP. */
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
            description:
                "Refuse an unavailable entrypoint or a moved candidate",
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
                const result = args.kind === "tasks.script"
                    ? await scriptResult(args)
                    : await analysisResult(args);
                return output(ctx, {
                    result_json: JSON.stringify(result),
                    failed: "error_code" in result,
                });
            },
        },
    },
};
