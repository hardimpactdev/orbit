import { assertRejects } from "jsr:@std/assert@1.0.19";
import pin from "./ssh-files.json" with { type: "json" };
import { verifyAgentSsh } from "../../bin/prepare-agent-ssh.ts";

Deno.test("SSH dependency verification refuses modified source, extra files and a changed release", async () => {
  await verifyAgentSsh();
  const directory = await Deno.makeTempDir({ prefix: "agent-ssh-pin-" });
  const root = new URL(`file://${directory}/`);
  const base = new URL(".swamp/pulled-extensions/@swamp/ssh/", root);
  try {
    await Deno.mkdir(`${directory}/family/agent`, { recursive: true });
    const lock = JSON.parse(
      await Deno.readTextFile("family/agent/upstream_extensions.json"),
    );
    const path = `${directory}/family/agent/upstream_extensions.json`;
    await Deno.writeTextFile(path, JSON.stringify(lock));
    for (const file of Object.keys(pin.files_sha256)) {
      const to = new URL(file, base);
      await Deno.mkdir(new URL(".", to), { recursive: true });
      await Deno.copyFile(
        new URL(
          file,
          new URL(
            "../../.swamp/pulled-extensions/@swamp/ssh/",
            import.meta.url,
          ),
        ),
        to,
      );
    }
    await verifyAgentSsh(root);
    const runner = new URL("models/_lib/runner.ts", base);
    const original = await Deno.readTextFile(runner);
    await Deno.writeTextFile(runner, original + "\n// changed");
    await assertRejects(
      () => verifyAgentSsh(root),
      Error,
      "file digest mismatch",
    );
    await Deno.writeTextFile(runner, original);
    const extra = new URL("models/unreviewed.ts", base);
    await Deno.writeTextFile(extra, "export const extra = true;");
    await assertRejects(() => verifyAgentSsh(root), Error, "file set mismatch");
    await Deno.remove(extra);
    lock[pin.package].version = "2099.1.1";
    await Deno.writeTextFile(path, JSON.stringify(lock));
    await assertRejects(
      () => verifyAgentSsh(root),
      Error,
      "release pin mismatch",
    );
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
});
