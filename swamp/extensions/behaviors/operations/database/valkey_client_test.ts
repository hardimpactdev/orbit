import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ValkeyClient } from "./valkey_client.ts";

function client(reply: string) {
  let position = 0;
  const data = new TextEncoder().encode(reply);
  const conn = {
    read(buffer: Uint8Array) {
      if (position === data.length) return Promise.resolve(null);
      const size = Math.min(buffer.length, 3, data.length - position);
      buffer.set(data.subarray(position, position + size));
      position += size;
      return Promise.resolve(size);
    },
    write(bytes: Uint8Array) {
      return Promise.resolve(bytes.length);
    },
    close() {},
  } as Deno.Conn;
  return new ValkeyClient(conn);
}
Deno.test("RESP decoder handles fragmented arrays and bounded scalar replies", async () => {
  assertEquals(
    await client("*3\r\n+OK\r\n:17\r\n$3\r\nabc\r\n").command("PING"),
    ["OK", 17, "abc"],
  );
  assertEquals(await client("$-1\r\n").command("PING"), null);
});
Deno.test("RESP decoder refuses malformed, truncated, oversized and deeply nested replies", async () => {
  for (
    const reply of [
      "$3\r\nabcXX",
      "$3\r\nab",
      "*10001\r\n",
      "$1048577\r\n",
      ":NaN\r\n",
      "*1\r\n".repeat(18) + "+OK\r\n",
      "-ERR private-sentinel\r\n",
    ]
  ) {
    const error = await assertRejects(() => client(reply).command("PING"));
    assertEquals(String(error).includes("private-sentinel"), false);
  }
});
