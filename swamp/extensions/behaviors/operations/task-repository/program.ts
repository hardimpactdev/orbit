import { z } from "npm:zod@4.4.3";
import { REPORT_BYTES, strictJson } from "../../_lib/repository_analysis.ts";
import { AnalysisInput, ScriptInput } from "./input.ts";

/*
 * Typed data for tasks.script and tasks.analyze. The task VM work is one
 * official `@swamp/ssh exec` argv per step; this module only builds those
 * argv lists on Node 209 and shapes their public outputs. It runs nothing.
 */

const ObjectValue = z.record(z.string(), z.unknown());
const Kind = z.enum(["tasks.script", "tasks.analyze"]);
const OUTPUT_BYTES = 16384;
const OUTPUT_WINDOW = 102400;
const encoder = new TextEncoder();
const decoder = new TextDecoder();

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

/** The argv lists and stdin that the SSH steps run in the workspace. */
export async function prepare(args: {
    operation_id: number;
    node_id: number;
    kind: z.infer<typeof Kind>;
    intent: unknown;
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
        argv: [
            "env",
            "-C",
            input.checkout_path,
            ...environment,
            "./" + entrypoint,
            ...(kind === "tasks.script"
                ? (input as z.infer<typeof ScriptInput>).args
                : []),
        ],
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

/** The public redaction rule for repository program output. */
export function redactText(raw: string): string {
    // Terminal escape sequences carry no evidence.
    // deno-lint-ignore no-control-regex
    let text = raw.replace(/\x1b\[[0-?]*[ -/]*[@-~]/g, "");
    text = text.replace(
        /-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)/g,
        "[REDACTED]",
    );
    if (text.includes("-----END ")) {
        const tail = text.slice(text.lastIndexOf("-----END ") + 9);
        const close = tail.indexOf("-----");
        text = close < 0 ? tail : tail.slice(close + 5);
    }
    text = text.replace(
        /(\b[a-z][a-z0-9+.-]*:\/\/)[^/@\s]+@/gi,
        "$1[REDACTED]@",
    );
    text = text.replace(
        /(?:Proxy-)?Authorization\s*:[^\r\n]*/gi,
        "Authorization: [REDACTED]",
    );
    text = text.replace(
        /\bBearer\s+[A-Za-z0-9._+/=-]+/gi,
        "Bearer [REDACTED]",
    );
    text = text.replace(
        /(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})/g,
        "[REDACTED]",
    );
    return text.replace(
        /(["']?)([A-Za-z][A-Za-z0-9_-]{0,255})\1(\s*[:=]\s*)(?:"[^"\r\n]*"|'[^'\r\n]*'|[^\s,}&{\[\]]+)/g,
        (match, quote: string, name: string, separator: string) =>
            /TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CREDENTIAL|APIKEY|APPKEY|PRIVATEKEY|KEYS?/i
                    .test(name)
                ? quote + name + quote + separator + "[REDACTED]"
                : match,
    );
}

/** Keep the last bytes of a UTF-8 string without a broken first character. */
function tailBytes(value: string, limit: number): string {
    const bytes = encoder.encode(value);
    if (bytes.length <= limit) return value;
    let start = bytes.length - limit;
    while (start < bytes.length && (bytes[start] & 0xc0) === 0x80) start++;
    return decoder.decode(bytes.subarray(start));
}

/** stdout followed by stderr, as a redacted tail of at most 16 KiB. */
export function programOutput(
    execution: { stdout?: string; stderr?: string },
): string {
    const raw = (execution.stdout ?? "") + (execution.stderr ?? "");
    return tailBytes(redactText(tailBytes(raw, OUTPUT_WINDOW)), OUTPUT_BYTES);
}

/** tasks.script: a redacted 16 KiB tail of stdout and stderr, and the snapshot change. */
export function scriptResult(args: { gate: unknown; steps: Steps }) {
    const checked = ObjectValue.parse(args.gate);
    if (checked.error_code) return { error_code: String(checked.error_code) };
    const before = checked.before as Snapshot;
    const after = snapshot(args.steps, "after");
    const execution = run(args.steps, "execute");
    if (typeof execution.exitCode !== "number") {
        return { error_code: "tasks.repository_execution_unknown" };
    }
    const raw = (execution.stdout ?? "") + (execution.stderr ?? "");
    return {
        outcome: execution.exitCode === 0 ? "passed" : "failed",
        exit_code: execution.exitCode,
        output: programOutput(execution),
        output_truncated: encoder.encode(raw).length > OUTPUT_BYTES,
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

/** Validate the bounded analyzer stdout; a closed failure object returns its code. */
export function report(
    raw: string,
    identity: Record<string, string>,
    code: number,
): Record<string, unknown> {
    if (encoder.encode(raw).length > REPORT_BYTES) {
        return {
            error_code: "tasks.analysis_report_too_large",
            schema: 1,
            error: {
                code: "tasks.analysis_report_too_large",
                required_bytes: null,
            },
        };
    }
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
    const serialized = JSON.stringify(envelope);
    if (redactText(raw) !== raw || redactText(serialized) !== serialized) {
        throw new Error("Private report data");
    }
    return envelope;
}

/** tasks.analyze: the complete artifact and native provenance, or a refusal code. */
export async function analysisResult(args: {
    operation_id: number;
    intent: unknown;
    gate: unknown;
    execution_id: string;
    steps: Steps;
}) {
    const input = AnalysisInput.parse(args.intent);
    const checked = ObjectValue.parse(args.gate);
    if (checked.error_code) return { error_code: String(checked.error_code) };
    const before = checked.before as Snapshot;
    const after = snapshot(args.steps, "after");
    const execution = run(args.steps, "execute");
    if (
        typeof execution.exitCode !== "number" ||
        typeof execution.stdout !== "string"
    ) return { error_code: "tasks.repository_execution_unknown" };
    const raw = execution.stdout;
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
    version: "2026.11.02.2",
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
            description: "Shape the public script or analysis result",
            arguments: z.object({
                operation_id: z.number().int().positive(),
                kind: Kind,
                intent: ObjectValue,
                gate: ObjectValue,
                execution_id: z.string(),
                steps: ObjectValue,
            }),
            execute: async (
                args: {
                    operation_id: number;
                    kind: z.infer<typeof Kind>;
                    intent: unknown;
                    gate: unknown;
                    execution_id: string;
                    steps: Steps;
                },
                ctx: Context,
            ) => {
                const result = args.kind === "tasks.script"
                    ? scriptResult(args)
                    : await analysisResult(args);
                return output(ctx, {
                    result_json: JSON.stringify(result),
                    failed: "error_code" in result,
                });
            },
        },
    },
};
