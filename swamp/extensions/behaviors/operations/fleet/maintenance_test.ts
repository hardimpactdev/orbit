import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { MaintenanceContext as Context } from "./maintenance.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { maintenance } from "./maintenance.ts";

class MaintenanceFixture {
    resources = new Map<string, Record<string, unknown>>();
    calls: { method: string; path: string; body: unknown; key?: string }[] = [];
    statuses = new Map<number, string>();
    loseAdmission = false;
    dnsDigest = "a".repeat(64);
    rolloutStatus = "ready";
    targets = [3];
    refuseRollout = false;
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
        } else if (path.endsWith("/rollout/plan")) {
            data = { status: this.rolloutStatus, targets: this.targets };
        } else if (path.includes("/operations/")) {
            data = {
                status: this.statuses.get(Number(path.split("/").at(-1))) ??
                    "running",
            };
        } else if (
            method === "POST" && path === "/api/v1/fleet/rollout" &&
            this.refuseRollout
        ) {
            this.refuseRollout = false;
            return {
                status: 409,
                body: { error: { code: "fleet.rollout_not_ready" } },
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
        return maintenance(
            { tick: at },
            this.context(),
            this.http,
            new Date(at),
        );
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
Deno.test("a rollout plan that is not ready, or names no Node, admits nothing", async () => {
    const f = new MaintenanceFixture();
    f.rolloutStatus = "waiting";
    await f.tick("2026-10-28T02:00:00Z");
    f.rolloutStatus = "ready";
    f.targets = [];
    await f.tick("2026-10-28T02:01:00Z");
    assertEquals(f.writes("/api/v1/fleet/rollout").length, 0);
});
Deno.test("a refused rollout admission is retired, so the next pass admits the new plan", async () => {
    const f = new MaintenanceFixture();
    f.targets = [3, 4];
    f.refuseRollout = true;
    await f.tick("2026-10-28T02:00:00Z");
    f.targets = [4];
    await f.tick("2026-10-28T02:01:00Z");
    const writes = f.writes("/api/v1/fleet/rollout");
    assertEquals(writes.map((call) => call.body), [{ node_ids: [3, 4] }, {
        node_ids: [4],
    }]);
    assertEquals(writes[0].key === writes[1].key, false);
});
Deno.test("an unfinished rollout admits no second one", async () => {
    const f = new MaintenanceFixture();
    await f.tick("2026-10-28T02:00:00Z");
    await f.tick("2026-10-28T02:01:00Z");
    assertEquals(f.writes("/api/v1/fleet/rollout").length, 1);
});

Deno.test("unchanged peer DNS admits once across a full day", async () => {
    const f = new MaintenanceFixture();
    for (let minute = 0; minute < 1440; minute++) {
        await f.tick(new Date(Date.UTC(2026, 9, 30, 0, minute)).toISOString());
        for (const value of f.resources.values()) {
            if (typeof value.id === "number") {
                f.statuses.set(value.id, "succeeded");
            }
        }
    }
    assertEquals(f.writes("/api/v1/fleet/nodes/2/peer-dns").length, 1);
});

Deno.test("a changed policy admits again, but waits for active work", async () => {
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

Deno.test("a lost peer DNS admission is replayed across policy changes", async () => {
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

Deno.test("a failed peer DNS policy waits; a changed policy admits with a fresh key", async () => {
    const f = new MaintenanceFixture();
    const path = "/api/v1/fleet/nodes/2/peer-dns";
    await f.tick();
    f.statuses.set(f.resources.get("dns-2")!.id as number, "failed");
    await f.tick("2026-10-28T02:01:00Z");
    assertEquals(f.writes(path).length, 1);
    f.dnsDigest = "b".repeat(64);
    await f.tick("2026-10-28T02:02:00Z");
    const writes = f.writes(path);
    assertEquals(writes.length, 2);
    assertEquals(writes[0].key === writes[1].key, false);
});

Deno.test("rollout admission needs no window field and leaves the maintenance window to its native DAG", async () => {
    const f = new MaintenanceFixture();
    await f.tick("2026-10-28T12:00:00Z");
    assertEquals(
        f.writes("/api/v1/fleet/rollout").map((call) => call.body),
        [{ node_ids: [3] }],
    );
});
Deno.test("one rollout admission captures every ready Node in fleet order", async () => {
    const f = new MaintenanceFixture();
    f.targets.push(4);
    await f.tick();
    assertEquals(f.writes("/api/v1/fleet/rollout").map((call) => call.body), [{
        node_ids: [3, 4],
    }]);
});
