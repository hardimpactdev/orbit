import { localCommand } from "../operations/_lib/local-worker.ts";
import {
  type Family,
  type FamilyReport,
  type Issue,
  issue,
  nodeCheck,
  type ProbeContext,
  report,
  type Row,
  rows,
} from "./doctor_contract.ts";
import { ValkeyClient } from "../operations/database/valkey_client.ts";
import {
  aclState,
  AclUser,
  liveAcl,
} from "../operations/database/valkey_policy.ts";

const strings = (value: unknown): string[] => {
  if (!Array.isArray(value) || !value.every((v) => typeof v === "string")) {
    throw new Error("Invalid observation");
  }
  return value;
};
const bool = (value: unknown): boolean => {
  if (typeof value !== "boolean") throw new Error("Invalid observation");
  return value;
};
const object = (value: unknown): Row => {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error("Invalid observation");
  }
  return value as Row;
};
const list = (value: unknown): Row[] => {
  if (!Array.isArray(value)) throw new Error("Invalid observation");
  return value.map(object);
};
function failed(family: Family, row: Row): Issue {
  return issue(
    family,
    `${family}.inspection_failed`,
    row,
    "The observation could not be verified.",
    "verifiable",
    "unverifiable",
    "unverifiable",
  );
}

export async function checkInfrastructure(
  ctx: ProbeContext,
  families: Family[],
  readSql: SqlReader = readDatabaseServer,
): Promise<FamilyReport[]> {
  const results: FamilyReport[] = [];
  if (families.includes("role")) results.push(await checkRoles(ctx));
  if (families.includes("database_connection")) {
    results.push(checkConnections(ctx));
  }
  if (families.includes("database_server")) {
    results.push(await checkServers(ctx, readSql));
  }
  return results;
}

async function checkRoles(ctx: ProbeContext): Promise<FamilyReport> {
  const roles = rows(ctx, "role_checks");
  const issues: Issue[] = [];
  const add = (
    row: Row,
    code: string,
    summary: string,
    expected: string | boolean = true,
    observed: string | boolean = false,
  ) =>
    issues.push(
      issue("role", `role.${code}`, row, summary, expected, observed),
    );
  for (const role of roles) {
    if (role.status !== "active") {
      add(
        role,
        role.stale_claim && !role.claim_held
          ? "claim_stale"
          : "lifecycle_not_active",
        "Role lifecycle is not active.",
        "active",
        String(role.status),
      );
    }
    if (
      roles.some((other) =>
        other.id !== role.id &&
        (strings(role.conflicts).includes(String(other.role)) ||
          strings(other.conflicts).includes(String(role.role)))
      )
    ) {
      add(
        role,
        "assignment_conflict",
        "Role assignment conflicts with another role.",
        "compatible",
        "conflict",
      );
    }
    if (
      role.singleton && Array.isArray(role.fleet_ids) &&
      role.fleet_ids.length > 1
    ) {
      add(
        role,
        "singleton_conflict",
        "Singleton role is assigned more than once.",
        "unique",
        "conflict",
      );
    }
    if (role.role === "ingress") {
      if (
        !Number.isInteger(role.cluster_id) ||
        role.cluster_id !== role.node_cluster_id
      ) {
        add(
          role,
          "cluster_ownership_mismatch",
          "Ingress Cluster ownership does not match its Node.",
          "node_cluster",
          "mismatch",
        );
      }
      if (
        role.status === "active" && Array.isArray(role.cluster_ingress_ids) &&
        role.cluster_ingress_ids.length > 1
      ) {
        add(
          role,
          "cluster_cardinality_conflict",
          "Cluster has more than one active Ingress role.",
          "unique",
          "conflict",
        );
      }
    }
  }
  const active = roles.filter((role) => role.status === "active");
  if (
    active.length &&
    (ctx.inspectionFailed || ctx.observation.reachable === false)
  ) {
    issues.push(
      issue(
        "role",
        "role.node_unreachable",
        nodeCheck(ctx.node),
        "Role state cannot be inspected because the Node is unreachable.",
        "reachable",
        "unreachable",
        "unverifiable",
      ),
    );
    return report("role", roles.length, issues);
  }
  const observed: (number | string)[] = [];
  for (const role of active) {
    try {
      if (role.intent_failed) throw new Error("Intent unavailable");
      const runtime = await ctx.run("doctor.inspect-role-runtime", {
        packages: strings(role.packages),
        services: strings(role.services),
      });
      if (!bool(runtime.packages)) {
        add(role, "packages_missing", "Required role packages are missing.");
      }
      if (runtime.caddy_version !== null) {
        if (typeof runtime.caddy_version !== "string") {
          throw new Error("Invalid Caddy version");
        }
        const version = runtime.caddy_version.match(
          /^v?(\d+)\.(\d+)\.(\d+)(?:\s|$)/,
        );
        if (
          !version || Number(version[1]) < 2 ||
          (Number(version[1]) === 2 && Number(version[2]) < 9)
        ) {
          add(
            role,
            "caddy_version_unsupported",
            "Installed Caddy is older than the release Orbit renders against.",
            ">=2.9.0",
            version ? version.slice(1).join(".") : "unknown",
          );
        }
      }
      if (!bool(runtime.services)) {
        add(role, "services_inactive", "Required role services are inactive.");
      }
      if (
        role.private_dns !== undefined &&
        !await privateDnsMatches(ctx, object(role.private_dns))
      ) {
        add(
          role,
          "private_dns_route_mismatch",
          "The Gateway machine does not route the private domain to VPN DNS.",
        );
      }
      if (role.role === "ci-runner" && !await ciHostMatches(ctx)) {
        add(
          role,
          "ci_runner_host_mismatch",
          "CI runner project, bridge, ACL or default profile differs from the role baseline.",
        );
      }
      const firewall = await ctx.run(
        "firewall.inspect",
        object(role.firewall),
        true,
      );
      if (!bool(firewall.matches)) {
        add(
          role,
          "firewall_projection_mismatch",
          "Role firewall projection does not match managed intent.",
        );
      }
      if (role.role === "vpn") {
        const expected = object(role.vpn);
        const vpn = await ctx.run("wireguard.inspect-hub", {}, true);
        if (!bool(vpn.active)) {
          add(role, "vpn_inactive", "The managed VPN interface is inactive.");
        }
        for (
          const [key, code, summary] of [
            [
              "config_sha256",
              "vpn_projection_mismatch",
              "VPN server projection does not match managed intent.",
            ],
            [
              "dns_records_sha256",
              "dns_projection_mismatch",
              "Private DNS projection does not match managed intent.",
            ],
          ]
        ) {
          if (
            vpn[key] !== null &&
            (typeof vpn[key] !== "string" ||
              !/^[a-f0-9]{64}$/.test(String(vpn[key])))
          ) throw new Error("Invalid VPN observation");
          if (vpn[key] !== expected[key]) add(role, code, summary);
        }
      }
      observed.push(role.id as number);
    } catch {
      issues.push(failed("role", role));
    }
  }
  const shared = rows(ctx, "role_shared")[0] ?? {};
  const caddyRole = active.find((role) =>
    [
      "gateway",
      "router",
      "ingress",
      "app-dev",
      "app-prod",
      "websocket",
      "analytics",
    ].includes(String(role.role))
  ) ?? active[0];
  if (caddyRole && shared.caddy) {
    try {
      const expected = object(shared.caddy);
      if (expected.failed) throw new Error("Intent unavailable");
      if (expected.needed) {
        const live = await ctx.run("caddy.read-live", {}, true);
        if (typeof live.content !== "string") {
          throw new Error("Invalid live Caddyfile");
        }
        const digest = new Uint8Array(
          await crypto.subtle.digest(
            "SHA-256",
            new TextEncoder().encode(live.content),
          ),
        );
        const actual = Array.from(
          digest,
          (b) => b.toString(16).padStart(2, "0"),
        ).join("");
        if (!expected.buildable || actual !== expected.sha256) {
          const version =
            live.content.startsWith("# Managed by Orbit: Node Caddy build\n")
              ? actual.slice(0, 32)
              : undefined;
          add(
            caddyRole,
            "caddy_build_drift",
            `The live Caddyfile differs from a fresh Node Caddy build. Site sources: ${
              strings(expected.sources).join(", ") || "none"
            }.`,
            expected.buildable ? String(expected.version) : "buildable",
            expected.buildable ? version ?? "not_built" : "refused",
          );
        }
      }
    } catch {
      issues.push(failed("role", caddyRole));
    }
  }
  const phpRole = active.find((role) => role.role === "app-dev") ??
    active.find((role) => role.role === "app-prod");
  if (phpRole && shared.php) {
    try {
      const input = object(shared.php);
      if (input.failed) throw new Error("Intent unavailable");
      const state = await ctx.run("php.inspect-shared-pools", {
        configurations: object(input.configurations),
      }, true);
      for (const pool of list(state.missing_pools)) {
        if (
          ![pool.pool, pool.version, pool.directory].every((v) =>
            typeof v === "string"
          ) || typeof pool.installed !== "boolean"
        ) throw new Error("Invalid pool observation");
        add(
          phpRole,
          "php_pool_directory_missing",
          `PHP ${pool.version} pool [${pool.pool}] names the missing directory [${pool.directory}], ${
            pool.installed
              ? "so PHP-FPM cannot start. A PHP-FPM converge removes the pool."
              : "so converge does not publish it."
          }`,
          "present",
          "missing",
        );
      }
    } catch {
      issues.push(failed("role", phpRole));
    }
  }
  issues.sort((a, b) => Number(a.resource_id) - Number(b.resource_id));
  return report(
    "role",
    roles.length,
    issues,
    observed,
    active.length ? { [nodeCheck(ctx.node)]: ["role.node_unreachable"] } : {},
  );
}

async function privateDnsMatches(ctx: ProbeContext, expected: Row) {
  // find prints an empty line set when the managed peer state is absent. It never reads that state.
  const peer = await ctx.local([
    "sudo",
    "-n",
    "find",
    "/etc/wireguard",
    "-maxdepth",
    "1",
    "-name",
    "orbit.dns-link",
    "-printf",
    "%f\n",
  ]);
  if (peer.trim() === "orbit.dns-link") return true;
  const dropIn = await ctx.local([
    "sudo",
    "-n",
    "cat",
    "--",
    "/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf",
  ]);
  if (dropIn !== expected.drop_in) return false;
  const value = (text: string) => text.trim().replace(/^[^:]+:\s*/, "");
  return value(await ctx.local(["resolvectl", "domain", "orbit"])) ===
      `~${expected.domain}` &&
    value(await ctx.local(["resolvectl", "default-route", "orbit"])) === "no" &&
    value(await ctx.local(["resolvectl", "dns", "orbit"])) === expected.address;
}

function checkConnections(ctx: ProbeContext): FamilyReport {
  const connections = rows(ctx, "database_connections");
  const issues: Issue[] = [];
  const observed: (number | string)[] = [];
  for (const row of connections) {
    const add = (
      code: string,
      summary: string,
      expected: string,
      actual: string,
    ) =>
      issues.push(
        issue(
          "database_connection",
          `database_connection.${code}`,
          row,
          summary,
          expected,
          actual,
        ),
      );
    if (row.missing) {
      add(
        "missing",
        "The attachment names a registry connection that is not present.",
        "present",
        "absent",
      );
    } else if (row.intent_failed) {
      issues.push(failed("database_connection", row));
    } else {
      const text = (value: unknown) =>
        typeof value === "string" && value !== "";
      const healthy = row.node_exists &&
        (row.driver === "sqlite"
          ? text(row.path)
          : text(row.host) && row.port !== null &&
            (!["mysql", "pgsql"].includes(String(row.driver)) ||
              text(row.database) && text(row.username) &&
                row.password_present));
      if (!healthy) {
        add(
          "unhealthy",
          "The registry connection is missing required fields or its associated Node.",
          "usable",
          "unhealthy",
        );
      } else if (
        row.env_expected_keys &&
        (strings(row.env_expected_keys).some((key) =>
          !strings(row.env_equal_keys).includes(key)
        ) ||
          strings(row.env_forget_keys).some((key) =>
            strings(row.env_present_keys).includes(key)
          ))
      ) {
        add(
          "env_mismatch",
          "Attached stored environment keys do not match the registry projection.",
          "matching",
          "mismatch",
        );
      } else {
        observed.push(row.id as string);
      }
    }
  }
  return report("database_connection", connections.length, issues, observed);
}

const sqlLiteral = (value: string) => "'" + value.replaceAll("'", "''") + "'";
async function checkServers(
  ctx: ProbeContext,
  readSql: SqlReader,
): Promise<FamilyReport> {
  const servers = rows(ctx, "database_servers");
  const issues: Issue[] = [];
  const observed: (number | string)[] = [];
  for (const server of servers) {
    const before = issues.length;
    const add = (
      code: string,
      summary: string,
      expected: string,
      actual: string,
    ) =>
      issues.push(
        issue(
          "database_server",
          `database_server.${code}`,
          server,
          summary,
          expected,
          actual,
        ),
      );
    try {
      if (
        server.private_endpoints_managed &&
        JSON.stringify(server.published_ports) !==
          JSON.stringify(server.expected_ports)
      ) {
        add(
          "publication_mismatch",
          "The Process publications differ from the managed private endpoints.",
          "private endpoints",
          "different",
        );
      }
      const secrets = object(
        object(ctx.privateMaterial.database_servers)[String(server.id)],
      );
      if (typeof secrets.password !== "string") {
        throw new Error("Private material unavailable");
      }
      if (server.driver === "redis") {
        await inspectValkey(ctx, server, secrets, add);
      } else {
        const pg = server.driver === "pgsql";
        const run = async (sql: string, ready = false): Promise<string[]> => {
          const output = await readSql(
            server,
            String(secrets.password),
            sql,
            ready,
          );
          return output.split(/\r?\n/).filter((v) => v !== "").map((v) =>
            v.split("\t", 1)[0]
          );
        };
        if (server.private_endpoints_managed) {
          try {
            await run("SELECT 1;\n", true);
          } catch {
            add(
              "login_failed",
              "The SQL server did not accept its managed admin login.",
              "authenticated",
              "unavailable",
            );
          }
          if (server.initialization_secret_present) {
            add(
              "initialization_secret_present",
              "The Process still carries its first-start password.",
              "removed",
              "present",
            );
          }
        }
        if (pg) {
          for (const connection of list(server.connections)) {
            const names = strings(connection.test_databases);
            if (names.length) {
              const present = await run(
                `SELECT datname FROM pg_database WHERE datname IN (${
                  names.map(sqlLiteral).join(", ")
                }) ORDER BY datname;`,
              );
              for (
                const name of names.filter((name) => !present.includes(name))
              ) {
                add(
                  "test_database_missing",
                  `Connection [${connection.name}] is missing recorded test database [${name}]. Retry its original database:create request to restore missing members.`,
                  "present",
                  name,
                );
              }
            }
            for (const user of list(connection.users)) {
              const attributes = await run(
                `SELECT rolreplication::text FROM pg_roles WHERE rolname = ${
                  sqlLiteral(String(user.username))
                };`,
              );
              const expected = user.replication ? "true" : "false";
              if (attributes.length !== 1 || attributes[0] !== expected) {
                const actual = attributes.length === 0
                  ? "missing"
                  : attributes.length === 1 &&
                      ["true", "false"].includes(attributes[0])
                  ? attributes[0]
                  : "unverifiable";
                add(
                  "replication_mismatch",
                  `User [${user.username}] has a different PostgreSQL replication attribute or is missing.`,
                  expected,
                  actual,
                );
              }
            }
          }
        }
      }
      if (issues.length === before) observed.push(server.id as number);
    } catch {
      issues.push(failed("database_server", server));
    }
  }
  return report("database_server", servers.length, issues, observed);
}

async function inspectValkey(
  _ctx: ProbeContext,
  server: Row,
  secrets: Row,
  add: (
    code: string,
    summary: string,
    expected: string,
    observed: string,
  ) => void,
) {
  const anonymous = await ValkeyClient.connect(
    String(server.host),
    Number(server.port),
  );
  try {
    try {
      await anonymous.command("PING");
      add(
        "open",
        "A client without a password can run a command.",
        "authentication required",
        "open",
      );
    } catch {
      // Authentication is checked independently below; a refused anonymous read is never proof of health.
    }
  } finally {
    anonymous.close();
  }
  const client = await ValkeyClient.connect(
    String(server.host),
    Number(server.port),
  );
  try {
    if (!server.default_open) {
      await client.command("AUTH", "default", String(secrets.password));
    }
    const file = await client.command("CONFIG", "GET", "aclfile");
    if (
      !Array.isArray(file) || file.length !== 2 || file[0] !== "aclfile" ||
      typeof file[1] !== "string"
    ) throw new Error("Invalid ACL file observation");
    if (file[1] !== "/data/users.acl") {
      add(
        "acl_file_missing",
        "Valkey does not run with Orbit's ACL file.",
        "managed ACL file",
        "missing or different",
      );
    }
    const live = await liveAcl(client);
    if (server.default_open) live.delete("default");
    const expected = new Map(
      list(secrets.acl_users).map((user) => {
        const parsed = AclUser.parse(user);
        return [parsed.name, parsed] as const;
      }),
    );
    if (
      live.size !== expected.size ||
      [...expected].some(([name, user]) =>
        !live.has(name) || aclState(live.get(name)!) !== aclState(user)
      )
    ) {
      add(
        "acl_mismatch",
        "The live ACL users differ from the recorded users.",
        "recorded users",
        "different",
      );
    }
  } finally {
    client.close();
  }
}

export type SqlReader = (
  server: Row,
  password: string,
  sql: string,
  tcp: boolean,
) => Promise<string>;

/** Credentials stay in the native client's environment; SQL travels only on stdin. */
export async function readDatabaseServer(
  server: Row,
  password: string,
  sql: string,
  _tcp: boolean,
): Promise<string> {
  const pg = server.driver === "pgsql";
  const args = pg
    ? [
      "--no-psqlrc",
      `--host=${server.host}`,
      `--port=${server.port}`,
      "--username=postgres",
      "--dbname=postgres",
      "--no-align",
      "--tuples-only",
      "--quiet",
      "--set=ON_ERROR_STOP=1",
    ]
    : [
      "--protocol=TCP",
      `--host=${server.host}`,
      `--port=${server.port}`,
      "--user=root",
      "--batch",
      "--skip-column-names",
    ];
  const child = localCommand(pg ? "psql" : "mysql", {
    args,
    env: { [pg ? "PGPASSWORD" : "MYSQL_PWD"]: password },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(30000),
  }).spawn();
  try {
    const output = (async () => {
      const chunks: Uint8Array[] = [];
      let length = 0;
      for await (const chunk of child.stdout) {
        length += chunk.length;
        if (length > 1048576) {
          throw new Error("SQL observation exceeded its bound");
        }
        chunks.push(chunk);
      }
      const all = new Uint8Array(length);
      let offset = 0;
      for (const chunk of chunks) {
        all.set(chunk, offset);
        offset += chunk.length;
      }
      return new TextDecoder("utf-8", { fatal: true }).decode(all);
    })();
    const send = (async () => {
      const writer = child.stdin.getWriter();
      try {
        await writer.write(new TextEncoder().encode(sql));
      } finally {
        await writer.close();
      }
    })();
    const [text, , status] = await Promise.all([output, send, child.status]);
    if (!status.success) throw new Error("SQL observation failed");
    return text;
  } catch {
    try {
      child.kill("SIGKILL");
    } catch { /* The process can already have ended. */ }
    await child.status;
    throw new Error("SQL observation failed");
  }
}

async function ciHostMatches(ctx: ProbeContext): Promise<boolean> {
  const query = async (path: string) =>
    JSON.parse(await ctx.local(["sudo", "-n", "incus", "query", path]));
  const names = [["projects", "orbit-ci"], ["networks", "orbitci0"], [
    "network-acls",
    "orbit-ci-egress",
  ], ["storage-pools", "default"]];
  for (const [kind, name] of names) {
    const scoped = ["networks", "network-acls"].includes(kind)
      ? "?project=default"
      : "";
    const paths = strings(await query(`/1.0/${kind}${scoped}`));
    if (
      !paths.some((path) => path.split("?", 1)[0] === `/1.0/${kind}/${name}`)
    ) return false;
  }
  const project = object(await query("/1.0/projects/orbit-ci"));
  const network = object(await query("/1.0/networks/orbitci0?project=default"));
  const acl = object(
    await query("/1.0/network-acls/orbit-ci-egress?project=default"),
  );
  const profile = object(await query("/1.0/profiles/default?project=orbit-ci"));
  const includes = (actual: Row, expected: Row) =>
    Object.entries(expected).every(([key, value]) => actual[key] === value);
  const equal = (left: unknown, right: unknown) => {
    const canonical = (value: unknown): unknown =>
      Array.isArray(value)
        ? value.map(canonical)
        : value && typeof value === "object"
        ? Object.fromEntries(
          Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map((
            [k, v],
          ) => [k, canonical(v)]),
        )
        : value;
    return JSON.stringify(canonical(left)) === JSON.stringify(canonical(right));
  };
  const projectConfig = {
    "features.images": "true",
    "features.profiles": "true",
    "features.storage.volumes": "true",
    "features.storage.buckets": "true",
    "features.networks": "false",
    restricted: "true",
    "restricted.networks.access": "orbitci0",
    "restricted.containers.nesting": "block",
    "restricted.containers.privilege": "unprivileged",
    "restricted.virtual-machines.lowlevel": "block",
    "restricted.containers.lowlevel": "allow",
    "limits.virtual-machines": "0",
  };
  const networkConfig = {
    "ipv4.nat": "true",
    "ipv6.address": "none",
    "security.acls": "orbit-ci-egress",
    "security.acls.default.egress.action": "reject",
    "security.acls.default.ingress.action": "reject",
  };
  const rules = list(acl.egress).map((rule) =>
    Object.fromEntries(Object.entries(rule).filter(([, value]) => value !== ""))
  );
  const expectedRules = [
    {
      action: "reject",
      destination:
        "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3",
      state: "enabled",
    },
    { action: "reject", destination: "::/0", state: "enabled" },
    { action: "allow", destination: "0.0.0.0/0", state: "enabled" },
  ];
  return includes(object(project.config), projectConfig) &&
    network.type === "bridge" && network.managed === true &&
    typeof object(network.config)["ipv4.address"] === "string" &&
    !["", "none", "auto"].includes(
      String(object(network.config)["ipv4.address"]),
    ) &&
    includes(object(network.config), networkConfig) &&
    equal(rules, expectedRules) && equal(acl.ingress, []) &&
    equal(profile.config, {
      "boot.autostart": "true",
      "limits.cpu": "6",
      "limits.memory": "12GiB",
      "limits.processes": "8192",
      "security.nesting": "false",
      "security.privileged": "false",
    }) &&
    equal(profile.devices, {
      eth0: { name: "eth0", network: "orbitci0", type: "nic" },
      root: { path: "/", pool: "default", type: "disk" },
    });
}
