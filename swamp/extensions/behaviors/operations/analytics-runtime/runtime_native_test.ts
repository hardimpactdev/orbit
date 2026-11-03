import { assert, assertEquals, assertFalse, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { stepsOf } from "../../../../tests/_support/native_history.ts";
async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations/analytics-runtime", "operations/process-commands");
  const support = "operations/processes-schedules";
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/${support}`);
  for (const file of ["container-input.ts", "installation-input.ts", "process-input.ts", "required-mount-paths.ts"]) {
    await Deno.copyFile(
      `${ROOT}/extensions/behaviors/${support}/${file}`,
      `${repo.dir}/extensions/behaviors/${support}/${file}`,
    );
  }
  const environment = `SECRET_KEY_BASE=native-runtime-private-${crypto.randomUUID()}\n`;
  repo.secrets = [environment.split("=")[1].trim()];
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "process-create-operation-12"], { input: environment });
  const state = `${repo.dir}/fixture-state.json`;
  const read = () => JSON.parse(Deno.readTextFileSync(state));
  const write = (value: unknown) => Deno.writeTextFileSync(state, JSON.stringify(value));
  write({ mode: "", spec: "a".repeat(64), present: true, state: "running", calls: [], intent: {} });
  const hash = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(environment.trimEnd()))),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/fixture.ts`,
    (await Deno.readTextFile(`${ROOT}/tests/_support/analytics_runtime_host.ts`))
      .replace("FIXTURE_STATE_PATH", JSON.stringify(state)).replace("FIXTURE_ENV_HASH", hash),
  );
  for (
    const name of [
      "operation-analytics-update-direct",
      "operation-process-create",
      "operation-process-destroy",
      "operation-process-vite",
    ]
  ) {
    await repo.workflow(name, (workflow) => {
      for (const step of workflow.jobs.flatMap((j) => j.steps)) {
        const task = step.task as { behaviorType?: string };
        if (task.behaviorType?.startsWith("@swamp/ssh") || task.behaviorType?.startsWith("@swamp/cfgmgmt/")) {
          task.behaviorType = "@fixture/analytics-host";
        }
      }
    });
  }
  const definition = {
    process_id: 9,
    container: "orbit-process-9-plausible",
    desired: "running",
    spec: "a".repeat(64),
    image: "ghcr.io/plausible/community-edition:v3.2.1",
    command: ["/entrypoint.sh", "run"],
    restart: "unless-stopped",
    working_directory: null,
    ports: [],
    mounts: [],
  };
  const inputs = {
    operation_id: 12,
    node_id: 3,
    kind: "analytics.update",
    intent: {
      process_id: 9,
      assignment_id: 1,
      version: "3.3.0",
      previous_version: "3.2.1",
      before: definition,
      after: { ...definition, image: "ghcr.io/plausible/community-edition:v3.3.0", spec: "b".repeat(64) },
      health_url: "http://192.0.2.3:8000/api/health",
      ssh_execution: {
        control_node_id: 209,
        identity_file: "/approved",
        target: { host: "192.0.2.3", user: "orbit", port: 22, host_keys: ["ssh-ed25519 fixture"] },
      },
    },
    execution_secrets: { environment: { vault: "orbit-control-inputs", key: "process-create-operation-12" } },
  };
  async function run(mode = "", success = false) {
    write({ ...read(), mode });
    const result = await repo.runWorkflow("operation-analytics-update-direct", inputs, { success });
    return { result: JSON.parse(stepsOf(result.history).result.outputs.stdout), state: read() };
  }
  return {
    run,
    read,
    write,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
for (const mode of ["", "initialization", "restore", "install", "partial-remove", "unchanged", "rerun"]) {
  Deno.test(`analytics update native Process children: ${mode || "success"}`, async () => {
    await using f = await fixture();
    if (mode === "unchanged") f.write({ ...f.read(), spec: "b".repeat(64) });
    if (mode === "rerun") {
      const failed = await f.run("health");
      assert(failed.result.rolled_back);
      assertEquals(failed.state.spec, "a".repeat(64));
      assertEquals(failed.state.state, "running");
      assert(failed.state.calls.includes("create:a"));
      assert(failed.state.calls.includes("create:b"));
    }
    const success = ["", "rerun"].includes(mode);
    const { result, state } = await f.run(mode === "unchanged" ? "health" : mode === "rerun" ? "" : mode, success);
    if (success) {
      assertEquals(result, { ready: true, version: "3.3.0", previous_version: "3.2.1" });
      assertEquals(state.spec, "b".repeat(64));
      assertEquals(state.state, "running");
      if (!mode) {
        assertEquals(state.calls.filter((s: string) => s === "createdb").length, 1);
        assertEquals(state.calls.filter((s: string) => s === "migrate").length, 1);
        assert(state.calls.includes("create:b"));
      }
    } else if (mode === "restore") {
      assertFalse(result.rolled_back);
      assertNotEquals(state.state, "running");
    } else {
      assert(result.rolled_back);
      assertEquals(state.spec, (mode === "unchanged" ? "b" : "a").repeat(64));
      if (["partial-remove", "unchanged"].includes(mode)) assertEquals(state.state, "running");
      if (["initialization", "unchanged"].includes(mode)) assertFalse(state.calls.includes("remove"));
      if (mode === "unchanged") assertFalse(state.calls.includes("createdb"));
    }
  });
}
