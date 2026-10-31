import { z } from "npm:zod@4.4.3";
import { type CloudImage, imageCloud } from "./upcloud_image_api.ts";
import { cloudRequest } from "./upcloud_sweep.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { Globals } from "../../operation-runner/mod.ts";

const file = () => Deno.cwd() + "/upcloud-image-fixture.json";
const read = () => JSON.parse(Deno.readTextFileSync(file()));
const save = (state: unknown) =>
  Deno.writeTextFileSync(file(), JSON.stringify(state));
const secret = () => Deno.env.get("ORBIT_TEST_IMAGE_PRIVATE")!;
const token = () => Deno.env.get("ORBIT_TEST_IMAGE_TOKEN")!;
const artifact = () => Deno.env.get("ORBIT_TEST_IMAGE_ARTIFACT")!;
const boot = "00000000-0000-4000-8000-000000000070";
const uuid = (slot: number) =>
  `00000000-0000-4000-8000-${String(slot).padStart(12, "0")}`;
export const fixtureKeys = () =>
  Promise.resolve({
    builder: { private: secret(), public: "ssh-ed25519 AAAA fixture" },
    smoke: { private: secret(), public: "ssh-ed25519 BBBB fixture" },
  });
export const fixtureHttp: Http = (method, path, raw) => {
  const body = z.record(z.string(), z.unknown()).parse(raw ?? {});
  const state = read();
  state.calls.push([method, path]);
  if (path.includes("/private-material")) {
    const runId = new URL("https://fixture" + path).searchParams.get("run_id");
    if (path.includes("artifact=")) {
      return Promise.resolve({
        status: 200,
        body: new TextEncoder().encode(artifact()),
      });
    }
    return fixtureKeys().then((keys) => ({
      status: 200,
      body: {
        data: {
          upcloud_token: token(),
          bootstrap: state.keysStored ? keys : null,
          pi_artifact_file: {
            path:
              `/api/v1/direct-operations/7/private-material?run_id=${runId}&artifact=pi_artifact_file`,
            sha256: state.operation.input.pi_artifact.sha256,
            bytes: artifact().length,
            max_bytes: 268435456,
          },
        },
      },
    }));
  }
  if (path.endsWith("/secret-output")) {
    if (
      (body?.outputs as Record<string, { builder: { private: string } }>)
        .bootstrap.builder.private !== secret()
    ) throw new Error("bad output");
    state.keysStored = true;
    save(state);
    return Promise.resolve({ status: 200, body: { data: {} } });
  }
  if (path.endsWith("/result")) {
    const result = body?.result as Record<string, unknown>;
    state.operation.status = body?.status;
    state.phases.push(result.phase ?? body?.status);
    if (result.resources) {
      Object.assign(state.progress.resources, result.resources);
    }
    if (result.template_uuid) {
      state.progress.template_uuid = result.template_uuid;
    }
    if (result.promoted) state.promoted = true;
    if (body?.status === "failed") state.failure = body.error_code;
    save(state);
    if (body?.status === "succeeded" && state.loseFinal && !state.finalLost) {
      state.finalLost = true;
      save(state);
      throw new Error("lost projection");
    }
    return Promise.resolve({ status: 200, body: { data: { id: 7 } } });
  }
  save(state);
  return Promise.resolve({
    status: 200,
    body: {
      data: {
        ...state.operation,
        input: {
          ...state.operation.input,
          progress: state.progress,
          current: state.current !== false,
        },
      },
    },
  });
};
const provider: typeof fetch = (input, init) => {
  const state = read();
  if (new Headers(init?.headers).get("Authorization") !== "Bearer " + token()) {
    throw new Error("missing credential");
  }
  const path = String(input).replace("https://api.upcloud.com/1.3/", "");
  const method = init?.method ?? "GET";
  const body = init?.body ? JSON.parse(String(init.body)) : {};
  state.provider.push([method, path]);
  let output: unknown = {};
  if (path === "plan") {
    output = {
      plans: { plan: [{ name: "test-plan", storage_tier: "standard" }] },
    };
  } else if (path.startsWith("server?")) {
    output = { servers: { server: Object.values(state.servers) } };
  } else if (path === "server" && method === "POST") {
    const server = body.server;
    if (
      JSON.parse(server.user_data.slice(14)).ssh_keys.ed25519_private !==
        secret() ||
      server.networking.interfaces.interface.length !== 1 ||
      server.remote_access_enabled !== "no"
    ) throw new Error("invalid create");
    const id = uuid(server.title.endsWith("-smoke") ? 12 : 11);
    state.servers[id] = {
      uuid: id,
      title: server.title,
      hostname: server.hostname,
      state: "started",
      labels: server.labels,
      ip_addresses: {
        ip_address: [{
          address: "203.0.113.50",
          access: "public",
          family: "IPv4",
        }],
      },
      storage_devices: { storage_device: [{ storage: uuid(15) }] },
    };
    output = { server: { uuid: id } };
  } else if (path === "storage/template") {
    output = { storages: { storage: Object.values(state.templates) } };
  } else if (path.endsWith("/templatize")) {
    state.templates[uuid(20)] = {
      uuid: uuid(20),
      title: body.storage.title,
      access: "private",
      state: "online",
    };
    output = { storage: { uuid: uuid(20) } };
  } else if (path.startsWith("storage/")) {
    const id = path.split("/")[1];
    if (method === "GET") output = { storage: state.templates[id] };
    if (method === "PUT") Object.assign(state.templates[id], body.storage);
    if (method === "DELETE") delete state.templates[id];
  } else if (path.startsWith("server/")) {
    const id = path.split("/")[1].split("?")[0];
    if (path.endsWith("/stop")) state.servers[id].state = "stopped";
    else if (method === "GET") output = { server: state.servers[id] };
    else if (method === "DELETE") delete state.servers[id];
  } else throw new Error("unexpected API path");
  save(state);
  return Promise.resolve(new Response(JSON.stringify(output), { status: 200 }));
};
export const fixtureCloud = (
  input: CloudImage,
  api: GatewayOperation,
  ctx: { signal?: AbortSignal; globalArgs: z.infer<typeof Globals> },
) => imageCloud(input, cloudRequest(api, ctx.signal, provider));
export function fixtureGuest(target: { name: string }) {
  return {
    bootstrap() {
      const state = read();
      state.guest.push([target.name, "bootstrap"]);
      save(state);
      return Promise.resolve();
    },
    command(name: string, input: Record<string, unknown> = {}) {
      const state = read();
      state.guest.push([target.name, name]);
      save(state);
      if (
        state.failSmoke && target.name.endsWith("-smoke") &&
        name === "image.check"
      ) return Promise.reject(new Error("task_vm.image_check_failed"));
      if (name === "image.inspect-phase" || name === "image.start-phase") {
        const pi =
          (input.key as Record<string, unknown> | undefined)?.phase === "pi";
        return Promise.resolve({
          state:
            name === "image.inspect-phase" && pi && !state.artifactDelivered
              ? "missing"
              : "complete",
          boot_id: boot,
        });
      }
      return Promise.resolve({ ready: true });
    },
    artifact(bytes: Uint8Array, sha256: string) {
      if (
        new TextDecoder().decode(bytes) !== artifact() ||
        sha256 !== read().operation.input.pi_artifact.sha256
      ) throw new Error("bad artifact");
      const state = read();
      state.artifactDelivered = true;
      save(state);
      return Promise.resolve();
    },
  };
}
