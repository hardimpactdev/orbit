import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { type SystemCommand, type SystemTransport } from "./system_command.ts";
import { type RoleSnapshot } from "./role_direct_contract.ts";
import { roleBaseline } from "./role_direct_local.ts";

const snapshot = (role: RoleSnapshot["role"] = "ci-runner") => ({
  schema_version: 1,
  node_id: 13,
  agent_sha256: "a".repeat(64),
  assignment_id: 33,
  publication: { caddy_node_ids: [], php_node_ids: [], dns_node_ids: [] },
  role,
  created: false,
  user: "nckrtl",
  cli: {
    user: "nckrtl",
    release: null,
    profile: "",
    skip_reason: "release_unavailable",
    plan_error: null,
  },
  prerequisites: {
    packages: ["docker.io"],
    releases: ["resolute"],
    apps_directory: false,
    composer_home: false,
  },
  firewall: role === "database"
    ? null
    : { desired: [], remove: [], enable: false },
  footprint: {
    schema_version: 1,
    node_id: 13,
    agent_sha256: "a".repeat(64),
    force_artifacts: false,
    first_visit: false,
    artifacts: [],
    payloads: {},
    network: null,
    refusal: null,
  },
});
function fake(calls: SystemCommand[]): SystemTransport {
  return (command) => {
    calls.push(command);
    return Promise.resolve({
      success: command.command !== "/usr/bin/dpkg-query",
      stdout: command.stdin?.includes("account = pwd.getpwnam")
        ? JSON.stringify({
          user: "nckrtl",
          group: "operators",
          home: "/srv/users/nckrtl",
        })
        : command.stdin
        ? "{}"
        : "",
    });
  };
}
Deno.test("role prerequisites use the actual account and canonical system program", async () => {
  const calls: SystemCommand[] = [];
  const inputs: Record<string, unknown>[] = [];
  const run = roleBaseline(fake(calls), {
    program: (_program, input) => {
      inputs.push(input);
      return Promise.resolve({});
    },
  });
  assertEquals(await run(snapshot(), "prerequisites"), { ok: true });
  assertEquals(calls.length, 1);
  assertEquals(inputs, [{
    name: "role.install-prerequisites",
    input: {
      ...snapshot().prerequisites,
      user: "nckrtl",
      group: "operators",
      home: "/srv/users/nckrtl",
    },
  }]);
});
Deno.test("storage uses the discovered account home and exact configured custom root", async () => {
  const calls: SystemCommand[] = [];
  const run = roleBaseline(fake(calls));
  assertEquals(await run(snapshot("app-dev"), "storage"), { ok: true });
  assertEquals(calls[1].command, "/usr/bin/sudo");
  assertEquals(calls[1].stdin?.includes("/srv/users/nckrtl/apps"), true);
  calls.length = 0;
  assertEquals(
    await run(
      { ...snapshot("app-dev"), storage_root: "/srv/orbit/apps" },
      "storage",
    ),
    { ok: true },
  );
  assertEquals(calls[1].stdin?.includes("/srv/orbit/apps"), true);
});
Deno.test("foreign account discovery refuses before any privileged action", async () => {
  const calls: SystemCommand[] = [];
  const run = roleBaseline((command) => {
    calls.push(command);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        user: "foreign",
        group: "foreign",
        home: "/home/foreign",
      }),
    });
  });
  await assertRejects(() => run(snapshot(), "prerequisites"));
  assertEquals(calls.length, 1);
  assertEquals(calls[0].command, "/usr/bin/python3");
});
Deno.test("absent accounts and prerequisite failures return only safe domain codes", async () => {
  assertEquals(
    await roleBaseline(() =>
      Promise.resolve({ success: true, stdout: "null" })
    )(snapshot(), "prerequisites"),
    { ok: false, error_code: "role.prerequisite_failed" },
  );
  const calls: SystemCommand[] = [];
  const transport = fake(calls);
  const run = roleBaseline(transport, {
    program: () => Promise.resolve({ error_code: "role.prerequisite_failed" }),
  });
  assertEquals(await run(snapshot(), "prerequisites"), {
    ok: false,
    error_code: "role.prerequisite_failed",
  });
});
Deno.test("CI and firewall stages compose canonical system actions", async () => {
  const effects: string[] = [];
  const run = roleBaseline(undefined, {
    ciHost: () => {
      effects.push("ci");
      return Promise.resolve({ ok: true });
    },
    program: (_program, input) => {
      effects.push(String(input.mode));
      return Promise.resolve({ matches: true });
    },
  });
  assertEquals(await run(snapshot(), "ci-host"), { ok: true });
  assertEquals(await run(snapshot(), "firewall"), { ok: true });
  assertEquals(effects, ["ci", "rules"]);
  const ciCalls: SystemCommand[] = [];
  const failedCi = roleBaseline((command) => {
    ciCalls.push(command);
    return Promise.resolve({ success: false, stdout: "" });
  });
  assertEquals(await failedCi(snapshot(), "ci-host"), {
    ok: false,
    error_code: "ci_runner.host_failed",
  });
  assertEquals(ciCalls[0].command, "/usr/bin/sudo");
  assertEquals(ciCalls[0].args.slice(0, 2), ["-n", "/usr/bin/incus"]);
});
Deno.test("nonapplicable roles perform no storage, hibernation, CI or database firewall effect", async () => {
  let commands = 0;
  const run = roleBaseline(() => {
    commands++;
    return Promise.resolve({ success: true, stdout: "{}" });
  });
  for (
    const stage of ["storage", "hibernation", "ci-host", "firewall"] as const
  ) assertEquals(await run(snapshot("database"), stage), { ok: true });
  assertEquals(commands, 0);
});
Deno.test("cancellation and cross Node intent refuse before a system program starts", async () => {
  let commands = 0;
  const run = roleBaseline(() => {
    commands++;
    return Promise.resolve({ success: true, stdout: "{}" });
  });
  await assertRejects(() =>
    run(snapshot(), "prerequisites", AbortSignal.abort())
  );
  const cross = snapshot();
  cross.footprint.node_id = 14;
  await assertRejects(() => run(cross, "prerequisites"));
  assertEquals(commands, 0);
});
Deno.test("JavaScript and Router LAN use their canonical system transactions", async () => {
  const input = {
    ...snapshot("app-dev"),
    javascript: { version: "1.0.0" },
    router_lan: { desired: [], expand: true, prune: true },
  };
  const commands: SystemCommand[] = [];
  const javascript = roleBaseline((command) => {
    commands.push(command);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({ changed: true, retired: [] }),
    });
  });
  assertEquals(await javascript(input, "javascript"), { ok: true });
  assertEquals(commands[0].command, "/usr/bin/sudo");
  assertEquals(commands[0].args.slice(0, 4), [
    "-n",
    "--",
    "/usr/bin/python3",
    "-c",
  ]);
  assertEquals(JSON.parse(commands[0].stdin!), input.javascript);
  assertEquals(commands[0].args[4].includes("install_javascript"), true);
  const calls: Record<string, unknown>[] = [];
  assertEquals(
    await roleBaseline(undefined, {
      program: (_program, value) => {
        calls.push(value);
        return Promise.resolve({ matches: true });
      },
    })(input, "router-lan"),
    { ok: true },
  );
  assertEquals(calls, [input.router_lan]);
  let material: unknown;
  const run = roleBaseline(undefined, {
    javascript: (value) => {
      material = value;
      return Promise.resolve({ ok: true });
    },
  });
  assertEquals(await run(input, "javascript"), { ok: true });
  assertEquals(material, input.javascript);
});
