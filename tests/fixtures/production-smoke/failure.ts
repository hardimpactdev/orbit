import { Fixture, passed } from "./fixture.ts";
import { productionSmoke } from "../../../extensions/behaviors/production-smoke/mod.ts";
import {
  Block,
  RollbackOperation,
} from "../../../extensions/behaviors/production-smoke/failure.ts";
import { type z } from "npm:zod@4.4.3";

export class FailureFixture extends Fixture {
  block: z.infer<typeof Block> | null = null;
  rollback: z.infer<typeof RollbackOperation> | null = null;
  deployment: Record<string, unknown> | null = null;
  lostBlock = false;
  lostRollback = false;
  transport = false;
  refuseRollback = false;
  beforeRollback: (() => void) | null = null;
  afterBlock: (() => void) | null = null;
  mutateReceipt: ((value: Record<string, unknown>) => void) | null = null;
  baseline: typeof this.receipt = structuredClone(this.receipt);
  constructor() {
    super();
    const baseHttp = this.http;
    this.http = async (method, path, body, key) => {
      if (!path.includes("/failure") && !path.includes("/rollback")) {
        if (path.endsWith("request_key=smoke.7.9")) {
          this.requests.push({ method, path, body, key });
          return {
            status: 200,
            body: { data: structuredClone(this.baseline) },
          };
        }
        return await baseHttp(method, path, body, key);
      }
      this.requests.push({ method, path, body, key });
      if (this.transport) throw new Error("Gateway unavailable");
      let value: Record<string, unknown>;
      if (path.includes("/failure")) {
        if (method === "POST") {
          this.block ??= Block.parse({
            request_key: key,
            instance_id: 7,
            failure: body,
            blocked: true,
          });
          this.afterBlock?.();
          if (this.lostBlock) throw new Error("Block response lost");
          value = structuredClone(this.block);
        } else {value = {
            request_key: "smoke-failure.7.10",
            found: this.block !== null,
            block: structuredClone(this.block),
          };}
      } else if (method === "POST") {
        if (this.refuseRollback) {
          return {
            status: 409,
            body: { error: { code: "instance.smoke_selection_changed" } },
          };
        }
        this.rollback ??= RollbackOperation.parse({
          id: 55,
          kind: "instance.rollback",
          target_type: "instance",
          target_id: 7,
          input: body,
          status: "queued",
          error_code: null,
        });
        if (this.lostRollback) throw new Error("Rollback response lost");
        value = structuredClone(this.rollback);
      } else {
        this.beforeRollback?.();
        value = {
          request_key: "smoke-failure.7.10.rollback",
          found: this.rollback !== null,
          operation: structuredClone(this.rollback),
          deployment: this.deployment,
        };
      }
      this.mutateReceipt?.(value);
      return {
        status: method === "POST" && path.endsWith("/rollback") ? 202 : 200,
        body: { data: value },
      };
    };
  }
  async prepare() {
    await this.tick("baseline");
    this.pass();
    this.baseline = structuredClone(this.receipt);
    // The fixture intercepts the baseline receipt above; publish it before reading.
    await this.tick();
    this.observed.selected = {
      deployment_id: 10,
      commit: "c".repeat(40),
      release: "release-10",
    };
    this.observed.retained_releases.push("release-10");
    await this.tick(); // Preserve supersession for its own tick.
    await this.tick("baseline");
    this.receipt.smoke = {
      ...passed(this.receipt.operation!.input),
      state: "failed",
      failures: 1,
      conclusion: "failure",
      output: "Render failed",
    };
    this.requests = [];
  }
  async recover(apply = true, effects = true, deploymentId?: number) {
    await productionSmoke(() => this.http, () => this.now, 120_000, effects)
      .methods.failure.execute({ apply, deploymentId }, this.context());
    return this.stored.get("recovery")!;
  }
  complete() {
    this.rollback!.status = "succeeded";
    this.observed.selected = {
      deployment_id: 11,
      commit: "a".repeat(40),
      release: "release-9",
    };
    this.deployment = { ...this.observed.selected, status: "succeeded" };
  }
  get effects() {
    return this.posts.filter((request) =>
      request.path.endsWith("/failure") || request.path.endsWith("/rollback")
    );
  }
}
