import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";

export const DirectArgs = z.object({
  operation_id: z.number().int().positive(),
  kind: z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/),
  node_id: z.number().int().positive().nullable(),
  run_id: z.string().uuid(),
});
export const DirectResult = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
const Outcome = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  input: DirectResult.default({}),
  result: DirectResult.nullable(),
  cancel_requested: z.boolean(),
});
export type DirectIntent = z.infer<typeof DirectArgs>;
export type Result = z.infer<typeof DirectResult>;

/** Fixed shared endpoints; not an executor proxy or a retrying client. */
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
  read() {
    return this.call("GET", `?run_id=${this.args.run_id}`);
  }
  project(body: Record<string, unknown>) {
    return this.call("POST", "/result", { ...body, run_id: this.args.run_id });
  }
  primitive() {
    return this.call("POST", "/primitive", { run_id: this.args.run_id });
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
  const current = Outcome.parse(await gateway.read());
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
    result = validateResult(DirectResult.parse(await action(current.input)));
    if (result.error_code !== undefined) safeCode(result.error_code);
    status = result.error_code === undefined ? "succeeded" : "failed";
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
