import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { readCalls, shellSplit } from "../../../../tests/_support/fake_tool.ts";
import { NOW, reaperFixture } from "./native_fixture_test.ts";
Deno.test("idle superseded and silent groups are chosen and nothing else", async () => {
  await using f = await reaperFixture();
  const old = NOW - 7200;
  const decision = await f.run([
    {
      id: 10,
      title: "swamp-proof beast:minimal minimal@8",
      started_at: NOW - 600,
      running: true,
    },
    {
      id: 12,
      title: "swamp-proof beast:minimal minimal@8",
      started_at: null,
      running: false,
    },
    {
      id: 20,
      title: "swamp-proof shark:minimal minimal@8",
      started_at: NOW - 600,
      running: true,
    },
    {
      id: 30,
      title: "topology minimal@7 old",
      started_at: old,
      running: true,
    },
    {
      id: 40,
      title: "topology minimal@7 old",
      // Keep this unreadable VM below the current 5400-second age cap.
      started_at: NOW - 4000,
      running: true,
    },
  ]);
  assertEquals(decision.revision, 3);
  assertEquals(decision.cancel.map((c: { group_id: number }) => c.group_id), [
    10,
    20,
    30,
  ]);
  const stats = readCalls<string[]>(f.calls);
  assert(
    stats.every((args) =>
      JSON.stringify(shellSplit(args.at(-1)!)) ===
        JSON.stringify([
          "stat",
          "-c",
          "%Y",
          "/home/orbit/swamp-proof-activity",
        ])
    ),
  );
  assert(
    stats.every((args) => args.some((a) => a.startsWith("StrictHostKeyChecking=yes"))),
  );
  assertEquals(
    [...new Set(stats.flat().filter((a) => a.startsWith("orbit@")))].sort(),
    [
      "orbit@10.44.0.10",
      "orbit@10.44.0.20",
      "orbit@10.44.0.30",
      "orbit@10.44.0.40",
    ],
  );
});
Deno.test("no running VM still chooses superseded groups", async () => {
  await using f = await reaperFixture();
  const decision = await f.run([{
    id: 1,
    title: "swamp-proof a:minimal x",
    started_at: null,
    running: false,
  }, {
    id: 2,
    title: "swamp-proof a:minimal x",
    started_at: null,
    running: false,
  }]);
  assertEquals(decision.cancel, [{
    group_id: 1,
    reason: "superseded by group 2",
  }]);
});
