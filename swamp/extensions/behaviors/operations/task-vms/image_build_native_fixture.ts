import { digest, guestArgv } from "./image_guest.ts";
const file = "image-build-fixture.json";
async function save(state: Record<string, unknown>) {
  await Deno.writeTextFile(file, JSON.stringify(state));
}
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push(path);
  if (method === "GET" && path.includes("/private-material")) {
    await save(state);
    const bytes = new TextEncoder().encode(
      Deno.env.get("ORBIT_TEST_PI_ARTIFACT") ?? "",
    );
    if (path.includes("&artifact=pi_artifact_file")) {
      return {
        status: 200,
        body: state.corruptArtifact
          ? bytes.map((byte, index) => index === 0 ? byte ^ 1 : byte)
          : bytes,
      };
    }
    const run = new URL(path, "https://gateway.test").searchParams.get(
      "run_id",
    );
    return {
      status: 200,
      body: {
        data: {
          pi_artifact_file: {
            path:
              `/api/v1/direct-operations/7/private-material?run_id=${run}&artifact=pi_artifact_file`,
            sha256: state.operation.input.pi_artifact.sha256,
            bytes: bytes.length,
            max_bytes: 268435456,
          },
        },
      },
    };
  }
  if (method === "POST") {
    const body = raw as Record<string, unknown>;
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    await save(state);
    if (body.status === "succeeded" && state.loseFinal) {
      state.loseFinal = false;
      await save(state);
      throw new Error("Lost final projection reply");
    }
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
}
export async function fixtureHost(
  call: { name: string; input: Record<string, unknown> },
) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.actions.push(call);
  const operation = call.input.operation as Record<string, unknown> | undefined;
  const vm = call.input.vm as { name: string } | undefined;
  if (call.name === "incus.vm" && vm) {
    state.instances ??= {};
    if (operation?.kind === "create_image") {
      state.instances[vm.name] = "Running";
    }
    if (operation?.kind === "remove_instance") delete state.instances[vm.name];
  }
  await save(state);
  if (call.name === "incus.volume") {
    return { name: "orbit-topology-seed-" + "c".repeat(12) };
  }
  return { ready: true, fingerprint: "c".repeat(64) };
}
export function fixtureGuest(
  project: string,
  nameOfGuest: string,
  _pin: string,
) {
  const boot_id = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
  return {
    bootstrap() {
      return Promise.resolve();
    },
    async artifact(bytes: Uint8Array, sha256: string) {
      if (await digest(bytes) !== sha256) {
        throw new Error("Native artifact changed");
      }
      const state = JSON.parse(await Deno.readTextFile(file));
      state.artifactDelivered = true;
      await save(state);
    },
    async enableSsh() {
      const state = JSON.parse(await Deno.readTextFile(file));
      state.guest.push({name:"ssh.enable",input:{},uid:"0"});
      await save(state);
    },
    async checkSsh() {
      const state = JSON.parse(await Deno.readTextFile(file));
      state.guest.push({name:"ssh.check",input:{},uid:"0"});
      await save(state);
      if(state.failSsh)throw new Error("task_vm.image_ssh_failed");
    },
    async command(name: string, input: Record<string, unknown>) {
      const argv = guestArgv(project, nameOfGuest, [
        "/usr/local/bin/orbit-agent",
        "command",
        name,
      ]);
      const uid = argv[argv.indexOf("--user") + 1];
      if (uid !== (name === "image.owner" ? "1000" : "0")) {
        throw new Error("agent.identity_mismatch");
      }
      const state = JSON.parse(await Deno.readTextFile(file));
      state.guest.push({ name, input, uid });
      if (
        name === "image.start-phase" &&
        (input.key as { phase?: string })?.phase === "packages"
      ) {
        if (state.cancelDuringPhase) state.operation.cancel_requested = true;
        if (state.supersedeDuringPhase) state.operation.input.current = false;
      }
      await save(state);
      if (name === "image.check" && state.failSmoke) {
        throw new Error("task_vm.image_check_failed");
      }
      const phase = (input.key as Record<string, unknown> | undefined)?.phase;
      if (phase === "packages" && state.transientInspect) {
        if (name === "image.start-phase") return { state: "running", boot_id };
        if (name === "image.inspect-phase") {
          const count = state.inspectCount = (state.inspectCount ?? 0) + 1;
          await save(state);
          if (count === 1) return { state: "missing", boot_id };
          if (count === 2) throw new Error("task_vm.image_channel_lost");
          return { state: "complete", boot_id };
        }
      }
      if (name === "image.inspect-phase") return { state: "missing", boot_id };
      if (name === "image.start-phase") return { state: "complete", boot_id };
      return { ready: true };
    },
  };
}

export async function fixtureCleanup(
  command: { command: string; args: readonly string[] },
) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.cleanup ??= [];
  state.cleanup.push(command.args);
  const args = command.args.slice(5);
  const name = args.at(-1)!;
  if (state.failCleanup && args[0] === "delete") {
    await save(state);
    return { success: false, stdout: "" };
  }
  if (args[0] === "stop") state.instances[name] = "Stopped";
  if (args[0] === "delete") delete state.instances[name];
  await save(state);
  if (args[0] === "list") {
    return {
      success: true,
      stdout: JSON.stringify(
        state.instances?.[name]
          ? [{
            name,
            type: "virtual-machine",
            status: state.instances[name],
            expanded_devices: {
              root: { type: "disk", path: "/", pool: "orbit-tasks" },
            },
          }]
          : [],
      ),
    };
  }
  if (args[0] === "storage") return { success: true, stdout: "[]" };
  return { success: true, stdout: "" };
}
