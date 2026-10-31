import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  cacheBatches,
  CacheIntent,
  fillNativeCache,
} from "./cache_fill_direct.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import type { GatewayOperation, Result } from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const artifact = (key: string) => ({
  manager: "npm",
  package: `package-${key}`,
  version: "1.0.0",
  url: "https://registry.npmjs.org/test/-/test-1.0.0.tgz",
  integrity: null,
  key,
});
const input = (keys = ["verified", "refused"]) =>
  CacheIntent.parse({
    agent_sha256: "a".repeat(64),
    artifacts: keys.map(artifact),
    conflicts: {},
    max_bytes: 100000,
  });
const digest = `sha256-${"b".repeat(64)}`;
class Cache {
  projections: Result[] = [];
  actions: NodeCall[] = [];
  cancelled = false;
  secret = "private-fixture-sentinel";
  refuse = false;
  lose = false;
  action = (call: NodeCall) => {
    this.actions.push(call);
    if (this.lose) return Promise.reject(new Error("Lost reply"));
    return Promise.resolve(
      this.refuse ? { error_code: "task_vm.package_cache_failed" } : {
        hashes: { verified: digest },
        failures: { refused: `HTTP 429 ${this.secret}` },
      },
    );
  };
  gateway: Pick<GatewayOperation, "read" | "project" | "withPrivateMaterial"> =
    {
      read: () => Promise.resolve({ cancel_requested: this.cancelled }),
      project: (result) => {
        this.projections.push(result);
        return Promise.resolve({});
      },
      withPrivateMaterial: async (use) =>
        await use({ github_token: this.secret }),
    };
}
Deno.test("native cache partition preserves every artifact and bounds worst-case batch projections", () => {
  const admitted = input(
    Array.from({ length: 1800 }, (_, i) => String(i).padStart(64, "a")),
  );
  const batches = cacheBatches(admitted);
  assertEquals(batches.length > 1, true);
  assertEquals(batches.flatMap((b) => b.artifacts), admitted.artifacts);
  for (const batch of batches) {
    const names = batch.artifacts.map((a) => a.key);
    const worst = {
      archive_ids: [],
      archive_digests: [],
      failed_ids: names,
      failure_reasons: names.map(() => "\\".repeat(160)),
    };
    assertEquals(
      new TextEncoder().encode(JSON.stringify(worst)).length < 256 * 1024,
      true,
    );
  }
  assertThrows(() =>
    cacheBatches({
      ...admitted,
      conflicts: { [admitted.artifacts[0].key]: "IntegrityConflict" },
    })
  );
  assertThrows(() =>
    cacheBatches({
      ...admitted,
      artifacts: [admitted.artifacts[0], admitted.artifacts[0]],
    })
  );
});
Deno.test("native fill uses pinned sudo stdin and projects partial verified hashes with private bytes removed", async () => {
  const f = new Cache();
  assertEquals(await fillNativeCache(input(), f.gateway, f.action), {
    error_code: "task_vm.package_cache_failed",
  });
  const call = f.actions[0];
  assertEquals([call.name, call.pin, call.sudo, call.mutates], [
    "package-cache.host",
    "a".repeat(64),
    true,
    true,
  ]);
  assertEquals(call.input.github_token, f.secret);
  assertEquals(call.input.operation, "fill");
  assertEquals(f.projections[0].result, {
    replace_inventory: true,
    archive_ids: ["verified"],
    archive_digests: [digest],
    failed_ids: ["refused"],
    failure_reasons: ["HTTP 429 [REDACTED]"],
  });
  assertEquals(JSON.stringify(f.projections).includes(f.secret), false);
});
Deno.test("an uncertain fill reply projects no archive evidence and is left for native recovery", async () => {
  const f = new Cache();
  f.lose = true;
  await assertRejects(() => fillNativeCache(input(), f.gateway, f.action));
  assertEquals(f.projections, []);
});
Deno.test("cancel before the next batch records no new mutation", async () => {
  const f = new Cache();
  f.cancelled = true;
  await assertRejects(
    () => fillNativeCache(input(), f.gateway, f.action),
    DirectOperationCancelled,
  );
  assertEquals(f.actions, []);
});
Deno.test("cache inventory refuses missing hashes, extra keys, overlap and unbounded reasons", async () => {
  for (
    const raw of [
      { hashes: {}, failures: {} },
      {
        hashes: { verified: digest, foreign: digest },
        failures: { refused: "HTTP 429" },
      },
      {
        hashes: { verified: digest },
        failures: { verified: "Overlap", refused: "HTTP 429" },
      },
      ...["", "x".repeat(161), "HTTP 429\nsecret"].map((reason) => ({
        hashes: { verified: digest },
        failures: { refused: reason },
      })),
    ]
  ) {
    const f = new Cache();
    await assertRejects(() =>
      fillNativeCache(input(), f.gateway, () => Promise.resolve(raw))
    );
    assertEquals(f.projections, []);
  }
});
Deno.test("one refused batch does not stop later batches or leak diagnostic output", async () => {
  const f = new Cache();
  f.refuse = true;
  const admitted = input(
    Array.from({ length: 1800 }, (_, i) => String(i).padStart(64, "a")),
  );
  assertEquals(await fillNativeCache(admitted, f.gateway, f.action), {
    error_code: "task_vm.package_cache_failed",
  });
  assertEquals(f.projections.length, cacheBatches(admitted).length);
  assertEquals(f.actions.length, f.projections.length);
  assertEquals(
    f.projections.every((p) =>
      (p.result as Result).failure_reasons instanceof Array
    ),
    true,
  );
});
Deno.test("conflicts remain failed without downloading them and PHP empty maps are accepted", async () => {
  const f = new Cache();
  const admitted = CacheIntent.parse({
    ...input([]),
    conflicts: { conflict: "IntegrityConflict" },
  });
  const action = (call: NodeCall) => {
    assertEquals(call.input.artifacts, []);
    return Promise.resolve({ hashes: [], failures: [] });
  };
  assertEquals(await fillNativeCache(admitted, f.gateway, action), {
    error_code: "task_vm.package_cache_failed",
  });
  assertEquals((f.projections[0].result as Result).failed_ids, ["conflict"]);
  assertEquals(CacheIntent.parse({ ...input(), conflicts: [] }).conflicts, {});
  for (const conflicts of [["bad"], { conflict: 1 }]) {
    assertEquals(
      CacheIntent.safeParse({ ...input(), conflicts }).success,
      false,
    );
  }
});

Deno.test("cache result budget includes Gateway Unicode and slash escaping", () => {
  const admitted = input(
    Array.from({ length: 1000 }, (_, i) => "é/".repeat(200) + i),
  );
  for (const batch of cacheBatches(admitted)) {
    const names = batch.artifacts.map((a) => a.key);
    const result = {
      archive_ids: [],
      archive_digests: [],
      failed_ids: names,
      failure_reasons: names.map(() => "\\".repeat(160)),
    };
    const phpWire = JSON.stringify(result).replaceAll("/", "\\/").split("")
      .map((c) =>
        c.charCodeAt(0) > 127
          ? "\\u" + c.charCodeAt(0).toString(16).padStart(4, "0")
          : c
      ).join("");
    assertEquals(phpWire.length < 256 * 1024, true);
  }
});

Deno.test("all fill batches share the original native run deadline", async () => {
  const f = new Cache();
  let now = 0;
  const deadlines: number[] = [];
  const admitted = input(
    Array.from({ length: 1800 }, (_, i) => String(i).padStart(64, "a")),
  );
  const action = (call: NodeCall) => {
    deadlines.push(call.deadlineMs);
    now += 1_000_000;
    const artifacts = call.input.artifacts as { key: string }[];
    return Promise.resolve({
      hashes: Object.fromEntries(artifacts.map((a) => [a.key, digest])),
      failures: {},
    });
  };
  assertEquals(
    await fillNativeCache(admitted, f.gateway, action, undefined, {
      deadline: 1_700_000,
      now: () => now,
    }),
    { error_code: "task_vm.package_cache_failed" },
  );
  assertEquals(deadlines, [1_700_000, 700_000]);
});
Deno.test("expiry during credential hydration prevents a new batch effect", async () => {
  const f = new Cache();
  let now = 0;
  f.gateway.withPrivateMaterial = async (use) => {
    now = 1700;
    await use({ github_token: f.secret });
  };
  assertEquals(
    await fillNativeCache(input(), f.gateway, f.action, undefined, {
      deadline: 1700,
      now: () => now,
    }),
    { error_code: "task_vm.package_cache_failed" },
  );
  assertEquals(f.actions, []);
  assertEquals(f.projections, []);
});
Deno.test("non-null lockfile checksums reach the canonical agent unchanged and refused archives are not projected", async () => {
  const f = new Cache();
  const admitted = input();
  admitted.artifacts[0].integrity = "sha512-" + btoa("a".repeat(64));
  const action = (call: NodeCall) => {
    assertEquals(call.input.artifacts, admitted.artifacts);
    return Promise.resolve({
      hashes: {},
      failures: {
        verified: "Archive checksum mismatch",
        refused: "FillRefused",
      },
    });
  };
  assertEquals(await fillNativeCache(admitted, f.gateway, action), {
    error_code: "task_vm.package_cache_failed",
  });
  const result = f.projections[0].result as Result;
  assertEquals(result.archive_ids, []);
  assertEquals(result.failed_ids, ["verified", "refused"]);
  assertEquals(result.failure_reasons, [
    "Archive checksum mismatch",
    "FillRefused",
  ]);
});
