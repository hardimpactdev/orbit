import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { nativeEffect } from "./native_effect.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";
import { ValkeyClient } from "./valkey_client.ts";

const Name = z.string().regex(/^[A-Za-z0-9_]{1,64}$/);
const system = (name: string) =>
  ["mysql", "sys", "performance_schema", "information_schema", "postgres"]
    .includes(name.toLowerCase()) || name.toLowerCase().startsWith("template");
export const CreateInput = z.object({
  repair: z.boolean(),
  driver: z.enum(["mysql", "pgsql", "redis"]),
  username: z.string().regex(/^[A-Za-z0-9_-]{1,63}$/),
  databases: z.array(Name).max(34),
  test_prefix: Name.nullable(),
  locale: z.enum(["C", "C.UTF-8"]).nullable(),
  host: z.ipv4(),
  port: z.number().int().min(1).max(65535),
  container: z.string().regex(
    /^orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/,
  ).nullable(),
  agent_sha256: z.string().regex(/^[0-9a-f]{64}$/).optional(),
  index: z.number().int().nonnegative().nullable(),
  patterns: z.array(
    z.string().min(1).max(1024).regex(/^\S+$/).refine((s) => !s.includes("\0")),
  ).max(100),
  allow: z.array(z.string().regex(/^@?[a-z][a-z0-9|_-]*$/)).max(100),
}).strict().refine((i) => {
  if (i.driver === "redis") {
    return i.databases.length === 0 && i.container === null &&
      i.test_prefix === null;
  }
  if (
    !i.agent_sha256 || !i.container || !i.databases.length ||
    i.databases.some(system) || new Set(i.databases).size !== i.databases.length
  ) return false;
  if (
    !/^[A-Za-z0-9_]+$/.test(i.username) ||
    (i.driver === "mysql" && i.username.length > 32)
  ) return false;
  if (
    i.driver === "pgsql" &&
    (i.databases.some((n) => n.length > 63) || i.test_prefix !== null)
  ) return false;
  return i.test_prefix === null ||
    ![
      "mysql",
      "sys",
      "performance_schema",
      "information_schema",
      "postgres",
      "template",
    ].some((n) =>
      n.startsWith(i.test_prefix!.toLowerCase()) ||
      i.test_prefix!.toLowerCase().startsWith("template")
    );
});
const Material = z.object({
  password: z.string().min(1).max(16384).refine((s) =>
    !["\0", "\r", "\n"].some((c) => s.includes(c))
  ),
  payload: z.object({
    password: z.string().regex(/^[A-Za-z0-9]{40}$/),
    salt: z.string().regex(/^[A-Za-z0-9+/]{22}==$/),
  }).strict(),
}).strict();
const Codes = z.enum([
  "agent.version_mismatch",
  "database.server_admin_invalid",
  "database.server_admin_failed",
  "database.server_not_ready",
  "database.name_conflict",
  "database.source_missing",
  "database.server_command_failed",
  "database.index_invalid",
]);
export const CreateResult = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  z.object({ error_code: Codes }).strict(),
]);
type Input = z.infer<typeof CreateInput>;
type Private = z.infer<typeof Material>;
class Refused extends Error {
  constructor(readonly code: z.infer<typeof Codes>) {
    super(code);
  }
}
export const sqlLiteral = (s: string, postgres = false) =>
  "'" + (postgres ? s : s.replaceAll("\\", "\\\\")).replaceAll("'", "''") + "'";
const quoted = (s: string, pg: boolean) => pg ? `"${s}"` : `\`${s}\``;
const exactGrant = (s: string) => s.replaceAll("_", "\\_");
const pattern = (s: string) => s.replaceAll("_", "\\_") + "%";
const lines = (s: string) => s.trim() ? s.trim().split(/\r?\n/) : [];

/** Stable admitted salt makes a resumed PostgreSQL role creation use the same verifier. */
async function verifier(password: string, salt: string, iterations = 4096) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    "raw",
    enc.encode(password),
    "PBKDF2",
    false,
    ["deriveBits"],
  );
  const salted = await crypto.subtle.deriveBits(
    {
      name: "PBKDF2",
      hash: "SHA-256",
      iterations,
      salt: Uint8Array.from(atob(salt), (c) => c.charCodeAt(0)),
    },
    key,
    256,
  );
  const hmac = await crypto.subtle.importKey(
    "raw",
    salted,
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const client = await crypto.subtle.sign(
    "HMAC",
    hmac,
    enc.encode("Client Key"),
  );
  const stored = await crypto.subtle.digest("SHA-256", client);
  const server = await crypto.subtle.sign(
    "HMAC",
    hmac,
    enc.encode("Server Key"),
  );
  const b64 = (b: ArrayBuffer) =>
    btoa(String.fromCharCode(...new Uint8Array(b)));
  return `SCRAM-SHA-256$${iterations}:${salt}$${b64(stored)}:${b64(server)}`;
}

async function sqlCreate(
  i: Input,
  m: Private,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown>,
  signal?: AbortSignal,
) {
  const pg = i.driver === "pgsql";
  const literal = (value: string) => sqlLiteral(value, pg);
  const id = (s: string) => quoted(s, pg);
  const account = `${literal(i.username)}@'%'`;
  const execute = async (sql: string, mutates: boolean, owner = false) => {
    signal?.throwIfAborted();
    const raw = await invoke({
      name: "database.server-admin",
      pin: i.agent_sha256!,
      sudo: true,
      deadlineMs: 900_000,
      mutates,
      input: {
        operation: "execute",
        container: i.container,
        password_variable: pg ? "PGPASSWORD" : "MYSQL_PWD",
        client: pg
          ? [
            "psql",
            "--no-psqlrc",
            "--host=127.0.0.1",
            `--username=${owner ? i.username : "postgres"}`,
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
            `--user=${owner ? i.username : "root"}`,
            "--batch",
            "--skip-column-names",
          ],
        password: owner ? m.payload.password : m.password,
        sql,
      },
    }, signal);
    const refusal = z.object({ error_code: Codes }).safeParse(raw);
    if (refusal.success) throw new Refused(refusal.data.error_code);
    return z.object({ output: z.string().max(1024 * 1024) }).parse(raw).output;
  };
  const role = lines(
    await execute(
      pg
        ? `SELECT rolname FROM pg_roles WHERE rolname=${literal(i.username)};\n`
        : `SELECT User FROM mysql.user WHERE User=${literal(i.username)};\n`,
      false,
    ),
  );
  if (i.repair && !role.length) throw new Refused("database.name_conflict");
  if (role.length) {
    if (pg) {
      const stored = (await execute(
        `SELECT rolpassword FROM pg_authid WHERE rolname=${
          literal(i.username)
        } AND NOT (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication);\n`,
        false,
      )).trim();
      const match =
        /^SCRAM-SHA-256\$([0-9]{1,6}):([A-Za-z0-9+/=]{1,128})\$[A-Za-z0-9+/=]{44}:[A-Za-z0-9+/=]{44}$/
          .exec(stored);
      if (
        !match || Number(match[1]) < 4096 || Number(match[1]) > 65536 ||
        stored !==
          await verifier(m.payload.password, match[2], Number(match[1]))
      ) throw new Refused("database.name_conflict");
    } else {
      const passwordPlugin = (await execute(
        `SELECT User FROM mysql.user WHERE User=${
          literal(i.username)
        } AND Host='%' AND plugin IN ('caching_sha2_password','mysql_native_password') AND authentication_string <> '';\n`,
        false,
      )).trim();
      if (passwordPlugin !== i.username) {
        throw new Refused("database.name_conflict");
      }
    }
    try {
      const identity = (await execute(
        pg ? "SELECT current_user;\n" : "SELECT CURRENT_USER();\n",
        false,
        true,
      )).trim();
      if (identity !== (pg ? i.username : `${i.username}@%`)) {
        throw new Error("Foreign account");
      }
    } catch (error) {
      if (
        error instanceof Refused &&
        error.code !== "database.server_admin_failed"
      ) throw error;
      throw new Refused("database.name_conflict");
    }
  }
  // Check every existing name before granting anything. No existing data is ever reset.
  if (pg) {
    const foreign = await execute(
      `SELECT datname FROM pg_database WHERE datname IN (${
        i.databases.map(literal).join(",")
      }) AND pg_get_userbyid(datdba) <> ${literal(i.username)};\n`,
      false,
    );
    if (foreign.trim()) throw new Refused("database.name_conflict");
    if (!role.length) {
      const scram = await verifier(m.payload.password, m.payload.salt);
      await execute(
        `CREATE ROLE ${
          id(i.username)
        } LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD ${
          literal(scram)
        };\n`,
        true,
      );
    }
    for (const name of i.databases) {
      const exists = (await execute(
        `SELECT datname FROM pg_database WHERE datname=${literal(name)};\n`,
        false,
      )).trim();
      if (!exists) {
        await execute(
          `CREATE DATABASE ${id(name)} OWNER ${id(i.username)}${
            i.locale === null
              ? ""
              : ` TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE ${
                literal(i.locale)
              }`
          };\n`,
          true,
        );
      }
      // Assert owner again in the mutating session; a renamed/replaced database is never adopted.
      await execute(
        `DO $orbit$ BEGIN IF EXISTS (SELECT FROM pg_database WHERE datname=${
          literal(name)
        } AND datdba IS DISTINCT FROM (SELECT oid FROM pg_roles WHERE rolname=${
          literal(i.username)
        })) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END $orbit$;\nREVOKE ALL ON DATABASE ${
          id(name)
        } FROM PUBLIC;\n\\connect ${name}\nREVOKE CREATE ON SCHEMA public FROM PUBLIC;\nGRANT ALL ON SCHEMA public TO ${
          id(i.username)
        };\n\\connect postgres\n`,
        true,
      );
    }
  } else {
    const condition = `SCHEMA_NAME IN (${i.databases.map(literal).join(",")})${
      i.test_prefix === null
        ? ""
        : ` OR SCHEMA_NAME LIKE ${literal(pattern(i.test_prefix))}`
    }`;
    const existing = lines(
      await execute(
        `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE ${condition};\n`,
        false,
      ),
    );
    for (const name of existing) {
      if (system(name)) throw new Refused("database.name_conflict");
      const owned = role.length &&
        (await execute(
          `SELECT Db FROM mysql.db WHERE User=${
            literal(i.username)
          } AND Host='%' AND (Db=${literal(exactGrant(name))}${
            i.test_prefix === null || !name.startsWith(i.test_prefix)
              ? ""
              : ` OR Db=${literal(pattern(i.test_prefix))}`
          }) AND Create_priv='Y';\n`,
          false,
        )).trim();
      if (!owned) throw new Refused("database.name_conflict");
    }
    if (!role.length) {
      await execute(
        `CREATE USER ${account} IDENTIFIED BY ${
          literal(m.payload.password)
        };\n`,
        true,
      );
    }
    const grants = i.databases.map((n) =>
      `GRANT ALL PRIVILEGES ON ${id(exactGrant(n))}.* TO ${account};`
    ).join("\n") + (i.test_prefix === null
      ? ""
      : `\nGRANT ALL PRIVILEGES ON ${
        id(pattern(i.test_prefix))
      }.* TO ${account};`);
    // The grant precedes CREATE so replay can distinguish this role's partial create.
    await execute(grants + "\n", true);
    for (const name of i.databases) {
      if (!existing.includes(name)) {
        await execute(`CREATE DATABASE ${id(name)};\n`, true);
      }
    }
  }
}

async function aclCreate(
  i: Input,
  m: Private,
  connect: typeof ValkeyClient.connect,
  signal?: AbortSignal,
) {
  const client = await connect(i.host, i.port);
  try {
    signal?.throwIfAborted();
    await client.command("AUTH", "default", m.password);
    if (i.index !== null) {
      const config = z.array(z.string()).length(2).parse(
        await client.command("CONFIG", "GET", "databases"),
      );
      if (config[0] !== "databases" || !/^[1-9][0-9]*$/.test(config[1])) {
        throw new Refused("database.server_command_failed");
      }
      if (i.index >= Number(config[1])) {
        throw new Refused("database.index_invalid");
      }
    }
    const hash = Array.from(
      new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(m.payload.password),
        ),
      ),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const prior = await client.command("ACL", "GETUSER", i.username);
    if (prior !== null) {
      const acl = z.array(z.unknown()).max(32).parse(prior);
      const at = acl.indexOf("passwords");
      if (at < 0 || !z.array(z.string()).parse(acl[at + 1]).includes(hash)) {
        throw new Refused("database.name_conflict");
      }
    }
    signal?.throwIfAborted();
    await client.command(
      "ACL",
      "SETUSER",
      i.username,
      "reset",
      "on",
      `#${hash}`,
      ...i.patterns.map((p) => `~${p}`),
      "resetchannels",
      "-@all",
      ...i.allow.map((a) => `+${a}`),
      "-@admin",
      "-@dangerous",
      "-function",
      "-script",
    );
    // A failed SAVE retains this owned user for safe convergence on explicit resume.
    await client.command("ACL", "SAVE");
  } finally {
    client.close();
  }
}

export async function createDatabase(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke = runNodeAgent,
  connect = ValkeyClient.connect,
): Promise<z.infer<typeof CreateResult>> {
  try {
    const i = CreateInput.parse(raw);
    let result: z.infer<typeof CreateResult> | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      try {
        if (i.driver === "redis") await aclCreate(i, material, connect, signal);
        else await sqlCreate(i, material, invoke, signal);
        result = { backend: "active" };
      } catch (error) {
        if (error instanceof Refused) result = { error_code: error.code };
        else throw new Error("database.create_outcome_unknown");
      }
    });
    if (!result) throw new Error("No completed creation");
    return result;
  } catch {
    throw new Error("database.create_outcome_unknown");
  }
}

export function databaseCreate(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke = runNodeAgent,
  connect = ValkeyClient.connect,
) {
  return nativeEffect(
    "database.create",
    "@nckrtl/database-create",
    CreateResult,
    (input, gateway, signal) =>
      createDatabase(
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
  ...databaseCreate(),
  type: "@nckrtl/database-create",
};
