import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Executor } from "../../operation-runner/mod.ts";
import { RouteRefusal, type Stages } from "./lifecycle.ts";

export class RouteWaiting extends Error {
  constructor(readonly keys: string[]) {
    super("Route children are still running");
  }
}
export const ParentPlan = z.object({
  prepared: z.literal(true),
  mode: z.string(),
  production: z.boolean(),
  public: z.boolean(),
  remote_router: z.boolean(),
  web_root: z.boolean(),
  targeted: z.boolean(),
  child_phases: z.array(z.object({ stage: z.string(), key: z.string() })),
  activate_public: z.boolean().default(false),
  withdraw_public: z.boolean().default(false),
  changed: z.boolean().default(true),
  metrics: z.boolean().default(false),
});

/** Family stage adapter. B owns admission, ancestry proof, lease release and re-entry. */
export class RouteParentStages implements Stages {
  private results: Record<string, unknown>;
  private readonly executor: Executor;
  constructor(
    private readonly http: Http,
    private readonly operationId: number,
    private readonly worker: string,
    private readonly childPhases: readonly { stage: string; key: string }[],
    results: Record<string, unknown>,
    private readonly waiting: (keys: string[]) => Promise<void>,
    private readonly commandName = "route.parent",
    private readonly pause: (milliseconds: number) => Promise<void> = (
      milliseconds,
    ) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  ) {
    this.results = { ...results };
    this.executor = new Executor(http);
  }
  completed(name: string): boolean {
    return z.object({ ready: z.literal(true) }).safeParse(
      this.results[`stage.${name}`],
    ).success;
  }
  private async command(stage: string, action: "open" | "commit") {
    const key = `stage-${action}.${stage}`,
      step = action === "commit" ? `stage.${stage}` : key;
    if (this.results[step]) {
      const result = z.record(z.string(), z.unknown()).parse(
        this.results[step],
      );
      if (typeof result.error_code === "string") {
        throw new RouteRefusal(result.error_code);
      }
      return result;
    }
    await this.executor.post(`${this.operationId}/step`, {
      worker: this.worker,
      key: `${key}:start`,
      name: step,
      status: "running",
    });
    const result = z.record(z.string(), z.unknown()).parse(
      await this.executor.post(`${this.operationId}/run`, {
        worker: this.worker,
        key,
        transport: "gateway",
        name: this.commandName,
        input: { action, stage },
      }),
    );
    const error = typeof result.error_code === "string"
      ? result.error_code
      : null;
    if (error && !/^[a-z0-9_.-]+$/.test(error)) {
      throw new Error("Invalid Route stage refusal");
    }
    await this.executor.post(`${this.operationId}/step`, {
      worker: this.worker,
      key: `${key}:finish`,
      name: step,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
    });
    if (error) throw new RouteRefusal(error);
    this.results[step] = result;
    return result;
  }
  async run(stage: string) {
    if (this.completed(stage)) return;
    const opened = await this.command(stage, "open");
    const seconds = opened.wait_seconds;
    if (
      seconds !== undefined &&
      (!Number.isInteger(seconds) || Number(seconds) < 0 ||
        Number(seconds) > 31)
    ) {
      throw new Error("Invalid Route DNS grace period");
    }
    if (typeof seconds === "number" && seconds > 0) {
      await this.pause(seconds * 1000);
    }
    const keys = this.childPhases.filter((child) => child.stage === stage).map((
      child,
    ) => child.key);
    // Admit each keyed sibling only after its predecessor has settled. Several scopes can share
    // one Node target hold; a stage may not create competing runnable children for that target.
    for (const key of keys) {
      await this.executor.child(this.operationId, this.worker, key);
      const children = await this.executor.children(this.operationId);
      const child = children.find((candidate) => candidate.key === key);
      if (
        !child || !["succeeded", "failed", "cancelled"].includes(child.status)
      ) {
        await this.executor.wait(this.operationId, this.worker, [key]);
        await this.waiting([key]);
        throw new RouteWaiting([key]);
      }
      if (child.status !== "succeeded") {
        throw new RouteRefusal(child.error_code ?? "route.child_failed");
      }
    }
    // Gateway requireSucceeded checks stamped ancestry, snapshot, constraints and family result.
    await this.command(stage, "commit");
  }
}
