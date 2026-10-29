import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { buildUpCloudImage, UpCloudImageInput } from "./upcloud-images.ts";
import type { FamilyEffects } from "./runtime.ts";

const input = () =>
  UpCloudImageInput.parse({
    build_id: 7,
    node_id: 2,
    identity: `sha256:${"a".repeat(64)}`,
    generation: 1,
    stamp: "20261028123000",
    name: "tvm-image-20261028123000",
    source: "source-template",
    deadline_at: 4_000_000_000,
    recipe: "{}",
    recipe_json: "{}",
    pi_artifact: null,
  });
class Effects implements FamilyEffects {
  stages = new Map<string, unknown>();
  async stage<T>(key: string, work: () => Promise<T>): Promise<T> {
    if (this.stages.has(key)) return this.stages.get(key) as T;
    const result = await work();
    this.stages.set(key, result);
    return result;
  }

  calls: { key: string; kind: string; input: Record<string, unknown> }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  phases = new Map<string, string>();
  power: string | null = "started";
  smokePower: string | null = "started";
  refuse: string | null = null;
  lose: string | null = null;
  async child(
    key: string,
    kind: string,
    _node: number,
    data: Record<string, unknown>,
  ) {
    await Promise.resolve();
    if (!this.receipts.has(key)) {
      this.calls.push({ key, kind, input: data });
      if (key === this.refuse) throw new Error("refused");
      if (key.endsWith("-start")) {
        const params = JSON.parse(String(data.parameters_json));
        assertEquals(params.key.execution, "upcloud-7");
        assertEquals(params.boot_id, "boot-1");
        this.phases.set(params.key.phase, "complete");
      }
      if (key === "launch-builder") this.power = "started";
      if (key === "launch-smoke") this.smokePower = "started";
      if (key === "stop-builder" || key.endsWith("hard-stop-builder")) {
        this.power = "stopped";
      }
      if (key.endsWith("hard-stop-smoke")) this.smokePower = "stopped";
      if (key.endsWith("delete-builder")) this.power = null;
      if (key.endsWith("delete-smoke")) this.smokePower = null;
      this.receipts.set(key, { ready: true, template_uuid: "new-template" });
    }
    if (this.lose === key) {
      this.lose = null;
      throw new Error("lost reply");
    }
    return this.receipts.get(key)!;
  }
  read(view: string): Promise<Record<string, unknown>> {
    if (view.includes("phase-")) {
      return Promise.resolve({
        state: this.phases.get(view.split("phase-")[1]) ?? "missing",
        boot_id: "boot-1",
      });
    }
    if (view.endsWith("power")) {
      return Promise.resolve({
        state: view.includes("smoke") ? this.smokePower : this.power,
        address_ready: true,
      });
    }
    return Promise.resolve({
      ready: true,
      template_ready: true,
      prune_templates: ["old-template"],
    });
  }
  wait() {
    return Promise.resolve();
  }
}
Deno.test("UpCloud builds and smoke-verifies the firewall before promotion, then prunes", async () => {
  const effects = new Effects();
  assertEquals(await buildUpCloudImage(input(), effects), {
    ready: true,
    template_uuid: "new-template",
  });
  const keys = effects.calls.map((call) => call.key);
  assertEquals(keys.indexOf("firewall") < keys.indexOf("clean"), true);
  assertEquals(keys.indexOf("smoke-check") < keys.indexOf("promote"), true);
  assertEquals(keys.indexOf("smoke-verify") < keys.indexOf("promote"), true);
  assertEquals(keys.at(-1), "prune-old-template");
  assertEquals(
    effects.calls.find((call) => call.key === "launch-builder")!.input
      .bootstrap,
    { $secret: "task_vm.image_bootstrap", id: 7 },
  );
});
Deno.test("failed UpCloud smoke never promotes and cleans only this attempt", async () => {
  const effects = new Effects();
  effects.refuse = "smoke-check";
  await assertRejects(() => buildUpCloudImage(input(), effects));
  assertEquals(effects.calls.some((call) => call.key === "promote"), false);
  assertEquals(effects.calls.slice(-5).map((call) => call.key), [
    "cleanup-hard-stop-builder",
    "cleanup-delete-builder",
    "cleanup-hard-stop-smoke",
    "cleanup-delete-smoke",
    "cleanup-discard-template",
  ]);
});
Deno.test("uncertain UpCloud replies resume the same children instead of cleanup", async () => {
  for (const lose of ["launch-builder", "templatize", "promote"]) {
    const effects = new Effects();
    const child = effects.child.bind(effects);
    let lost = false;
    effects.child = async (...args) => {
      const receipt = await child(...args);
      if (args[0] === lose && !lost) {
        lost = true;
        const { ExecutorError } = await import("../../operation-runner/mod.ts");
        throw new ExecutorError("operation.outcome_unknown");
      }
      return receipt;
    };
    await assertRejects(() => buildUpCloudImage(input(), effects));
    await buildUpCloudImage(input(), effects);
    const keys = effects.calls.map((call) => call.key);
    assertEquals(new Set(keys).size, keys.length);
    assertEquals(keys.some((key) => key.startsWith("cleanup-")), false);
  }
});
Deno.test("an unknown UpCloud phase refuses without starting or promoting it", async () => {
  const effects = new Effects();
  effects.phases.set("apt-source", "unknown");
  await assertRejects(() => buildUpCloudImage(input(), effects));
  assertEquals(
    effects.calls.some((call) =>
      call.key === "apt-source-start" || call.key === "promote"
    ),
    false,
  );
});

Deno.test("image failure cleanup yields until its admitted template deletion settles", async () => {
  const effects = new Effects();
  effects.refuse = "smoke-check";
  const child = effects.child.bind(effects);
  effects.child = (key, kind, node, data) => {
    if (key === "cleanup-discard-template") {
      return Promise.reject(new ExecutorError("operation.waiting"));
    }
    return child(key, kind, node, data);
  };
  await assertRejects(
    () => buildUpCloudImage(input(), effects),
    ExecutorError,
    "operation.waiting",
  );
});
