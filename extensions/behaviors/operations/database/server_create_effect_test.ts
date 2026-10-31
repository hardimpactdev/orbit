import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createServer } from "./server_create.ts";
import { passwordHash } from "./valkey_policy.ts";
import { ValkeyClient } from "./valkey_client.ts";

const enabled = Deno.env.get("DATABASE_SERVER_EFFECT_CANARY") === "1";
for (const driver of ["mysql", "pgsql", "redis"] as const) {
  Deno.test({
    name: `disposable ${driver} server provisions, scrubs secrets and replays`,
    ignore: !enabled,
    fn: async () => {
      const suffix = crypto.randomUUID().replaceAll("-", "").slice(0, 12);
      const container = `orbit-process-999998-server-create-${suffix}`;
      const volume = `orbit-server-create-${suffix}-data`;
      const password = crypto.randomUUID().replaceAll("-", "") + suffix;
      const pin = Array.from(
        new Uint8Array(
          await crypto.subtle.digest(
            "SHA-256",
            await Deno.readFile("/usr/local/bin/orbit-agent"),
          ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
      const port = (listener.addr as Deno.NetAddr).port;
      listener.close();
      const pg = driver === "pgsql";
      const redis = driver === "redis";
      const hash = await passwordHash(password);
      const env = redis
        ? {}
        : { [pg ? "POSTGRES_PASSWORD" : "MYSQL_ROOT_PASSWORD"]: password };
      const acl = redis ? `user default on #${hash} ~* &* +@all\n` : "";
      const input = {
        driver,
        process_id: 999998,
        container,
        container_input: {
          image: redis
            ? "valkey/valkey:8.1"
            : pg
            ? "postgres:18-alpine"
            : "mysql:8.0",
          command: redis
            ? [
              "valkey-server",
              "--appendonly",
              "yes",
              "--dir",
              "/data",
              "--aclfile",
              "/data/users.acl",
            ]
            : [pg ? "postgres" : "mysqld"],
          restart: "unless-stopped",
          working_directory: null,
          ports: [`127.0.0.1:${port}:${redis ? 6379 : pg ? 5432 : 3306}`],
          mounts: [{
            source: volume,
            target: redis
              ? "/data"
              : pg
              ? "/var/lib/postgresql"
              : "/var/lib/mysql",
            read_only: false,
          }],
        },
        spec: "b".repeat(64),
        scrub_spec: "c".repeat(64),
        agent_sha256: pin,
        initialize: !redis,
        validate_existing: false,
        was_active: false,
        host: "127.0.0.1",
        port,
        default_open: false,
        volume: redis ? volume : null,
      };
      const material = (
        effect: (m: Record<string, unknown>) => Promise<void>,
      ) =>
        effect({
          password,
          environment: env,
          acl,
          payload: redis
            ? {
              users: [{
                name: "default",
                rules: "on ~* &* +@all",
                hashes: [hash],
              }],
              default_open: false,
            }
            : {},
        });
      const docker = async (...args: string[]) => {
        const r = await new Deno.Command("docker", {
          args,
          stdout: "piped",
          stderr: "null",
        }).output();
        if (!r.success) {
          throw new Error("Disposable server fixture command failed");
        }
        return new TextDecoder().decode(r.stdout);
      };
      try {
        assertEquals(await createServer(input, material), {
          backend: "active",
        });
        const config = JSON.parse(
          await docker(
            "inspect",
            "--format",
            "{{json .Config.Env}}",
            container,
          ),
        ) as string[];
        assertEquals(
          config.some((s) =>
            s.includes(password) ||
            /^(?:MYSQL_ROOT_PASSWORD|POSTGRES_PASSWORD)=/.test(s)
          ),
          false,
        );
        assertEquals(await createServer(input, material), {
          backend: "active",
        });
        const current = {
          ...input,
          initialize: false,
          was_active: true,
          spec: input.scrub_spec,
        };
        const cleanMaterial = (
          effect: (m: Record<string, unknown>) => Promise<void>,
        ) => material((m) => effect({ ...m, environment: {} }));
        assertEquals(await createServer(current, cleanMaterial), {
          backend: "active",
        });
        if (redis) {
          const client = await ValkeyClient.connect("127.0.0.1", port);
          try {
            assertEquals(
              await client.command("AUTH", "default", password),
              "OK",
            );
            assertEquals(await client.command("PING"), "PONG");
          } finally {
            client.close();
          }
        }
        assertEquals(
          (await docker(
            "inspect",
            "--format",
            "{{json .Config.Env}}",
            container,
          )).includes(password),
          false,
        );
      } finally {
        for (
          const name of [
            container,
            `${container}-candidate`,
            `${container}-rollback`,
            `${container}-rollback-running`,
          ]
        ) {
          await new Deno.Command("docker", {
            args: ["rm", "--force", name],
            stdout: "null",
            stderr: "null",
          }).output();
        }
        await docker("volume", "rm", volume);
      }
    },
  });
}
