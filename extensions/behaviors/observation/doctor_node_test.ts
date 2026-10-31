import {
  assertEquals,
  assertFalse,
  assertStringIncludes,
} from "jsr:@std/assert@1";
import { checkNode, dnsPolicyMatches } from "./doctor_node.ts";
import type { ProbeContext, Row } from "./doctor_contract.ts";
function context(
  facts: Row = {},
  observation: Row = {},
  answers: Record<string, Row> = {},
) {
  const calls: { name: string; input: Row; sudo?: boolean }[] = [];
  const ctx: ProbeContext = {
    node: {
      id: 209,
      name: "dev",
      platform: "linux",
      architecture: "amd64",
      user: "orbit",
      managed: true,
      parked: false,
      roles: [],
      resources: {
        node_checks: [{
          status: "active",
          managed: true,
          agent_eligible: false,
          ...facts,
        }],
      },
      agent_sha256: "a".repeat(64),
    },
    nodes: [],
    observation: {
      platform: "linux",
      architecture: "x86_64",
      wireguard: true,
      disks: [],
      ...observation,
    },
    inspectionFailed: false,
    privateMaterial: {},
    run: (name, input, sudo) => {
      calls.push({ name, input, sudo });
      return answers[name]
        ? Promise.resolve(answers[name])
        : Promise.reject(new Error("private transport diagnostics"));
    },
    local: () => Promise.reject(new Error("No shell expected")),
  };
  return { ctx, calls };
}
Deno.test("Node inactive/unmanaged retains lifecycle, residue and stray facts without execution", async () => {
  const { ctx, calls } = context({
    status: "failed",
    managed: false,
    stray_node: true,
    route_residues: [{
      route_id: 171,
      domain: "dev.test",
      steps: ["caddy", "php"],
    }],
  });
  const result = await checkNode(ctx);
  assertEquals(result.checked, 1);
  assertEquals(result.issues.map((i) => i.code), [
    "node.lifecycle_not_active",
    "node.route_residue_retained",
    "node.task_vm_stray_node",
  ]);
  assertEquals(result.issues[1].observed, "caddy,php");
  assertEquals(calls, []);
});
Deno.test("Node failed inspection precedes unreachable and cannot clear observed resources", async () => {
  const { ctx } = context({}, { reachable: false });
  ctx.inspectionFailed = true;
  assertEquals((await checkNode(ctx)).issues.map((i) => i.code), [
    "node.inspection_failed",
  ]);
  ctx.inspectionFailed = false;
  const unreachable = await checkNode(ctx);
  assertEquals(unreachable.issues.map((i) => i.code), ["node.ssh_unreachable"]);
  assertEquals(unreachable.observed_resources, []);
});
Deno.test("Node platform/architecture aliases compare and unsupported identity is redacted", async () => {
  const { ctx } = context({}, {
    platform: "darwin",
    architecture: "aarch64",
    wireguard: false,
  });
  assertEquals((await checkNode(ctx)).issues.map((i) => i.code), [
    "node.platform_mismatch",
    "node.architecture_mismatch",
    "node.wireguard_ip_mismatch",
  ]);
  ctx.node.architecture = "private-unsupported-value";
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), [
    "node.inspection_failed",
    "node.platform_mismatch",
    "node.wireguard_ip_mismatch",
  ]);
  assertFalse(JSON.stringify(result).includes("private-unsupported-value"));
});
Deno.test("Node disk bounds preserve ten percent and two GiB thresholds and hide device details", async () => {
  for (
    const [available, freeInodes, low] of [
      [16 * 1024, 90000, true],
      [16 * 1024 * 1024, 90000, false],
      [16 * 1024 * 1024, 9999, true],
      [2 * 1024 * 1024, 90000, true],
      [Math.floor(25 * 1024 * 1024 / 10), 10000, false],
      [3 * 1024 * 1024, 10000, false],
    ] as const
  ) {
    const { ctx } = context({}, {
      disks: [{
        location: "home",
        available_kib: available,
        size_kib: 25 * 1024 * 1024,
        free_inodes: freeInodes,
        total_inodes: 100000,
        device: "/dev/private",
      }],
    });
    const result = await checkNode(ctx);
    assertEquals(result.issues.some((i) => i.code === "node.disk_low"), low);
    assertFalse(JSON.stringify(result).includes("/dev/private"));
  }
});
Deno.test("Mac Node gets identity/tunnel/disk checks without Linux agent drift", async () => {
  const { ctx } = context({}, {
    platform: "darwin",
    architecture: "aarch64",
    agent_binary: false,
    agent_unit: false,
    disks: [{
      location: "home",
      size_kib: 10000000,
      available_kib: 16000,
      free_inodes: null,
      total_inodes: null,
    }],
  });
  ctx.node.platform = "macos";
  ctx.node.architecture = "arm64";
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), ["node.disk_low"]);
  assertStringIncludes(String(result.issues[0].observed), "inodes unavailable");
});
Deno.test("Node agent checksum, secret, unit, CLI, rollout and stale view have bounded findings", async () => {
  const { ctx } = context({
    agent_eligible: true,
    secret_checksum: "c".repeat(64),
    cli_foreign: true,
    release_lag: { expected: "abcd", observed: "failed" },
    view_problem: "stale",
  }, {
    agent_binary: true,
    agent_checksum: "b".repeat(64),
    secret_checksum: "d".repeat(64),
    agent_unit: true,
    agent_active: true,
    worker_state: "failed",
  });
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), [
    "node.worker_failed",
    "node.agent_binary_mismatch",
    "node.agent_secret_mismatch",
    "node.cli_foreign",
    "node.release_lag",
    "node.agent_view_stale",
  ]);
  assertFalse(JSON.stringify(result).includes("d".repeat(64)));
});
const dns = {
  server: "10.44.0.2",
  domains: ["orbit", "test"],
  public_domain: "example.com",
  gateway_address: "10.44.0.1",
};
const dnsObservation = {
  state: "orbit\n10.44.0.2\norbit\ntest",
  hooks:
    "PostUp = resolvectl dns %i 10.44.0.2; resolvectl domain %i \\~orbit \\~test",
  dns: "Link 4 (orbit): 10.44.0.2",
  domains: "Link 4 (orbit): ~test ~orbit",
  default_route: "Link 4 (orbit): no",
  uplink: true,
};
Deno.test("DNS policy matches saved hooks and live resolver set exactly", () => {
  assertEquals(dnsPolicyMatches(dns.server, dns.domains, dnsObservation), true);
  assertEquals(
    dnsPolicyMatches(dns.server, dns.domains, {
      ...dnsObservation,
      hooks: "different",
    }),
    false,
  );
  assertEquals(
    dnsPolicyMatches(dns.server, dns.domains, {
      ...dnsObservation,
      domains: "Link 4 (orbit): ~orbit ~test ~test",
    }),
    false,
  );
});
Deno.test("Node DNS uses uncached primitive and checks exact Gateway IP and public uplink", async () => {
  const { ctx, calls } = context({ dns }, {}, {
    "wireguard.inspect-peer-dns": dnsObservation,
    "wireguard.query-dns": {
      answers: [
        "gateway.orbit: 10.44.0.1 -- link: orbit",
        "example.com: 192.0.2.1",
      ],
    },
  });
  assertEquals((await checkNode(ctx)).issues, []);
  assertEquals(calls.map((c) => [c.name, c.input]), [[
    "wireguard.inspect-peer-dns",
    { server: dns.server },
  ], ["wireguard.query-dns", { names: ["gateway.orbit", "example.com"] }]]);
  ctx.run = (name) =>
    Promise.resolve(
      name === "wireguard.inspect-peer-dns"
        ? { ...dnsObservation, uplink: false, state: "bad" }
        : {
          answers: [
            "gateway.orbit: 10.44.0.10 -- link: orbit",
            "example.com: 192.0.2.1 -- link: orbit",
          ],
        },
    );
  assertEquals((await checkNode(ctx)).issues.map((i) => i.code), [
    "node.private_dns_uplink_missing",
    "node.private_dns_policy_mismatch",
    "node.private_dns_unreachable",
    "node.public_dns_unreachable",
  ]);
});
Deno.test("Node invalid DNS policy/transport failure is unverifiable and does not disclose output", async () => {
  const { ctx, calls } = context({
    dns: { ...dns, public_domain: "inside.orbit" },
  });
  assertEquals((await checkNode(ctx)).issues.map((i) => i.code), [
    "node.inspection_failed",
  ]);
  assertEquals(calls, []);
  ctx.node.resources.node_checks[0].dns = dns;
  const result = await checkNode(ctx);
  assertEquals(result.status, "unverifiable");
  assertFalse(JSON.stringify(result).includes("private transport"));
});
const host = {
  project: "orbit-tasks",
  pool: "tasks",
  operator_owned: ["personal/*"],
  ci_runner: true,
  live: [{ name: "tvm-1", state: "ready" }, { name: "tvm-2", state: "parked" }],
  owned_build_ids: [7],
  stray_records: [{ name: "tvm-1", state: "ready" }],
};
const fingerprint = "a".repeat(64);
Deno.test("Task VM audit distinguishes missing rows, orphan builders, operator/CI ownership and topology seeds", async () => {
  const { ctx } = context({ task_host: host }, {}, {
    "incus.instances": {
      names: ["tvm-1", "tvm-3", "tvm-image-7-attempt-1-smoke", "tvm-image-8"],
    },
    "incus.images": { images: { [fingerprint]: ["orbit-task-base"] } },
    "incus.inventory": {
      instances: [
        { project: "orbit-tasks", name: "tvm-3" },
        { project: "orbit-tasks", name: "tvm-image-7" },
        { project: "personal", name: "operator" },
        { project: "orbit-ci", name: "ci-beast-1" },
        { project: "default", name: "unknown" },
      ],
      volumes: [{
        project: "orbit-tasks",
        pool: "tasks",
        name: "tvm-1-topology-seed",
      }, {
        project: "orbit-tasks",
        pool: "tasks",
        name: "orbit-topology-seed-aaaaaaaaaaaa-new",
      }, { project: "default", pool: "tasks", name: "unexpected" }],
    },
  });
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), [
    "node.task_vm_orphaned",
    "node.task_vm_orphaned",
    "node.task_vm_missing",
    "node.task_vm_stray_instance",
    "node.task_vm_stray_volume",
    "node.task_vm_stray_record",
  ]);
  assertEquals(result.issues.map((i) => i.observed), [
    "tvm-3",
    "tvm-image-8",
    "absent",
    "default/unknown",
    "default/tasks/unexpected",
    "tvm-1 ready",
  ]);
});
Deno.test("Task VM audit keeps earlier findings when later inventory fails", async () => {
  const { ctx } = context({ task_host: host }, {}, {
    "incus.instances": { names: [] },
  });
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), [
    "node.task_vm_missing",
    "node.task_vm_missing",
    "node.task_vm_inspection_failed",
  ]);
  assertFalse(JSON.stringify(result).includes("private transport"));
});
Deno.test("Task VM incomplete inventory never proves emptiness or clears drift", async () => {
  const { ctx } = context({ task_host: host }, {}, {
    "incus.instances": { names: [false] },
  });
  const result = await checkNode(ctx);
  assertEquals(result.issues.map((i) => i.code), [
    "node.task_vm_inspection_failed",
  ]);
});
