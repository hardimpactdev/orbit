import { z } from "npm:zod@4.4.3";
import {
  type Context,
  Executor,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import { ErrorCode, ResultObject } from "./contract.ts";

type Result = Record<string, unknown>;
export class PendingEffect extends Error {}

/** Node-family execution state; Gateway and target receipts remain authoritative. */
export class NodeExecution {
  private heartbeatError: unknown;
  private renewing = false;
  private timer: ReturnType<typeof setInterval>;
  constructor(
    public operation: z.infer<typeof Operation>,
    private executor: Executor,
    private worker: string,
    private ctx: Context,
  ) {
    this.timer = setInterval(() => {
      if (this.renewing || this.heartbeatError) return;
      this.renewing = true;
      this.executor.post(`${this.operation.id}/heartbeat`, {
        worker: this.worker,
      })
        .catch((error) => {
          this.heartbeatError = error;
        })
        .finally(() => {
          this.renewing = false;
        });
    }, 30_000);
  }
  close() {
    clearInterval(this.timer);
  }
  private fence() {
    if (this.heartbeatError) throw this.heartbeatError;
    this.ctx.signal?.throwIfAborted();
  }
  post(suffix: string, body: Result): Promise<unknown> {
    this.fence();
    return this.executor.post(`${this.operation.id}/${suffix}`, {
      worker: this.worker,
      ...body,
    });
  }
  child(key: string): Promise<unknown> {
    this.fence();
    return this.executor.child(this.operation.id, this.worker, key);
  }
  async wait(keys: string[]) {
    this.fence();
    this.operation = await this.executor.wait(
      this.operation.id,
      this.worker,
      keys,
    );
    this.fence();
  }
  async get(suffix: string): Promise<unknown> {
    this.fence();
    const result = suffix === "children"
      ? await this.executor.children(this.operation.id)
      : suffix.startsWith("receipts/")
      ? await this.executor.receipt(
        this.operation.id,
        decodeURIComponent(suffix.slice(9)),
      )
      : (() => {
        throw new ExecutorError("operation.execution_forbidden");
      })();
    this.fence();
    return result;
  }
  async refresh() {
    this.operation = Operation.parse(await this.post("heartbeat", {}));
    return this.operation;
  }
  async run(
    step: string,
    transport: string,
    name: string,
    input: Result,
    output = `Apply ${step}\n`,
  ): Promise<Result> {
    if (Object.hasOwn(this.operation.step_results, step)) {
      return ResultObject.parse(this.operation.step_results[step]);
    }
    await this.post("step", {
      key: `${step}:start`,
      name: step,
      status: "running",
      output,
    });
    const resource = `effect-${this.operation.id}-${step}`;
    const saved = await this.ctx.readResource(resource);
    let result: Result;
    if (saved) result = ResultObject.parse(saved.result);
    else {
      const intent = { transport, name, input };
      const intentName = `intent-${this.operation.id}-${step}`;
      const previous = await this.ctx.readResource(intentName);
      if (previous && JSON.stringify(previous) !== JSON.stringify(intent)) {
        throw new ExecutorError("operation.idempotency_conflict");
      }
      if (!previous) await this.ctx.writeResource("intent", intentName, intent);
      let receipt: Result | undefined;
      if (previous && transport !== "gateway") {
        const observed = z.object({
          state: z.enum(["succeeded", "failed", "running", "unknown"]),
          result: ResultObject.nullable(),
        }).parse(await this.get(`receipts/${encodeURIComponent(step)}`));
        if (observed.state === "running") throw new PendingEffect();
        if (observed.state === "succeeded" || observed.state === "failed") {
          if (observed.result === null) {
            throw new ExecutorError("operation.outcome_unknown");
          }
          receipt = observed.state === "failed" && !observed.result.error_code
            ? { ...observed.result, error_code: "operation.execution_failed" }
            : observed.result;
        }
      }
      result = receipt ??
        ResultObject.parse(
          await this.post("run", { key: step, transport, name, input }),
        );
      await this.ctx.writeResource("effect", resource, { result });
    }
    const error = result.error_code === undefined
      ? null
      : ErrorCode.parse(result.error_code);
    await this.post("step", {
      key: `${step}:finish`,
      name: step,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
      output: error ? `${error}\n` : `Finished ${step}\n`,
    });
    this.operation.step_results[step] = result;
    return result;
  }
}
