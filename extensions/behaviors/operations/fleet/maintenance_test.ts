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
    if (path.endsWith("/peer-dns/targets")) data = [2];
    else if (path.endsWith("/recipe-pr/plan")) {
      if (this.failPr) throw new Error("fleet.github_unavailable");
      data = { status: "ready", date: "20261028", pulls: [] };
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
Deno.test("successful daily opens and active Node Operations are not admitted twice", async () => {
  const f = new MaintenanceFixture();
  await f.tick();
  for (const value of f.resources.values()) {
    if (typeof value.id === "number") f.statuses.set(value.id, "succeeded");
  }
  await f.tick("2026-10-28T02:05:00Z");
  assertEquals(f.writes("/api/v1/fleet/recipe-pr").length, 2);
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
