import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Executor, ExecutorError } from "../../operation-runner/mod.ts";
import type { Child, Receipt } from "./runtime.ts";
import { ErrorCode } from "./contract.ts";

const ChildReceipt = z.object({
  id: z.number().int().positive(),
  key: z.string(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.enum([
    "queued",
    "running",
    "waiting",
    "succeeded",
    "failed",
    "cancelled",
  ]),
  error_code: ErrorCode.nullable(),
  result: z.record(z.string(), z.unknown()).nullable(),
});
export class WaitingForChildren extends Error {
  constructor(readonly keys: string[]) {
    super("The parent released its lease while its recorded children execute");
  }
}

/** B owns admission, placement and input. A parent yields its worker until recorded children settle. */
export function childExecutor(
  http: Http,
  parentId: number,
  worker: string,
) {
  const executor = new Executor(http);
  return async (child: Child): Promise<Receipt> => {
    await executor.post(`${parentId}/children`, { worker, key: child.key });
    const response = await http(
      "GET",
      `/api/v1/operation-executor/${parentId}/children`,
    );
    if (response.status < 200 || response.status >= 300) {
      throw new ExecutorError("operation.child_receipt_unavailable");
    }
    const children = z.array(ChildReceipt).parse(
      z.object({ data: z.unknown() }).parse(response.body).data,
    );
    const matching = children.filter((receipt) => receipt.key === child.key);
    if (
      matching.length !== 1 || matching[0].kind !== child.kind ||
      matching[0].node_id !== child.node_id
    ) throw new Error("The recorded child does not match admitted placement");
    const receipt = matching[0];
    if (receipt.status === "succeeded") return receipt.result ?? {};
    if (receipt.status === "failed" || receipt.status === "cancelled") {
      return {
        ...receipt.result,
        error_code: receipt.error_code ??
          (receipt.status === "cancelled"
            ? "operation.cancelled"
            : "operation.child_failed"),
      };
    }
    await executor.post(`${parentId}/wait`, { worker, keys: [child.key] });
    throw new WaitingForChildren([child.key]);
  };
}
