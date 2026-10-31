import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { processSystem } from "./process-system.ts";
import type { CommandResult, SystemCommand } from "./system.ts";

type File = {
  type: number;
  mode: number;
  content: string;
  uid: number;
  gid: number;
  links: number;
};
type Container = { owner: number; spec: string; running: boolean };
type Call = { program: string; args: readonly string[]; stdin?: string };
const unit = "orbit-process-7-proof.service";
const container = "orbit-process-7-proof";
const units = "/etc/systemd/system";
const candidates = "/etc/orbit/systemd-candidates";
const environments = "/etc/orbit/processes";
const good = (): CommandResult => ({ code: 0, stdout: "" });
const bad = (): CommandResult => ({ code: 1, stdout: "" });
const digest = async (value: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");

/** Models actual file ownership and Docker renames, rather than canned call answers. */
class Host {
  files = new Map<string, File>();
  containers = new Map<string, Container>();
  calls: Call[] = [];
  active = new Set<string>();
  fail?: (call: Call) => boolean;
  constructor() {
    for (const path of ["/etc", "/etc/orbit", units]) this.dir(path);
  }
  dir(path: string, mode = 0o755) {
    this.files.set(path, {
      type: 0x4000,
      mode,
      content: "",
      uid: 0,
      gid: 0,
      links: 2,
    });
  }
  file(path: string, content: string, mode = 0o644) {
    this.files.set(path, {
      type: 0x8000,
      mode,
      content,
      uid: 0,
      gid: 0,
      links: 1,
    });
  }
  run: SystemCommand = async (program, fullArgs, stdin) => {
    await Promise.resolve();
    assertEquals(program, "/usr/bin/sudo");
    assertEquals(fullArgs.slice(0, 2), ["-n", "--"]);
    const actual = fullArgs[2];
    const args = fullArgs.slice(3);
    const call = { program: actual, args, stdin };
    this.calls.push(call);
    if (this.fail?.(call)) return bad();
    switch (actual) {
      case "/usr/bin/test": {
        const file = this.files.get(args[1]);
        const yes = args[0] === "-e"
          ? file !== undefined && file.type !== 0xa000
          : args[0] === "-L"
          ? file?.type === 0xa000
          : args[0] === "-d" && file?.type === 0x4000;
        return yes ? good() : bad();
      }
      case "/usr/bin/stat": {
        const file = this.files.get(args.at(-1)!);
        return file
          ? {
            code: 0,
            stdout: `${
              (file.type | file.mode).toString(16)
            }:${file.uid}:${file.gid}:${file.mode.toString(8)}:${file.links}`,
          }
          : bad();
      }
      case "/usr/bin/cat": {
        const file = this.files.get(args.at(-1)!);
        return file ? { code: 0, stdout: file.content } : bad();
      }
      case "/usr/bin/mkdir":
        this.dir(args.at(-1)!, parseInt(args[1], 8));
        return good();
      case "/usr/bin/chmod":
        this.files.get(args.at(-1)!)!.mode = parseInt(args[0], 8);
        return good();
      case "/usr/bin/install":
        this.file(args.at(-1)!, stdin!, parseInt(args[1], 8));
        return good();
      case "/usr/bin/mv": {
        const source = args.at(-2)!;
        const destination = args.at(-1)!;
        const file = this.files.get(source);
        if (!file) return bad();
        this.files.set(destination, file);
        this.files.delete(source);
        return good();
      }
      case "/usr/bin/rm":
        this.files.delete(args.at(-1)!);
        return good();
      case "/usr/bin/rmdir": {
        const path = args.at(-1)!;
        if ([...this.files.keys()].some((key) => key.startsWith(path + "/"))) {
          return bad();
        }
        this.files.delete(path);
        return good();
      }
      case "/usr/bin/find":
        return {
          code: 0,
          stdout: [...this.files.keys()].filter((key) =>
            key.startsWith(args[0] + "/") &&
            !key.slice(args[0].length + 1).includes("/")
          ).map((key) => key.slice(args[0].length + 1) + "\0").join(""),
        };
      case "/usr/bin/sync":
      case "/usr/bin/systemd-analyze":
        return good();
      case "/usr/bin/systemctl": {
        const artifact = args.at(-1)!;
        if (args[0] === "is-active") {
          return {
            code: this.active.has(artifact) ? 0 : 3,
            stdout: this.active.has(artifact) ? "active\n" : "inactive\n",
          };
        }
        if (
          ["start", "restart"].includes(args[0]) ||
          (args[0] === "enable" && args.includes("--now"))
        ) this.active.add(artifact);
        if (args[0] === "disable") this.active.delete(artifact);
        return good();
      }
      case "/usr/bin/docker": {
        if (args[0] === "image") {
          return { code: 0, stdout: "sha256:" + "a".repeat(64) };
        }
        assertEquals(args[0], "container");
        const artifact = args.at(-1)!;
        if (args[1] === "ls") {
          const filter = args[args.indexOf("--filter") + 1];
          const selected = filter.slice(7, -1);
          return {
            code: 0,
            stdout: this.containers.has(selected) ? selected + "\n" : "",
          };
        }
        const state = this.containers.get(artifact);
        if (args[1] === "inspect") {
          if (!state) return bad();
          return {
            code: 0,
            stdout: args[3] === "{{.State.Status}}"
              ? state.running ? "running\n" : "exited\n"
              : `true\nprocess\n${state.owner}\n${state.spec}\n${state.running}\n`,
          };
        }
        if (args[1] === "rename") {
          const source = args[2];
          if (!this.containers.has(source) || this.containers.has(args[3])) {
            return bad();
          }
          this.containers.set(args[3], this.containers.get(source)!);
          this.containers.delete(source);
          return good();
        }
        if (!state) return bad();
        if (["start", "restart"].includes(args[1])) state.running = true;
        if (args[1] === "stop") state.running = false;
        if (args[1] === "rm") this.containers.delete(artifact);
        return good();
      }
      case "/usr/bin/curl": {
        if (!args.includes("--unix-socket")) return good();
        const body = JSON.parse(stdin!);
        const artifact = args.at(-1)!.split("?name=")[1];
        if (this.containers.has(artifact)) return bad();
        this.containers.set(artifact, {
          owner: Number(body.Labels["orbit.process.id"]),
          spec: body.Labels["orbit.process.spec"],
          running: false,
        });
        return {
          code: 0,
          stdout: JSON.stringify({ Id: "c".repeat(64), Warnings: null }),
        };
      }
      default:
        throw new Error("Unexpected fixed system program");
    }
  };
  invoke = processSystem(this.run, () => Promise.resolve());
  mutations() {
    return this.calls.filter((call) =>
      ["/usr/bin/install", "/usr/bin/mv", "/usr/bin/rm", "/usr/bin/rmdir"]
        .includes(call.program) ||
      (call.program === "/usr/bin/systemctl" && call.args[0] !== "is-active") ||
      (call.program === "/usr/bin/docker" &&
        !["ls", "inspect"].includes(call.args[1]))
    );
  }
}

async function stageInput(secret = "synthetic-system-private") {
  const environment = `# Orbit Process 7\nPASSWORD="${secret}"\n`;
  const hash = await digest(environment);
  const content =
    `[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/sleep infinity\nEnvironmentFile=${environments}/7/${hash}.env\n`;
  return { process_id: 7, unit, content, environment };
}
const dockerInput = () => ({
  process_id: 7,
  container,
  desired: "running",
  spec: "b".repeat(64),
  image: "example:1",
  command: ["/usr/bin/run", "synthetic-private-command"],
  restart: "always",
  working_directory: null,
  ports: ["127.0.0.1:8787:80", "8080:81", "[::1]:8788:82/udp"],
  mounts: [{ source: "proof", target: "/var/lib/proof", read_only: false }],
  environment: { PASSWORD: "synthetic-private-environment" },
});

Deno.test("stage writes immutable root0600 environment and public verified candidate using only private stdin", async () => {
  const host = new Host();
  const input = await stageInput();
  assertEquals(await host.invoke("process.stage-unit", input), {
    staged: true,
    previous: false,
    state: null,
  });
  const environment = host.files.get(
    `${environments}/7/${await digest(input.environment)}.env`,
  )!;
  assertEquals({
    mode: environment.mode,
    uid: environment.uid,
    gid: environment.gid,
    content: environment.content,
  }, { mode: 0o600, uid: 0, gid: 0, content: input.environment });
  assertEquals(host.files.get(environments)!.mode, 0o700);
  assertEquals(host.files.get(`${environments}/7`)!.mode, 0o700);
  assertEquals(host.files.get(candidates)!.mode, 0o700);
  assertEquals(host.files.get(`${candidates}/${unit}`)!.mode, 0o644);
  assert(
    !JSON.stringify(host.calls.map((call) => [call.program, call.args]))
      .includes("synthetic-system-private"),
  );
  assert(
    !host.files.get(`${candidates}/${unit}`)!.content.includes(
      "synthetic-system-private",
    ),
  );
  assert(host.calls.some((call) => call.program === "/usr/bin/sync"));
});

Deno.test("identical stage preserves current unit and reports inactive state", async () => {
  const host = new Host();
  const input = await stageInput();
  host.file(`${units}/${unit}`, input.content);
  assertEquals(await host.invoke("process.stage-unit", input), {
    staged: false,
    previous: true,
    state: "inactive",
  });
  assert(!host.files.has(`${candidates}/${unit}`));
  assert(
    !host.calls.some((call) => call.program === "/usr/bin/systemd-analyze"),
  );
});

Deno.test("stage refuses wrong environment reference and foreign existing unit before filesystem writes", async () => {
  for (const foreign of [false, true]) {
    const host = new Host();
    const input = await stageInput();
    if (foreign) {
      host.file(`${units}/${unit}`, "[Service]\nExecStart=/usr/bin/false\n");
    } else {input.content = input.content.replace(
        await digest(input.environment),
        "a".repeat(64),
      );}
    assertEquals(await host.invoke("process.stage-unit", input), {
      error_code: foreign ? "process.name_collision" : "process.invalid",
    });
    assertEquals(host.mutations(), []);
  }
});

Deno.test("stage refuses symlink hardlink public or foreign environment generations", async () => {
  for (
    const override of [{ type: 0xa000 }, { links: 2 }, { mode: 0o644 }, {
      uid: 1000,
    }]
  ) {
    const host = new Host();
    const input = await stageInput();
    host.dir(environments, 0o700);
    host.dir(`${environments}/7`, 0o700);
    const path = `${environments}/7/${await digest(input.environment)}.env`;
    host.file(path, input.environment, 0o600);
    Object.assign(host.files.get(path)!, override);
    assertEquals(await host.invoke("process.stage-unit", input), {
      error_code: "process.invalid",
    });
    assertEquals(host.mutations(), []);
  }
});

Deno.test("invalid systemd candidate is removed without changing the current unit", async () => {
  const host = new Host();
  const input = await stageInput();
  const previous =
    "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/true\n";
  host.file(`${units}/${unit}`, previous);
  host.fail = (call) => call.program === "/usr/bin/systemd-analyze";
  assertEquals(await host.invoke("process.stage-unit", input), {
    error_code: "process.candidate_invalid",
  });
  assertEquals(host.files.get(`${units}/${unit}`)!.content, previous);
  assert(!host.files.has(`${candidates}/${unit}`));
  assert(!host.calls.some((call) => call.program === "/usr/bin/systemctl"));
});

Deno.test("failed replacement restores old unit and immutable environment generation before restoring service", async () => {
  const host = new Host();
  const old = await stageInput("old-private-value");
  const next = await stageInput("next-private-value");
  await host.invoke("process.stage-unit", old);
  await host.invoke("process.activate-unit", {
    process_id: 7,
    unit,
    change: "create",
    desired: "running",
    on_demand: false,
  });
  await host.invoke("process.stage-unit", next);
  let failed = false;
  host.fail = (call) =>
    call.program === "/usr/bin/systemctl" && call.args[0] === "restart" &&
    !failed && (failed = true);
  assertEquals(
    await host.invoke("process.activate-unit", {
      process_id: 7,
      unit,
      change: "replace",
      desired: "running",
      on_demand: false,
    }),
    { error_code: "process.start_failed" },
  );
  assertEquals(host.files.get(`${units}/${unit}`)!.content, old.content);
  assert(host.active.has(unit));
  assert(
    host.files.has(`${environments}/7/${await digest(old.environment)}.env`),
  );
  assert(
    host.files.has(`${environments}/7/${await digest(next.environment)}.env`),
  );
  const backup = host.calls.find((call) =>
    call.program === "/usr/bin/install" &&
    call.args.at(-1) === `${candidates}/.${unit}.backup`
  )!;
  assertEquals(backup.args[1], "0600");
});

Deno.test("on-demand activation restarts without enabling boot activation", async () => {
  const host = new Host();
  await host.invoke("process.stage-unit", await stageInput());
  assertEquals(
    await host.invoke("process.activate-unit", {
      process_id: 7,
      unit,
      change: "create",
      desired: "running",
      on_demand: true,
    }),
    { backup_left: false },
  );
  assert(
    !host.calls.some((call) =>
      call.program === "/usr/bin/systemctl" && call.args[0] === "enable"
    ),
  );
  assert(host.active.has(unit));
});

Deno.test("removal validates whole private set before stopping or deleting anything", async () => {
  const host = new Host();
  const input = await stageInput();
  await host.invoke("process.stage-unit", input);
  await host.invoke("process.activate-unit", {
    process_id: 7,
    unit,
    change: "create",
    desired: "running",
    on_demand: false,
  });
  host.file(`${environments}/7/foreign.env`, "foreign", 0o600);
  host.calls = [];
  assertEquals(
    await host.invoke("process.remove", {
      process_id: 7,
      runtime: "systemd",
      name: unit,
      vite: null,
    }),
    { error_code: "process.invalid" },
  );
  assertEquals(host.mutations(), []);
  assert(host.active.has(unit));
});

Deno.test("removal deletes only selected Process generations and candidate/backup after ownership checks", async () => {
  const host = new Host();
  const input = await stageInput();
  await host.invoke("process.stage-unit", input);
  host.file(`${units}/${unit}`, input.content);
  host.file(`${candidates}/.${unit}.backup`, input.content, 0o600);
  host.dir(`${environments}/8`, 0o700);
  host.file(`${environments}/8/kept.env`, "another Process", 0o600);
  assertEquals(
    await host.invoke("process.remove", {
      process_id: 7,
      runtime: "systemd",
      name: unit,
      vite: null,
    }),
    {},
  );
  assert(!host.files.has(`${units}/${unit}`));
  assert(!host.files.has(`${candidates}/${unit}`));
  assert(!host.files.has(`${candidates}/.${unit}.backup`));
  assert(!host.files.has(`${environments}/7`));
  assert(host.files.has(`${environments}/8/kept.env`));
});

Deno.test("Docker create transports command and environment only in Engine JSON stdin", async () => {
  const host = new Host();
  const input = dockerInput();
  assertEquals(await host.invoke("process.converge-container", input), {});
  const call = host.calls.find((entry) => entry.program === "/usr/bin/curl")!;
  const body = JSON.parse(call.stdin!);
  assertEquals(body.Cmd, input.command);
  assertEquals(body.Env, ["PASSWORD=synthetic-private-environment"]);
  assertEquals(body.HostConfig.PortBindings, {
    "80/tcp": [{ HostIp: "127.0.0.1", HostPort: "8787" }],
    "81/tcp": [{ HostIp: "", HostPort: "8080" }],
    "82/udp": [{ HostIp: "::1", HostPort: "8788" }],
  });
  assertEquals(host.containers.get(container), {
    owner: 7,
    spec: input.spec,
    running: true,
  });
  const argv = JSON.stringify(
    host.calls.map((entry) => [entry.program, entry.args]),
  );
  assert(!argv.includes("synthetic-private-command"));
  assert(!argv.includes("synthetic-private-environment"));
});

Deno.test("Docker removal refuses any foreign rollback before removing its owned canonical container", async () => {
  const host = new Host();
  host.containers.set(container, {
    owner: 7,
    spec: "a".repeat(64),
    running: true,
  });
  host.containers.set(container + "-rollback-running", {
    owner: 8,
    spec: "a".repeat(64),
    running: false,
  });
  assertEquals(
    await host.invoke("process.remove", {
      process_id: 7,
      runtime: "docker",
      name: container,
      vite: null,
    }),
    { error_code: "process.name_collision" },
  );
  assertEquals(host.mutations(), []);
  assert(host.containers.get(container)!.running);
});

Deno.test("Docker failed replacement start restores the previous running container and removes failed candidate", async () => {
  const host = new Host();
  const old = { owner: 7, spec: "a".repeat(64), running: true };
  host.containers.set(container, { ...old });
  let failed = false;
  host.fail = (call) =>
    call.program === "/usr/bin/docker" && call.args[1] === "start" &&
    call.args[2] === container && !failed && (failed = true);
  assertEquals(await host.invoke("process.converge-container", dockerInput()), {
    error_code: "process.start_failed",
  });
  assertEquals(host.containers.get(container), old);
  assertEquals([...host.containers.keys()], [container]);
});

Deno.test("Docker readiness failure rolls publication back without exposing health URL", async () => {
  const host = new Host();
  const old = { owner: 7, spec: "a".repeat(64), running: true };
  host.containers.set(container, { ...old });
  host.fail = (call) =>
    call.program === "/usr/bin/curl" && !call.args.includes("--unix-socket");
  assertEquals(
    await host.invoke("process.converge-container", {
      ...dockerInput(),
      health: {
        url: "http://127.0.0.1:8787/ready?token=synthetic-health-token",
        attempts: 2,
        interval_seconds: 0,
      },
    }),
    { error_code: "process.health_failed" },
  );
  assertEquals(host.containers.get(container), old);
  assertEquals([...host.containers.keys()], [container]);
  assert(
    !JSON.stringify(host.calls.map((call) => call.args)).includes(
      "synthetic-health-token",
    ),
  );
});

Deno.test("Docker stale expected spec or ambiguous rollback refuses before effects", async () => {
  for (const ambiguous of [false, true]) {
    const host = new Host();
    host.containers.set(container, {
      owner: 7,
      spec: "a".repeat(64),
      running: true,
    });
    if (ambiguous) {
      host.containers.set(container + "-rollback-running", {
        owner: 7,
        spec: "a".repeat(64),
        running: false,
      });
      host.containers.set(container + "-rollback-stopped", {
        owner: 7,
        spec: "a".repeat(64),
        running: false,
      });
    }
    assertEquals(
      await host.invoke("process.converge-container", {
        ...dockerInput(),
        ...(ambiguous ? {} : { expected_spec: "f".repeat(64) }),
      }),
      {
        error_code: ambiguous
          ? "process.recovery_required"
          : "process.spec_changed",
      },
    );
    assertEquals(host.mutations(), []);
  }
});

Deno.test("Docker interrupted publication restores rollback then safely converges in the same call", async () => {
  const host = new Host();
  host.containers.set(container + "-rollback-running", {
    owner: 7,
    spec: "a".repeat(64),
    running: false,
  });
  host.containers.set(container + "-candidate", {
    owner: 7,
    spec: "b".repeat(64),
    running: false,
  });
  assertEquals(
    await host.invoke("process.converge-container", dockerInput()),
    {},
  );
  assertEquals(host.containers.get(container), {
    owner: 7,
    spec: "b".repeat(64),
    running: true,
  });
  assertEquals([...host.containers.keys()], [container]);
});

Deno.test("system controls enforce marker identity and preserve on-demand start behavior", async () => {
  const host = new Host();
  host.file(`${units}/${unit}`, "X-Orbit-Process-ID=8\n");
  const input = {
    process_id: 7,
    runtime: "systemd",
    name: unit,
    operation: "start",
    on_demand: true,
  };
  assertEquals(await host.invoke("process.control", input), {
    error_code: "process.name_collision",
  });
  assertEquals(host.mutations(), []);
  host.file(`${units}/${unit}`, "X-Orbit-Process-ID=7\n");
  assertEquals(await host.invoke("process.control", input), {});
  assertEquals(host.calls.at(-1)!.args, ["start", unit]);
});

Deno.test("invalid paths names or Docker ports refuse before any system command", async () => {
  const host = new Host();
  assertEquals(
    await host.invoke("process.status", {
      process_id: 7,
      runtime: "systemd",
      name: "../../foreign.service",
    }),
    { error_code: "process.invalid" },
  );
  assertEquals(
    await host.invoke("process.check-release", {
      directory: "/home/orbit/../foreign",
    }),
    { error_code: "process.invalid" },
  );
  assertEquals(
    await host.invoke("process.converge-container", {
      ...dockerInput(),
      ports: ["8080:0"],
    }),
    { error_code: "process.invalid" },
  );
  assertEquals(host.calls, []);
});

Deno.test("private system transport exceptions produce only a fixed diagnostic", async () => {
  const invoke = processSystem(() =>
    Promise.reject(new Error("synthetic-private-diagnostic"))
  );
  await assertRejects(
    () => invoke("process.check-release", { directory: "/home/orbit" }),
    Error,
    "Process system action did not complete",
  );
});
