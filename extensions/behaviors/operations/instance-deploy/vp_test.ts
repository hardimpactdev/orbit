import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { Context } from "../../operation-runner/mod.ts";
import { instanceDeploy } from "./mod.ts";

type Public = Record<string, unknown>;
class VpGateway {
  steps: Record<string, Public> = {};
  receipts = new Map<string, Public>();
  effects: string[] = [];
  completion: Public | null = null;
  installed = false;
  oldVersion = false;
  incomplete = false;
  lost = false;
  noOp = false;
  environment = {
    store: "/opt/orbit/vite-plus",
    node_version: "24.22.0",
    package_manager: "npm",
    package_manager_version: "11.12.0",
    package_manager_hash: null,
  };
  descriptors() {
    const descriptor = (
      key: string,
      transport: string,
      name: string,
      input: Public = {},
    ) => ({
      key,
      step: key.replaceAll(".", "_"),
      transport,
      name,
      input,
      resumable: true,
      required: true,
    });
    const effects = [
      descriptor("vp.plan", "gateway", "instance.vp-plan", { instance_id: 17 }),
    ];
    if (this.steps.vp_plan && !this.noOp) {
      effects.push(
        descriptor(
          "vp.scope",
          "instance-deploy-agent",
          "tool.inspect-vp-scope",
        ),
      );
      effects.push(
        descriptor("vp.version", "instance-deploy-agent", "tool.vp-version"),
      );
      if (this.steps.vp_scope) {
        effects.push(
          descriptor(
            "vp.inspect",
            "instance-deploy-agent",
            "tool.inspect-vp-store",
            { environments: [this.environment] },
          ),
        );
        if (this.steps.vp_inspect?.installed === false) {
          effects.push(
            descriptor(
              "vp.install",
              "instance-deploy-agent",
              "tool.install-vp-environment",
              {
                environment: this.environment,
                npmrc: { $secret: "instance.vp_environment", id: 17 },
              },
            ),
          );
        }
        effects.push(
          descriptor(
            "vp.verify",
            "instance-deploy-agent",
            "tool.inspect-vp-store",
            { environments: [this.environment] },
          ),
        );
      }
    }
    effects.push(
      descriptor("vp.receipt", "gateway", "instance.vp-receipt", {
        instance_id: 17,
      }),
    );
    return effects;
  }
  operation() {
    return {
      id: 7,
      kind: "instance.prepare_vp_environment",
      node_id: 9,
      status: "running",
      cancel_requested: false,
      input: { instance_id: 17 },
      steps: Object.keys(this.steps).map((name) => ({
        name,
        status: "succeeded",
      })),
      step_results: this.steps,
      execution_context: { descriptors: this.descriptors() },
    };
  }
  http: Http = (_method, path, raw) => {
    const body = (raw ?? {}) as Public;
    if (_method === "GET" && path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/receipts/")[1]);
      const result = this.receipts.get(key) ?? null;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            effect_key: `op-7.${key}`,
            exit_code: null,
            finished_at: null,
            state: result === null ? "unknown" : "succeeded",
            result,
          },
        },
      });
    }
    let data: unknown = this.operation();
    if (path.endsWith("/run")) {
      const key = String(body.key);
      const descriptor = this.descriptors().find((item) => item.key === key)!;
      assertEquals(body.input, descriptor.input);
      assertEquals(body.transport, descriptor.transport);
      assertEquals("node_id" in body, false);
      if (!this.receipts.has(key)) {
        this.effects.push(key);
        const result: Public = key === "vp.plan"
          ? {
            path: "/fast/apps/proof/default",
            environment: this.noOp
              ? null
              : { ...this.environment, vp_version: "0.3.3" },
          }
          : key === "vp.scope"
          ? { binary: "/opt/orbit/vite-plus/bin/vp" }
          : key === "vp.version"
          ? this.oldVersion
            ? { error_code: "deployment.vp_store_failed" }
            : { version: "0.3.3" }
          : key === "vp.inspect"
          ? { installed: this.installed }
          : key === "vp.verify"
          ? this.installed
            ? { installed: true }
            : { error_code: "deployment.vp_store_failed" }
          : key === "vp.receipt"
          ? {
            confirmed: true,
            instance_id: 17,
            no_op: this.noOp,
            vp_environment: this.noOp ? null : this.environment,
          }
          : { installed: false };
        if (key === "vp.install" && !this.incomplete) this.installed = true;
        this.receipts.set(key, result);
      }
      data = this.receipts.get(key);
      if (key === "vp.install" && this.lost) {
        this.lost = false;
        throw new Error("Lost install reply");
      }
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.steps[String(body.name)] = body.result as Public;
    }
    if (path.endsWith("/complete")) this.completion = body;
    return Promise.resolve({ status: 200, body: { data } });
  };
  execute() {
    const { context } = createModelTestContext({
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    });
    const ctx = Object.assign(context, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      readResource: () => Promise.resolve(null),
    }) as Context;
    return instanceDeploy(() => this.http).methods.execute.execute({
      kind: "instance.prepare_vp_environment",
      worker: "proof",
    }, ctx);
  }
}
Deno.test("Vite+ no-op and installed fast paths avoid installation", async () => {
  const absent = new VpGateway();
  absent.noOp = true;
  assertEquals((await absent.execute()).status, "succeeded");
  assertEquals(absent.effects, ["vp.plan", "vp.receipt"]);
  const present = new VpGateway();
  present.installed = true;
  assertEquals((await present.execute()).status, "succeeded");
  assertEquals(present.effects.includes("vp.install"), false);
});
Deno.test("Vite+ install uses protected registry settings and verifies before completion", async () => {
  const gateway = new VpGateway();
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(gateway.effects, [
    "vp.plan",
    "vp.scope",
    "vp.version",
    "vp.inspect",
    "vp.install",
    "vp.verify",
    "vp.receipt",
  ]);
});
Deno.test("old recipe Vite+ and incomplete runtime stop before build", async () => {
  const old = new VpGateway();
  old.oldVersion = true;
  assertEquals((await old.execute()).status, "failed");
  assertEquals(old.effects.includes("vp.install"), false);
  const incomplete = new VpGateway();
  incomplete.incomplete = true;
  assertEquals((await incomplete.execute()).status, "failed");
  assertEquals(incomplete.effects.includes("vp.receipt"), false);
});
Deno.test("lost Vite+ installation reply resumes from its keyed evidence", async () => {
  const gateway = new VpGateway();
  gateway.lost = true;
  await assertRejects(() => gateway.execute(), Error, "Lost install reply");
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(gateway.effects.filter((key) => key === "vp.install").length, 1);
});
