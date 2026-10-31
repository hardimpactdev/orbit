import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createServer, waitForValkeyReady } from "./server_create.ts";
import { aclState, convergeAcl, passwordHash } from "./valkey_policy.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const input = {
  driver: "mysql",
  process_id: 42,
  container: "orbit-process-42-proof",
  container_input: {
    image: "mysql:8.0",
    command: ["mysqld"],
    restart: "unless-stopped",
    working_directory: null,
    ports: ["10.44.0.9:3306:3306"],
    mounts: [{
      source: "orbit-proof-data",
      target: "/var/lib/mysql",
      read_only: false,
    }],
  },
  spec: "b".repeat(64),
  scrub_spec: "c".repeat(64),
  agent_sha256: "a".repeat(64),
  initialize: true,
  validate_existing: false,
  was_active: false,
  host: "10.44.0.9",
  port: 3306,
  default_open: false,
  volume: null,
};
const password = "private-server-create-sentinel";
const material = {
  password,
  environment: { MYSQL_ROOT_PASSWORD: password },
  acl: "",
  payload: {},
};
const use = (f: (m: Record<string, unknown>) => Promise<void>) => f(material);

Deno.test("MySQL initializes with protected stdin, proves readiness, scrubs the environment then proves readiness", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createServer(input, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve({});
    }),
    { backend: "active" },
  );
  assertEquals(calls.map((c) => c.name), [
    "process.converge-container",
    "database.server-admin",
    "process.converge-container",
    "database.server-admin",
  ]);
  assertEquals(
    calls.map((c) => [c.pin, c.sudo]),
    Array.from({ length: 4 }, () => [input.agent_sha256, true]),
  );
  assertEquals(calls[0].input.environment, material.environment);
  assertEquals(calls[0].input.spec, input.spec);
  assertEquals(calls[2].input.environment, {});
  assertEquals(calls[2].input.spec, input.scrub_spec);
  assertEquals(calls[1].input.client, [
    "mysql",
    "--protocol=TCP",
    "--host=127.0.0.1",
    "--user=root",
    "--batch",
    "--skip-column-names",
  ]);
  assertEquals(calls[1].mutates, false);
});

Deno.test("PostgreSQL uses the authenticated TCP readiness client and its own initializer", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createServer(
      {
        ...input,
        driver: "pgsql",
        port: 5432,
        container_input: {
          ...input.container_input,
          image: "postgres:18.6",
          command: ["postgres"],
          mounts: [{
            ...input.container_input.mounts[0],
            target: "/var/lib/postgresql",
          }],
        },
      },
      (f) => f({ ...material, environment: { POSTGRES_PASSWORD: password } }),
      undefined,
      (call) => {
        calls.push(call);
        return Promise.resolve({});
      },
    ),
    { backend: "active" },
  );
  assertEquals(calls[0].input.environment, { POSTGRES_PASSWORD: password });
  assertEquals(calls[1].input.password_variable, "PGPASSWORD");
  assertEquals(
    (calls[1].input.client as string[]).includes("--host=127.0.0.1"),
    true,
  );
  assertEquals(calls[2].input.environment, {});
});

Deno.test("a failed readiness stops before scrub and exposes only its closed code", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createServer(input, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve(
        calls.length === 2 ? { error_code: "database.server_not_ready" } : {},
      );
    }),
    { error_code: "database.server_not_ready" },
  );
  assertEquals(calls.length, 2);
});

Deno.test("an active SQL repair never reintroduces the initialization password", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createServer(
      { ...input, initialize: false, was_active: true, spec: input.scrub_spec },
      (f) => f({ ...material, environment: {} }),
      undefined,
      (call) => {
        calls.push(call);
        return Promise.resolve({});
      },
    ),
    { backend: "active" },
  );
  assertEquals(calls.length, 2);
  assertEquals(calls[0].input.environment, {});
});

Deno.test("an unexpected environment or unsafe mount refuses before any Node mutation", async () => {
  let calls = 0;
  for (
    const [candidate, secret] of [
      [input, {
        ...material,
        environment: { MYSQL_ROOT_PASSWORD: password, EVIL: "private-extra" },
      }],
      [{
        ...input,
        container_input: {
          ...input.container_input,
          mounts: [{
            ...input.container_input.mounts[0],
            source: "/srv/foreign",
          }],
        },
      }, material],
    ] as const
  ) {
    const error = await assertRejects(() =>
      createServer(candidate, (f) => f(secret), undefined, () => {
        calls++;
        return Promise.resolve({});
      })
    );
    assertEquals(String(error).includes(password), false);
  }
  assertEquals(calls, 0);
});

function redisInput() {
  return {
    ...input,
    driver: "redis",
    port: 6379,
    initialize: false,
    volume: "orbit-proof-data",
    container_input: {
      ...input.container_input,
      image: "valkey/valkey:8.0",
      command: [
        "valkey-server",
        "--appendonly",
        "yes",
        "--dir",
        "/data",
        "--aclfile",
        "/data/users.acl",
      ],
      mounts: [{ ...input.container_input.mounts[0], target: "/data" }],
    },
  };
}

Deno.test("new Valkey writes its private ACL before converge and authenticates readiness", async () => {
  const calls: NodeCall[] = [];
  const commands: string[][] = [];
  const acl = `user default on #${await passwordHash(password)} ~* &* +@all\n`;
  assertEquals(
    await createServer(
      redisInput(),
      (f) =>
        f({
          ...material,
          environment: {},
          acl,
          payload: { users: [], default_open: false },
        }),
      undefined,
      (call) => {
        calls.push(call);
        return Promise.resolve({});
      },
      () =>
        Promise.resolve({
          command(...args: string[]) {
            commands.push(args);
            return Promise.resolve("OK");
          },
          close() {},
        }),
    ),
    { backend: "active" },
  );
  assertEquals(calls.map((c) => c.name), [
    "database.write-valkey-acl",
    "process.converge-container",
  ]);
  assertEquals(calls[0].input.acl, acl);
  assertEquals(calls[1].input.environment, {});
  assertEquals(commands, [["AUTH", "default", password], ["PING"]]);
});

Deno.test("Valkey adoption refuses foreign ACL or persistence before any Node write", async () => {
  for (const foreignAcl of [false, true]) {
    let writes = 0;
    assertEquals(
      await createServer(
        { ...redisInput(), validate_existing: true },
        (f) =>
          f({
            ...material,
            environment: {},
            acl: "private-acl",
            payload: { users: [], default_open: false },
          }),
        undefined,
        () => {
          writes++;
          return Promise.resolve({});
        },
        () =>
          Promise.resolve({
            async command(...args: string[]) {
              if (args[1] === "LIST") {
                return [
                  `user default on #${await passwordHash(
                    password,
                  )} ~* &* +@all`,
                  ...(foreignAcl ? ["user foreign on nopass ~* +@all"] : []),
                ];
              }
              if (args[0] === "CONFIG") return [args[2], "/foreign"];
              return "OK";
            },
            close() {},
          }),
      ),
      { error_code: "database.server_adoption_refused" },
    );
    assertEquals(writes, 0);
  }
});

Deno.test("ACL convergence preserves open default and unchanged users but removes stale users", async () => {
  const hash = "a".repeat(64);
  const calls: string[][] = [];
  await convergeAcl(
    {
      command(...args: string[]) {
        calls.push(args);
        return Promise.resolve(
          args[1] === "LIST"
            ? [
              "user default on nopass ~* &* +@all",
              `user unchanged sanitize-payload on #${hash} ~b ~a &* -@all +@list`,
              "user stale on nopass ~* +@all",
            ]
            : "OK",
        );
      },
      close() {},
    },
    [{ name: "unchanged", rules: "on ~a ~b &* -@all +@list", hashes: [hash] }],
    true,
  );
  assertEquals(calls.filter((c) => c[1] === "SETUSER"), []);
  assertEquals(calls.slice(-2), [["ACL", "DELUSER", "stale"], ["ACL", "SAVE"]]);
  assertEquals(
    aclState({ name: "a", rules: "on ~* +@list -@all", hashes: [] }) ===
      aclState({ name: "a", rules: "on ~* -@all +@list", hashes: [] }),
    false,
  );
});

Deno.test("Valkey adoption login refusal is bounded and cannot write its ACL", async () => {
  let writes = 0;
  assertEquals(
    await createServer(
      { ...redisInput(), validate_existing: true },
      (f) =>
        f({
          ...material,
          environment: {},
          acl: "private-acl",
          payload: { users: [], default_open: false },
        }),
      undefined,
      () => {
        writes++;
        return Promise.resolve({});
      },
      () =>
        Promise.resolve({
          command() {
            return Promise.reject(new Error(password));
          },
          close() {},
        }),
    ),
    { error_code: "database.server_adoption_refused" },
  );
  assertEquals(writes, 0);
});

Deno.test("Valkey readiness tolerates a transient refusal and closes the successful probe", async () => {
  let now = 0;
  let attempts = 0;
  let closes = 0;
  assertEquals(
    await waitForValkeyReady(
      () => {
        if (++attempts === 1) {
          return Promise.reject(
            new Error("connection refused"),
          );
        }
        return Promise.resolve({
          command() {
            return Promise.resolve("PONG");
          },
          close() {
            closes++;
          },
        });
      },
      undefined,
      (ms) => {
        now += ms;
        return Promise.resolve();
      },
      () => now,
    ),
    true,
  );
  assertEquals(attempts, 2);
  assertEquals(closes, 1);
});
Deno.test("Valkey readiness exhausts its fixed startup budget without exposing diagnostics", async () => {
  let now = 0;
  let attempts = 0;
  assertEquals(
    await waitForValkeyReady(
      () => {
        attempts++;
        return Promise.reject(new Error(password));
      },
      undefined,
      (ms) => {
        now += ms;
        return Promise.resolve();
      },
      () => now,
    ),
    false,
  );
  assertEquals(now, 120000);
  assertEquals(attempts, 60);
});

Deno.test("stale open Valkey repair refuses closed or uncertain live policy without any write", async () => {
  for (const probeFails of [false, true]) {
    let writes = 0;
    const result = await createServer(
      { ...redisInput(), was_active: true, default_open: true },
      (effect) =>
        effect({
          ...material,
          environment: {},
          acl: "user default on nopass ~* &* +@all\n",
          payload: { users: [], default_open: true },
        }),
      undefined,
      () => {
        writes++;
        return Promise.resolve({});
      },
      () =>
        Promise.resolve({
          async command(...args: string[]) {
            if (probeFails) throw new Error(password);
            if (args[0] === "CONFIG") return ["aclfile", "/data/users.acl"];
            if (args[0] === "ACL") {
              return [
                `user default on #${await passwordHash(password)} ~* &* +@all`,
              ];
            }
            return "OK";
          },
          close() {},
        }),
    );
    assertEquals(result, { error_code: "database.server_policy_changed" });
    assertEquals(writes, 0);
  }
});
