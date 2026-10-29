import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { type FamilyEffects, prepareRuntime, RuntimeInput } from "./runtime.ts";

const input = () =>
  RuntimeInput.parse({
    task_vm_id: 5,
    process_id: 27,
    node_id: 12,
    user: "orbit",
    origin: "http://10.44.0.3:8317",
    sha256: "a".repeat(64),
    models: ["test-model"],
    artifact: "/sandbox-artifacts/pi-server",
    deadline_at: 4_000_000_000,
    model_key: { $secret: "task_vm.model_key", id: 5 },
    token: { $secret: "task_vm.pi_token", id: 5 },
  });
class Effects implements FamilyEffects {
  children: {
    key: string;
    kind: string;
    node: number;
    input: Record<string, unknown>;
  }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  current = true;
  responds = true;
  refuse: string | null = null;
  lose: string | null = null;
  child(
    key: string,
    kind: string,
    node: number,
    input: Record<string, unknown>,
  ) {
    if (!this.receipts.has(key)) {
      this.children.push({ key, kind, node, input });
      if (this.refuse === key) {
        return Promise.reject(new Error("task_vm.runtime_failed"));
      }
      this.receipts.set(key, { ready: true });
    }
    if (this.lose === key) {
      this.lose = null;
      return Promise.reject(new Error("Lost child reply"));
    }
    return Promise.resolve(this.receipts.get(key)!);
  }
  read() {
    return Promise.resolve({
      pi_current: this.current,
      responds: this.responds,
    });
  }
  wait() {
    this.responds = true;
    return Promise.resolve();
  }
}

Deno.test("runtime prepares its owned key, configuration and Process without uploading a current Pi artifact", async () => {
  const effects = new Effects();
  assertEquals(await prepareRuntime(input(), effects), { ready: true });
  assertEquals(effects.children.map((entry) => entry.key), [
    "model-key",
    "pi-configure",
    "pi-process-owner",
    "runtime-confirmed",
  ]);
  assertEquals(effects.children.every((entry) => entry.node === 12), true);
  assertEquals(effects.children[2], {
    key: "pi-process-owner",
    kind: "process.create",
    node: 12,
    input: { process_id: 27 },
  });
  assertEquals(effects.children[1].input.model_key, {
    $secret: "task_vm.model_key",
    id: 5,
  });
});

Deno.test("an absent Pi artifact is uploaded and installed at its admitted pin before configuration", async () => {
  const effects = new Effects();
  effects.current = false;
  effects.responds = false;
  await prepareRuntime(input(), effects);
  assertEquals(effects.children.map((entry) => entry.key), [
    "model-key",
    "pi-upload",
    "pi-install",
    "pi-configure",
    "pi-process-owner",
    "runtime-confirmed",
  ]);
  assertEquals(effects.children[2].input.sha256, "a".repeat(64));
});

Deno.test("a refused model key or configuration creates no Process", async () => {
  for (const refuse of ["model-key", "pi-configure"]) {
    const effects = new Effects();
    effects.refuse = refuse;
    await assertRejects(
      () => prepareRuntime(input(), effects),
      Error,
      "task_vm.runtime_failed",
    );
    assertEquals(
      effects.children.some((entry) => entry.key === "pi-process-owner"),
      false,
    );
  }
});

Deno.test("runtime restart after uncertain child completion reuses every earlier effect key", async () => {
  for (
    const lose of [
      "model-key",
      "pi-configure",
      "pi-process-owner",
      "runtime-confirmed",
    ]
  ) {
    const effects = new Effects();
    effects.lose = lose;
    await assertRejects(() => prepareRuntime(input(), effects));
    assertEquals(await prepareRuntime(input(), effects), { ready: true });
    assertEquals(
      new Set(effects.children.map((entry) => entry.key)).size,
      effects.children.length,
    );
  }
});

Deno.test("runtime refuses a credential reference owned by another VM or a literal credential", () => {
  const value = input();
  assertThrows(() =>
    RuntimeInput.parse({
      ...value,
      token: { $secret: "task_vm.pi_token", id: 6 },
    })
  );
  assertThrows(() =>
    RuntimeInput.parse({ ...value, model_key: "literal-secret" })
  );
});

Deno.test("a refused owning Process prevents runtime readiness", async () => {
  const effects = new Effects();
  effects.refuse = "pi-process-owner";
  await assertRejects(
    () => prepareRuntime(input(), effects),
    Error,
    "task_vm.runtime_failed",
  );
  assertEquals(
    effects.children.some((entry) => entry.key === "runtime-confirmed"),
    false,
  );
});
