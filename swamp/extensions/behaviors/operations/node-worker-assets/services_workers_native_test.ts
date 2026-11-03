import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo, swampBinary } from "../../../../tests/_support/native_harness.ts";
import { exists } from "../../../../tests/_support/route_fixture.ts";
Deno.test({
  name: "workflow labels dispatch to a container and write its resource",
  ignore: Deno.env.get("ORBIT_DISPOSABLE_DOCKER") !== "1",
  fn: async () => {
    await using repo = await nativeRepo();
    repo.timeoutMs = 40_000;
    await Deno.mkdir(`${repo.dir}/extensions/behaviors`, { recursive: true });
    await Deno.writeTextFile(
      `${repo.dir}/extensions/behaviors/probe.ts`,
      `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/worker-placement",version:"2026.10.28.1",globalArguments:z.object({}),resources:{proof:{schema:z.object({host:z.string()}),lifetime:"1h",garbageCollection:1}},methods:{probe:{description:"Prove execution in the worker container",arguments:z.object({}),execute:async(_,ctx)=>{await Deno.writeTextFile("/var/lib/swamp-worker/placement-proof",Deno.hostname());return {dataHandles:[await ctx.writeResource("proof","proof",{host:Deno.hostname()})]};}}}};`,
    );
    await repo.cli(["behavior", "create", "@fixture/worker-placement", "probe"]);
    const name = "worker-placement-probe", created = JSON.parse((await repo.cli(["workflow", "create", name])).stdout);
    await Deno.writeTextFile(
      created.path,
      JSON.stringify({
        id: created.id,
        name,
        labels: { pool: "fixture-container" },
        queueTimeout: 30,
        jobs: [{
          name: "probe",
          steps: [{ name: "probe", task: { type: "behavior", behavior: "probe", methodName: "probe" } }],
        }],
      }),
    );
    await repo.cli(["workflow", "validate", name]);
    await repo.cli(["access", "token", "mint", "fixture-admin", "--principal", "user:fixture"]);
    const secret = async (name: string, args: string[]) => {
      const path = `${repo.dir}/${name}`;
      await Deno.writeTextFile(path, JSON.parse((await repo.cli(args)).stdout).token, { mode: 0o600 });
      return path;
    };
    const admin = await secret("admin", ["access", "token", "reveal", "fixture-admin", "--yes"]);
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 }), port = (listener.addr as Deno.NetAddr).port;
    listener.close();
    const url = `ws://127.0.0.1:${port}`, container = `swamp-placement-${crypto.randomUUID().slice(0, 10)}`;
    const flags = ["--server", url, "--token-file", admin],
      log = await Deno.open(`${repo.dir}/serve.log`, { create: true, write: true });
    const env = { ...Deno.env.toObject(), SWAMP_NO_UPDATE_CHECK: "1", SWAMP_NO_TELEMETRY: "1" };
    for (
      const key of ["SWAMP_SERVER_URL", "SWAMP_SERVE_URL", "SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE",
        "SWAMP_REPO_DIR", "SWAMP_CA_CERT"]
    ) delete (env as Record<string, string>)[key];
    const serve = new Deno.Command(await swampBinary(), {
      args: ["serve", "--port", String(port), "--host", "127.0.0.1", "--auth-mode", "token", "--admins", "user:fixture"],
      cwd: repo.dir,
      env,
      clearEnv: true,
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    const stdout = serve.stdout.pipeTo(log.writable, { preventClose: true }),
      stderr = serve.stderr.pipeTo(new WritableStream({ write: (chunk) => log.write(chunk).then(() => {}) }));
    try {
      const deadline = Date.now() + 10_000;
      while (true) {
        try {
          const response = await fetch(`http://127.0.0.1:${port}/ready`, { signal: AbortSignal.timeout(1000) });
          await response.body?.cancel();
          if (response.ok) break;
        } catch { /* Wait for the local readiness endpoint. */ }
        assert(Date.now() < deadline, "serve readiness timeout");
        await new Promise((r) => setTimeout(r, 100));
      }
      await secret("enrollment", ["worker", "token", "create", "fixture-container", "--duration", "10m", ...flags]);
      await repo.cli(["access", "token", "mint", "fixture-worker-access", "--principal", "worker:fixture-container", ...flags]);
      await secret("worker-access", ["access", "token", "reveal", "fixture-worker-access", "--yes"]);
      await Deno.mkdir(`${repo.dir}/cache`);
      const docker = await new Deno.Command("docker", {
        args: ["run", "--detach", "--name", container, "--network=host", "--volume", `${repo.dir}:/fixture`,
          "--volume", `${repo.dir}/cache:/var/lib/swamp-worker`, "orbit-swamp-worker:20261028.205235.0-sha.1602a617",
          "worker", "connect", url, "--token-file", "/fixture/enrollment", "--server-token-file", "/fixture/worker-access",
          "--cache-dir", "/var/lib/swamp-worker", "--concurrency", "auto", "--label", "pool=fixture-container"],
      }).output();
      assert(docker.success, new TextDecoder().decode(docker.stderr));
      const result = JSON.parse((await repo.cli(["workflow", "run", name, ...flags])).stdout);
      assertEquals(result.status, "succeeded");
      assert(exists(`${repo.dir}/cache/placement-proof`));
      assert((await Deno.readTextFile(`${repo.dir}/cache/placement-proof`)).trim());
      assertFalse(exists("/var/lib/swamp-worker"));
    } finally {
      try {
        await new Deno.Command("docker", {
          args: ["rm", "--force", "--volumes", container],
          signal: AbortSignal.timeout(20_000),
        }).output();
      } finally {
        serve.kill("SIGTERM");
        await serve.status;
        await Promise.all([stdout, stderr]);
        log.close();
      }
    }
  },
});
