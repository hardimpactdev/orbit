import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";

async function command(input: unknown, success = true) {
  const process = new Deno.Command("python3", {
    args: [new URL("./serve-command.py", import.meta.url).pathname],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = process.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(input)));
  await writer.close();
  const result = await process.output();
  assertEquals(result.success, success, new TextDecoder().decode(result.stderr));
  return success ? JSON.parse(new TextDecoder().decode(result.stdout)) as string[] : [];
}
Deno.test("serve command changes required flags and environment, and is idempotent", async () => {
  const original = ["/usr/bin/env", "OTHER=value", "/bin/swamp", "serve",
    "--port", "9418", "--dispatch-env-allow=,", "--max-concurrent-scheduled-runs", "32", "--trusted-hosts", "swamp.orbit"];
  const expected = ["/usr/bin/env", "SWAMP_REMOTE_ONLY=1", "OTHER=value", "/bin/swamp", "serve",
    "--port", "9418", "--dispatch-env-allow=,",
    "--trusted-hosts", "swamp.orbit",
    "--max-concurrent-scheduled-runs", "1", "--remote-only", "--hot-reload"];
  assertEquals(await command(original), expected);
  assertEquals(await command(expected), expected);
  assertFalse(expected.includes("--max-concurrent-webhook-runs"));
});
Deno.test("serve preserves webhook limits and replaces conflicting booleans", async () => {
  assertEquals(
    await command(["/bin/swamp", "serve", "--remote-only=false", "--hot-reload=false", "--max-concurrent-webhook-runs", "4"]),
    ["/usr/bin/env", "SWAMP_REMOTE_ONLY=1", "/bin/swamp", "serve", "--max-concurrent-webhook-runs", "4",
    "--max-concurrent-scheduled-runs", "1", "--remote-only", "--hot-reload"],
  );
});
Deno.test("serve replaces its remote environment and preserves other values", async () => {
  assertEquals(
    (await command(["/usr/bin/env", "SWAMP_REMOTE_ONLY=0", "OTHER=a=b", "/bin/swamp", "serve"])).slice(0, 5),
    ["/usr/bin/env", "SWAMP_REMOTE_ONLY=1", "OTHER=a=b", "/bin/swamp", "serve"],
  );
});
Deno.test("serve refuses malformed input before runtime actions", async () => {
  for (
    const value of [[], "serve", ["/bin/swamp", "worker"], ["/bin/swamp", "serve", "--max-concurrent-scheduled-runs", "--remote-only"]]
  ) await command(value, false);
});
