import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { CacheFillInput, fillPackageCache } from "./package-cache.ts";
import { ChildFailed, FamilyWaiting } from "./driver.ts";
import type { FamilyEffects } from "./runtime.ts";

class Effects implements FamilyEffects {
  keys: string[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  child(
    key: string,
    _kind: string,
    node: number,
    _data: Record<string, unknown>,
  ) {
    assertEquals(node, 3);
    if (!this.receipts.has(key)) {
      this.keys.push(key);
      this.receipts.set(key, { ready: true });
    }
    return Promise.resolve(this.receipts.get(key)!);
  }
  read() {
    return Promise.resolve({});
  }
  wait() {
    return Promise.resolve();
  }
}

const fillInput = CacheFillInput.parse({
  host_node_id: 3,
  deadline_at: 4_000_000_000,
  batches: [{ artifacts: [], conflicts: {} }, { artifacts: [], conflicts: {} }],
});

Deno.test("Gateway empty conflict maps reach the cache batch children", async () => {
  const admitted = CacheFillInput.parse({
    host_node_id: 3,
    deadline_at: 4_000_000_000,
    batches: [{ artifacts: [], conflicts: [] }],
  });
  const effects = new Effects();
  assertEquals(await fillPackageCache(admitted, effects), {
    ready: true,
    skipped: [],
  });
  assertEquals(effects.keys, ["fill-0"]);
  assertEquals(JSON.stringify(admitted.batches[0].conflicts), "[]");
});

Deno.test("cache conflict maps refuse nonempty arrays and nonstring reasons", () => {
  for (const conflicts of [["invalid"], [{}], { archive: 1 }]) {
    assertEquals(
      CacheFillInput.safeParse({
        host_node_id: 3,
        deadline_at: 4_000_000_000,
        batches: [{ artifacts: [], conflicts }],
      }).success,
      false,
    );
  }
});

Deno.test("host cache fill settles bounded batches with stable child keys and a small parent result", async () => {
  const effects = new Effects();
  assertEquals(await fillPackageCache(fillInput, effects), {
    ready: true,
    skipped: [],
  });
  assertEquals(effects.keys, ["fill-0", "fill-1"]);
  await fillPackageCache(fillInput, effects);
  assertEquals(effects.keys, ["fill-0", "fill-1"]);
});

Deno.test("one terminal fill failure does not prevent the remaining batches; Gateway derives skipped", async () => {
  const effects = new Effects();
  const child = effects.child.bind(effects);
  effects.child = async (key, ...args) => {
    if (key === "fill-0") throw new ChildFailed("task_vm.package_cache_failed");
    return await child(key, ...args);
  };
  await assertRejects(
    () => fillPackageCache(fillInput, effects),
    ExecutorError,
    "task_vm.package_cache_failed",
  );
  assertEquals(effects.keys, ["fill-1"]);
});

Deno.test("pending or uncertain cache children yield without admitting the next batch", async () => {
  for (
    const error of [
      new FamilyWaiting(),
      new ExecutorError("operation.outcome_unknown"),
      new ExecutorError("operation.lease_lost"),
    ]
  ) {
    const effects = new Effects();
    effects.child = () => Promise.reject(error);
    await assertRejects(
      () => fillPackageCache(fillInput, effects),
      ExecutorError,
      error.code,
    );
    assertEquals(effects.keys, []);
  }
});
