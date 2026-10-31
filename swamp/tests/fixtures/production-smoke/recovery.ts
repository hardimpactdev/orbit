import { FailureFixture } from "./failure.ts";
import { ControlRequest } from "../../../extensions/behaviors/production-smoke/recovery.ts";
import { productionSmoke } from "../../../extensions/behaviors/production-smoke/mod.ts";
export class RecoveryFixture extends FailureFixture {
  controls = new Map<string, Record<string, unknown>>();
  held = false;
  lostControl = false;
  brokenControl = false;
  needsRedeploy = false;
  redeploy: Record<string, unknown> | null = null;
  redeployment: Record<string, unknown> | null = null;
  constructor() {
    super();
    const base = this.http;
    this.http = async (method, path, body, key) => {
      if (path.includes("/repair")) {
        const request_key = key ??
          new URL("https://fixture" + path).searchParams.get("request_key")!;
        const repair = {
          request_key,
          instance_id: 7,
          project_id: 20,
          failure_key: request_key.replace(/\.repair$/, ""),
          group_id: 91,
          task_id: 92,
        };
        return {
          status: method === "POST" ? 201 : 200,
          body: {
            data: method === "POST"
              ? repair
              : { request_key, found: false, repair: null },
          },
        };
      }
      if (path.includes("/smoke/receipt?")) {
        this.requests.push({ method, path, body, key });
        const request_key = new URL("https://fixture" + path).searchParams.get(
          "request_key",
        )!;
        const receipt = request_key === this.receipt.request_key
          ? this.receipt
          : request_key === this.baseline.request_key && this.baseline.found
          ? this.baseline
          : { request_key, found: false, operation: null, smoke: null };
        return { status: 200, body: { data: structuredClone(receipt) } };
      }
      if (!path.includes("/control") && !path.includes("/redeploy")) {
        const reply = await base(method, path, body, key);
        if (this.rollback) {
          this.rollback.input.recovery_redeploy_required = this.needsRedeploy;
        }
        return reply;
      }
      this.requests.push({ method, path, body, key });
      if (path.includes("/control")) {
        const requestKey = key ??
          new URL("https://fixture" + path).searchParams.get("request_key")!;
        if (method === "POST") {
          const input = ControlRequest.parse(body);
          if (input.action === "hold") this.held = true;
          if (input.action === "release") this.held = false;
          if (input.action === "alert" && !this.held) {
            return {
              status: 409,
              body: { error: { code: "instance.smoke_control_invalid" } },
            };
          }
          this.controls.set(requestKey, {
            request_key: requestKey,
            instance_id: 7,
            action: input.action,
            hold_key: input.hold_key,
            held: this.held,
            resumed: input.action === "release",
            reason: null,
          });
          if (this.lostControl) throw new Error("Lost control reply");
        }
        const control = this.controls.get(requestKey) ?? null;
        return {
          status: 200,
          body: {
            data: this.brokenControl
              ? {}
              : method === "POST"
              ? control
              : { request_key: requestKey, found: control !== null, control },
          },
        };
      }
      if (method === "POST") {
        const input = ControlRequest.parse(body);
        this.redeploy = {
          id: 88,
          kind: "instance.deploy",
          target_type: "instance",
          target_id: 7,
          input: {
            ...input,
            smoke_hold_key: input.hold_key,
            commit: input.expected.commit,
          },
          status: "queued",
          error_code: null,
        };
        return { status: 202, body: { data: this.redeploy } };
      }
      return {
        status: 200,
        body: {
          data: {
            request_key: new URL("https://fixture" + path).searchParams.get(
              "request_key",
            ),
            found: this.redeploy !== null,
            operation: this.redeploy,
            deployment: this.redeployment,
          },
        },
      };
    };
  }
  async cycle(apply = true, effects = true) {
    await productionSmoke(() => this.http, () => this.now, 120_000, effects)
      .methods.recover.execute({ apply }, this.context());
    return this.stored.get("lifecycle")!;
  }
  async restoredSmoke() {
    await this.cycle(); // Reconcile original rollback and supersede the failed job.
    await this.cycle(); // Admit restored smoke.
    this.pass();
    return await this.cycle();
  }
}
