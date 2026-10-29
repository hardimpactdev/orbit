import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeEnvironment } from "../../bin/compile-family.ts";

const binary = Deno.env.get("SWAMP_BIN");
if (!binary) throw new Error("Set SWAMP_BIN to the pinned Swamp executable");

Deno.test("deployment lock leaves generic SSH unloaded; restoring its test-only entry installs exec/script", async () => {
  const repo = await Deno.makeTempDir({ prefix: "typed-agent-activation-" });
  const run = async (...args: string[]) => {
    const output = await new Deno.Command(binary!, {
      args: [...args, "--json"],
      cwd: repo,
      clearEnv: true,
      env: nativeEnvironment(),
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(
      output.code,
      0,
      new TextDecoder().decode(output.stdout) +
        new TextDecoder().decode(output.stderr),
    );
    return JSON.parse(new TextDecoder().decode(output.stdout));
  };
  try {
    const deployLock = JSON.parse(
      await Deno.readTextFile("extensions/models/upstream_extensions.json"),
    );
    assert(!Object.hasOwn(deployLock, "@swamp/ssh"));
    await Deno.copyFile(".swamp.yaml", `${repo}/.swamp.yaml`);
    await Deno.mkdir(`${repo}/extensions/models`, { recursive: true });
    await Deno.mkdir(`${repo}/family/agent`, { recursive: true });
    await Deno.copyFile("family/agent/ssh.ts", `${repo}/family/agent/ssh.ts`);
    await Deno.writeTextFile(
      `${repo}/extensions/models/upstream_extensions.json`,
      JSON.stringify(deployLock),
    );
    const cold = await run("behavior", "type", "search", "ssh");
    assert(
      !JSON.stringify(cold).includes('"@swamp/ssh"'),
      "Unregistered factory must not request or load generic SSH",
    );
    let pulled = false;
    try {
      pulled = (await Deno.stat(`${repo}/.swamp/pulled-extensions/@swamp/ssh`))
        .isDirectory;
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    assert(!pulled, "Cold loading the deployment tree must not pull SSH");

    // A native install/restore DOES consume a lock entry even without a consumer.
    // Keep this exact record only under family/agent, outside deployment discovery.
    const testOnlyLock = await Deno.readTextFile(
      "family/agent/upstream_extensions.json",
    );
    await Deno.writeTextFile(
      `${repo}/extensions/models/upstream_extensions.json`,
      testOnlyLock,
    );
    await run("extension", "install");
    assert(
      (await Deno.stat(
        `${repo}/.swamp/pulled-extensions/@swamp/ssh/models/ssh.ts`,
      )).isFile,
    );
    const installed = JSON.stringify(
      await run("behavior", "type", "describe", "@swamp/ssh"),
    );
    assert(
      installed.includes('"exec"') && installed.includes('"script"'),
      "Restored package exposes its generic methods",
    );
  } finally {
    await Deno.remove(repo, { recursive: true });
  }
});
