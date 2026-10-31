import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { installProcess } from "./process-installation.ts";
import type { localAgent } from "./agent.ts";

const content = "# Orbit Process 7\n[Service]\nExecStart=/usr/bin/true\n";
const digest = async (value: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
const base = {
  process_id: 7,
  name: "orbit-process-7-proof.service",
  runtime: "systemd" as const,
  agent_sha256: "a".repeat(64),
  content_hash: await digest(content),
  desired: "stopped" as const,
  on_demand: false,
  activation_pending: false,
  retired: null,
};
type Call = { name: string; input: Record<string, unknown>; pin: string };
function fixture(override: Record<string, Record<string, unknown>> = {}) {
  const calls: Call[] = [];
  const defaults: Record<string, Record<string, unknown>> = {
    "process.stage-unit": { staged: true, previous: false, state: null },
    "process.activate-unit": { backup_left: false },
    "process.status": { state: "inactive", private: "must-not-escape" },
    "process.check-release": { available: true },
  };
  const invoke: typeof localAgent = (name, input, pin) => {
    calls.push({ name, input, pin });
    return Promise.resolve(
      override[name] ?? defaults[name] ?? {},
    );
  };
  const material = {
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => await use({ installation: { runtime: "systemd", content } }),
  };
  return { calls, invoke, material };
}
Deno.test("Process install stages and activates an owned stopped unit, returning only bounded status", async () => {
  const f = fixture();
  assertEquals(await installProcess(base, f.material, undefined, f.invoke), {
    ready: true,
    state: "inactive",
  });
  assertEquals(f.calls.map((c) => c.name), [
    "process.stage-unit",
    "process.activate-unit",
    "process.status",
  ]);
  assertEquals(f.calls[0].input, { process_id: 7, unit: base.name, content });
  assertEquals(f.calls[1].input, {
    process_id: 7,
    unit: base.name,
    desired: "stopped",
    on_demand: false,
    change: "create",
  });
  assertEquals(f.calls.every((c) => c.pin === base.agent_sha256), true);
  assertEquals(JSON.stringify(f.calls.slice(1)).includes(content), false);
});
Deno.test("Process install skips only a settled unchanged unit, and activates pending or drifted state", async () => {
  for (const activation_pending of [false, true]) {
    for (const state of ["active", "inactive"]) {
      const f = fixture({
        "process.stage-unit": { staged: false, previous: true, state },
      });
      await installProcess(
        { ...base, activation_pending },
        f.material,
        undefined,
        f.invoke,
      );
      assertEquals(f.calls.map((c) => c.name), [
        "process.stage-unit",
        ...(activation_pending || state !== "inactive"
          ? ["process.activate-unit"]
          : []),
        "process.status",
      ]);
      const activated = f.calls.find((c) => c.name === "process.activate-unit");
      if (activated) assertEquals(activated.input.change, "reload");
    }
  }
});
Deno.test("Process replacement removes only the admitted retired runtime and preserves activation recovery failure", async () => {
  const f = fixture({
    "process.stage-unit": { staged: true, previous: true, state: null },
    "process.activate-unit": { backup_left: true },
  });
  assertEquals(
    await installProcess(
      {
        ...base,
        retired: { runtime: "docker", name: "orbit-process-7-proof" },
      },
      f.material,
      undefined,
      f.invoke,
    ),
    { error_code: "process.systemd_converge_failed" },
  );
  assertEquals(f.calls.map((c) => c.name), [
    "process.remove",
    "process.stage-unit",
    "process.activate-unit",
  ]);
  assertEquals(f.calls[0].input, {
    process_id: 7,
    runtime: "docker",
    name: "orbit-process-7-proof",
    vite: null,
  });
  assertEquals(f.calls[2].input.change, "replace");
});
Deno.test("Process hash, artifact, runtime and release refusals precede installation effects", async () => {
  for (
    const change of [
      { content_hash: "b".repeat(64) },
      { name: "orbit-process-8-foreign.service" },
      {
        retired: {
          runtime: "docker" as const,
          name: "orbit-process-8-foreign",
        },
      },
      { retired: { runtime: "systemd" as const, name: base.name } },
    ]
  ) {
    const f = fixture();
    await assertRejects(() =>
      installProcess({ ...base, ...change }, f.material, undefined, f.invoke)
    );
    assertEquals(f.calls, []);
  }
  const f = fixture({ "process.check-release": { available: false } });
  assertEquals(
    await installProcess(
      { ...base, directory: "/home/orbit-app-7/current" },
      f.material,
      undefined,
      f.invoke,
    ),
    { error_code: "process.release_unavailable" },
  );
  assertEquals(f.calls.map((c) => c.name), ["process.check-release"]);
});
Deno.test("Process installation refuses typed primitive errors without reading status or exposing output", async () => {
  for (
    const name of [
      "process.remove",
      "process.stage-unit",
      "process.activate-unit",
    ]
  ) {
    const f = fixture({
      [name]: { error_code: "process.name_collision", output: "private" },
    });
    assertEquals(
      await installProcess(
        {
          ...base,
          retired: { runtime: "docker", name: "orbit-process-7-proof" },
        },
        f.material,
        undefined,
        f.invoke,
      ),
      { error_code: "process.name_collision" },
    );
    assertEquals(f.calls.some((c) => c.name === "process.status"), false);
  }
  const f = fixture();
  await assertRejects(
    () =>
      installProcess(base, f.material, undefined, () => {
        throw new Error(content);
      }),
    Error,
    "Private Process installation did not complete",
  );
});
Deno.test("Docker installation keeps command/environment callback-local and binds admitted spec and identity", async () => {
  const f = fixture();
  const input = {
    process_id: 7,
    runtime: "docker" as const,
    name: "orbit-process-7-proof",
    agent_sha256: base.agent_sha256,
    desired: "stopped" as const,
    on_demand: false,
    retired: null,
    spec: "b".repeat(64),
  };
  const definition = {
    image: "busybox:stable",
    command: ["/bin/echo", "private-command"],
    restart: "no",
    working_directory: null,
    ports: [],
    mounts: [],
    environment: { SECRET: "private-value" },
  };
  const material = {
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) =>
      await use({
        installation: { runtime: "docker", spec: input.spec, definition },
      }),
  };
  const outcome = await installProcess(input, material, undefined, f.invoke);
  assertEquals(outcome, { ready: true, state: "inactive" });
  assertEquals(f.calls.map((c) => c.name), [
    "process.converge-container",
    "process.status",
  ]);
  assertEquals(f.calls[0].input, {
    process_id: 7,
    container: input.name,
    desired: "stopped",
    spec: input.spec,
    ...definition,
  });
  assertEquals(JSON.stringify(outcome).includes("private"), false);
  f.calls.length = 0;
  await assertRejects(() =>
    installProcess(
      { ...input, spec: "c".repeat(64) },
      material,
      undefined,
      f.invoke,
    )
  );
  assertEquals(f.calls, []);
  await assertRejects(() =>
    installProcess(base, material, undefined, f.invoke)
  );
  assertEquals(f.calls, []);
});
