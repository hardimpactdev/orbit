import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { checkInfrastructure } from "./doctor_infrastructure.ts";
import type { Family, ProbeContext, Row } from "./doctor_contract.ts";
import { ValkeyClient } from "../operations/database/valkey_client.ts";

function fixture(resources: Record<string, Row[]> = {}) {
  const calls: { name: string; input: Row; sudo?: boolean }[] = [];
  const local: string[][] = [];
  const ctx: ProbeContext = {
    node: {
      id: 209,
      name: "dev",
      platform: "linux",
      architecture: "x86_64",
      user: "orbit",
      managed: true,
      parked: false,
      roles: [],
      resources,
      agent_sha256: "a".repeat(64),
    },
    nodes: [],
    observation: { reachable: true },
    inspectionFailed: false,
    privateMaterial: {
      database_servers: {
        "4": { password: "private-sentinel", acl_users: [] },
      },
    },
    run: (name, input, sudo) => {
      calls.push({ name, input, sudo });
      return Promise.resolve(
        name === "doctor.inspect-role-runtime"
          ? { packages: true, services: true, caddy_version: null }
          : name === "firewall.inspect"
          ? { matches: true }
          : name === "database.server-admin"
          ? { output: "1\n" }
          : {},
      );
    },
    local: (argv) => {
      local.push(argv);
      return Promise.resolve("");
    },
  };
  return { ctx, calls, local };
}
const role = (id: number, name: string, values: Row = {}): Row => ({
  id,
  name,
  role: name,
  status: "active",
  stale_claim: false,
  claim_held: false,
  singleton: false,
  fleet_ids: [id],
  conflicts: [],
  packages: ["git"],
  services: [],
  firewall: { expected: [], exclusive_families: [], stored: false },
  ...values,
});
const codes = (reports: Awaited<ReturnType<typeof checkInfrastructure>>) =>
  reports.flatMap((r) => r.issues.map((i) => i.code));
const server = (values: Row = {}): Row => ({
  id: 4,
  name: "dev-db",
  host: "10.8.0.209",
  driver: "mysql",
  container: "orbit-process-3-dev-db",
  private_endpoints_managed: true,
  published_ports: ["10.8.0.209:3306:3306"],
  expected_ports: ["10.8.0.209:3306:3306"],
  initialization_secret_present: false,
  connections: [],
  ...values,
});

Deno.test("empty infrastructure inventory does not inspect a Node", async () => {
  const { ctx, calls } = fixture();
  const result = await checkInfrastructure(ctx, [
    "role",
    "database_server",
    "database_connection",
  ]);
  assertEquals(result.map((r) => [r.family, r.status, r.checked]), [
    ["role", "healthy", 0],
    ["database_connection", "healthy", 0],
    ["database_server", "healthy", 0],
  ]);
  assertEquals(calls, []);
});

Deno.test("unreachable roles retain assignment lifecycle singleton and cluster drift", async () => {
  const { ctx, calls } = fixture({
    role_checks: [
      role(1, "gateway", {
        status: "provisioning",
        stale_claim: true,
        conflicts: ["ingress"],
        singleton: true,
        fleet_ids: [1, 10],
      }),
      role(2, "ingress", {
        cluster_id: 3,
        node_cluster_id: 4,
        cluster_ingress_ids: [2, 12],
      }),
    ],
  });
  ctx.inspectionFailed = true;
  const reports = await checkInfrastructure(ctx, ["role"]);
  assertEquals(codes(reports), [
    "role.claim_stale",
    "role.assignment_conflict",
    "role.singleton_conflict",
    "role.assignment_conflict",
    "role.cluster_ownership_mismatch",
    "role.cluster_cardinality_conflict",
    "role.node_unreachable",
  ]);
  assertEquals(reports[0].status, "unverifiable");
  assertEquals(calls, []);
});

Deno.test("active role inspections retain independent package service firewall and VPN findings", async () => {
  const { ctx, calls } = fixture({
    role_checks: [
      role(1, "vpn", {
        vpn: {
          config_sha256: "a".repeat(64),
          dns_records_sha256: "b".repeat(64),
        },
      }),
    ],
  });
  const run = ctx.run;
  ctx.run = (name, input, sudo) => {
    calls.push({ name, input, sudo });
    return Promise.resolve(
      name === "doctor.inspect-role-runtime"
        ? { packages: false, services: false, caddy_version: "v2.8.4" }
        : name === "firewall.inspect"
        ? { matches: false }
        : name === "wireguard.inspect-hub"
        ? { active: false, config_sha256: null, dns_records_sha256: null }
        : run(name, input, sudo),
    );
  };
  const result = await checkInfrastructure(ctx, ["role"]);
  assertEquals(codes(result), [
    "role.packages_missing",
    "role.caddy_version_unsupported",
    "role.services_inactive",
    "role.firewall_projection_mismatch",
    "role.vpn_inactive",
    "role.vpn_projection_mismatch",
    "role.dns_projection_mismatch",
  ]);
  assertEquals(calls.map((c) => [c.name, c.sudo]), [
    ["doctor.inspect-role-runtime", undefined],
    ["firewall.inspect", true],
    ["wireguard.inspect-hub", true],
  ]);
});

Deno.test("malformed role output stays bounded and later roles still run", async () => {
  const { ctx } = fixture({
    role_checks: [role(1, "router"), role(2, "database")],
  });
  const run = ctx.run;
  let once = true;
  ctx.run = (name, input, sudo) => {
    if (once) {
      once = false;
      return Promise.resolve({ packages: "private-sentinel" });
    }
    return run(name, input, sudo);
  };
  const result = await checkInfrastructure(ctx, ["role"]);
  assertEquals(codes(result), ["role.inspection_failed"]);
  assertEquals(result[0].observed_resources, [2]);
  assert(!JSON.stringify(result).includes("private-sentinel"));
});

Deno.test("shared Caddy drift is reported once and every missing PHP pool belongs to app-dev", async () => {
  const { ctx, calls } = fixture({
    role_checks: [role(1, "router"), role(2, "app-prod"), role(3, "app-dev")],
    role_shared: [{
      caddy: {
        needed: true,
        sha256: "a".repeat(64),
        version: "expected-version",
        buildable: true,
        sources: ["routes"],
      },
      php: { configurations: {} },
    }],
  });
  const run = ctx.run;
  ctx.run = (name, input, sudo) => {
    if (name === "caddy.read-live") {
      calls.push({ name, input, sudo });
      return Promise.resolve({ content: "unmanaged Caddyfile" });
    }
    if (name === "php.inspect-shared-pools") {
      calls.push({ name, input, sudo });
      return Promise.resolve({
        versions: ["8.5"],
        missing_pools: [{
          pool: "orbit-instance-10",
          version: "8.5",
          directory: "/missing-one",
          installed: true,
        }, {
          pool: "orbit-instance-11",
          version: "8.5",
          directory: "/missing-two",
          installed: false,
        }],
      });
    }
    return run(name, input, sudo);
  };
  const result = await checkInfrastructure(ctx, ["role"]);
  assertEquals(
    result[0].issues.map((i) => [i.code, i.resource_id, i.observed]),
    [["role.caddy_build_drift", 1, "not_built"], [
      "role.php_pool_directory_missing",
      3,
      "missing",
    ], ["role.php_pool_directory_missing", 3, "missing"]],
  );
  assertEquals(calls.filter((c) => c.name === "caddy.read-live").length, 1);
});

Deno.test("private DNS and CI host comparisons execute only bounded Node-local reads", async () => {
  const { ctx, local } = fixture({
    role_checks: [
      role(1, "gateway", {
        private_dns: {
          address: "10.8.0.1",
          domain: "orbit",
          drop_in: "stored drop-in\n",
        },
      }),
      role(2, "ci-runner"),
    ],
  });
  ctx.local = (argv) => {
    local.push(argv);
    return Promise.resolve(
      argv.includes("incus")
        ? "[]"
        : argv.includes("find")
        ? ""
        : argv.includes("cat")
        ? "stored drop-in\n"
        : argv.includes("domain")
        ? "Link 3 (orbit): ~orbit\n"
        : argv.includes("default-route")
        ? "Link 3 (orbit): no\n"
        : "Link 3 (orbit): 10.8.0.9\n",
    );
  };
  assertEquals(codes(await checkInfrastructure(ctx, ["role"])), [
    "role.private_dns_route_mismatch",
    "role.ci_runner_host_mismatch",
  ]);
  assert(
    !local.flat().some((arg) => arg.includes("ssh") || arg.includes("bash")),
  );
});

Deno.test("database registry checks separate attachment and connection identity without secrets", async () => {
  const connection = {
    driver: "mysql",
    node_exists: true,
    host: "10.8.0.5",
    port: 3306,
    database: "app",
    username: "app",
    password_present: true,
  };
  const { ctx, calls } = fixture({
    database_connections: [
      { id: "attachment:1", name: "app:DB", missing: true },
      {
        id: "attachment:2",
        name: "app:DB",
        ...connection,
        env_expected_keys: ["DB_PASSWORD", "DB_HOST"],
        env_equal_keys: ["DB_HOST"],
        env_forget_keys: [],
        env_present_keys: ["DB_PASSWORD", "DB_HOST"],
      },
      { id: "connection:2", name: "app", ...connection },
      {
        id: "connection:3",
        name: "broken",
        ...connection,
        password_present: false,
      },
      {
        id: "connection:4",
        name: "sqlite",
        driver: "sqlite",
        path: "/db.sqlite",
        node_exists: true,
      },
    ],
  });
  const result = await checkInfrastructure(ctx, ["database_connection"]);
  assertEquals(codes(result), [
    "database_connection.missing",
    "database_connection.env_mismatch",
    "database_connection.unhealthy",
  ]);
  assertEquals(result[0].observed_resources, [
    "connection:2",
    "connection:4",
  ]);
  assertEquals(calls, []);
  assert(!JSON.stringify(result).includes("private-sentinel"));
});

Deno.test("PostgreSQL reads report each missing test member and replication mismatch", async () => {
  const { ctx, calls } = fixture({
    database_servers: [server({
      driver: "pgsql",
      initialization_secret_present: true,
      published_ports: [],
      connections: [{
        name: "app",
        test_databases: ["app_test", "app_test_test_1"],
        users: [{ username: "replica", replication: true }, {
          username: "app",
          replication: false,
        }],
      }],
    })],
  });
  const result = await checkInfrastructure(
    ctx,
    ["database_server"],
    (_server, password, sql) => {
      calls.push({ name: "native-sql-read", input: { password, sql } });
      return Promise.resolve(
        sql.includes("pg_database")
          ? "app_test\n"
          : sql.includes("pg_roles")
          ? "false\n"
          : "1\n",
      );
    },
  );
  assertEquals(codes(result), [
    "database_server.publication_mismatch",
    "database_server.initialization_secret_present",
    "database_server.test_database_missing",
    "database_server.replication_mismatch",
  ]);
  assertEquals(calls.length, 4);
  assert(
    calls.every((c) =>
      c.name === "native-sql-read" &&
      String(c.input.sql).startsWith("SELECT ") &&
      c.input.password === "private-sentinel"
    ),
  );
  assert(!JSON.stringify(result).includes("private-sentinel"));
});

Deno.test("SQL login failure is bounded and standalone inspection exceptions do not leak", async () => {
  const { ctx } = fixture({
    database_servers: [server({ initialization_secret_present: true })],
  });
  const result = await checkInfrastructure(
    ctx,
    ["database_server"],
    () => Promise.reject(new Error("private-sentinel")),
  );
  assertEquals(codes(result), [
    "database_server.login_failed",
    "database_server.initialization_secret_present",
  ]);
  assert(!JSON.stringify(result).includes("private-sentinel"));
});

Deno.test("Valkey compares ACL read results and never issues a mutation", async () => {
  const { ctx } = fixture({
    database_servers: [
      server({ driver: "redis", port: 6379, default_open: false }),
    ],
  });
  ctx.privateMaterial = {
    database_servers: {
      "4": {
        password: "private-sentinel",
        acl_users: [{
          name: "default",
          rules: "on ~* &* +@all",
          hashes: ["a".repeat(64)],
        }],
      },
    },
  };
  const commands: string[][] = [];
  const connect = ValkeyClient.connect;
  const hosts: string[] = [];
  ValkeyClient.connect = (host, _port) => {
    hosts.push(host);
    return Promise.resolve({
      command: (...args: string[]) => {
        commands.push(args);
        return Promise.resolve(
          args[0] === "PING"
            ? "PONG"
            : args[0] === "AUTH"
            ? "OK"
            : args[0] === "CONFIG"
            ? ["aclfile", "wrong-file"]
            : [
              `user default on #${"a".repeat(64)} ~* &* +@all`,
              "user stale on nopass ~* +@all",
            ],
        );
      },
      close: () => {},
    } as unknown as ValkeyClient);
  };
  try {
    const result = await checkInfrastructure(ctx, ["database_server"]);
    assertEquals(codes(result), [
      "database_server.open",
      "database_server.acl_file_missing",
      "database_server.acl_mismatch",
    ]);
    assertEquals(hosts, ["10.8.0.209", "10.8.0.209"]);
    assertEquals(commands.map((c) => c.slice(0, 2)), [
      ["PING"],
      ["AUTH", "default"],
      ["CONFIG", "GET"],
      ["ACL", "LIST"],
    ]);
    assert(!JSON.stringify(result).includes("private-sentinel"));
  } finally {
    ValkeyClient.connect = connect;
  }
});

Deno.test("family selection performs no reads for unselected families", async () => {
  const { ctx, calls } = fixture({ role_checks: [role(1, "vpn")] });
  const result = await checkInfrastructure(ctx, ["node", "route"] as Family[]);
  assertEquals(result, []);
  assertEquals(calls, []);
});
