import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  Executor,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import { ErrorCode } from "./contract.ts";
import type { Child, Receipt, Session } from "./runtime.ts";

const Descriptor = z.object({
  key: z.string().min(1),
  step: z.string().min(1),
  transport: z.string().min(1),
  name: z.string().min(1),
  input: z.record(z.string(), z.unknown()),
  resumable: z.boolean(),
  required: z.boolean(),
});
const ExecutionContext = z.object({
  descriptors: z.array(Descriptor),
  result: z.record(z.string(), z.unknown()).optional(),
});
const Claimed = Operation.extend({ execution_context: ExecutionContext });
const ChildIntent = z.object({
  key: z.string(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  input: z.record(z.string(), z.unknown()),
});
const FamilyContext = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  input: z.record(z.string(), z.unknown()),
  execution_context: ExecutionContext,
  child_context: z.object({ children: z.array(ChildIntent) }).default({
    children: [],
  }),
});
const EffectReceipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: z.record(z.string(), z.unknown()).nullable(),
  finished_at: z.string().nullable(),
});
export type ChildExecutor = (child: Child) => Promise<Receipt>;

/** Uses only server-owned descriptors. A lost reply keeps the same Operation and effect key. */
export class LifecycleSession implements Session {
  private operation: z.infer<typeof Claimed> | null = null;
  private family: z.infer<typeof FamilyContext> | null = null;
  private executor: Executor;

  constructor(
    private http: Http,
    readonly id: number,
    readonly worker: string,
    private executeChild: ChildExecutor,
    private pause = () =>
      new Promise<void>((resolve) => setTimeout(resolve, 5_000)),
  ) {
    this.executor = new Executor(http);
  }

  get nodeId(): number {
    return this.current().node_id;
  }
  get kind(): string {
    return this.current().kind;
  }
  get input(): Receipt {
    return this.current().input;
  }
  private current(): z.infer<typeof FamilyContext> {
    if (!this.family) throw new Error("Lifecycle session has not been loaded");
    return this.family;
  }

  async refresh(): Promise<void> {
    this.operation = Claimed.parse(
      await this.executor.post(`${this.id}/heartbeat`, { worker: this.worker }),
    );
    const response = await this.http(
      "GET",
      `/api/v1/operations/${this.id}/instance-lifecycle`,
    );
    if (response.status < 200 || response.status >= 300) {
      throw new ExecutorError("operation.context_unavailable");
    }
    const family = FamilyContext.parse(
      z.object({ data: z.unknown() }).parse(response.body).data,
    );
    if (
      family.id !== this.id || family.kind !== this.operation.kind ||
      family.node_id !== this.operation.node_id
    ) throw new Error("Lifecycle context does not match its claimed Operation");
    this.family = family;
  }

  async run(key: string): Promise<Receipt> {
    await this.refresh();
    const descriptors = this.current().execution_context.descriptors.filter(
      (descriptor) => descriptor.key === key,
    );
    if (descriptors.length !== 1) {
      throw new Error("The lifecycle effect requires one admitted descriptor");
    }
    const descriptor = descriptors[0];
    const saved = this.operation!.step_results[descriptor.step];
    if (saved !== undefined) {
      return z.record(z.string(), z.unknown()).parse(saved);
    }
    await this.executor.post(`${this.id}/step`, {
      worker: this.worker,
      key: `${key}:start`,
      name: descriptor.step,
      status: "running",
    });
    // Kit I processes recovered replies through the Gateway family hook and stores keyed proof.
    const recovered = await this.recover(key);
    const pendingCancellation = recovered === null &&
      this.kind === "instance.transfer" && key === "transfer-capture" &&
      !descriptor.resumable && this.operation!.cancel_requested &&
      this.current().execution_context.result?.rollback_pending === true;
    const result = pendingCancellation
      ? { error_code: "operation.cancelled", rollback_pending: true }
      : recovered ?? z.record(z.string(), z.unknown()).parse(
        await this.executor.post(`${this.id}/run`, {
          worker: this.worker,
          key,
          transport: descriptor.transport,
          name: descriptor.name,
          input: descriptor.input,
        }),
      );
    const error = result.error_code === undefined || result.error_code === null
      ? null
      : ErrorCode.parse(result.error_code);
    if (recovered === null && error === "operation.execution_uncertain") {
      // An unresolved invocation is still running. Do not finish its step or save terminal completion intent.
      throw new ExecutorError(error);
    }
    await this.executor.post(`${this.id}/step`, {
      worker: this.worker,
      key: `${key}:finish`,
      name: descriptor.step,
      status: error === null ? "succeeded" : "failed",
      result,
      error_code: error,
    });
    // DB-only projections can introduce child/removal metadata. Refresh before Swamp selects the next effect.
    await this.refresh();
    return result;
  }

  private async recover(key: string): Promise<Receipt | null> {
    while (true) {
      const response = await this.http(
        "GET",
        `/api/v1/operation-executor/${this.id}/receipts/${
          encodeURIComponent(key)
        }`,
      );
      if (response.status < 200 || response.status >= 300) {
        const error = z.object({ error: z.object({ code: ErrorCode }) })
          .safeParse(response.body);
        throw new ExecutorError(
          error.success
            ? error.data.error.code
            : "operation.receipt_unavailable",
        );
      }
      const receipt = EffectReceipt.parse(
        z.object({ data: z.unknown() }).parse(response.body).data,
      );
      if (receipt.effect_key !== `op-${this.id}.${key}`) {
        throw new ExecutorError("operation.receipt_invalid");
      }
      if (receipt.state === "succeeded" || receipt.state === "failed") {
        if (
          receipt.result === null || receipt.exit_code === null ||
          receipt.finished_at === null ||
          (receipt.state === "succeeded" && receipt.exit_code !== 0)
        ) throw new ExecutorError("operation.receipt_invalid");
        if (receipt.state === "failed" && !receipt.result.error_code) {
          // The Gateway must project a proven failed effect to a typed refusal.
          throw new ExecutorError("operation.receipt_invalid");
        }
        return receipt.result;
      }
      if (
        receipt.result !== null || receipt.exit_code !== null ||
        receipt.finished_at !== null
      ) throw new ExecutorError("operation.receipt_invalid");
      if (receipt.state === "unknown") return null;
      await this.pause();
      await this.refresh();
    }
  }

  async child(child: Child): Promise<Receipt> {
    await this.refresh();
    const eligible = this.current().child_context.children.filter((intent) =>
      intent.key === child.key
    );
    if (eligible.length !== 1 || eligible[0].kind !== child.kind) {
      if (this.operation!.cancel_requested) {
        throw new ExecutorError("operation.cancelled");
      }
      throw new ExecutorError("operation.child_forbidden");
    }
    const result = await this.executeChild(eligible[0]);
    await this.refresh();
    return result;
  }
}
