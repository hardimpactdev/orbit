import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  cacheBatches,
  cacheFillDirect,
  CacheIntent,
  fillNativeCache,
} from "./cache_fill_direct.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import type { GatewayOperation, Result } from "../_lib/direct.ts";
import type { CacheFill } from "./cache_host.ts";

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
    artifacts: keys.map(artifact),
    conflicts: {},
    max_bytes: 100000,
  });
const digest = `sha256-${"b".repeat(64)}`;
class Cache {
  projections: Result[] = [];
  actions: CacheFill[] = [];
  cancelled = false;
  secret = "private-fixture-sentinel";
  refuse = false;
  lose = false;
  action = (call: CacheFill) => {
    this.actions.push(call);
    if (this.lose) return Promise.reject(new Error("Lost reply"));
    return Promise.resolve(
      this.refuse
        ? {
          hashes: {},
          failures: Object.fromEntries(
            call.artifacts.map((a) => [a.key, "FillRefused"]),
          ),
        }
        : {
          hashes: { verified: digest },
          failures: { refused: `HTTP 429 ${this.secret}` },
        },
    );
  };
  gateway: Pick<GatewayOperation, "read" | "project" | "withPrivateMaterial"> =
    {
      read: () =>
        Promise.resolve({
          status: "running",
          cancel_requested: this.cancelled,
        }),
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
Deno.test("native fill keeps credentials callback-local and projects partial verified hashes with private bytes removed", async () => {
  const f = new Cache();
  assertEquals(await fillNativeCache(input(), f.gateway, f.action), {
    error_code: "task_vm.package_cache_failed",
  });
  const call = f.actions[0];
  assertEquals(call.github_token, f.secret);
  assertEquals(call.artifacts, input().artifacts);
  assertEquals(f.projections[0].result, {
    replace_inventory: true,
    archive_ids: ["verified"],
    archive_digests: [digest],
    failed_ids: ["refused"],
    failure_reasons: ["HTTP 429 [REDACTED]"],
  });
  assertEquals(JSON.stringify(f.projections).includes(f.secret), false);
});
Deno.test("an uncertain fill reply never invents archive evidence", async () => {
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
  const action = (call: CacheFill) => {
    assertEquals(call.artifacts, []);
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

Deno.test("cache batches share one overall fill deadline", async () => {
  const f = new Cache();
  const admitted = input(
    Array.from({ length: 1800 }, (_, i) => String(i).padStart(64, "a")),
  );
  let now = 0;
  await assertRejects(
    () =>
      fillNativeCache(
        admitted,
        f.gateway,
        (call) => {
          f.actions.push(call);
          now += 900_000;
          return Promise.resolve({
            hashes: {},
            failures: Object.fromEntries(
              (call.artifacts as { key: string }[]).map(
                (a) => [a.key, "FillRefused"],
              ),
            ),
          });
        },
        undefined,
        () => now,
      ),
    Error,
    "Package cache fill deadline expired",
  );
  assertEquals(f.actions.map((call) => call.deadline_ms), [1_700_000, 800_000]);
  assertEquals(f.projections.length, 1);
});

Deno.test("a thrown native fill error cannot put its GitHub token into native run data and settles failed", async () => {
  const token = "github-secret-do-not-persist";
  const projections: Record<string, unknown>[] = [];
  const args = {
    operation_id: 7,
    node_id: 13,
    kind: "task_vm.fill_package_cache" as const,
    run_id: "3fc3ad54-423a-4b4b-8187-99514098426b",
  };
  const model = cacheFillDirect((_ctx) => (method, path, body) => {
    if (method === "POST") {
      projections.push(body as Record<string, unknown>);
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material") ? { github_token: token } : {
          ...args,
          id: 7,
          status: "queued",
          cancel_requested: false,
          input: input(),
          result: null,
        },
      },
    });
  }, () => Promise.reject(new Error(token)));
  const error = await assertRejects(
    () =>
      model.methods.execute.execute(args, {
        globalArgs: {
          gatewayUrl: "https://gateway.test",
          caFile: "/tmp/root.crt",
        },
      }),
    Error,
    "task_vm.package_cache_failed",
  );
  assertEquals(projections.map((p) => p.status), ["running", "failed"]);
  assertEquals(
    JSON.stringify({ projections, error: String(error) }).includes(token),
    false,
  );
  assertEquals(projections.at(-1)?.result, {
    error_code: "task_vm.package_cache_failed",
  });
});

Deno.test("fill forwards lock integrity to the native archive verifier and never records a mismatch as an archive", async () => {
  const f = new Cache();
  const integrity = "sha512-" + btoa("a".repeat(64));
  const admitted = input(["verified"]);
  admitted.artifacts[0].integrity = integrity;
  assertEquals(
    await fillNativeCache(admitted, f.gateway, (call) => {
      assertEquals(
        (call.artifacts as { integrity: string }[])[0].integrity,
        integrity,
      );
      return Promise.resolve({
        hashes: {},
        failures: { verified: "Archive checksum mismatch" },
      });
    }),
    { error_code: "task_vm.package_cache_failed" },
  );
  assertEquals(f.projections[0].result, {
    replace_inventory: true,
    archive_ids: [],
    archive_digests: [],
    failed_ids: ["verified"],
    failure_reasons: ["Archive checksum mismatch"],
  });
});

Deno.test("an ended Operation starts no further cache batch", async () => {
  const f = new Cache();
  f.gateway.read = () =>
    Promise.resolve({ status: "failed", cancel_requested: false });
  await assertRejects(
    () => fillNativeCache(input(), f.gateway, f.action),
    Error,
    "already ended",
  );
  assertEquals(f.actions, []);
  assertEquals(f.projections, []);
});
