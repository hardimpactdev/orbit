import { assertEquals } from "jsr:@std/assert@1.0.19";
import { updateConnection } from "./connection_update.ts";

const input = {
  validate_index: true,
  host: "10.44.0.9",
  port: 6379,
  index: "3",
};
const secret = "private-update-root-sentinel";
const use = async (
  callback: (material: Record<string, unknown>) => Promise<void>,
) => {
  await callback({ password: secret });
};
class Client {
  calls: string[][] = [];
  closed = false;
  fail = false;
  reply: unknown = ["databases", "16"];
  async command(...args: string[]) {
    this.calls.push(args);
    if (this.fail) throw new Error(secret);
    return args[0] === "AUTH" ? "OK" : await Promise.resolve(this.reply);
  }
  close() {
    this.closed = true;
  }
}
Deno.test("connection update reads CONFIG only on its admitted server and closes it", async () => {
  const client = new Client();
  const result = await updateConnection(input, use, undefined, (host, port) => {
    assertEquals([host, port], [input.host, input.port]);
    return Promise.resolve(client);
  });
  assertEquals(result, { backend: "active" });
  assertEquals(client.calls, [["AUTH", "default", secret], [
    "CONFIG",
    "GET",
    "databases",
  ]]);
  assertEquals(client.closed, true);
});
Deno.test("out-of-range index refuses without mutation and repeated reads are safe", async () => {
  for (const index of ["16", "999999999999999999999999999999"]) {
    const client = new Client();
    const connect = () => Promise.resolve(client);
    for (let i = 0; i < 2; i++) {
      assertEquals(
        await updateConnection({ ...input, index }, use, undefined, connect),
        { error_code: "database.index_invalid" },
      );
    }
    assertEquals(
      client.calls.every((call) => ["AUTH", "CONFIG"].includes(call[0])),
      true,
    );
    assertEquals(client.closed, true);
  }
});
Deno.test("record-only updates do not open connections or request private material", async () => {
  const refuse = () => {
    throw new Error("unexpected private or socket access");
  };
  assertEquals(
    await updateConnection(
      { validate_index: false },
      refuse,
      undefined,
      refuse,
    ),
    { backend: "active" },
  );
});
Deno.test("transport failure closes the connection and discards all diagnostics", async () => {
  const client = new Client();
  client.fail = true;
  assertEquals(
    await updateConnection(
      input,
      use,
      undefined,
      () => Promise.resolve(client),
    ),
    { error_code: "database.server_command_failed" },
  );
  assertEquals(client.closed, true);
});
Deno.test("malformed counts fail closed rather than accepting an index", async () => {
  for (
    const reply of [["databases", "0"], ["databases", "private"], [
      "other",
      "16",
    ]]
  ) {
    const client = new Client();
    client.reply = reply;
    assertEquals(
      await updateConnection(
        input,
        use,
        undefined,
        () => Promise.resolve(client),
      ),
      { error_code: "database.server_command_failed" },
    );
    assertEquals(client.closed, true);
  }
});
