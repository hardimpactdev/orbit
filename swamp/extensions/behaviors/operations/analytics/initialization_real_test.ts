/** Real Docker coverage. Run only on a host dedicated to disposable fixtures. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
Deno.test({
  name: "actual plausible database initialization and repeat",
  ignore: Deno.env.get("ORBIT_DISPOSABLE_DOCKER") !== "1",
  async fn() {
    await using repo = await nativeRepo();
    const processId = 910000000 +
      crypto.getRandomValues(new Uint32Array(1))[0] % 10000000;
    const server = `orbit-process-${processId}-plausible`;
    // Predeclare every possible name so even a timed-out create is cleaned up.
    const containers = [`${server}-pg`, `${server}-ch`];
    const docker = async (args: string[], check = true) => {
      const result = await new Deno.Command("docker", {
        args,
        stdout: "piped",
        stderr: "piped",
        signal: AbortSignal.timeout(60000),
      }).output();
      if (check) {
        assertEquals(
          result.code,
          0,
          new TextDecoder().decode(result.stderr),
        );
      }
      return {
        ...result,
        text: new TextDecoder().decode(result.stdout).trim(),
      };
    };
    try {
      await docker([
        "run",
        "-d",
        "--name",
        containers[0],
        "-e",
        "POSTGRES_HOST_AUTH_METHOD=trust",
        "postgres:16-alpine",
      ]);
      await docker([
        "run",
        "-d",
        "--name",
        containers[1],
        "-e",
        "CLICKHOUSE_SKIP_USER_SETUP=1",
        "clickhouse/clickhouse-server:24.12-alpine",
      ]);
      const until = Date.now() + 40000;
      for (
        const args of [[
          "exec",
          containers[0],
          "pg_isready",
          "-U",
          "postgres",
        ], [
          "exec",
          containers[1],
          "clickhouse-client",
          "--query",
          "SELECT 1",
        ]]
      ) {
        while (!(await docker(args, false)).success) {
          assert(
            Date.now() < until,
            "Disposable database did not become ready",
          );
          await new Promise((r) => setTimeout(r, 200));
        }
      }
      const addresses = [];
      for (const name of containers) {
        addresses.push(
          (await docker([
            "inspect",
            name,
            "--format",
            "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}",
          ])).text,
        );
      }
      const secret = Array.from(
        crypto.getRandomValues(new Uint8Array(64)),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      repo.secrets = [secret, btoa(secret)];
      const environment = {
        SECRET_KEY_BASE: secret,
        DATABASE_URL: `postgres://postgres@${addresses[0]}:5432/plausible_db`,
        CLICKHOUSE_DATABASE_URL: `http://${addresses[1]}:8123/plausible_events_db`,
        BASE_URL: "http://localhost:8000",
        DISABLE_REGISTRATION: "true",
      };
      const script = `
import { initializePlausible } from ${JSON.stringify(new URL("./initialization.ts", import.meta.url).href)};
import { processSystem } from ${
        JSON.stringify(new URL("../processes-schedules/process-system.ts", import.meta.url).href)
      };
const environment = await new Response(Deno.stdin.readable).json();
const native = processSystem();
const volumes: string[] = [];
const action: typeof native = async (name, input, signal) => {
  const outcome = await native(name, input, signal);
  if (name === "process.converge-container" && !outcome.error_code) {
    const result = await new Deno.Command("/usr/bin/docker", {
      args: ["container", "inspect", "--format", "{{json .Mounts}}", input.container as string],
      stdout: "piped", stderr: "null",
    }).output();
    if (!result.success) throw new Error("Fixture cannot inspect temporary mounts");
    volumes.push(...JSON.parse(new TextDecoder().decode(result.stdout))
      .filter((v: {Type: string}) => v.Type === "volume").map((v: {Name: string}) => v.Name));
  }
  return outcome;
};
const result = await initializePlausible({
  process_id: ${processId}, container: ${JSON.stringify(server)}, spec: "a".repeat(64),
  image: "ghcr.io/plausible/community-edition:v3.2.1", desired: "running", command: ["/entrypoint.sh", "run"],
  restart: "no", working_directory: null, ports: [], mounts: [],
}, environment, action);
let volumes_removed = volumes.length > 0;
for (const name of volumes) {
  const result = await new Deno.Command("/usr/bin/docker", {
    args: ["volume", "inspect", name], stdout: "null", stderr: "null",
  }).output();
  if (result.success) volumes_removed = false;
}
console.log(JSON.stringify({...result, volumes_removed}));
`;
      for (let repeat = 0; repeat < 2; repeat++) {
        // Only the child has a timeout. Its termination cannot skip the parent's finally.
        const child = new Deno.Command(Deno.execPath(), {
          args: ["eval", script],
          stdin: "piped",
          stdout: "piped",
          stderr: "piped",
          signal: AbortSignal.timeout(120000),
        }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(JSON.stringify(environment)));
        await writer.close();
        const result = await child.output();
        const stdout = new TextDecoder().decode(result.stdout);
        const stderr = new TextDecoder().decode(result.stderr);
        await repo.assertPrivateAbsent(stdout, stderr);
        assertEquals(result.code, 0, "Native initialization failed");
        assertEquals(JSON.parse(stdout), { ready: true, volumes_removed: true });
        for (const phase of ["createdb", "migrate"]) {
          assert(!(await docker(["inspect", `${server}-init-${phase}`], false)).success);
        }
      }
      const rows = await docker([
        "exec",
        containers[0],
        "psql",
        "-U",
        "postgres",
        "-d",
        "plausible_db",
        "-Atc",
        "SELECT count(*) FROM schema_migrations",
      ]);
      assert(Number(rows.text) > 0);
    } finally {
      // No test-level timeout can bypass this finally; each command has an independent bound.
      const errors: unknown[] = [];
      for (
        const name of [
          `${server}-init-createdb`,
          `${server}-init-migrate`,
          ...containers.toReversed(),
        ]
      ) {
        try {
          await docker(["rm", "--force", "--volumes", name], false);
        } catch (error) {
          errors.push(error);
        }
      }
      assertEquals(errors, [], "Disposable Docker cleanup failed");
    }
  },
});
