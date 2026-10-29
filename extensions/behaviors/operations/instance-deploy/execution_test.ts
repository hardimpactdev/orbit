import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Configuration,
  type DeploymentPort,
  DeploymentRefused,
  type Phase,
  type Receipt,
} from "./contract.ts";
import { deploy } from "./execution.ts";

class Target implements DeploymentPort {
  calls: string[] = [];
  receipts = new Map<string, Receipt>();
  refusal: { phase: string; error: string } | null = null;
  lostReply: string | null = null;
  cancellation: string | null = null;
  selected = "previous";
  seed = "previous-commit";
  activated() {
    return Promise.resolve(this.receipts.has("activation"));
  }
  cancelled() {
    return Promise.resolve(this.calls.at(-1) === this.cancellation);
  }
  async phase(phase: Phase): Promise<Receipt> {
    await Promise.resolve();
    if (this.receipts.has(phase.name)) return this.receipts.get(phase.name)!;
    this.calls.push(phase.name);
    if (this.refusal?.phase === phase.name) {
      throw new DeploymentRefused(this.refusal.error);
    }
    if (phase.name === "activation") this.selected = "candidate";
    const receipt: Receipt = { exit_code: 0 };
    this.receipts.set(phase.name, receipt);
    if (this.lostReply === phase.name) {
      this.lostReply = null;
      throw new Error("Lost response after effect");
    }
    return receipt;
  }
  result() {
    return Promise.resolve({
      selected_release: this.selected,
      seed: this.seed,
    });
  }
}
const production: Configuration = {
  needs_vp: false,
  variant: "production",
  php: true,
  steps: [
    { name: "migrate", phase: "before_activation", required: true },
    { name: "warmup", phase: "after_activation", required: true },
  ],
};
Deno.test("production prepares, syncs, migrates, activates, refreshes PHP, restarts, warms, prunes", async () => {
  const target = new Target();
  assertEquals((await deploy(production, target)).status, "succeeded");
  assertEquals(target.calls, [
    "source_preparation",
    "environment_sync",
    "before_activation.migrate",
    "activation",
    "php_refresh",
    "process_restart",
    "after_activation.warmup",
    "cleanup",
  ]);
});
Deno.test("rollback runs no deploy scripts or cleanup and preserves PHP ordering", async () => {
  const target = new Target();
  await deploy({ ...production, variant: "rollback" }, target);
  assertEquals(target.calls, [
    "rollback",
    "activation",
    "php_refresh",
    "process_restart",
  ]);
});
Deno.test("non-PHP deployments omit runtime refresh", async () => {
  const target = new Target();
  await deploy({ ...production, php: false }, target);
  assertEquals(target.calls.includes("php_refresh"), false);
});
Deno.test("preparation and pre-activation failures keep the previous selection", async () => {
  for (
    const phase of [
      "source_preparation",
      "environment_sync",
      "before_activation.migrate",
    ]
  ) {
    const target = new Target();
    target.refusal = { phase, error: "deployment.step_failed" };
    const completion = await deploy(production, target);
    assertEquals(completion.status, "failed");
    assertEquals(target.selected, "previous");
    assertEquals(target.calls.at(-1), phase);
  }
});
Deno.test("post-activation failure reports the new selection and stops later phases", async () => {
  const target = new Target();
  target.refusal = {
    phase: "process_restart",
    error: "process.restart_failed",
  };
  const completion = await deploy(production, target);
  assertEquals(completion, {
    status: "failed",
    error_code: "process.restart_failed",
    result: { selected_release: "candidate", seed: "previous-commit" },
  });
  assertEquals(target.calls.includes("after_activation.warmup"), false);
});
Deno.test("cleanup refusal keeps a successful deployment", async () => {
  const target = new Target();
  target.refusal = { phase: "cleanup", error: "deployment.prune_failed" };
  assertEquals((await deploy(production, target)).status, "succeeded");
});
Deno.test("development deploys in place and runs only Project before-activation steps", async () => {
  const target = new Target();
  await deploy({ ...production, variant: "development" }, target);
  assertEquals(target.calls, [
    "source_preparation",
    "before_activation.migrate",
  ]);
  assertEquals(target.selected, "previous");
});
Deno.test("best-effort executed failure continues, other refusals stop", async () => {
  const config: Configuration = {
    ...production,
    variant: "development",
    steps: [
      { name: "warm", phase: "before_activation", required: false },
      { name: "build", phase: "before_activation", required: true },
    ],
  };
  for (
    const error of [
      "deployment.step_failed",
      "operation.execution_forbidden",
      "deployment.interrupted",
    ]
  ) {
    const target = new Target();
    target.refusal = { phase: "before_activation.warm", error };
    assertEquals(
      (await deploy(config, target)).status,
      error === "deployment.step_failed" ? "succeeded" : "failed",
    );
    assertEquals(
      target.calls.includes("before_activation.build"),
      error === "deployment.step_failed",
    );
  }
});
Deno.test("required failure keeps the previous successful development seed", async () => {
  const target = new Target();
  target.refusal = {
    phase: "before_activation.migrate",
    error: "deployment.step_failed",
  };
  assertEquals(
    (await deploy({ ...production, variant: "development" }, target)).status,
    "failed",
  );
  assertEquals(target.seed, "previous-commit");
});
Deno.test("cancellation before activation leaves production unchanged", async () => {
  const target = new Target();
  target.cancellation = "environment_sync";
  assertEquals((await deploy(production, target)).status, "cancelled");
  assertEquals(target.selected, "previous");
});
Deno.test("rollback finishes PHP and restarts after cancellation during activation", async () => {
  const target = new Target();
  target.cancellation = "activation";
  assertEquals(
    (await deploy({ ...production, variant: "rollback" }, target)).status,
    "succeeded",
  );
  assertEquals(target.calls, [
    "rollback",
    "activation",
    "php_refresh",
    "process_restart",
  ]);
});
Deno.test("a lost effect reply leaves execution open and saved receipts are reused on restart", async () => {
  const target = new Target();
  target.lostReply = "activation";
  await assertRejects(() => deploy(production, target), Error, "Lost response");
  assertEquals((await deploy(production, target)).status, "succeeded");
  assertEquals(target.calls.filter((name) => name === "activation").length, 1);
});

Deno.test("production finishes runtime refresh and after steps after cancellation during activation", async () => {
  const target = new Target();
  target.cancellation = "activation";
  assertEquals((await deploy(production, target)).status, "succeeded");
  assertEquals(target.calls.at(-1), "cleanup");
});

Deno.test("a resumed activated rollback finishes even when cancellation is already recorded", async () => {
  const target = new Target();
  target.receipts.set("rollback", { exit_code: 0 });
  target.receipts.set("activation", { exit_code: 0 });
  target.selected = "candidate";
  target.calls.push("activation");
  target.cancellation = "activation";
  assertEquals(
    (await deploy({ ...production, variant: "rollback" }, target)).status,
    "succeeded",
  );
  assertEquals(target.calls, ["activation", "php_refresh", "process_restart"]);
});
