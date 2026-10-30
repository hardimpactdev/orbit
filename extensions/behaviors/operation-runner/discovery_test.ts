import { assertEquals } from "jsr:@std/assert@1.0.19";
import { operationDiscovery } from "./mod.ts";

function discover(
  kinds: { kind: string; claimable: number; oldest_at: string | null }[],
  allowed = kinds.map((k) => k.kind),
) {
  const calls: string[] = [];
  const writes: string[] = [];
  const behavior = operationDiscovery(() => (method, path) => {
    calls.push(`${method} ${path}`);
    return Promise.resolve({ status: 200, body: { data: { kinds } } });
  });
  return {
    calls,
    writes,
    run: () =>
      behavior.methods.discover.execute({
        family: "test",
        kinds: allowed,
        worker: "tick",
      }, {
        globalArgs: {
          gatewayUrl: "https://gateway.orbit",
          caFile: "/dev/null",
        },
        readResource: () => Promise.resolve(null),
        writeResource: (_spec, name) => {
          writes.push(name);
          return Promise.resolve({ name });
        },
      }),
  };
}

Deno.test("one kind gets four distinct replayable claims, bounded by the count hint", async () => {
  const fixture = discover([{
    kind: "fleet.peer_dns",
    claimable: 100,
    oldest_at: null,
  }]);
  const first = await fixture.run();
  assertEquals(
    first.work,
    [0, 1, 2, 3].map((slot) => ({
      kind: "fleet.peer_dns",
      slot,
      worker: `tick:fleet.peer_dns:${slot}`,
    })),
  );
  assertEquals((await fixture.run()).work, first.work);
  assertEquals(fixture.calls, [
    "GET /api/v1/operation-executor/claimable",
    "GET /api/v1/operation-executor/claimable",
  ]);
  assertEquals(
    (await discover([{ kind: "fleet.peer_dns", claimable: 2, oldest_at: null }])
      .run()).work.length,
    2,
  );
});

Deno.test("oldest family kinds get one claim each before extra slots; total never exceeds eight", async () => {
  const kinds = ["test.new", "test.old", "test.middle"].map((kind, i) => ({
    kind,
    claimable: 100,
    oldest_at: `2026-10-${[29, 20, 25][i]}T00:00:00Z`,
  }));
  const result = await discover([...kinds, {
    kind: "foreign.kind",
    claimable: 100,
    oldest_at: "2026-01-01T00:00:00Z",
  }], kinds.map((k) => k.kind)).run();
  assertEquals(result.work.map((w) => w.kind), [
    "test.old",
    "test.middle",
    "test.new",
    "test.old",
    "test.middle",
    "test.new",
    "test.old",
    "test.middle",
  ]);
  assertEquals(new Set(result.work.map((w) => w.worker)).size, 8);
  assertEquals(result.work.map((w) => w.slot), [0, 1, 2, 3, 4, 5, 6, 7]);
});

Deno.test("empty family summary writes no resource or speculative claim", async () => {
  const empty = discover([{
    kind: "fleet.peer_dns",
    claimable: 0,
    oldest_at: null,
  }]);
  const result = await empty.run();
  assertEquals(result.dataHandles, []);
  assertEquals(empty.writes, []);
  assertEquals(
    (await discover([{ kind: "fleet.peer_dns", claimable: 0, oldest_at: null }])
      .run()).work,
    [],
  );
});
