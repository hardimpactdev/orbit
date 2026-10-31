import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { generateSshHostKey, SshHostKey } from "./ssh_host_key.ts";
Deno.test("native host key generation returns the existing key type and removes its scratch directory", async () => {
  const root = Deno.env.get("TMPDIR") ?? "/tmp";
  const names = async () => {
    const result = [];
    for await (const entry of Deno.readDir(root)) {
      if (entry.name.startsWith("orbit-host-key-")) result.push(entry.name);
    }
    return result.sort();
  };
  const before = await names();
  const pair = await generateSshHostKey("tvm-930001");
  assertEquals(SshHostKey.safeParse(pair).success, true);
  assertEquals(await names(), before);
});
Deno.test("invalid host key identity refuses before creating scratch files", async () => {
  await assertRejects(
    () => generateSshHostKey("../foreign"),
    Error,
    "task_vm.host_key_failed",
  );
});
