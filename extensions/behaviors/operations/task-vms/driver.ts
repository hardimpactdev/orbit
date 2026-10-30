import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, ExecutorError } from "../../operation-runner/mod.ts";
import type { FamilyEffects } from "./runtime.ts";

const Child = z.object({
  id: z.number().int().positive(),
  status: z.string(),
  result: z.record(z.string(), z.unknown()).nullable().optional(),
  error_code: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
});
export const ChildIntent = z.object({ key: z.string() });
export class FamilyWaiting extends ExecutorError {
  constructor() {
    super("operation.waiting");
  }
}
export class ChildFailed extends ExecutorError {}

export class FamilyDriver implements FamilyEffects {
  constructor(
    private operationId: number,
    private worker: string,
    private deadline: number,
    private ctx: Context,
    private http: Http,
    private timeoutCode = "task_vm.runtime_timeout",
    private guard: () => void = () => {},
  ) {}
  private lastKey: string | null = null;

  private async request(
    method: "GET" | "POST",
    path: string,
    body?: unknown,
    key?: string,
  ) {
    this.guard();
    if (this.ctx.signal?.aborted) throw this.ctx.signal.reason;
    let response;
    try {
      response = await this.http(method, `/api/v1/${path}`, body, key);
    } catch {
      throw new ExecutorError("operation.outcome_unknown");
    }
    if (response.status < 200 || response.status >= 300) {
      const error = z.object({ error: z.object({ code: z.string() }) })
        .safeParse(response.body);
      throw new ExecutorError(
        error.success ? error.data.error.code : "operation.outcome_unknown",
      );
    }
    return z.object({ data: z.unknown() }).parse(response.body).data;
  }

  async stage<T>(key: string, action: () => Promise<T>): Promise<T> {
    this.guard();
    const name = `stage-${this.operationId}-${key}`;
    const saved = await this.ctx.readResource(name);
    if (saved) return z.object({ value: z.unknown() }).parse(saved).value as T;
    const value = await action();
    await this.ctx.writeResource("checkpoint", name, { value: value ?? null });
    return value;
  }

  async child(
    key: string,
    _kind: string,
    _node: number,
    _input: Record<string, unknown>,
  ): Promise<Record<string, unknown>> {
    this.lastKey = key;
    const resource = `child-${this.operationId}-${key}`;
    const saved = await this.ctx.readResource(resource);
    let id: number;
    if (saved) {
      id = z.object({ id: z.number().int().positive() }).parse(saved).id;
    } else {
      const intentName = `child-intent-${this.operationId}-${key}`;
      if (!await this.ctx.readResource(intentName)) {
        await this.ctx.writeResource("childIntent", intentName, { key });
      }
      const child = Child.parse(
        await this.request(
          "POST",
          `operation-executor/${this.operationId}/children`,
          {
            worker: this.worker,
            key,
          },
          `task-vm:${this.operationId}:${key}`,
        ),
      );
      id = child.id;
      await this.ctx.writeResource("child", resource, { id });
    }
    for (;;) {
      const child = Child.parse(await this.request("GET", `operations/${id}`));
      if (child.status === "succeeded") {
        return {
          ...(child.result ?? {}),
          effect_finished_at: child.finished_at ?? null,
        };
      }
      if (child.status === "failed" || child.status === "cancelled") {
        throw new ChildFailed(child.error_code ?? "operation.cancelled");
      }
      await this.yieldFor([key], false);
    }
  }

  async read(view: string) {
    return z.record(z.string(), z.unknown()).parse(
      await this.request("GET", `task-vm-executor/${this.operationId}/${view}`),
    );
  }

  async cancelChildren(): Promise<void> {
    const children = z.array(
      z.object({
        id: z.number().int().positive(),
        key: z.string(),
        status: z.string(),
      }),
    ).parse(
      await this.request(
        "GET",
        `operation-executor/${this.operationId}/children`,
      ),
    );
    const pending = children.filter((child) =>
      !["succeeded", "failed", "cancelled"].includes(child.status)
    );
    for (const child of pending) {
      await this.request("POST", `operations/${child.id}/cancel`, {});
    }
    if (pending.length > 0) {
      await this.yieldFor(pending.map((child) => child.key), false);
    }
  }

  async wait() {
    if (this.lastKey === null) {
      throw new Error("A VM observation needs its admitted intent child.");
    }
    await this.yieldFor([this.lastKey]);
  }
  private async yieldFor(
    keys: string[],
    enforceDeadline = true,
  ): Promise<never> {
    if (enforceDeadline) this.checkDeadline();
    await this.request("POST", `operation-executor/${this.operationId}/wait`, {
      worker: this.worker,
      keys,
    });
    throw new FamilyWaiting();
  }

  private checkDeadline() {
    if (Date.now() / 1000 >= this.deadline) {
      throw new ExecutorError(this.timeoutCode);
    }
  }
}
