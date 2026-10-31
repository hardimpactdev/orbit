// deno-lint-ignore-file require-await
import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { DockerMigrationProgram } from "../node/docker_migration_system.ts";
import {
  DockerMigrationObservation,
  migrateDocker,
} from "./docker_migration_direct.ts";
const hash = "a".repeat(64);
function fixture() {
  const calls: string[] = [];
  const migration = {
    recipe: "sha256:recipe",
    snapshot: "20261001T000000Z",
    architecture: "amd64" as const,
    docker_version: "29.1",
    containerd_version: "2.2",
    process_ids: ["7"],
    process_states: { "7": "running" as const },
    process_specs: { "7": { name: "orbit-process-7-web", hash, volumes: [] } },
    fallback: false,
  };
  const definition = {
    process_id: 7,
    container: "orbit-process-7-web",
    spec: hash,
    desired: "running",
    environment: { SECRET: "never-output" },
  };
  const gateway = {
    withPrivateMaterial: async <T>(
      callback: (data: Record<string, unknown>) => Promise<T>,
    ) => await callback({ processes: [definition] }),
  };
  const report = {
    healthy: true,
    fallback: false,
    containers: [{
      name: "orbit-process-7-web",
      state: "running",
      health: "healthy",
    }],
    process_ids: ["7"],
  };
  const dependencies = {
    migrate: async () => {
      calls.push("prepare");
      return {
        ok: true as const,
        fallback: false,
        containers: report.containers,
        process_ids: ["7"],
      };
    },
    reconcile: async () => {
      calls.push("reconcile");
      return {};
    },
    observe: async (mode: "plan" | "report") => {
      calls.push(mode);
      return { ...report, ...(mode === "plan" ? { planned: true } : {}) };
    },
  };
  return { calls, migration, definition, gateway, report, dependencies };
}
Deno.test("Docker parent plans without mutation and keeps private definitions out of output", async () => {
  const f = fixture();
  const result = await migrateDocker(
    { mode: "plan", migration: f.migration },
    f.gateway,
    undefined,
    f.dependencies,
  );
  assertEquals(f.calls, ["plan"]);
  assertEquals(result.planned, true);
  assertEquals(JSON.stringify(result).includes("never-output"), false);
});
Deno.test("Docker parent orders prepare, all Process reconciliation, then final report", async () => {
  const f = fixture();
  const result = await migrateDocker(
    { mode: "migrate", migration: f.migration },
    f.gateway,
    undefined,
    f.dependencies,
  );
  assertEquals(f.calls, ["prepare", "reconcile", "report"]);
  assertEquals(result.healthy, true);
});
Deno.test("Docker parent refuses changed private Process identity before effects", async () => {
  const f = fixture();
  f.definition.spec = "b".repeat(64);
  await assertRejects(() =>
    migrateDocker(
      { mode: "migrate", migration: f.migration },
      f.gateway,
      undefined,
      f.dependencies,
    )
  );
  assertEquals(f.calls, []);
});
Deno.test("Docker parent does not reconcile after a confirmed package refusal", async () => {
  const f = fixture();
  const deps = {
    ...f.dependencies,
    migrate: async () => ({
      ok: false as const,
      error_code: "docker.migration_failed" as const,
      stage: "fallback-required" as const,
      resumable: true as const,
      healthy: false as const,
      fallback: false,
      containers: [],
      process_ids: [],
    }),
  };
  const result = await migrateDocker(
    { mode: "migrate", migration: f.migration },
    f.gateway,
    undefined,
    deps,
  );
  assertEquals(result.error_code, "docker.migration_failed");
  assertEquals(f.calls, []);
});
Deno.test("Docker parent refuses a foreign lower Process before reconciliation", async () => {
  const f = fixture();
  const deps = {
    ...f.dependencies,
    migrate: async () => ({
      ok: true as const,
      fallback: false,
      containers: [],
      process_ids: ["8"],
    }),
  };
  await assertRejects(() =>
    migrateDocker(
      { mode: "migrate", migration: f.migration },
      f.gateway,
      undefined,
      deps,
    )
  );
  assertEquals(f.calls, []);
});
Deno.test("Docker parent reports a confirmed Process failure after reading final container states", async () => {
  const f = fixture();
  const deps = {
    ...f.dependencies,
    reconcile: async () => ({ error_code: "process.converge_failed" }),
  };
  const result = await migrateDocker(
    { mode: "restore-policies", migration: f.migration },
    f.gateway,
    undefined,
    deps,
  );
  assertEquals(result.error_code, "docker.process_reconcile_failed");
  assertEquals(result.failed_process_ids, [7]);
  assertEquals(f.calls, ["prepare", "report"]);
});
Deno.test("Docker parent does not hide an uncertain Process outcome or continue mutation", async () => {
  const f = fixture();
  const deps = {
    ...f.dependencies,
    reconcile: async () => {
      throw new Error("uncertain private output");
    },
  };
  await assertRejects(() =>
    migrateDocker(
      { mode: "migrate", migration: f.migration },
      f.gateway,
      undefined,
      deps,
    )
  );
  assertEquals(f.calls, ["prepare"]);
});
Deno.test("Docker observation compiles with the canonical transaction program", async () => {
  const child = new Deno.Command("python3", {
    args: ["-c", "import ast,sys; ast.parse(sys.stdin.read())"],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  const bytes = new TextEncoder().encode(
    `${DockerMigrationProgram}\n${DockerMigrationObservation}`,
  );
  const [, output] = await Promise.all([
    (async () => {
      await writer.write(bytes);
      await writer.close();
    })(),
    child.output(),
  ]);
  assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
});
