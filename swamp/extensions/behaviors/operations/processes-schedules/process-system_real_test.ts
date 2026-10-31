import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import { processSystem } from "./process-system.ts";
import { type SystemCommand, systemCommand } from "./system.ts";

Deno.test("real sudo pipe publishes root0600 environment and public candidate atomically without stdout", async () => {
  // Only fixed paths in this disposable local tree reach the filesystem.
  const root = await Deno.makeTempDir({ prefix: "orbit-process-pipe-" });
  const caller = { uid: Deno.uid()!, gid: Deno.gid()! };
  const admin = async (program: string, args: string[]) => {
    const result = await systemCommand("/usr/bin/sudo", [
      "-n",
      "--",
      program,
      ...args,
    ]);
    assertEquals(result.code, 0);
    return result.stdout;
  };
  try {
    await Deno.mkdir(`${root}/systemd`);
    await admin("/usr/bin/chown", ["--recursive", "0:0", "--", root]);
    await admin("/usr/bin/chmod", ["0755", "--", root]);
    const calls: {
      program: string;
      args: readonly string[];
      stdin?: string;
      stdout: string;
    }[] = [];
    const mapPath = (value: string) =>
      value === "/etc" ? root : value
        .replaceAll("/etc/orbit", `${root}/orbit`)
        .replaceAll("/etc/systemd/system", `${root}/systemd`);
    const run: SystemCommand = async (
      program,
      args,
      stdin,
      signal,
      timeout,
    ) => {
      assertEquals(program, "/usr/bin/sudo");
      const executable = args[2];
      assert([
        "/usr/bin/stat",
        "/usr/bin/test",
        "/usr/bin/mkdir",
        "/usr/bin/mktemp",
        "/usr/bin/dd",
        "/usr/bin/chmod",
        "/usr/bin/sync",
        "/usr/bin/mv",
        "/usr/bin/rm",
        "/usr/bin/systemd-analyze",
      ].includes(executable));
      const result = await systemCommand(
        program,
        args.map(mapPath),
        stdin,
        signal,
        timeout,
      );
      calls.push({ program: executable, args, stdin, stdout: result.stdout });
      return {
        ...result,
        stdout: result.stdout.replaceAll(`${root}/orbit`, "/etc/orbit"),
      };
    };
    const environment = '# Orbit Process 7\nTOKEN="private-pipe-sentinel"\n';
    const hash = Array.from(
      new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(environment),
        ),
      ),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const unit = "orbit-process-7-pipe.service";
    const content =
      `[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/sleep infinity\nEnvironmentFile=/etc/orbit/processes/7/${hash}.env\n`;
    assertEquals(
      await processSystem(run)("process.stage-unit", {
        process_id: 7,
        unit,
        content,
        environment,
      }),
      { staged: true, previous: false, state: null },
    );
    const path = `${root}/orbit/processes/7/${hash}.env`;
    assertEquals(
      (await admin("/usr/bin/stat", ["--format=%u:%g:%a:%h", "--", path]))
        .trim(),
      "0:0:600:1",
    );
    assertEquals(await admin("/usr/bin/cat", ["--", path]), environment);
    assertEquals(
      await admin("/usr/bin/cat", [
        "--",
        `${root}/orbit/systemd-candidates/${unit}`,
      ]),
      content,
    );
    assert(
      !JSON.stringify(
        calls.map(({ program, args, stdout }) => ({ program, args, stdout })),
      ).includes("private-pipe-sentinel"),
    );
    assert(
      calls.some((call) =>
        call.program === "/usr/bin/dd" && call.stdin === environment
      ),
    );
    assert(!calls.some((call) => call.args.includes("/dev/stdin")));
  } finally {
    await admin("/usr/bin/chown", [
      "--recursive",
      `${caller.uid}:${caller.gid}`,
      "--",
      root,
    ]);
    await admin("/usr/bin/chmod", ["--recursive", "u+rwX", "--", root]);
    await Deno.remove(root, { recursive: true });
  }
});
