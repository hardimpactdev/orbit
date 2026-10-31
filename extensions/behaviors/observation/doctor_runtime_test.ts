import {
  assertEquals,
  assertFalse,
  assertStringIncludes,
} from "jsr:@std/assert@1";
import { checkRuntime } from "./doctor_runtime.ts";
import type { Family, ProbeContext, Row } from "./doctor_contract.ts";

function context(
  resources: Record<string, Row[]> = {},
  answers: Record<string, Row | ((input: Row) => Row)> = {},
) {
  const calls: { name: string; input: Row; sudo?: boolean }[] = [];
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
    observation: {},
    inspectionFailed: false,
    privateMaterial: {},
    run: (name, input, sudo) => {
      calls.push({ name, input, sudo });
      const answer = answers[name];
      if (!answer) {
        return Promise.reject(new Error("private stderr must not escape"));
      }
      try {
        return Promise.resolve(
          typeof answer === "function" ? answer(input) : answer,
        );
      } catch (error) {
        return Promise.reject(error);
      }
    },
    local: () => Promise.reject(new Error("No shell probe expected")),
  };
  return { ctx, calls };
}
const process = (
  id: number,
  runtime = "systemd",
  desired = "running",
): Row => ({
  id,
  name: `p${id}`,
  runtime,
  desired,
  keep_alive: false,
  input: {
    process_id: id,
    runtime,
    name: `orbit-process-${id}-p${id}${
      runtime === "systemd" ? ".service" : ""
    }`,
    desired,
  },
});
const observed = (status: string, restarts = 0): Row => ({
  present: true,
  status,
  systemd_observations: [{
    active_state: status,
    sub_state: status === "inactive" ? "dead" : "running",
    restarts,
  }],
});
const codes = async (ctx: ProbeContext, family: Family) =>
  (await checkRuntime(ctx, [family]))[0].issues.map((i) => i.code);
const account = {
  user: "orbit",
  group: "orbit",
  home: "/home/orbit",
  shell: "/bin/bash",
};
const schedule = (id = "123e4567-e89b-42d3-a456-426614174000"): Row => ({
  id,
  name: "timer",
  placement_node_id: 209,
  user: "orbit",
  production_home: null,
  working_directory: null,
  input: {
    id,
    command: "private command",
    calendar: "daily",
    timeout_seconds: 30,
    desired: "disabled",
  },
});
const scheduleHealthy = {
  artifacts_present: true,
  permissions_match: true,
  specification_match: true,
  timer_state_match: true,
  calendar_match: true,
  execution_context_match: true,
  completion_callback_match: true,
};
const tool = (
  id: number,
  manager: string,
  name: string,
  constraint: string | null = null,
): Row => ({
  id,
  manager,
  manager_node_id: 209,
  package: name,
  package_valid: true,
  version_constraint: constraint,
});

Deno.test("empty and unreachable runtime families never execute probes", async () => {
  const empty = context();
  for (
    const family of ["process", "schedule", "firewall", "tool"] as Family[]
  ) assertEquals(await codes(empty.ctx, family), []);
  assertEquals(empty.calls, []);
  const blocked = context({
    processes: [process(1)],
    schedules: [schedule()],
    tools: [tool(2, "vp", "x")],
    firewall_rules: [{ id: 3, status: "active" }],
  });
  blocked.ctx.inspectionFailed = true;
  for (
    const family of ["process", "schedule", "firewall", "tool"] as Family[]
  ) {
    assertEquals(await codes(blocked.ctx, family), [
      `${family}.node_unreachable`,
    ]);
  }
  assertEquals(blocked.calls, []);
});

Deno.test("intentional systemd and Docker stops stay healthy", async () => {
  const { ctx, calls } = context({
    processes: [
      process(1, "systemd", "stopped"),
      process(2, "docker", "stopped"),
    ],
  }, {
    "doctor.inspect-process": (input) =>
      input.runtime === "systemd"
        ? observed("inactive")
        : { present: true, status: "created", systemd_observations: [] },
  });
  const result = (await checkRuntime(ctx, ["process"]))[0];
  assertEquals(result.issues, []);
  assertEquals(result.observed_resources, [1, 2]);
  assertEquals(calls.map((c) => c.sudo), [true, true]);
});

Deno.test("hibernation only excuses stopped eligible running processes and reads each marker once", async () => {
  const path = "/dev/shm/orbit/hibernation/app-instance-7.awake";
  const { ctx, calls } = context({
    processes: [1, 2, 3].map((id) => ({
      ...process(id),
      hibernation_path: path,
      keep_alive: id === 3,
    })),
  }, {
    "doctor.inspect-process": observed("inactive"),
    "hibernation.times": { times: { [path]: null } },
  });
  assertEquals(await codes(ctx, "process"), ["process.state_mismatch"]);
  assertEquals(calls.filter((c) => c.name === "hibernation.times").length, 1);
});

Deno.test("process restart increments and auto restart are crash loops with bounded evidence", async () => {
  const { ctx } = context({ processes: [process(1), process(2)] }, {
    "doctor.inspect-process": (input) => ({
      present: true,
      status: "active",
      systemd_observations: input.process_id === 1
        ? [{ active_state: "active", sub_state: "running", restarts: 2 }, {
          active_state: "active",
          sub_state: "running",
          restarts: 3,
        }]
        : [{
          active_state: "activating",
          sub_state: "auto-restart",
          restarts: null,
        }],
    }),
  });
  const result = (await checkRuntime(ctx, ["process"]))[0];
  assertEquals(result.issues.map((i) => i.code), [
    "process.crash_loop",
    "process.crash_loop",
  ]);
  assertStringIncludes(
    String(result.issues[0].observed),
    "NRestarts=2 -> active/running; NRestarts=3",
  );
});

Deno.test("missing and malformed process states stay distinct without diagnostics", async () => {
  const { ctx } = context({ processes: [process(1), process(2), process(3)] }, {
    "doctor.inspect-process": (input) =>
      input.process_id === 1
        ? { present: false, status: null, systemd_observations: [] }
        : input.process_id === 2
        ? { present: true, status: "active", systemd_observations: [] }
        : (() => {
          throw new Error("private diagnostics");
        })(),
  });
  assertEquals(await codes(ctx, "process"), [
    "process.runtime_missing",
    "process.inspection_failed",
    "process.inspection_failed",
  ]);
  assertFalse(
    JSON.stringify(await checkRuntime(ctx, ["process"])).includes(
      "private diagnostics",
    ),
  );
});

Deno.test("disabled Schedule passes exact admitted context after local account lookup", async () => {
  const { ctx, calls } = context({ schedules: [schedule()] }, {
    "account.lookup": account,
    "doctor.inspect-schedule": scheduleHealthy,
  });
  assertEquals(await codes(ctx, "schedule"), []);
  assertEquals(calls.map((c) => c.name), [
    "account.lookup",
    "doctor.inspect-schedule",
  ]);
  assertEquals(calls[1].input, {
    ...schedule().input as Row,
    ...account,
    working_directory: "/home/orbit",
    login_shell: true,
  });
});

Deno.test("Schedule missing artifact suppresses derivative findings and bad placement executes nothing", async () => {
  const { ctx, calls } = context({
    schedules: [schedule(), { ...schedule("another"), placement_node_id: 123 }],
  }, {
    "account.lookup": account,
    "doctor.inspect-schedule": {
      ...scheduleHealthy,
      artifacts_present: false,
      permissions_match: false,
    },
  });
  assertEquals(await codes(ctx, "schedule"), [
    "schedule.artifact_missing",
    "schedule.placement_mismatch",
  ]);
  assertEquals(calls.length, 2);
});

Deno.test("Schedule observations report every bounded mismatch and hide command and output", async () => {
  const { ctx } = context({ schedules: [schedule()] }, {
    "account.lookup": account,
    "doctor.inspect-schedule": {
      ...scheduleHealthy,
      permissions_match: false,
      timer_state_match: false,
      completion_callback_match: false,
      stderr: "secret observation",
    },
  });
  assertEquals(await codes(ctx, "schedule"), [
    "schedule.artifact_permissions_mismatch",
    "schedule.timer_state_mismatch",
    "schedule.completion_callback_mismatch",
  ]);
  const text = JSON.stringify(await checkRuntime(ctx, ["schedule"]));
  assertFalse(text.includes("private command"));
  assertFalse(text.includes("secret observation"));
});

Deno.test("Schedule orphan inventory passes all known IDs and records a successful empty scan", async () => {
  const known = [String(schedule().id)],
    orphan = "123e4567-e89b-42d3-a456-426614174001";
  const { ctx, calls } = context({
    schedule_inventory: [{ scan: true, known_ids: known }],
  }, { "doctor.schedule-orphans": { ids: [orphan, orphan] } });
  const result = (await checkRuntime(ctx, ["schedule"]))[0];
  assertEquals(calls[0].input, { known_ids: known });
  assertEquals(result.issues.map((i) => i.resource_id), [orphan]);
  assertEquals(result.observed_inventories, {
    "schedule.orphan_artifact": [orphan],
  });
  ctx.run = () => Promise.resolve({ ids: [] });
  assertEquals(
    (await checkRuntime(ctx, ["schedule"]))[0].observed_inventories,
    { "schedule.orphan_artifact": [] },
  );
});

Deno.test("malformed orphan inventory cannot clear previous orphan findings", async () => {
  const { ctx } = context({
    schedule_inventory: [{ scan: true, known_ids: [] }],
  }, { "doctor.schedule-orphans": { ids: [false] } });
  const result = (await checkRuntime(ctx, ["schedule"]))[0];
  assertEquals(result.observed_inventories, {});
  assertEquals(result.issues[0].kind, "unverifiable");
  assertFalse("node:209:inventory" in result.observed_checks);
});

Deno.test("Firewall skips inactive intent and keeps metrics expectations outside checked count", async () => {
  const { ctx, calls } = context({
    firewall_rules: [{
      id: 1,
      name: "inactive",
      status: "failed",
      shape: { comment: "inactive" },
    }, { id: 2, status: "active", shape: { comment: "active" } }],
    firewall_expectations: [{ id: "metrics", shape: { comment: "metrics" } }],
  }, {
    "firewall.inspect": {
      backend: "active",
      ownerships: ["exact", "drift"],
      live: "not returned",
    },
  });
  const result = (await checkRuntime(ctx, ["firewall"]))[0];
  assertEquals(result.checked, 2);
  assertEquals(result.issues.map((i) => i.code), [
    "firewall.lifecycle_not_active",
    "firewall.rule_mismatch",
  ]);
  assertEquals(calls[0].input.expected, [{ comment: "active" }, {
    comment: "metrics",
  }]);
});

Deno.test("inactive Firewall backend is drift even if ownerships are absent", async () => {
  const { ctx } = context({
    firewall_rules: [{ id: 1, status: "active", shape: {} }],
  }, { "firewall.inspect": { backend: "inactive" } });
  assertEquals(await codes(ctx, "firewall"), ["firewall.backend_inactive"]);
  ctx.run = () => Promise.resolve({ backend: "active", ownerships: [] });
  const result = (await checkRuntime(ctx, ["firewall"]))[0];
  assertEquals(result.issues[0].code, "firewall.inspection_failed");
  assertFalse("node:209:inventory" in result.observed_checks);
});

Deno.test("unreachable metrics-only Firewall remains checked zero with a finding", async () => {
  const { ctx } = context({
    firewall_expectations: [{ id: "metrics", shape: {} }],
  });
  ctx.inspectionFailed = true;
  const result = (await checkRuntime(ctx, ["firewall"]))[0];
  assertEquals(result.checked, 0);
  assertEquals(result.issues[0].code, "firewall.node_unreachable");
});

Deno.test("Tools share one manager inventory and duplicate selected package does not poison other packages", async () => {
  const { ctx, calls } = context({
    tools: [
      tool(1, "composer", "app/one"),
      tool(2, "composer", "app/two", "^2.0"),
      tool(3, "apt", "openssh"),
    ],
  }, {
    "tool.composer-inventory": {
      packages: [{ name: "app/one", version: "1.0.0" }, {
        name: "app/one",
        version: "1.1.0",
      }, { name: "app/two", version: "2.1.0" }],
    },
    "tool.apt-inventory": {
      packages: [{ name: "openssh", version: "not-semver" }],
    },
  });
  assertEquals(await codes(ctx, "tool"), ["tool.inspection_failed"]);
  assertEquals(calls.map((c) => c.name), [
    "tool.composer-inventory",
    "tool.apt-inventory",
  ]);
});

Deno.test("incomplete VP inventory cannot prove absence but still proves present packages", async () => {
  const { ctx } = context({
    tools: [tool(1, "vp", "missing"), tool(2, "vp", "present", "^1.0")],
  }, {
    "tool.vp-inventory": {
      packages: [{ name: "present", version: "1.2.0" }],
      scan_complete: false,
    },
  });
  assertEquals(await codes(ctx, "tool"), ["tool.inspection_failed"]);
  ctx.run = () => Promise.resolve({ packages: [], scan_complete: true });
  assertEquals(await codes(ctx, "tool"), [
    "tool.not_installed",
    "tool.not_installed",
  ]);
});

Deno.test("constrained unparseable Tool versions are unverifiable and mismatches stay bounded", async () => {
  const { ctx } = context({
    tools: [
      tool(1, "apt", "meta"),
      tool(2, "apt", "constrained", "^2.0"),
      tool(3, "apt", "old", "^2.0"),
    ],
  }, {
    "tool.apt-inventory": {
      packages: [{ name: "meta", version: "release-foo" }, {
        name: "constrained",
        version: "release-foo",
      }, { name: "old", version: "1:1.9.0-1ubuntu1" }],
    },
  });
  assertEquals(await codes(ctx, "tool"), [
    "tool.inspection_failed",
    "tool.version_mismatch",
  ]);
});

Deno.test("tool scans keep absent manager informational, incomplete unverifiable and sanitized discovery last", async () => {
  const { ctx } = context({ tool_inventory: [{ scan: true }] }, {
    "tool.homebrew-scan": {
      formulae: {
        state: "complete",
        packages: [{
          name: "zebra",
          installed_version: "2.1",
          dependency: false,
          block: null,
        }],
      },
      casks: { state: "unsupported", packages: [] },
    },
    "tool.vp-inventory": () => {
      throw { errorCode: "tool.vp_scope_absent", message: "private output" };
    },
  });
  const result = (await checkRuntime(ctx, ["tool"]))[0];
  assertEquals(result.status, "healthy");
  assertEquals(result.issues.map((i) => i.code), [
    "tool.inventory_scan",
    "tool.package_unregistered",
  ]);
  assertEquals(
    result.issues[1].observed,
    "version=2.1.0;dependency=no;adoption=supported;block=none",
  );
  assertFalse(JSON.stringify(result).includes("private output"));
  ctx.run = () => Promise.resolve({ scan_complete: false, packages: [] });
  const failed = (await checkRuntime(ctx, ["tool"]))[0];
  assertEquals(failed.status, "unverifiable");
  assertFalse("node:209:inventory" in failed.observed_checks);
});

Deno.test("active vp store missing is drift; uncompleted prerequisite is unverifiable", async () => {
  const input = {
    store: "/home/orbit/.vite-plus",
    node_version: "24.1.0",
    package_manager: "pnpm",
    package_manager_version: "10.1.0",
    package_manager_hash: null,
  };
  const { ctx } = context({
    tool_environments: [{ id: 1, active: true, vp_version: "0.1.0", input }, {
      id: 2,
      active: false,
    }],
  }, {
    "tool.vp-version": { version: "vp 0.2.0" },
    "tool.inspect-vp-scope": { binary: "/home/orbit/.vite-plus/bin/vp" },
    "tool.inspect-vp-store": { installed: [false] },
  });
  assertEquals(await codes(ctx, "tool"), [
    "tool.vp_store_missing",
    "tool.vp_store_inspection_failed",
  ]);
});
