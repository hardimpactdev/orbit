import { z } from "npm:zod@4.4.3";
import {
    type DirectIntent,
    DirectOperationCancelled,
    DirectResult,
    type Result,
} from "./direct-input.ts";
export {
    DirectArgs,
    type DirectIntent,
    DirectOperationCancelled,
    DirectResult,
    type Result,
} from "./direct-input.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

/** Shared native configuration, independent of the retired polled runner. */
export const Globals = z.object({
    gatewayUrl: z.string().url().default("https://gateway.orbit"),
    caFile: z.string().min(1).default(
        "/usr/local/share/ca-certificates/orbit-root.crt",
    ),
});

export type Context = {
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    readResource(name: string): Promise<Record<string, unknown> | null>;
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
};

const Outcome = z.object({
    id: z.number().int().positive(),
    kind: z.string(),
    node_id: z.number().int().positive().nullable(),
    status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
    input: DirectResult.default({}),
    result: DirectResult.nullable(),
    cancel_requested: z.boolean(),
});
/** Retained legacy client: each request is a single fail-closed attempt. */
export class GatewayOperation {
    constructor(
        private http: Http,
        private args: DirectIntent,
    ) {}
    async call(
        method: "GET" | "POST",
        suffix: string,
        body?: Record<string, unknown>,
    ): Promise<unknown> {
        const response = await this.http(
            method,
            `/api/v1/direct-operations/${this.args.operation_id}${suffix}`,
            body,
        );
        if (response.status < 200 || response.status >= 300) {
            throw new Error("Direct Operation request did not succeed");
        }
        return z.object({ data: z.unknown() }).parse(response.body).data;
    }
    async read(signal?: AbortSignal): Promise<unknown> {
        // A pre-cancelled legacy invocation still reads its bound row so the
        // existing directOperation path can record cancellation without an effect.
        const readSignal = signal?.aborted ? undefined : signal;
        const response = await this.call("GET", `?run_id=${this.args.run_id}`);
        readSignal?.throwIfAborted();
        return response;
    }
    project(body: Record<string, unknown>) {
        return this.call("POST", "/result", {
            ...body,
            run_id: this.args.run_id,
        });
    }
    /** Private bytes are callback-local; native arguments/resources must stay secret-free. */
    async withPrivateMaterial(
        use: (material: Result) => Promise<void>,
    ): Promise<void> {
        try {
            const material = DirectResult.parse(
                await this.call(
                    "GET",
                    `/private-material?run_id=${this.args.run_id}`,
                ),
            );
            await use(material);
        } catch {
            // A fetch/schema/callback error can contain private material. Never forward its text.
            throw new Error("Private material callback failed");
        }
    }
    async storeSecretOutput(outputs: Result): Promise<void> {
        try {
            await this.call("POST", "/secret-output", {
                run_id: this.args.run_id,
                outputs,
            });
        } catch {
            throw new Error("Private output write failed");
        }
    }
    /** Consume one admitted artifact in memory; never expose its bytes as native output. */
    async artifact(field: string, sha256: string): Promise<Uint8Array> {
        if (
            !/^[a-z][a-z0-9_]{0,63}$/.test(field) ||
            !/^[a-f0-9]{64}$/.test(sha256)
        ) {
            throw new Error("Invalid artifact identity");
        }
        let bytes: Uint8Array | undefined;
        await this.withPrivateMaterial(async (material) => {
            const reference = z.object({
                path: z.string(),
                sha256: z.literal(sha256),
                bytes: z.number().int().positive().max(268435456),
                max_bytes: z.literal(268435456),
            }).strict().parse(material[field]);
            const path =
                `/api/v1/direct-operations/${this.args.operation_id}/private-material?run_id=${this.args.run_id}&artifact=${field}`;
            if (reference.path !== path) {
                throw new Error("Foreign artifact reference");
            }
            const response = await this.http("GET", path);
            if (
                response.status !== 200 ||
                !(response.body instanceof Uint8Array) ||
                response.body.length !== reference.bytes
            ) {
                throw new Error("Invalid artifact download");
            }
            const observed = Array.from(
                new Uint8Array(
                    await crypto.subtle.digest(
                        "SHA-256",
                        new Uint8Array(response.body),
                    ),
                ),
                (byte) => byte.toString(16).padStart(2, "0"),
            ).join("");
            if (observed !== sha256) {
                throw new Error("Artifact digest mismatch");
            }
            bytes = response.body;
        });
        if (!bytes) throw new Error("Artifact unavailable");
        return bytes;
    }
}

/** Native steps own retry/cancellation/locking. Families supply their action and result schema. */
export async function directOperation(
    args: DirectIntent,
    gateway: Pick<GatewayOperation, "read" | "project">,
    action: (input: Result) => Promise<unknown>,
    validateResult: (result: Result) => Result,
    signal?: AbortSignal,
) {
    const current = Outcome.parse(await gateway.read(signal));
    if (
        current.id !== args.operation_id || current.kind !== args.kind ||
        current.node_id !== args.node_id
    ) throw new Error("Direct Operation does not match admitted intent");
    if (["succeeded", "failed", "cancelled"].includes(current.status)) {
        if (current.status === "failed") {
            throw new Error(safeCode(current.result?.error_code));
        }
        return { status: current.status };
    }
    const started_at = new Date().toISOString();
    await gateway.project({ status: "running", started_at });
    let result: Result = {};
    let status: "succeeded" | "failed" | "cancelled";
    if (current.cancel_requested || signal?.aborted) {
        status = "cancelled";
    } else {
        try {
            result = validateResult(
                DirectResult.parse(await action(current.input)),
            );
            if (result.error_code !== undefined) safeCode(result.error_code);
            status = result.error_code === undefined ? "succeeded" : "failed";
        } catch (error) {
            if (error instanceof DirectOperationCancelled) {
                result = {};
                status = "cancelled";
            } else {
                // Native failure owns terminal settlement; exception text may contain secrets.
                throw new Error("operation.native_outcome_unknown");
            }
        }
    }
    await gateway.project({
        status,
        started_at,
        finished_at: new Date().toISOString(),
        result,
        error_code: result.error_code ?? null,
    });
    if (status === "failed") throw new Error(safeCode(result.error_code));
    return { status };
}

function safeCode(code: unknown): string {
    if (!z.string().regex(/^[a-z0-9_.-]{1,100}$/).safeParse(code).success) {
        throw new Error("Invalid direct Operation refusal");
    }
    return code as string;
}
