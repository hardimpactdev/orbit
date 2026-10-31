import { digest } from "./image_guest.ts";
const file = "image-build-fixture.json";
async function save(state: Record<string, unknown>) {
  await Deno.writeTextFile(file, JSON.stringify(state));
}
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push(path);
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
  await save(state);
  if (call.name === "incus.volume") {
    return { name: "orbit-topology-seed-" + "c".repeat(12) };
  }
  return { ready: true, fingerprint: "c".repeat(64) };
}
export function fixtureGuest(_project: string, _name: string, _pin: string) {
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
    async command(name: string, input: Record<string, unknown>) {
      const state = JSON.parse(await Deno.readTextFile(file));
      state.guest.push({ name, input });
      await save(state);
      if (name === "image.check" && state.failSmoke) {
        throw new Error("task_vm.image_check_failed");
      }
      if (name === "image.inspect-phase") return { state: "missing", boot_id };
      if (name === "image.start-phase") return { state: "complete", boot_id };
      return { ready: true };
    },
  };
}
