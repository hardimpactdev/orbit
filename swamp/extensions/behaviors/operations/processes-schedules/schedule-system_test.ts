import {
  assertEquals,
  assertMatch,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.14";
import {
  lookupScheduleAccount,
  renderScheduleUnits,
  type ScheduleCommand,
  scheduleSystem,
} from "./schedule-system.ts";
import type { SystemCommand } from "./system.ts";

const id = "123e4567-e89b-42d3-a456-426614174000";
const script = `/etc/orbit/schedules/${id}.sh`;
const callback = `/etc/orbit/schedules/${id}.complete`;
const service = `/etc/systemd/system/orbit-schedule-${id}.service`;
const timer = `/etc/systemd/system/orbit-schedule-${id}.timer`;
const input = {
  id,
  user: "orbit",
  group: "orbit",
  home: "/home/orbit",
  working_directory: "/home/orbit/current",
  shell: "/bin/bash",
  login_shell: false,
  command_hash: "HASH",
  calendar: "daily",
  timeout_seconds: 60,
  desired: "disabled" as const,
};
const privateCommand = "printf '%s' 'private-schedule-value'";
const commandBytes = await crypto.subtle.digest(
  "SHA-256",
  new TextEncoder().encode(privateCommand),
);
input.command_hash = Array.from(
  new Uint8Array(commandBytes),
  (b) => b.toString(16).padStart(2, "0"),
).join("");
const target = {
  id,
  user: input.user,
  working_directory: input.working_directory,
};
type File = {
  type: string;
  uid: number;
  gid: number;
  mode: number;
  links: number;
  content: string;
};

class Fixture {
  files = new Map<string, File>([["/etc/systemd/system", {
    type: "directory",
    uid: 0,
    gid: 0,
    mode: 0o755,
    links: 2,
    content: "",
  }]]);
  calls: { program: string; args: readonly string[]; stdin?: string }[] = [];
  enabled = false;
  active = false;
  serviceActive = false;
  verifyFails = false;
  enableFails = false;
  reloadFails = false;
  disableFails = false;
  unavailable = false;
  count = 0;
  runCode = 0;
  runOutput = "";

  run: SystemCommand = (_program, argv, stdin) => {
    assertEquals(_program, "/usr/bin/sudo");
    assertEquals(argv[0], "-n");
    const separator = argv.indexOf("--");
    const program = argv[separator + 1];
    const args = argv.slice(separator + 2);
    this.calls.push({ program, args, stdin });
    let stdout = "";
    let code = 0;
    switch (program) {
      case "/usr/bin/timeout":
        return Promise.resolve({ code: this.runCode, stdout: this.runOutput });
      case "/usr/bin/getent":
        stdout = args[0] === "group"
          ? "orbit:x:1000:orbit\n"
          : "orbit:x:1000:1000:Orbit:/home/orbit:/bin/bash\n";
        break;
      case "/usr/bin/test": {
        if (["-d", "-x"].includes(args[0])) code = this.unavailable ? 1 : 0;
        else {code = this.files.has(args[1]) &&
              (args[0] !== "-L" ||
                this.files.get(args[1])!.type === "symbolic link")
            ? 0
            : 1;}
        break;
      }
      case "/usr/bin/stat": {
        const file = this.files.get(args.at(-1)!);
        if (!file) code = 1;
        else {stdout = `${file.type}|${file.uid}|${file.gid}|${
            file.mode.toString(8)
          }|${file.links}|${file.content.length}\n`;}
        break;
      }
      case "/usr/bin/cat":
        stdout = this.files.get(args.at(-1)!)!.content;
        break;
      case "/usr/bin/install":
        this.files.set(args.at(-1)!, {
          type: "directory",
          uid: 0,
          gid: 0,
          mode: parseInt(args[args.indexOf("-m") + 1], 8),
          links: 2,
          content: "",
        });
        break;
      case "/usr/bin/mktemp": {
        const path = `${
          args[0].slice("--tmpdir=".length)
        }/.orbit-schedule-${++this.count}`;
        this.files.set(path, {
          type: "regular file",
          uid: 0,
          gid: 0,
          mode: 0o600,
          links: 1,
          content: "",
        });
        stdout = path + "\n";
        break;
      }
      case "/usr/bin/dd":
        this.files.get(args[0].slice(3))!.content = stdin!;
        break;
      case "/usr/bin/chown":
        this.files.get(args.at(-1)!)!.gid = Number(args[0].split(":")[1]);
        break;
      case "/usr/bin/chmod":
        this.files.get(args.at(-1)!)!.mode = parseInt(args[0], 8);
        break;
      case "/usr/bin/mv":
        this.files.set(args.at(-1)!, this.files.get(args.at(-2)!)!);
        this.files.delete(args.at(-2)!);
        break;
      case "/usr/bin/rm":
        this.files.delete(args.at(-1)!);
        break;
      case "/usr/bin/systemd-analyze":
        code = args[0] === "verify" && this.verifyFails ||
            args[0] === "calendar" && args[1] === "invalid"
          ? 1
          : 0;
        break;
      case "/usr/bin/systemctl":
        switch (args[0]) {
          case "is-enabled":
            stdout = this.enabled ? "enabled\n" : "disabled\n";
            code = this.enabled ? 0 : 1;
            break;
          case "is-active":
            stdout =
              (args[1].endsWith(".service") ? this.serviceActive : this.active)
                ? "active\n"
                : "inactive\n";
            code = stdout === "active\n" ? 0 : 3;
            break;
          case "enable":
            if (this.enableFails) code = 1;
            else {
              this.enabled = true;
              if (args[1] === "--now") this.active = true;
            }
            break;
          case "disable":
            if (this.disableFails) code = 1;
            else {
              this.enabled = false;
              if (args[1] === "--now") this.active = false;
            }
            break;
          case "start":
            if (args.at(-1)!.endsWith(".service")) this.serviceActive = true;
            else this.active = true;
            break;
          case "stop":
            if (args.at(-1)!.endsWith(".service")) this.serviceActive = false;
            else this.active = false;
            break;
          case "daemon-reload":
            code = this.reloadFails ? 1 : 0;
            break;
          case "reset-failed":
            break;
          default:
            throw new Error("Unexpected systemctl action");
        }
        break;
      default:
        throw new Error("Unexpected system program");
    }
    return Promise.resolve({ code, stdout });
  };

  action(name: ScheduleCommand, value: Record<string, unknown> = input) {
    return scheduleSystem(name, value, undefined, this.run);
  }
  mutations() {
    return this.calls.filter((call) =>
      call.program === "/usr/bin/systemctl" &&
      !["is-enabled", "is-active"].includes(call.args[0])
    );
  }
}

function put(f: Fixture, path: string, content: string, mode = 0o644, gid = 0) {
  f.files.set(path, {
    type: "regular file",
    uid: 0,
    gid,
    mode,
    links: 1,
    content,
  });
}

// This is the former deployed agent layout, not a second runtime implementation.
function legacy(f: Fixture) {
  const context = JSON.stringify({
    user: input.user,
    group: input.group,
    home: input.home,
    working_directory: input.working_directory,
    shell: input.shell,
    login_shell: input.login_shell,
  });
  put(
    f,
    script,
    `# X-Orbit-Schedule-ID=${id}\n# X-Orbit-Execution-Context=${context}\n${privateCommand}\n`,
    0o750,
    1000,
  );
  put(
    f,
    service,
    `[Unit]\nDescription=Orbit Schedule ${id}\nX-Orbit-Schedule-ID=${id}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=orbit\nGroup=orbit\nWorkingDirectory=/home/orbit/current\nEnvironment="HOME=/home/orbit"\nEnvironment="SHELL=/bin/bash"\nExecStart=/usr/local/bin/orbit-agent service schedules-run ${id}\nExecStopPost=+/usr/local/bin/orbit-agent service schedules-complete ${id}\nTimeoutStartSec=60\n`,
  );
  put(f, timer, renderScheduleUnits(input).timer);
}

Deno.test("Schedule timer only admits a native run and writes no private command artifacts", async () => {
  const f = new Fixture();
  assertEquals(await f.action("schedule.install"), {});
  assertEquals(f.files.has(script), false);
  assertEquals(f.files.has(callback), false);
  for (const path of [service, timer]) {
    assertEquals(f.files.get(path)?.mode, 0o644);
    assertEquals(f.files.get(path)?.uid, 0);
    assertEquals(f.files.get(path)!.content.includes(privateCommand), false);
  }
  assertEquals(
    f.files.get(service)?.content,
    renderScheduleUnits(input).service,
  );
  const unit = f.files.get(service)!.content;
  assertStringIncludes(unit, "ExecStart=/usr/bin/curl --disable ");
  assertStringIncludes(
    unit,
    '--proto =https --noproxy "*" --cacert /etc/orbit/agent/ca.pem',
  );
  assertStringIncludes(
    unit,
    `--request POST --header "Idempotency-Key: \${INVOCATION_ID}" --output /dev/null https://gateway.orbit/api/v1/schedules/${id}/run`,
  );
  assertStringIncludes(unit, `X-Orbit-Command-SHA256=${input.command_hash}`);
  assertEquals(
    /--location|--insecure|Authorization:|ExecStopPost|schedules-run|schedules-complete/
      .test(unit),
    false,
  );
  assertEquals(
    f.calls.every((call) => !JSON.stringify(call).includes(privateCommand)),
    true,
  );
  assertEquals([f.enabled, f.active], [false, false]);
});

Deno.test("new Schedule timers apply enabled desired state", async () => {
  const f = new Fixture();
  assertEquals(
    await f.action("schedule.install", { ...input, desired: "enabled" }),
    {},
  );
  assertEquals([f.enabled, f.active], [true, true]);
});

Deno.test("identical add preserves actual timer state and changed public specifications refuse before effects", async () => {
  const f = new Fixture();
  assertEquals(await f.action("schedule.install"), {});
  f.enabled = true;
  f.active = false;
  f.calls = [];
  assertEquals(await f.action("schedule.install"), {});
  assertEquals([f.enabled, f.active], [true, false]);
  assertEquals(f.mutations(), []);
  const previous = structuredClone(f.files);
  for (
    const change of [{ command_hash: "b".repeat(64) }, { calendar: "hourly" }, {
      timeout_seconds: 30,
    }, { login_shell: true }]
  ) {
    assertEquals(await f.action("schedule.install", { ...input, ...change }), {
      error_code: "schedule.artifact_conflict",
    });
    assertEquals(f.files, previous);
    assertEquals(f.mutations(), []);
  }
});

Deno.test("legacy migration checks exact context and command then preserves actual enabled and active states", async () => {
  for (
    const [enabled, active] of [[true, false], [false, true], [false, false], [
      true,
      true,
    ]]
  ) {
    const f = new Fixture();
    legacy(f);
    f.enabled = enabled;
    f.active = active;
    assertEquals(
      await f.action("schedule.install", {
        ...input,
        desired: enabled ? "disabled" : "enabled",
        command: privateCommand,
      }),
      {},
    );
    assertEquals([f.enabled, f.active], [enabled, active]);
    assertEquals(
      f.files.get(service)!.content,
      renderScheduleUnits(input).service,
    );
    assertEquals(f.files.has(script), false);
    assertEquals(f.files.has(callback), false);
    assertEquals(
      f.calls.some((call) => call.program.includes("orbit-agent")),
      false,
    );
    assertEquals(
      f.calls.filter((call) => call.stdin?.includes(privateCommand)).length,
      0,
    );
  }
});

Deno.test("old artifacts cannot migrate or delete with only a marker or a different private command", async () => {
  for (const action of ["schedule.install", "schedule.remove"] as const) {
    for (
      const change of [
        "command",
        "script",
        "service",
        "timer",
        "callback",
        "missing-private",
      ]
    ) {
      const f = new Fixture();
      legacy(f);
      const value: Record<string, unknown> = {
        ...input,
        command: privateCommand,
        ...(action === "schedule.remove" ? { cascade: true } : {}),
      };
      if (change === "command") value.command = "false";
      else if (change === "missing-private") delete value.command;
      else if (change === "callback") {
        put(f, callback, `# X-Orbit-Schedule-ID=${id}\nforeign`, 0o700);
      } else {f.files.get(
          change === "script" ? script : change === "service" ? service : timer,
        )!.content += "# changed\n";}
      const before = structuredClone(f.files);
      assertEquals(await f.action(action, value), {
        error_code: action === "schedule.remove" && change === "missing-private"
          ? "schedule.failed"
          : "schedule.artifact_conflict",
      });
      assertEquals(f.files, before);
      assertEquals(f.mutations(), []);
    }
  }
});

Deno.test("legacy removal validates old files and never installs or enables an admission timer", async () => {
  const f = new Fixture();
  legacy(f);
  f.enabled = true;
  assertEquals(
    await f.action("schedule.remove", {
      ...input,
      command: privateCommand,
      cascade: true,
    }),
    {},
  );
  for (const path of [script, callback, service, timer]) {
    assertEquals(f.files.has(path), false);
  }
  assertEquals(
    f.calls.some((call) =>
      call.program === "/usr/bin/install" || call.args[0] === "enable" ||
      call.args[0] === "start"
    ),
    false,
  );
});

Deno.test("failed legacy migration restores the old closed artifacts and separate timer state", async () => {
  const f = new Fixture();
  legacy(f);
  f.enabled = true;
  f.active = false;
  f.verifyFails = true;
  const before = structuredClone(f.files);
  assertEquals(
    await f.action("schedule.install", { ...input, command: privateCommand }),
    { error_code: "schedule.failed" },
  );
  for (const path of [script, callback, service, timer]) {
    assertEquals(f.files.get(path), before.get(path));
  }
  assertEquals([f.enabled, f.active], [true, false]);
});

Deno.test("foreign files, links and writable directories refuse before stop or removal", async () => {
  for (
    const name of [
      "schedule.install",
      "schedule.activate",
      "schedule.remove",
    ] as const
  ) {
    for (
      const drift of [{ uid: 1000 }, { type: "symbolic link" }, { links: 2 }, {
        mode: 0o666,
      }, { content: "foreign" }]
    ) {
      const f = new Fixture();
      assertEquals(await f.action("schedule.install"), {});
      Object.assign(f.files.get(service)!, drift);
      f.calls = [];
      const before = structuredClone(f.files);
      const value = name === "schedule.install"
        ? input
        : name === "schedule.remove"
        ? { id, group: "orbit", cascade: true }
        : target;
      assertEquals(await f.action(name, value), {
        error_code: "schedule.artifact_conflict",
      });
      assertEquals(f.files, before);
      assertEquals(f.mutations(), []);
    }
  }
  const f = new Fixture();
  f.files.set("/etc/orbit", {
    type: "symbolic link",
    uid: 0,
    gid: 0,
    mode: 0o755,
    links: 1,
    content: "",
  });
  assertEquals(await f.action("schedule.install"), {
    error_code: "schedule.artifact_conflict",
  });
  assertEquals(f.mutations(), []);
});

Deno.test("invalid intent and unavailable execution account refuse without effects", async () => {
  for (
    const change of [
      { id: "../other" },
      { calendar: "invalid" },
      { command_hash: "invalid" },
      { timeout_seconds: 0 },
      { shell: "/bin/sh\nExecStart=bad" },
      { home: "/foreign-home" },
    ]
  ) {
    const f = new Fixture();
    const before = structuredClone(f.files);
    assertMatch(
      String(
        (await f.action("schedule.install", { ...input, ...change }))
          .error_code,
      ),
      /^schedule\./,
    );
    assertEquals(f.files, before);
    assertEquals(f.mutations(), []);
  }
  const f = new Fixture();
  f.unavailable = true;
  assertEquals(await f.action("schedule.install"), {
    error_code: "schedule.target_unavailable",
  });
  assertEquals(f.mutations(), []);
});

Deno.test("verification failure restores absent artifacts and reports failed rollback separately", async () => {
  const f = new Fixture();
  f.verifyFails = true;
  assertEquals(await f.action("schedule.install"), {
    error_code: "schedule.failed",
  });
  for (const path of [script, callback, service, timer]) {
    assertEquals(f.files.has(path), false);
  }
  f.reloadFails = true;
  assertEquals(await f.action("schedule.install"), {
    error_code: "schedule.rollback_failed",
  });
});

Deno.test("activation restores disabled timer state on refusal", async () => {
  const f = new Fixture();
  assertEquals(await f.action("schedule.install"), {});
  f.enableFails = true;
  assertEquals(await f.action("schedule.activate", target), {
    error_code: "schedule.failed",
  });
  assertEquals([f.enabled, f.active], [false, false]);
  f.enableFails = false;
  assertEquals(await f.action("schedule.activate", target), {});
  assertEquals([f.enabled, f.active], [true, true]);
});

Deno.test("standalone removal checks active work before disabling its timer and cascade deletes owned units", async () => {
  const f = new Fixture();
  assertEquals(await f.action("schedule.install"), {});
  f.serviceActive = true;
  f.enabled = true;
  f.calls = [];
  const remove = { id, group: "orbit", cascade: false };
  assertEquals(await f.action("schedule.remove", remove), {
    error_code: "schedule.run_active",
  });
  assertEquals(f.mutations(), []);
  assertEquals(f.enabled, true);
  assertEquals(
    await f.action("schedule.remove", { ...remove, cascade: true }),
    {},
  );
  assertEquals(f.serviceActive, false);
  assertEquals(f.files.has(service), false);
  assertEquals(f.files.has(timer), false);
  assertEquals(await f.action("schedule.remove", remove), {});
});

Deno.test("systemd parses generated public units without private files", async () => {
  const unit = renderScheduleUnits(input);
  const directory = await Deno.makeTempDir({ prefix: "schedule-unit-" });
  try {
    const servicePath = `${directory}/orbit-schedule-${id}.service`;
    const timerPath = `${directory}/orbit-schedule-${id}.timer`;
    await Deno.writeTextFile(servicePath, unit.service);
    await Deno.writeTextFile(timerPath, unit.timer);
    const result = await new Deno.Command("/usr/bin/systemd-analyze", {
      args: ["verify", servicePath, timerPath],
      stdout: "null",
      stderr: "piped",
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
});

Deno.test("cancellation after installation starts permits completion and rollback", async () => {
  for (const verifyFails of [false, true]) {
    const f = new Fixture();
    f.verifyFails = verifyFails;
    const cancellation = new AbortController();
    const run: SystemCommand = async (program, argv, stdin, signal) => {
      signal?.throwIfAborted();
      const result = await f.run(program, argv, stdin, signal);
      if (argv.includes("disable")) cancellation.abort();
      return result;
    };
    assertEquals(
      await scheduleSystem("schedule.install", input, cancellation.signal, run),
      verifyFails ? { error_code: "schedule.failed" } : {},
    );
    assertEquals(cancellation.signal.aborted, true);
    for (const path of [service, timer]) {
      assertEquals(f.files.has(path), !verifyFails);
    }
    assertEquals([f.enabled, f.active], [false, false]);
  }
});

Deno.test("cancellation before the first effect refuses without mutation", async () => {
  const f = new Fixture();
  const before = structuredClone(f.files);
  const cancellation = new AbortController();
  const run: SystemCommand = async (program, argv, stdin, signal) => {
    signal?.throwIfAborted();
    const result = await f.run(program, argv, stdin, signal);
    if (argv.includes("calendar")) cancellation.abort();
    return result;
  };
  assertEquals(
    await scheduleSystem("schedule.install", input, cancellation.signal, run),
    { error_code: "schedule.failed" },
  );
  assertEquals(f.files, before);
  assertEquals(f.mutations(), []);
});

function directArtifacts(f: Fixture) {
  legacy(f);
  put(
    f,
    callback,
    `#!/bin/sh\n# X-Orbit-Schedule-ID=${id}\nstatus=error\n[ "$SERVICE_RESULT" != success ] || status=success\n/usr/bin/curl --disable --silent --fail --max-time 10 --proto '=https' --noproxy '*' --cacert /etc/orbit/agent/ca.pem --header 'Content-Type: application/json' --data "{\\"status\\":\\"$status\\"}" 'https://gateway.orbit/api/v1/schedules/${id}/complete' >/dev/null 2>&1 || :\n`,
    0o700,
  );
  put(
    f,
    service,
    `[Unit]\nDescription=Orbit Schedule ${id}\nX-Orbit-Schedule-ID=${id}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=orbit\nGroup=orbit\nWorkingDirectory=/home/orbit/current\nExecStart=/usr/bin/env -i "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" "HOME=/home/orbit" "SHELL=/bin/bash" "USER=orbit" "LOGNAME=orbit" "/bin/bash" ${script}\nExecStopPost=+/bin/sh ${callback}\nTimeoutStartSec=60\n`,
  );
}

Deno.test("migration removes only an exact obsolete completion callback and script", async () => {
  for (const action of ["schedule.install", "schedule.remove"] as const) {
    const f = new Fixture();
    directArtifacts(f);
    assertEquals(
      await f.action(action, {
        ...input,
        command: privateCommand,
        ...(action === "schedule.remove" ? { cascade: false } : {}),
      }),
      {},
    );
    assertEquals(f.files.has(script), false);
    assertEquals(f.files.has(callback), false);
    assertEquals(f.files.has(service), action === "schedule.install");
    assertEquals(
      f.calls.some((call) =>
        call.program.includes("orbit-agent") ||
        call.program === "/usr/bin/timeout"
      ),
      false,
    );
  }
});

Deno.test("interrupted cleanup validates remaining obsolete files against the published public unit", async () => {
  const f = new Fixture();
  directArtifacts(f);
  put(f, service, renderScheduleUnits(input).service);
  f.files.delete(script);
  f.enabled = true;
  f.active = false;
  assertEquals(
    await f.action("schedule.install", { ...input, command: privateCommand }),
    {},
  );
  assertEquals(f.files.has(callback), false);
  assertEquals([f.enabled, f.active], [true, false]);
});

Deno.test("marker-preserving service changes refuse activation and deletion before effects", async () => {
  for (const action of ["schedule.activate", "schedule.remove"] as const) {
    const f = new Fixture();
    assertEquals(await f.action("schedule.install"), {});
    f.files.get(service)!.content = f.files.get(service)!.content.replace(
      "--request POST",
      "--request DELETE",
    );
    f.calls = [];
    assertEquals(
      await f.action(
        action,
        action === "schedule.activate"
          ? target
          : { id, group: "orbit", cascade: true },
      ),
      { error_code: "schedule.artifact_conflict" },
    );
    assertEquals(f.mutations(), []);
  }
});

Deno.test("busy legacy migration and standalone deletion retain the original owned runtime", async () => {
  for (const action of ["schedule.install", "schedule.remove"] as const) {
    const f = new Fixture();
    legacy(f);
    f.enabled = true;
    f.active = false;
    f.serviceActive = true;
    const before = structuredClone(f.files);
    assertEquals(
      await f.action(action, {
        ...input,
        command: privateCommand,
        ...(action === "schedule.remove" ? { cascade: false } : {}),
      }),
      { error_code: "schedule.run_active" },
    );
    for (const path of [script, callback, service, timer]) {
      assertEquals(f.files.get(path), before.get(path));
    }
    assertEquals([f.enabled, f.active], [true, false]);
    assertEquals(f.serviceActive, true);
    assertEquals(f.calls.some((call) => call.args[0] === "stop"), false);
  }
});

Deno.test("same-root Schedule account lookup reads actual primary group without guessing", async () => {
  const calls: readonly string[][] = [];
  const trace: string[][] = calls as string[][];
  const run: SystemCommand = (program, args) => {
    assertEquals(program, "/usr/bin/sudo");
    trace.push([...args]);
    return Promise.resolve({
      code: 0,
      stdout: args.includes("passwd")
        ? "orbit-app-9:x:1234:5678::/home/orbit-app-9:/usr/sbin/nologin\n"
        : "applications:x:5678:orbit-app-9\n",
    });
  };
  assertEquals(await lookupScheduleAccount("orbit-app-9", undefined, run), {
    user: "orbit-app-9",
    uid: 1234,
    gid: 5678,
    group: "applications",
    home: "/home/orbit-app-9",
    shell: "/usr/sbin/nologin",
  });
  assertEquals(trace, [
    ["-n", "--", "/usr/bin/getent", "passwd", "orbit-app-9"],
    ["-n", "--", "/usr/bin/getent", "group", "5678"],
  ]);
});
Deno.test("same-root Schedule account lookup rejects missing root foreign and mismatched primary group", async () => {
  for (
    const passwd of [
      "",
      "orbit-app-9:x:0:5678::/home/orbit-app-9:/bin/bash",
      "foreign:x:1234:5678::/home/foreign:/bin/bash",
      "orbit-app-9:x:1234:5678::/home/orbit-app-9:/bin/bash",
    ]
  ) {
    const run: SystemCommand = (_program, args) =>
      Promise.resolve({
        code: 0,
        stdout: args.includes("passwd") ? passwd : "foreign:x:9999:",
      });
    await assertRejects(() =>
      lookupScheduleAccount("orbit-app-9", undefined, run)
    );
  }
});
