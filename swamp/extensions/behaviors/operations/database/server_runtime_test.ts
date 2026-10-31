import { assertEquals } from "jsr:@std/assert@1.0.19";
import { processSystem } from "../processes-schedules/process-system.ts";
import type { SystemCommand } from "../processes-schedules/system.ts";
import { serverRuntime } from "./server_runtime.ts";

const input = {
  process_id: 42,
  container: "orbit-process-42-proof",
  desired: "running",
  spec: "a".repeat(64),
  image: "mysql:8.4",
  command: ["mysqld"],
  restart: "unless-stopped",
  working_directory: null,
  ports: ["10.44.0.9:3306:3306"],
  mounts: [{
    source: "orbit-proof-data",
    target: "/var/lib/mysql",
    read_only: false,
  }],
  environment: { MYSQL_ROOT_PASSWORD: "private-initializer" },
};
type State = { owner: number; spec: string; running: boolean };
class Fixture {
  containers = new Map<string, State>();
  calls: { program: string; args: readonly string[]; stdin?: string }[] = [];
  failStart = false;
  command: SystemCommand = (program, args, stdin, _signal, timeoutMs) => {
    this.calls.push({ program, args, stdin });
    assertEquals(program, "/usr/bin/sudo");
    assertEquals(args.slice(0, 2), ["-n", "--"]);
    assertEquals(timeoutMs! > 0 && timeoutMs! <= 900000, true);
    assertEquals(JSON.stringify(args).includes("private-initializer"), false);
    const executable = args[2];
    let command = args.slice(3);
    const ok = { code: 0, stdout: "" };
    if (executable === "/usr/bin/curl") {
      const body = JSON.parse(stdin!);
      const name = command.at(-1)!.split("?name=")[1];
      this.containers.set(name, {
        owner: Number(body.Labels["orbit.process.id"]),
        spec: body.Labels["orbit.process.spec"],
        running: false,
      });
      return Promise.resolve({
        code: 0,
        stdout: JSON.stringify({ Id: "c".repeat(64) }),
      });
    }
    assertEquals(executable, "/usr/bin/docker");
    assertEquals(command[0], "--host=unix:///var/run/docker.sock");
    command = command.slice(1);
    if (command[0] === "image") {
      return Promise.resolve({ code: 0, stdout: "sha256:fixture" });
    }
    if (command[1] === "ls") {
      const filter = command[command.indexOf("--filter") + 1];
      const name = filter.slice(7, -1);
      return Promise.resolve({
        code: 0,
        stdout: this.containers.has(name) ? name : "",
      });
    }
    if (command[1] === "inspect") {
      const state = this.containers.get(command.at(-1)!);
      return Promise.resolve(
        state
          ? {
            code: 0,
            stdout:
              `true\nprocess\n${state.owner}\n${state.spec}\n${state.running}\n`,
          }
          : { code: 1, stdout: "" },
      );
    }
    if (command[1] === "rename") {
      const state = this.containers.get(command[2]);
      if (!state || this.containers.has(command[3])) {
        return Promise.resolve({ code: 1, stdout: "" });
      }
      this.containers.delete(command[2]);
      this.containers.set(command[3], state);
    } else if (command[1] === "rm") this.containers.delete(command.at(-1)!);
    else {
      const state = this.containers.get(command[2]);
      if (!state) return Promise.resolve({ code: 1, stdout: "" });
      if (
        command[1] === "start" && this.failStart &&
        command[2] === input.container && state.spec === input.spec
      ) {
        this.failStart = false;
        return Promise.resolve({ code: 1, stdout: "" });
      }
      state.running = command[1] !== "stop";
    }
    return Promise.resolve(ok);
  };
  runtime = serverRuntime(processSystem(this.command));
  mutations() {
    return this.calls.filter((call) =>
      call.args[2] === "/usr/bin/curl" ||
      ["start", "stop", "rename", "rm", "restart"].includes(call.args[5])
    );
  }
}
Deno.test("server uses canonical Process local Docker creation with private environment only on stdin", async () => {
  const f = new Fixture();
  assertEquals(await f.runtime.converge(input), {});
  const create = f.calls.find((call) => call.args[2] === "/usr/bin/curl")!;
  const body = JSON.parse(create.stdin!);
  assertEquals(body.Env, ["MYSQL_ROOT_PASSWORD=private-initializer"]);
  assertEquals(body.Labels, {
    "orbit.managed": "true",
    "orbit.container.kind": "process",
    "orbit.process.id": "42",
    "orbit.process.spec": input.spec,
  });
  assertEquals(body.HostConfig.Mounts, [{
    Type: "volume",
    Source: "orbit-proof-data",
    Target: "/var/lib/mysql",
    ReadOnly: false,
  }]);
  assertEquals(body.HostConfig.PortBindings, {
    "3306/tcp": [{ HostIp: "10.44.0.9", HostPort: "3306" }],
  });
  assertEquals(f.containers.get(input.container), {
    owner: 42,
    spec: input.spec,
    running: true,
  });
});
Deno.test("foreign canonical, candidate or rollback refuses before any create, stop or deletion", async () => {
  for (
    const suffix of ["", "-candidate", "-rollback-running", "-rollback-stopped"]
  ) {
    const f = new Fixture();
    f.containers.set(input.container + suffix, {
      owner: 99,
      spec: input.spec,
      running: true,
    });
    assertEquals(await f.runtime.converge(input), {
      error_code: "process.name_collision",
    });
    assertEquals(f.mutations(), []);
    assertEquals(
      await f.runtime.remove({ process_id: 42, container: input.container }),
      { error_code: "process.name_collision" },
    );
    assertEquals(f.mutations(), []);
  }
});
Deno.test("canonical Process replacement restores the exact old container if candidate start fails", async () => {
  const f = new Fixture(),
    previous = { owner: 42, spec: "b".repeat(64), running: true };
  f.containers.set(input.container, { ...previous });
  f.failStart = true;
  assertEquals(await f.runtime.converge(input), {
    error_code: "process.start_failed",
  });
  assertEquals([...f.containers.keys()], [input.container]);
  assertEquals(f.containers.get(input.container), previous);
});
Deno.test("owned removal inspects every artifact before the first deletion and is idempotent", async () => {
  const f = new Fixture();
  for (
    const suffix of ["", "-candidate", "-rollback-running", "-rollback-stopped"]
  ) {
    f.containers.set(input.container + suffix, {
      owner: 42,
      spec: input.spec,
      running: true,
    });
  }
  assertEquals(
    await f.runtime.remove({ process_id: 42, container: input.container }),
    {},
  );
  assertEquals(f.containers.size, 0);
  assertEquals(
    f.calls.slice(0, f.calls.findIndex((call) => call.args[5] === "rm")).filter(
      (call) => call.args[5] === "inspect",
    ).length,
    4,
  );
  assertEquals(
    await f.runtime.remove({ process_id: 42, container: input.container }),
    {},
  );
});
Deno.test("unchanged running container performs no mutation and ambiguous rollback refuses", async () => {
  const f = new Fixture();
  f.containers.set(input.container, {
    owner: 42,
    spec: input.spec,
    running: true,
  });
  assertEquals(await f.runtime.converge(input), {});
  assertEquals(f.mutations(), []);
  for (const suffix of ["-rollback-running", "-rollback-stopped"]) {
    f.containers.set(input.container + suffix, {
      owner: 42,
      spec: "b".repeat(64),
      running: false,
    });
  }
  assertEquals(await f.runtime.converge(input), {
    error_code: "process.recovery_required",
  });
  assertEquals(f.mutations(), []);
});
