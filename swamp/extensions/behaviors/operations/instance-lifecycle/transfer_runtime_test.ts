import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { TransferRuntime, transferRuntimeActions } from "./transfer_runtime.ts";
import type { installProcessLocal } from "../processes-schedules/process-installation.ts";
import type { startActions } from "../processes-schedules/start.ts";
const digest = "a".repeat(64);
function batch(desired: "running" | "stopped" = "running"): TransferRuntime {
  return TransferRuntime.parse({
    node_id: 17,
    field: "destination_installations",
    ports: [],
    members: [{
      id: 4,
      input: {
        control: {
          process_id: 4,
          agent_sha256: digest,
          runtime: "systemd",
          name: "orbit-process-4-worker.service",
          on_demand: false,
          vite: null,
        },
        installation: {
          process_id: 4,
          agent_sha256: digest,
          runtime: "systemd",
          name: "orbit-process-4-worker.service",
          on_demand: false,
          desired,
          retired: null,
          content_hash: digest,
          activation_pending: false,
        },
      },
    }],
  });
}
function fixtures() {
  const calls: string[] = [];
  const installation = {
    runtime: "systemd" as const,
    content: "private-unit",
    environment: "TOKEN=private-value",
  };
  const gateway = {
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      calls.push("private");
      await use({
        destination_installations: { "4": { installation } },
        source_installations: {
          "4": {
            installation: { ...installation, content: "wrong-placement" },
          },
        },
      });
    },
  };
  const control = (
    kind: string,
    input: unknown,
    signal?: AbortSignal,
  ) => {
    signal?.throwIfAborted();
    assertEquals(Object.hasOwn(input as object, "vite_plan"), false);
    calls.push(kind);
    return Promise.resolve({ ready: true, state: "inactive" });
  };
  const install: typeof installProcessLocal = (
    _input,
    value,
    _transport,
    signal,
  ) => {
    signal?.throwIfAborted();
    assertEquals(value, installation);
    calls.push("install");
    return Promise.resolve({ ready: true, state: "active" });
  };
  const traffic: ReturnType<typeof startActions> = {
    prepare: (_input, signal) => {
      signal?.throwIfAborted();
      calls.push("prepare");
      return Promise.resolve({ ready: true });
    },
    verify: (_input, signal, wake) => {
      signal?.throwIfAborted();
      assertEquals(wake, true);
      calls.push("verify");
      return Promise.resolve({ ready: true, state: "active" });
    },
    activate: () => {
      throw new Error("Unexpected traffic activation");
    },
  };
  return { calls, gateway, control, install, traffic };
}
Deno.test("transfer installs only the fixed destination field inside its private callback", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(f.control, f.install, f.traffic);
  await actions.install(batch(), f.gateway);
  assertEquals(f.calls, ["private", "install"]);
});
Deno.test("transfer refuses a missing selected private batch before any installation", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(f.control, f.install, f.traffic);
  await assertRejects(() =>
    actions.install(batch(), {
      withPrivateMaterial: async (use) =>
        await use({ source_installations: {} }),
    })
  );
  assertEquals(f.calls, []);
});
Deno.test("transfer preserves desired stopped Processes without preparing or waking traffic", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(f.control, f.install, f.traffic);
  await actions.prepare(batch("stopped"));
  await actions.install(batch("stopped"), f.gateway);
  await actions.verify(batch("stopped"));
  assertEquals(f.calls, ["private", "install"]);
});
Deno.test("transfer stop and retirement reuse captured Process identities without start metadata", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(f.control, f.install, f.traffic);
  await actions.pause(batch());
  await actions.retire(batch());
  assertEquals(f.calls, ["process.stop", "process.destroy"]);
});
Deno.test("transfer refuses an occupied admitted destination port instead of choosing another", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(
    f.control,
    f.install,
    f.traffic,
    () => Promise.resolve({ port: 5174 }),
  );
  const runtime = batch();
  runtime.ports = [5173];
  await assertRejects(
    () => actions.ready(runtime),
    Error,
    "instance.transfer_port_busy",
  );
  assertEquals(f.calls, []);
});
Deno.test("transfer rejects forged and duplicate Process batch identities before effects", () => {
  const runtime = batch();
  runtime.members[0].id = 8;
  assertThrows(() => TransferRuntime.parse(runtime));
  const duplicate = batch();
  duplicate.members.push(duplicate.members[0]);
  assertThrows(() => TransferRuntime.parse(duplicate));
});
Deno.test("transfer surfaces a typed installer refusal and does not verify it as success", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(
    f.control,
    () => Promise.resolve({ error_code: "process.install_failed" }),
    f.traffic,
  );
  await assertRejects(
    () => actions.install(batch(), f.gateway),
    Error,
    "process.install_failed",
  );
  assertEquals(f.calls, ["private"]);
});
Deno.test("transfer restoration prepares and reinstalls the original runtime without a forward abort signal", async () => {
  const f = fixtures();
  const actions = transferRuntimeActions(f.control, f.install, f.traffic);
  await actions.restore(batch(), f.gateway);
  assertEquals(f.calls, ["prepare", "private", "install"]);
});
