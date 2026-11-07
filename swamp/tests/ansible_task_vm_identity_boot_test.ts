/** The orbit-identity unit of the base finds a task VM's identity share at once, and a base VM, which has none, ends after a short grace. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Copy = { "ansible.builtin.copy"?: { dest: string; content: string } };
const tasks = parse(
  Deno.readTextFileSync(
    new URL("../ansible/tasks/task-vm-boot.yml", import.meta.url),
  ),
) as Copy[];
const script =
  tasks.find((task) =>
    task["ansible.builtin.copy"]?.dest === "/usr/local/sbin/orbit-identity"
  )!["ansible.builtin.copy"]!.content;

/** Runs the script with stub commands: `cat` prints the virtiofs tags, `sleep` counts its calls, and `mkdir` ends the run where the mount begins. */
async function boot(
  tags: string,
): Promise<{ code: number; sleeps: number; stderr: string }> {
  const bin = await Deno.makeTempDir();
  const stub = async (name: string, body: string) => {
    await Deno.writeTextFile(`${bin}/${name}`, `#!/bin/sh\n${body}\n`);
    await Deno.chmod(`${bin}/${name}`, 0o755);
  };
  await stub("cat", `printf '%s' '${tags}'`);
  await stub("sleep", `echo x >> ${bin}/sleeps`);
  await stub("mkdir", "exit 42");
  const run = await new Deno.Command("sh", {
    args: ["-c", script],
    env: { PATH: `${bin}:${Deno.env.get("PATH")}` },
    stderr: "piped",
  }).output();
  const sleeps = await Deno.readTextFile(`${bin}/sleeps`).then(
    (text) => text.split("\n").filter(Boolean).length,
    () => 0,
  );
  await Deno.remove(bin, { recursive: true });

  return {
    code: run.code,
    sleeps,
    stderr: new TextDecoder().decode(run.stderr),
  };
}

Deno.test("a task VM mounts its identity share without waiting: the kernel found the tag before the unit runs", async () => {
  // The agent's config share has no virtiofs tag in a task VM, so a wait for it took the whole 5 s grace (op 13001).
  const copy = await boot("incus_orbit-identity\n");
  assertEquals(copy.code, 42);
  assertEquals(copy.sleeps, 0);
});

Deno.test("a base VM has no identity share and ends after 1 s", async () => {
  const base = await boot("");
  assertEquals(base.code, 0);
  assertEquals(base.sleeps, 10);
  assertStringIncludes(base.stderr, "no identity share");
});
