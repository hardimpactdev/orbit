import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { closeServer } from "./server_close.ts";

const input = { host: "10.44.0.9", port: 6379, default_open: true };
const use = (f: (material: Record<string, unknown>) => Promise<void>) =>
  f({ password: "private-root-sentinel" });
class Client {
  calls: string[][] = [];
  clients = "id=17 name=proof\n";
  open = true;
  failSave = false;
  closed = false;
  command(...args: string[]): Promise<unknown> {
    this.calls.push(args);
    if (args[0] === "AUTH") return Promise.resolve("OK");
    if (args[0] === "CLIENT" && args[1] === "ID") return Promise.resolve(17);
    if (args[0] === "CLIENT" && args[1] === "LIST") {
      return Promise.resolve(this.clients);
    }
    if (args[0] === "ACL" && args[1] === "GETUSER") {
      return Promise.resolve([
        "flags",
        this.open ? ["on", "nopass"] : ["on"],
        "passwords",
        [],
      ]);
    }
    if (args[0] === "ACL" && args[1] === "SAVE" && this.failSave) {
      throw new Error("private-root-sentinel diagnostic");
    }
    return Promise.resolve("OK");
  }
  close() {
    this.closed = true;
  }
}
Deno.test("closure refuses other default clients before ACL mutation", async () => {
  const client = new Client();
  client.clients += "id=18 name=client\n";
  assertEquals(
    await closeServer(input, use, undefined, () => Promise.resolve(client)),
    { error_code: "database.server_in_use" },
  );
  assertEquals(client.calls.some((c) => c[1] === "SETUSER"), false);
  assertEquals(client.closed, true);
});
Deno.test("closure authenticates both first attempt and resume without anonymous fallback", async () => {
  for (const open of [true, false]) {
    const client = new Client();
    client.open = open;
    assertEquals(
      await closeServer(input, use, undefined, () => Promise.resolve(client)),
      { backend: "active" },
    );
    assertEquals(client.calls[0], ["AUTH", "default", "private-root-sentinel"]);
    assertEquals(client.calls.at(-1), [
      "CLIENT",
      "KILL",
      "USER",
      "default",
      "SKIPME",
      "yes",
    ]);
    assertEquals(client.calls.filter((c) => c[1] === "SETUSER").length, 1);
  }
});
Deno.test("save refusal restores only initially anonymous access and discards diagnostics", async () => {
  for (const open of [true, false]) {
    const client = new Client();
    client.open = open;
    client.failSave = true;
    const result = await closeServer(
      input,
      use,
      undefined,
      () => Promise.resolve(client),
    );
    assertEquals(result, { error_code: "database.server_command_failed" });
    assertEquals(client.calls.some((c) => c.includes("nopass")), open);
    assertEquals(JSON.stringify(result).includes("private"), false);
  }
});
Deno.test("closed server only proves admin login and invalid input never reads material", async () => {
  const client = new Client();
  assertEquals(
    await closeServer(
      { ...input, default_open: false },
      use,
      undefined,
      () => Promise.resolve(client),
    ),
    { backend: "active" },
  );
  assertEquals(client.calls.map((c) => c[0]), ["AUTH", "PING"]);
  let reads = 0;
  await assertRejects(() =>
    closeServer({ ...input, host: "foreign.example" }, () => {
      reads++;
      return Promise.resolve();
    })
  );
  assertEquals(reads, 0);
});
