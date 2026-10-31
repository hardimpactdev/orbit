import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { GatewayOperation } from "../_lib/direct.ts";
import { installSchedule } from "./installation.ts";

const command = "/usr/bin/true # private-install-fixture";
async function fixture() {
  const digest = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(command),
  );
  const input = {
    id: "9a067a27-c2ce-45c5-83f5-735e03dc5b2f",
    command_hash: Array.from(
      new Uint8Array(digest),
      (byte) => byte.toString(16).padStart(2, "0"),
    ).join(""),
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
    working_directory: "/home/orbit",
    shell: "/bin/sh",
    login_shell: false,
    calendar: "2099-01-01 00:00:00",
    timeout_seconds: 5,
    desired: "disabled" as const,
  };
  const calls: { method: string; path: string; body: unknown }[] = [];
  const gateway = new GatewayOperation((method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({ status: 200, body: { data: { command } } });
  }, {
    operation_id: 17,
    node_id: 9,
    kind: "schedule.create",
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  });
  return { input, gateway, calls };
}

Deno.test("Schedule installation consumes private command only inside its local effect callback", async () => {
  const f = await fixture();
  let effects = 0;
  const result = await installSchedule(
    f.input,
    f.gateway,
    undefined,
    (name, specification) => {
      effects++;
      assertEquals(name, "schedule.install");
      assertEquals(specification.command, command);
      assertEquals(specification.desired, "disabled");
      assertEquals(specification.command_hash, f.input.command_hash);
      return Promise.resolve({ private_output: command });
    },
  );
  assertEquals(effects, 1);
  assertEquals(result, { ready: true });
  assertEquals(f.calls.length, 1);
  assertEquals(
    f.calls[0].path,
    `/api/v1/direct-operations/17/private-material?run_id=a075eb23-7ac4-4487-80d1-57a038a92676`,
  );
  assertEquals(
    JSON.stringify({ input: f.input, calls: f.calls, result }).includes(
      command,
    ),
    false,
  );
});

Deno.test("changed private command or callback failure cannot leak through the native error", async () => {
  const f = await fixture();
  let effects = 0;
  assertEquals(
    await installSchedule(
      { ...f.input, command_hash: "b".repeat(64) },
      f.gateway,
      undefined,
      () => {
        effects++;
        return Promise.resolve({});
      },
    ),
    { error_code: "schedule.artifact_conflict" },
  );
  assertEquals(effects, 0);
  await assertRejects(
    () =>
      installSchedule(
        f.input,
        f.gateway,
        undefined,
        () => Promise.reject(new Error(command)),
      ),
    Error,
    "Private material callback failed",
  );
});

Deno.test("a typed install refusal returns no private fields", async () => {
  const f = await fixture();
  assertEquals(
    await installSchedule(f.input, f.gateway, undefined, () =>
      Promise.resolve({
        error_code: "schedule.artifact_conflict",
        output: command,
      })),
    { error_code: "schedule.artifact_conflict" },
  );
});
