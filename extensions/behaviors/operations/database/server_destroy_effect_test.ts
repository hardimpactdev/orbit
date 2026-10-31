import { assertEquals } from "jsr:@std/assert@1.0.19";
import { destroyServer } from "./server_destroy.ts";
import { localServerRuntime } from "./server_runtime.ts";

Deno.test({
  name:
    "disposable server destroy removes owned containers, preserves data and replays",
  ignore: Deno.env.get("DATABASE_SERVER_DESTROY_EFFECT_CANARY") !== "1",
  fn: async () => {
    const suffix = crypto.randomUUID().replaceAll("-", "").slice(0, 12);
    const container = `orbit-process-999997-server-destroy-${suffix}`;
    const volume = `orbit-server-destroy-${suffix}-data`;
    const docker = async (args: string[], success = true) => {
      const reply = await new Deno.Command("docker", {
        args,
        stdout: "null",
        stderr: "null",
      }).output();
      assertEquals(reply.success, success, "Disposable server fixture outcome");
    };
    try {
      assertEquals(
        await localServerRuntime.converge({
          process_id: 999997,
          container,
          desired: "running",
          spec: "a".repeat(64),
          image: "valkey/valkey:8.1",
          command: ["valkey-server", "--dir", "/data"],
          restart: "unless-stopped",
          working_directory: null,
          ports: [],
          mounts: [{ source: volume, target: "/data", read_only: false }],
          environment: {},
        }),
        {},
      );
      await docker(["exec", container, "touch", "/data/volume-preserved"]);
      const input = { process_id: 999997, container };
      assertEquals(await destroyServer(input), { backend: "absent" });
      await docker(["container", "inspect", container], false);
      await docker(["volume", "inspect", volume]);
      await docker([
        "run",
        "--rm",
        "--mount",
        `type=volume,src=${volume},dst=/data,readonly`,
        "valkey/valkey:8.1",
        "test",
        "-f",
        "/data/volume-preserved",
      ]);
      assertEquals(await destroyServer(input), { backend: "absent" });
      await docker(["run", "-d", "--name", container, "valkey/valkey:8.1"]);
      assertEquals(await destroyServer(input), {
        error_code: "process.name_collision",
      });
      await docker(["container", "inspect", container]);
    } finally {
      for (
        const name of [
          container,
          `${container}-candidate`,
          `${container}-rollback-running`,
          `${container}-rollback-stopped`,
        ]
      ) {
        await new Deno.Command("docker", {
          args: ["rm", "--force", name],
          stdout: "null",
          stderr: "null",
        }).output();
      }
      await docker(["volume", "rm", volume]);
    }
  },
});
