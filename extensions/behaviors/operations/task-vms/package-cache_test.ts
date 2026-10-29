import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { CacheMountInput, preparePackageCache } from "./package-cache.ts";
import type { FamilyEffects } from "./runtime.ts";

const input = CacheMountInput.parse({
  task_vm_id: 5,
  project_id: 9,
  host_node_id: 3,
  guest: { project: "orbit-tasks", name: "tvm-5" },
  artifacts_json: '{"artifacts":[]}',
  snapshot_digest: "a".repeat(64),
  deadline_at: 4_000_000_000,
});

class Effects implements FamilyEffects {
  keys: string[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  refuse: string | null = null;
  child(
    key: string,
    _kind: string,
    node: number,
    data: Record<string, unknown>,
  ) {
    assertEquals(node, 3);
    if (key === this.refuse) {
      return Promise.reject(
        new ExecutorError("task_vm.package_cache_mount_invalid"),
      );
    }
    if (!this.receipts.has(key)) {
      this.keys.push(key);
      if (key === "cache-seed") {
        assertEquals(data, {
          guest: input.guest,
          parameters_json: input.artifacts_json,
        });
      }
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

Deno.test("cache preparation initializes the host and proves the mount before guest seeding", async () => {
  const effects = new Effects();
  assertEquals(await preparePackageCache(input, effects), { ready: true });
  assertEquals(effects.keys, ["cache-initialize", "cache-mount", "cache-seed"]);
});

Deno.test("a failed cache mount never seeds the guest and resumes from its existing child identities", async () => {
  const effects = new Effects();
  effects.refuse = "cache-mount";
  await assertRejects(
    () => preparePackageCache(input, effects),
    ExecutorError,
    "task_vm.package_cache_mount_invalid",
  );
  assertEquals(effects.keys, ["cache-initialize"]);
  effects.refuse = null;
  await preparePackageCache(input, effects);
  assertEquals(effects.keys, ["cache-initialize", "cache-mount", "cache-seed"]);
});
