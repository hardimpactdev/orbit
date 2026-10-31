import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { maintenance } from "./maintenance.ts";

class MaintenanceFixture {
  resources = new Map<string, Record<string, unknown>>();
  calls: { method: string; path: string; body: unknown; key?: string }[] = [];
  statuses = new Map<number, string>();
  loseAdmission = false;
  failPr = false;
  dnsDigest = "a".repeat(64);
  prWriter = "direct";
  baseRevision = "b".repeat(40);
  recipeRevision = "b".repeat(64);
  servingRevision = "c".repeat(64);
  pulls = [] as {
    number: number;
    variant: string;
    head_sha: string;
    checks: string;
    checks_revision: string;
    policy_revision: string;
  }[];
  recipeIntents = new Map<string, number>();
  targets = [{
    node_id: 3,
    window: true,
    attempts: 0,
    running: false,
    finished_at: null as string | null,
  }];
  recipe = {
    status: "ready",
    build_id: 1,
    node_id: 2,
    recipe: "sha256:recipe",
  };
  context(): Context {
    const { context } = createModelTestContext();
    return Object.assign(context, {
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: (
        _spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        this.resources.set(name, structuredClone(value));
        return Promise.resolve({ name });
      },
    }) as unknown as Context;
  }
  http: Http = async (method, path, body, key) => {
    await Promise.resolve();
    this.calls.push({ method, path, body, key });
    let data: unknown = {};
    if (path.endsWith("/peer-dns/plan")) {
      data = [{ node_id: 2, configuration_digest: this.dnsDigest }];
    } else if (path.endsWith("/recipe-pr/plan")) {
      if (this.failPr) throw new Error("fleet.github_unavailable");
      data = {
        status: "ready",
        writer: this.prWriter,
        recipe_revision: this.recipeRevision,
        base_revision: this.baseRevision,
        serving_recipe_revision: this.servingRevision,
        date: "20261028",
        pulls: this.pulls,
      };
    } else if (path.endsWith("/recipe/plan")) data = this.recipe;
    else if (path.endsWith("/rollout/plan")) {
      data = {
        status: "running",
        rollout: 5,
        targets: this.targets,
      };
    } else if (path.includes("/operations/")) {
      data = {
        status: this.statuses.get(Number(path.split("/").at(-1))) ?? "running",
      };
    } else if (method === "POST") {
      data = { id: this.calls.length };
      if (path === "/api/v1/fleet/recipe-pr") {
        if (!key) throw new Error("Missing Gateway intent identity");
        if (!this.recipeIntents.has(key)) {
          this.recipeIntents.set(key, this.calls.length);
        }
        data = { id: this.recipeIntents.get(key) };
      }
      if (this.loseAdmission) {
        this.loseAdmission = false;
        throw new Error("lost admission reply");
      }
    }
    return { status: 200, body: { data } };
  };
  tick(at = "2026-10-28T02:00:00Z") {
    return maintenance({ tick: at }, this.context(), this.http, new Date(at));
  }
  writes(path: string) {
    return this.calls.filter((c) => c.method === "POST" && c.path === path);
  }
}
Deno.test("lost admission retains the original path, payload and key on the next pass", async () => {
  const f = new MaintenanceFixture();
  f.loseAdmission = true;
  assertEquals((await f.tick()).status, "partial");
  await f.tick("2026-10-28T02:05:00Z");
  const writes = f.writes("/api/v1/fleet/nodes/2/peer-dns");
  assertEquals(writes.length, 2);
  assertEquals(writes[0].key, writes[1].key);
  assertEquals(writes[0].body, writes[1].body);
});
Deno.test("a failed PR read does not block ordered recipe or rollout admissions", async () => {
  const f = new MaintenanceFixture();
  f.failPr = true;
  assertEquals(await f.tick(), {
    status: "partial",
    errors: ["fleet.github_unavailable"],
  });
  assertEquals(f.writes("/api/v1/fleet/nodes/2/recipe").length, 1);
  assertEquals(f.writes("/api/v1/fleet/nodes/3/rollout").length, 1);
});
Deno.test("snapshot opens start at 03:00 Amsterdam in winter and summer", async () => {
  for (
    const [before, start] of [
      ["2026-10-28T01:55:00Z", "2026-10-28T02:00:00Z"],
      ["2026-07-01T00:55:00Z", "2026-07-01T01:00:00Z"],
    ]
  ) {
    const f = new MaintenanceFixture();
    await f.tick(before);
    assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 0);
    await f.tick(start);
    assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 2);
  }
});
Deno.test("daily opens use Gateway intent idempotency and retain no Swamp admission receipt", async () => {
  const f = new MaintenanceFixture();
  await f.tick();
  for (const value of f.resources.values()) {
    if (typeof value.id === "number") f.statuses.set(value.id, "succeeded");
  }
  await f.tick("2026-10-28T02:05:00Z");
  const requests = f.writes("/api/v1/fleet/recipe-pr");
  assertEquals(requests.length, 4);
  assertEquals(requests[0].key, requests[2].key);
  assertEquals(requests[1].key, requests[3].key);
  assertEquals(
    [...f.resources.values()].some((resource) =>
      resource.path === "/api/v1/fleet/recipe-pr"
    ),
    false,
  );
  assertEquals(f.writes("/api/v1/fleet/nodes/2/recipe").length, 1);
  assertEquals(f.writes("/api/v1/fleet/nodes/3/rollout").length, 2);
});

Deno.test("catch-up visits retain five-minute spacing", async () => {
  const f = new MaintenanceFixture();
  f.targets[0].attempts = 1;
  f.targets[0].finished_at = "2026-10-28T02:00:00Z";
  await f.tick("2026-10-28T02:04:00Z");
  assertEquals(f.writes("/api/v1/fleet/nodes/3/rollout").length, 0);
  await f.tick("2026-10-28T02:05:00Z");
  assertEquals(f.writes("/api/v1/fleet/nodes/3/rollout").length, 1);
});

Deno.test("unchanged peer DNS admits exactly 24 hourly Operations across a full day", async () => {
  const f = new MaintenanceFixture();
  for (let minute = 0; minute < 1440; minute++) {
    await f.tick(new Date(Date.UTC(2026, 9, 30, 0, minute)).toISOString());
    for (const value of f.resources.values()) {
      if (typeof value.id === "number") f.statuses.set(value.id, "succeeded");
    }
  }
  assertEquals(f.writes("/api/v1/fleet/nodes/2/peer-dns").length, 24);
});

Deno.test("a changed policy admits in the same hour, but waits for active work", async () => {
  const f = new MaintenanceFixture();
  const path = "/api/v1/fleet/nodes/2/peer-dns";
  await f.tick();
  f.dnsDigest = "b".repeat(64);
  await f.tick("2026-10-28T02:01:00Z");
  assertEquals(f.writes(path).length, 1);
  f.statuses.set(f.resources.get("dns-2")!.id as number, "succeeded");
  await f.tick("2026-10-28T02:02:00Z");
  assertEquals(f.writes(path).length, 2);
  f.statuses.set(f.resources.get("dns-2")!.id as number, "succeeded");
  await f.tick("2026-10-28T02:03:00Z");
  assertEquals(f.writes(path).length, 2);
});

Deno.test("a lost peer DNS admission is replayed across hourly and policy changes", async () => {
  const f = new MaintenanceFixture();
  const path = "/api/v1/fleet/nodes/2/peer-dns";
  f.loseAdmission = true;
  await f.tick("2026-10-28T02:59:00Z");
  f.dnsDigest = "b".repeat(64);
  await f.tick("2026-10-28T03:00:00Z");
  const writes = f.writes(path);
  assertEquals(writes.length, 2);
  assertEquals(writes[0].key, writes[1].key);
  f.statuses.set(f.resources.get("dns-2")!.id as number, "succeeded");
  await f.tick("2026-10-28T03:01:00Z");
  assertEquals(f.writes(path).length, 3);
});

Deno.test("failed hourly work retries with a fresh key; success does not", async () => {
  const f = new MaintenanceFixture();
  const path = "/api/v1/fleet/nodes/2/peer-dns";
  await f.tick();
  f.statuses.set(f.resources.get("dns-2")!.id as number, "failed");
  await f.tick("2026-10-28T02:01:00Z");
  const writes = f.writes(path);
  assertEquals(writes.length, 2);
  assertEquals(writes[0].key === writes[1].key, false);
});

Deno.test("direct recipe maintenance queries only every five minutes and waits for the owning writer", async () => {
  const f = new MaintenanceFixture();
  f.prWriter = "polled";
  await f.tick("2026-10-28T02:00:00Z");
  assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 0);
  const before =
    f.calls.filter((call) => call.path.endsWith("/recipe-pr/plan")).length;
  f.prWriter = "direct";
  await f.tick("2026-10-28T02:01:00Z");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/recipe-pr/plan")).length,
    before,
  );
  await f.tick("2026-10-28T02:05:00Z");
  assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 2);
});

const candidate = (checks = "passed") => ({
  number: 1684,
  variant: "snapshot",
  head_sha: "d".repeat(40),
  checks,
  checks_revision: "a".repeat(64),
  policy_revision: "b".repeat(64),
});
Deno.test("one hour of unchanged PR evidence creates only retire and merge intents", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate()];
  for (let minute = 0; minute < 60; minute += 5) {
    await f.tick(new Date(Date.UTC(2026, 9, 28, 0, minute)).toISOString());
  }
  assertEquals(f.recipeIntents.size, 2);
  assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 8);
  assertEquals(
    [...f.recipeIntents.keys()].every((key) => key.length <= 128),
    true,
  );
  assertEquals(
    [...f.resources.values()].some((value) =>
      value.path === "/api/v1/fleet/recipe-pr"
    ),
    false,
  );
});
Deno.test("pending snapshot checks do not admit merge and changed check observations wake it once", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate("pending")];
  for (const minute of [0, 5, 10]) {
    await f.tick(new Date(Date.UTC(2026, 9, 28, 0, minute)).toISOString());
  }
  assertEquals(
    [...f.recipeIntents.keys()].some((key) => key.includes(":merge:")),
    false,
  );
  f.pulls[0].checks = "passed";
  f.pulls[0].checks_revision = "c".repeat(64);
  await f.tick("2026-10-28T00:20:00Z");
  await f.tick("2026-10-28T00:35:00Z");
  assertEquals(
    [...f.recipeIntents.keys()].filter((key) => key.includes(":merge:")).length,
    1,
  );
  f.pulls[0].checks_revision = "d".repeat(64);
  await f.tick("2026-10-28T00:50:00Z");
  assertEquals(
    [...f.recipeIntents.keys()].filter((key) => key.includes(":merge:")).length,
    2,
  );
});
Deno.test("a changed exact PR head creates a new bounded Gateway intent key", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate()];
  await f.tick("2026-10-28T00:05:00Z");
  f.pulls[0].head_sha = "e".repeat(40);
  await f.tick("2026-10-28T00:20:00Z");
  assertEquals(f.recipeIntents.size, 2);
  assertEquals(
    [...f.recipeIntents.keys()].every((key) => key.length <= 128),
    true,
  );
});
Deno.test("retirement ignores ordinary main commits and wakes for recipe or candidate changes", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate()];
  await f.tick("2026-10-28T00:00:00Z");
  f.baseRevision = "c".repeat(40);
  await f.tick("2026-10-28T00:15:00Z");
  assertEquals(f.recipeIntents.size, 1);
  f.recipeRevision = "c".repeat(64);
  await f.tick("2026-10-28T00:30:00Z");
  assertEquals(f.recipeIntents.size, 2);
  f.pulls.push({ ...candidate(), number: 99 });
  await f.tick("2026-10-28T00:45:00Z");
  assertEquals(
    [...f.recipeIntents.keys()].filter((key) => key.includes(":retire:1684:"))
      .length,
    3,
  );
});
Deno.test("failed snapshot refresh settles until base or check evidence changes", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate("failed")];
  await f.tick("2026-10-28T00:10:00Z");
  await f.tick("2026-10-28T00:25:00Z");
  assertEquals(f.recipeIntents.size, 1);
  f.baseRevision = "c".repeat(40);
  await f.tick("2026-10-28T00:40:00Z");
  assertEquals(f.recipeIntents.size, 2);
  f.pulls[0].checks_revision = "c".repeat(64);
  await f.tick("2026-10-28T00:55:00Z");
  assertEquals(f.recipeIntents.size, 3);
});
Deno.test("artifact inspection can precede green and wakes only for recipe or check changes", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [{ ...candidate("pending"), variant: "artifacts" }];
  await f.tick("2026-10-28T00:05:00Z");
  await f.tick("2026-10-28T00:20:00Z");
  assertEquals(f.recipeIntents.size, 1);
  f.recipeRevision = "c".repeat(64);
  await f.tick("2026-10-28T00:35:00Z");
  assertEquals(f.recipeIntents.size, 2);
  f.pulls[0].checks = "passed";
  f.pulls[0].checks_revision = "c".repeat(64);
  await f.tick("2026-10-28T00:50:00Z");
  assertEquals(f.recipeIntents.size, 3);
});
Deno.test("daily opens wake for a serving recipe change and ignore remote-only recipe changes", async () => {
  const f = new MaintenanceFixture();
  await f.tick();
  f.recipeRevision = "d".repeat(64);
  await f.tick("2026-10-28T02:05:00Z");
  assertEquals(f.recipeIntents.size, 2);
  f.servingRevision = "e".repeat(64);
  await f.tick("2026-10-28T02:10:00Z");
  assertEquals(f.recipeIntents.size, 4);
});

Deno.test("draft readiness and base changes wake same-head actions without check changes", async () => {
  for (const [checks, minute] of [["failed", 10], ["passed", 5]] as const) {
    const f = new MaintenanceFixture();
    f.pulls = [candidate(checks)];
    const tick = (offset: number) =>
      f.tick(new Date(Date.UTC(2026, 9, 28, 0, minute + offset)).toISOString());
    await tick(0);
    await tick(15);
    assertEquals(f.recipeIntents.size, 1);
    f.pulls[0].policy_revision = "c".repeat(64);
    await tick(30);
    assertEquals(f.recipeIntents.size, 2);
    f.pulls[0].policy_revision = "d".repeat(64);
    await tick(45);
    assertEquals(f.recipeIntents.size, 3);
  }
});
Deno.test("a newer candidate base change wakes an older candidate retirement", async () => {
  const f = new MaintenanceFixture();
  f.pulls = [candidate(), { ...candidate(), number: 1685 }];
  await f.tick("2026-10-28T00:00:00Z");
  f.pulls[1].policy_revision = "c".repeat(64);
  await f.tick("2026-10-28T00:15:00Z");
  assertEquals(
    [...f.recipeIntents.keys()].filter((key) => key.includes(":retire:1684:"))
      .length,
    2,
  );
});
