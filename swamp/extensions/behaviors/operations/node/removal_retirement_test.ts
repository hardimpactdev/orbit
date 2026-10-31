import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { removalReachable, retireNodeOffWorker } from "./removal_retirement.ts";
const retirement = {
  executor_node_id: 14,
  target: {
    node_id: 13,
    host: "203.0.113.13",
    port: 22,
    user: "managed",
    fingerprint: "SHA256:" + "A".repeat(43),
  },
};
Deno.test("off-worker retirement reuses the exact admitted pinned SSH target and fixed removal source", async () => {
  const calls: unknown[] = [];
  const outcome = await retireNodeOffWorker(
    retirement,
    "/private/existing-key",
    {} as never,
    undefined,
    (target, identity) => (argv, stdin) => {
      calls.push({
        target,
        identity,
        argv: argv.slice(0, 2),
        input: JSON.parse(stdin!),
      });
      return Promise.resolve({ success: true, stdout: '{"removed":true}' });
    },
  );
  assertEquals(outcome, { ready: true });
  assertEquals(calls, [{
    target: retirement.target,
    identity: "/private/existing-key",
    argv: ["/usr/bin/python3", "-c"],
    input: { node_id: 13, retire_worker: true },
  }]);
  await assertRejects(() =>
    retireNodeOffWorker(
      { ...retirement, executor_node_id: 13 },
      "/private/existing-key",
      {} as never,
    )
  );
});
Deno.test("offline reachability probes only the admitted endpoint and uncertain transport does not imply unreachable", async () => {
  const calls: unknown[] = [];
  assertEquals(
    await removalReachable(retirement, (input) => {
      calls.push({ command: input.command, input: JSON.parse(input.stdin!) });
      return Promise.resolve({ success: true, stdout: '{"reachable":false}' });
    }),
    false,
  );
  assertEquals(calls, [{
    command: "/usr/bin/python3",
    input: { host: "203.0.113.13", port: 22 },
  }]);
  await assertRejects(
    () =>
      removalReachable(
        retirement,
        () => Promise.resolve({ success: false, stdout: "" }),
      ),
    Error,
    "uncertain",
  );
});
