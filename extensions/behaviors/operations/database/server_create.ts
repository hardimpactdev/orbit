import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { nativeEffect } from "./native_effect.ts";
import { ValkeyClient } from "./valkey_client.ts";
import {
  aclState,
  AclUser,
  type Client,
  convergeAcl,
  liveAcl,
  passwordHash,
} from "./valkey_policy.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Container = z.object({
  image: z.string().regex(
    /^(?:mysql:|postgres:18|valkey\/valkey:)[A-Za-z0-9._:@/-]*$/,
  ),
  command: z.array(z.string().max(4096)).min(1).max(16),
  restart: z.literal("unless-stopped"),
  working_directory: z.string().nullable(),
  ports: z.array(z.string().max(128)).max(2),
  mounts: z.array(
    z.object({
      source: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.-]{1,254}$/),
      target: z.enum(["/data", "/var/lib/mysql", "/var/lib/postgresql"]),
      read_only: z.literal(false),
    }).strict(),
  ).length(1),
}).strict();
const Input = z.object({
  driver: z.enum(["mysql", "pgsql", "redis"]),
  process_id: z.number().int().positive(),
  container: z.string(),
  container_input: Container,
  spec: Digest,
  scrub_spec: Digest,
  agent_sha256: Digest,
  initialize: z.boolean(),
  validate_existing: z.boolean(),
  was_active: z.boolean(),
  host: z.ipv4(),
  port: z.number().int().min(1).max(65535),
  default_open: z.boolean(),
  volume: z.string().nullable(),
}).strict().refine((v) =>
  new RegExp(`^orbit-process-${v.process_id}-[a-z0-9]+(?:-[a-z0-9]+)*$`).test(
    v.container,
  )
);
const Material = z.object({
  password: z.string().min(1).max(16384),
  environment: z.record(z.string(), z.string().max(16384)),
  acl: z.string().max(1024 * 1024),
  payload: z.object({
    users: z.array(AclUser).max(10000).default([]),
    default_open: z.boolean().default(false),
  }).strict(),
}).strict();
const Refusal = z.object({
  error_code: z.enum([
    "agent.version_mismatch",
    "database.server_adoption_refused",
    "database.server_policy_changed",
    "database.server_command_failed",
    "database.server_admin_invalid",
    "database.server_admin_failed",
    "database.server_not_ready",
    "database.valkey_acl_invalid",
    "database.valkey_acl_failed",
    "process.invalid",
    "process.name_collision",
    "process.start_failed",
    "process.stop_failed",
    "process.restart_failed",
    "process.converge_failed",
    "process.recovery_required",
  ]),
}).strict();
export const ServerResult = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  Refusal,
]);
class Refused extends Error {
  constructor(readonly result: z.infer<typeof Refusal>) {
    super("Node action refused");
  }
}

/** Bound application startup on the Node; credentials and RESP replies remain local. */
export async function waitForValkeyReady(
  login: () => Promise<Client>,
  signal?: AbortSignal,
  pause = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms)),
  now = () => performance.now(),
) {
  const deadline = now() + 120000;
  do {
    signal?.throwIfAborted();
    let client: Client | undefined;
    try {
      client = await login();
      await client.command("PING");
      return true;
    } catch {
      signal?.throwIfAborted();
    } finally {
      client?.close();
    }
    const remaining = deadline - now();
    if (remaining <= 0) return false;
    await pause(Math.min(2000, remaining));
  } while (now() < deadline);
  return false;
}

/** One Node-local provisioning critical section; the native outcome projects separately. */
export async function createServer(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke = runNodeAgent,
  connect: (host: string, port: number) => Promise<Client> =
    ValkeyClient.connect,
) {
  try {
    const input = Input.parse(raw);
    signal?.throwIfAborted();
    let result: z.infer<typeof ServerResult> | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      if (material.payload.default_open !== input.default_open) {
        throw new Error("Wrong default policy");
      }
      const expectedCommand = input.driver === "redis"
        ? [
          "valkey-server",
          "--appendonly",
          "yes",
          "--dir",
          "/data",
          "--aclfile",
          "/data/users.acl",
        ]
        : [input.driver === "pgsql" ? "postgres" : "mysqld"];
      if (
        JSON.stringify(input.container_input.command) !==
          JSON.stringify(expectedCommand) ||
        !input.container_input.image.startsWith(
          input.driver === "redis"
            ? "valkey/valkey:"
            : input.driver === "pgsql"
            ? "postgres:18"
            : "mysql:",
        )
      ) throw new Error("Wrong database image or command");
      if (
        (input.driver === "pgsql" &&
          !/^postgres:18(?:\.[0-9]+)*(?:-[A-Za-z0-9][A-Za-z0-9_.-]*)?$/.test(
            input.container_input.image,
          )) ||
        input.container_input.mounts[0].target !==
          (input.driver === "redis"
            ? "/data"
            : input.driver === "pgsql"
            ? "/var/lib/postgresql"
            : "/var/lib/mysql") ||
        (input.driver === "redis" &&
          input.volume !== input.container_input.mounts[0].source)
      ) {
        throw new Error("Wrong database storage or image");
      }
      const environment = input.initialize
        ? {
          [
            input.driver === "pgsql"
              ? "POSTGRES_PASSWORD"
              : "MYSQL_ROOT_PASSWORD"
          ]: material.password,
        }
        : {};
      if (
        JSON.stringify(Object.keys(environment).sort()) !==
          JSON.stringify(Object.keys(material.environment).sort()) ||
        Object.entries(environment).some(([k, v]) =>
          material.environment[k] !== v
        )
      ) throw new Error("Wrong initializer environment");
      const run = async (
        name: string,
        body: Record<string, unknown>,
        deadlineMs: number,
        mutates = true,
      ) => {
        const reply = await invoke(
          {
            name,
            input: body,
            pin: input.agent_sha256,
            sudo: true,
            mutates,
            deadlineMs,
          } as NodeCall,
          signal,
        );
        const refusal = Refusal.safeParse(reply);
        if (refusal.success) throw new Refused(refusal.data);
        z.object({}).strict().parse(reply);
      };
      const login = async () => {
        const c = await connect(input.host, input.port);
        try {
          await c.command("AUTH", "default", material.password);
          return c;
        } catch {
          c.close();
          throw new Error("Valkey login failed");
        }
      };
      try {
        let rebuild = input.driver === "redis" && !input.was_active;
        if (input.driver === "redis" && input.validate_existing) {
          const client = await login().catch(() => {
            throw new Refused({
              error_code: "database.server_adoption_refused",
            });
          });
          try {
            const live = await liveAcl(client);
            const expected = [
              ...material.payload.users.filter((u) => u.name !== "default"),
              {
                name: "default",
                rules: `on ${input.default_open ? "nopass " : ""}~* &* +@all`,
                hashes: input.default_open
                  ? []
                  : [await passwordHash(material.password)],
              },
            ];
            if (
              live.size !== expected.length ||
              expected.some((u) =>
                !live.has(u.name) || aclState(live.get(u.name)!) !== aclState(u)
              )
            ) {
              throw new Refused({
                error_code: "database.server_adoption_refused",
              });
            }
            for (
              const [key, value] of Object.entries({
                dir: "/data",
                appendonly: "yes",
                appenddirname: "appendonlydir",
                appendfilename: "appendonly.aof",
                dbfilename: "dump.rdb",
                databases: "16",
              })
            ) {
              const config = z.array(z.string()).length(2).parse(
                await client.command("CONFIG", "GET", key),
              );
              if (config[0] !== key || config[1] !== value) {
                throw new Refused({
                  error_code: "database.server_adoption_refused",
                });
              }
            }
          } finally {
            client.close();
          }
        }
        if (input.driver === "redis" && input.was_active) {
          let client: Client | undefined;
          try {
            client = await login();
            const config = z.array(z.string()).length(2).parse(
              await client.command("CONFIG", "GET", "aclfile"),
            );
            const admin = (await liveAcl(client)).get("default");
            if (
              input.default_open &&
              (!admin || !admin.rules.split(/\s+/).includes("nopass"))
            ) {
              throw new Refused({
                error_code: "database.server_policy_changed",
              });
            }
            rebuild = config[1] !== "/data/users.acl" || !admin ||
              aclState(admin) !==
                aclState({
                  ...admin,
                  rules: `on ${input.default_open ? "nopass " : ""}~* &* +@all`,
                });
          } catch (error) {
            if (error instanceof Refused) throw error;
            if (input.default_open) {
              throw new Refused({
                error_code: "database.server_policy_changed",
              });
            }
            rebuild = true;
          } finally {
            client?.close();
          }
        }
        signal?.throwIfAborted();
        if (rebuild) {
          if (input.volume === null || !material.acl) {
            throw new Error("Missing ACL file intent");
          }
          await run("database.write-valkey-acl", {
            volume: input.volume,
            image: input.container_input.image,
            acl: material.acl,
          }, 300000);
        }
        const converge = (env: Record<string, string>, spec: string) =>
          run("process.converge-container", {
            ...input.container_input,
            process_id: input.process_id,
            container: input.container,
            desired: "running",
            spec,
            environment: env,
          }, 900000);
        await converge(environment, input.spec);
        if (rebuild && input.was_active) {
          await run("process.control", {
            operation: "restart",
            process_id: input.process_id,
            runtime: "docker",
            name: input.container,
            on_demand: false,
          }, 300000);
        }
        const ready = async () => {
          if (input.driver === "redis") {
            if (!await waitForValkeyReady(login, signal)) {
              throw new Refused({ error_code: "database.server_not_ready" });
            }
          } else {await run(
              "database.server-admin",
              {
                operation: "ready",
                container: input.container,
                password: material.password,
                password_variable: input.driver === "pgsql"
                  ? "PGPASSWORD"
                  : "MYSQL_PWD",
                client: input.driver === "pgsql"
                  ? [
                    "psql",
                    "--no-psqlrc",
                    "--host=127.0.0.1",
                    "--username=postgres",
                    "--dbname=postgres",
                    "--no-align",
                    "--tuples-only",
                    "--quiet",
                    "--set=ON_ERROR_STOP=1",
                  ]
                  : [
                    "mysql",
                    "--protocol=TCP",
                    "--host=127.0.0.1",
                    "--user=root",
                    "--batch",
                    "--skip-column-names",
                  ],
                timeout_seconds: 120,
              },
              150000,
              false,
            );}
        };
        if (input.initialize) {
          await ready();
          await converge({}, input.scrub_spec);
        }
        if (input.driver === "redis" && input.was_active) {
          await ready();
          const client = await login();
          try {
            await convergeAcl(
              client,
              material.payload.users,
              input.default_open,
            );
          } finally {
            client.close();
          }
        }
        await ready();
        result = { backend: "active" };
      } catch (error) {
        if (error instanceof Refused) result = error.result;
        else throw error;
      }
    });
    if (!result) throw new Error("No completed server");
    return result;
  } catch {
    throw new Error("database.server_create_outcome_unknown");
  }
}
export function serverCreate(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke = runNodeAgent,
  connect: (host: string, port: number) => Promise<Client> =
    ValkeyClient.connect,
) {
  return nativeEffect(
    "database_server.create",
    "@nckrtl/database-server-create",
    ServerResult,
    (input, gateway, signal) =>
      createServer(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        invoke,
        connect,
      ),
    makeHttp,
  );
}
export const behavior = {
  ...serverCreate(),
  type: "@nckrtl/database-server-create",
};
