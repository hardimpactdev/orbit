import {
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { Fixture } from "./tests/fixture.ts";

const gateway = Deno.env.get("ORBIT_GATEWAY_CHECKOUT");

type Scenario = {
  action: string;
  payload: Record<string, unknown>;
  fail?: string;
  users?: string[];
};

async function execute(scenario: Scenario, lostReply = false) {
  const f = new Fixture();
  f.operation.kind = scenario.action === "remove"
    ? "database.destroy"
    : "database_server.create";
  f.descriptors[0] = {
    ...f.descriptors[0],
    transport: "remote_program",
    name: "database.valkey",
    input: {
      action: scenario.action,
      payload: { $secret: "database.intent", id: 9 },
      password: { $secret: "database.intent", id: 9 },
    },
  };
  let calls: string[][] = [];
  let invocations = 0;
  f.invoke = async () => {
    invocations++;
    const child = new Deno.Command("python3", {
      args: [
        new URL("./tests/valkey-primitive.py", import.meta.url).pathname,
      ],
      env: { ORBIT_GATEWAY_CHECKOUT: gateway! },
      stdin: "piped",
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(scenario)));
    await writer.close();
    const output = await child.output();
    assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    const receipt = JSON.parse(new TextDecoder().decode(output.stdout));
    calls = receipt.calls;
    return receipt.result;
  };
  f.loseRun = lostReply;
  let result;
  if (lostReply) {
    await assertRejects(() => f.execute(), Error, "Lost run reply");
    result = await f.execute("recovered-worker");
  } else {
    result = await f.execute();
  }
  assertEquals(invocations, 1);
  assertFalse(JSON.stringify(f.finished).includes("fixture-password"));
  assertFalse(JSON.stringify(calls).includes("fixture-password"));
  return { calls, result, finished: f.finished };
}

Deno.test({
  name:
    "Valkey removal restores a deleted user after save failure and preserves default",
  ignore: !gateway,
  fn: async () => {
    const { calls, finished } = await execute({
      action: "remove",
      payload: { usernames: ["queue", "default"] },
      users: [
        "user default on nopass ~* +@all",
        "user queue on #aaaa ~queue:* +@list",
      ],
      fail: "first-save",
    });
    assertEquals(calls.filter((call) => call[1] === "DELUSER"), [
      ["ACL", "DELUSER", "queue"],
    ]);
    assertEquals(calls.find((call) => call[1] === "SETUSER"), [
      "ACL",
      "SETUSER",
      "queue",
      "reset",
      "on",
      "#aaaa",
      "~queue:*",
      "+@list",
    ]);
    assertEquals(finished!.status, "failed");
  },
});
Deno.test({
  name:
    "Valkey convergence preserves open default and deletes only stale users",
  ignore: !gateway,
  fn: async () => {
    const { calls, finished } = await execute({
      action: "converge",
      payload: { users: [], default_open: true },
      users: [
        "user default on nopass ~* +@all",
        "user stale on nopass ~* +@all",
      ],
    });
    assertEquals(calls.slice(-2), [["ACL", "DELUSER", "stale"], [
      "ACL",
      "SAVE",
    ]]);
    assertEquals(finished!.status, "succeeded");
  },
});
Deno.test({
  name:
    "Valkey convergence changes only differing users and keeps command order",
  ignore: !gateway,
  fn: async () => {
    const { calls } = await execute({
      action: "converge",
      users: [
        "user unchanged sanitize-payload on #aaaa #bbbb ~b ~a &* -@all +@list",
        "user changed on #cccc ~* +@list -@all",
      ],
      payload: {
        users: [
          {
            name: "unchanged",
            rules: "on ~a ~b &* -@all +@list",
            hashes: ["bbbb", "aaaa"],
          },
          { name: "changed", rules: "on ~* -@all +@list", hashes: ["cccc"] },
        ],
        default_open: false,
      },
    });
    assertEquals(
      calls.filter((call) => call[1] === "SETUSER").map((call) => call[2]),
      ["changed"],
    );
  },
});
