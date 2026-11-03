/** Official schemas and fake host effects shared by metrics workflow ports. */
import { type History, nativeRepo, ROOT } from "./native_harness.ts";
import { readCalls } from "./fake_tool.ts";
import { type Flow, type Task } from "./node_host.ts";
export { nativeServer } from "./native_server.ts";
export { sha256 } from "../../extensions/behaviors/operations/process-commands/native_fixture.ts";
export const ENABLED_CONFIG =
  'scrape_configs:\n  - job_name: node\n    static_configs:\n      - targets: ["192.0.2.9:9100"]\n';
export const DISABLED_CONFIG = "scrape_configs: []\n";
export type Call = {
  workflow: string;
  step: string;
  type: string;
  globals: Record<string, unknown>;
  args: { argv: string[]; timeoutSec: number; sudo: boolean; node_id: number; ssh_name: string };
  stdinSha256: string | null;
};
const HOST_KEYS = ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"];
const ssh = (host: string) => ({
  control_node_id: 209,
  identity_file: "/approved",
  public_key: "public",
  target: { name: "target", host, user: "orbit", port: 22, host_keys: HOST_KEYS },
});
const PLAN = JSON.parse(
  Deno.readTextFileSync(`${ROOT}/../apps/gateway/tests/Fixtures/Metrics/container-plan.json`),
).plan;
const connection = (host: string) => ({
  nodeHost: host,
  nodeUser: "orbit",
  nodePort: 22,
  nodeIdentityFile: "/approved",
  become: true,
});
const publication = (host: string) => ({ control_node_id: 209, host_keys: HOST_KEYS, connection: connection(host) });
function service(host: string, source: string, enabled = true) {
  const remote = connection(host), ensure = enabled ? "present" : "absent";
  return {
    files: [{
      ...remote,
      path: "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
      ensure,
      content: "public unit",
      mode: "0644",
      owner: "root",
      group: "root",
    }],
    downloads: enabled
      ? [{
        ...remote,
        url: "https://example.com/cadvisor",
        path: "/usr/local/bin/orbit-cadvisor",
        checksum: "a".repeat(64),
        mode: "0755",
        owner: "root",
        group: "root",
      }]
      : [],
    units: [{
      ...remote,
      service: "prometheus-node-exporter.service",
      ensure: enabled ? "running" : "stopped",
      enabled,
    }],
    rules: [9100, 9102].map((port) => ({ ...remote, port, source, action: "allow", ensure })),
    checks: enabled ? [`http://${host}:9100/metrics`] : [],
    pools: [],
    caddy: null,
  };
}
export const exporterIntent = () => ({
  exporter_node_id: 9,
  enabled: true,
  ssh_execution: ssh("192.0.2.9"),
  exporter_address: "192.0.2.9",
  scrape_wait: [
    "timeout",
    "60",
    "sh",
    "-c",
    "fixture wait",
    "scrape-wait",
    "http://127.0.0.1:9090/api/v1/targets?state=active",
    '"192.0.2.9:9100"',
  ],
  metrics_address: "192.0.2.3",
  runtime: { assignment_id: 1, ssh_execution: ssh("192.0.2.9"), configuration: ENABLED_CONFIG },
  service: {
    ...service("192.0.2.9", "192.0.2.3"),
    checks: ["http://192.0.2.9:9100/metrics", "http://192.0.2.9:9102/metrics"],
  },
});
export function roleIntent(enabled = true, purge = false) {
  const files = [
    { path: "/etc/orbit/metrics/prometheus.yml", content: "global: {}\nscrape_configs: []\n" },
    { path: "/etc/orbit/metrics/grafana/grafana.ini", content: "[server]\nroot_url = https://metrics.orbit/\n" },
    { path: "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml", content: "apiVersion: 1\n" },
    { path: "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml", content: "apiVersion: 1\n" },
    ...["node-resources", "caddy", "fpm"].map((name) => ({
      path: `/etc/orbit/metrics/grafana/dashboards/orbit-${name}.json`,
      content: "{}\n",
    })),
  ];
  return {
    enabled,
    purge_data: purge,
    assignment_id: 41,
    ssh_execution: ssh("192.0.2.9"),
    directories: [...new Set(files.map((f) => f.path.slice(0, f.path.lastIndexOf("/"))))]
      .sort((a, b) => a.split("/").length - b.split("/").length),
    files,
    // The Gateway renders the container plan; tests/Fixtures/Metrics/container-plan.json pins its rendering.
    plan: PLAN,
    fleet: [{
      exporter_node_id: 10,
      enabled,
      metrics_address: "192.0.2.9",
      ssh_execution: ssh("192.0.2.10"),
      runtime: { ssh_execution: ssh("192.0.2.9"), configuration: files[0].content },
      service: service("192.0.2.10", "192.0.2.9", enabled),
    }],
    caddy: {
      node_id: 2,
      user: "orbit",
      ...publication("192.0.2.2"),
      caddy: { content: "public config", version: "c".repeat(64), listen_addresses: ["192.0.2.2"], unserved: false },
    },
    dns: [{ node_id: 2, ...publication("192.0.2.2"), before: [], after: [], alternatives: [] }],
    certificates: enabled
      ? {
        leaves: [{
          node_id: 2,
          scope: "role-metrics",
          domain: "metrics.orbit",
          version: "0123456789abcdef",
          root_hash: "b".repeat(64),
        }],
        withdrawals: [],
      }
      : { leaves: [], withdrawals: [{ node_id: 2, scope: "role-metrics" }] },
    gateway_ssh: ssh("192.0.2.2"),
    firewall: { port: 3000, source: "192.0.2.2", action: "allow", ensure: enabled ? "present" : "absent" },
    purge_volumes: purge ? ["orbit-metrics-prometheus-data", "orbit-metrics-grafana-data"] : [],
  };
}
const EXPORTER = [
  "operation-metrics-exporter-enable",
  "operation-metrics-exporter-disable",
  "metrics-exporter-node",
  "metrics-exporter-allows",
  "metrics-prometheus-publish",
];
const ROLE = [
  "operation-metrics-enable",
  "operation-metrics-disable",
  "metrics-docker",
  "metrics-configuration",
  "metrics-containers",
  "metrics-containers-remove",
  "metrics-publication",
  "metrics-withdrawal",
  "metrics-exporter-node",
  "metrics-exporter-allows",
];
const STUBS = ["node-role-certificate-publish", "operation-route-caddy", "route-dns-publish", "operation-php-monitor"];
export async function metricsFixture(role = false) {
  const repo = await nativeRepo();
  repo.timeoutMs = 300_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  const statePath = `${repo.dir}/host.json`, callsPath = `${repo.dir}/calls.jsonl`;
  const initial = { fail: "", calls: [] as Call[], candidate: null as string | null, published: "old", loaded: "old" };
  const save = (value: unknown) => Deno.writeTextFileSync(statePath, JSON.stringify(value));
  save(role ? { fail: [] } : initial);
  await Deno.mkdir(`${repo.dir}/extensions/behaviors`, { recursive: true });
  const base = new URL(`file://${repo.dir}/.swamp/pulled-extensions/@swamp/`).href;
  const template = role ? "metrics_role_host.ts" : "metrics_exporter_host.ts";
  let source = await Deno.readTextFile(`${ROOT}/tests/_support/${template}`);
  for (
    const [key, value] of Object.entries({
      FIXTURE_STATE_PATH: statePath,
      FIXTURE_CALLS_PATH: callsPath,
      OFFICIAL_CFG_PATH: base + "cfgmgmt/models/",
      '"OFFICIAL_SCHEMA_PATH"': base + "ssh/models/_lib/schemas.ts",
    })
  ) source = source.replaceAll(key, JSON.stringify(value));
  await Deno.writeTextFile(`${repo.dir}/extensions/behaviors/fixture.ts`, source);
  // Preserve child input schemas: the canonical-intent cases must validate against them.
  const stub = async (name: string) =>
    await repo.workflow(name, (workflow) => {
      const inputs: Record<string, unknown> = {
        _fixture_type: "stub",
        _fixture_workflow: name,
        _fixture_step: "accepted",
        node_id: "${{ inputs.node_id }}",
        kind: "${{ inputs.kind }}",
      };
      if (name === "node-role-certificate-publish") inputs.ssh_name = "${{ inputs.ssh.name }}";
      workflow.jobs = [{
        name: "fixture",
        steps: [{
          name: "accepted",
          task: role
            ? {
              type: "behavior",
              behaviorType: "@fixture/metrics-role-host",
              behaviorName: '${{ ("stub-" + run.id).split(".").join("-") }}',
              methodName: "exec",
              inputs,
            }
            : { type: "assert", expr: "true", message: "fixture child accepted" },
        }],
      }] as unknown as typeof workflow.jobs;
    });
  for (const name of role ? ROLE : EXPORTER) {
    const children: string[] = [];
    await repo.workflow(name, (workflow) => {
      for (const step of (workflow as unknown as Flow).jobs.flatMap((j) => j.steps)) {
        const task = step.task as Task;
        if (task.type === "workflow" && !(role ? ROLE : EXPORTER).includes(task.workflowIdOrName!)) {
          children.push(task.workflowIdOrName!);
        }
        if (task.behaviorType?.startsWith("@swamp/")) {
          task.inputs = {
            ...task.inputs,
            _fixture_type: task.behaviorType,
            ...(role
              ? { _fixture_workflow: name, _fixture_step: step.name, _fixture_method: task.methodName }
              : { _fixture_name: step.name }),
          };
          task.behaviorType = role ? "@fixture/metrics-role-host" : "@fixture/metrics-host";
        }
      }
    });
    for (const child of children) await stub(child);
  }
  if (role) { for (const name of STUBS) await stub(name); }
  const secret = `fixture-role-password-${crypto.randomUUID()}`;
  if (role) {
    repo.secrets = [secret];
    await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
    await repo.cli(["vault", "put", "orbit-control-inputs", "metrics-role-operation-12"], { input: secret });
  }
  const state = () => JSON.parse(Deno.readTextFileSync(statePath)) as typeof initial;
  const recorded = () => readCalls<Call>(callsPath);
  const run = async (
    name: string,
    intent: unknown,
    fail: string | string[] = role ? [] : "",
    success = true,
    flags: string[] = [],
  ) => {
    save(role ? { fail } : { ...state(), fail });
    const inputs = {
      operation_id: 12,
      node_id: 9,
      kind: "metrics." + (role ? "" : "exporter_") + (name.endsWith("disable") ? "disable" : "enable"),
      intent,
      ...(role
        ? {
          execution_secrets: name.endsWith("enable")
            ? { password: { vault: "orbit-control-inputs", key: "metrics-role-operation-12" } }
            : {},
        }
        : {}),
    };
    await repo.cli(["workflow", "validate", name, ...flags]);
    const result = JSON.parse(
      (await repo.cli(["workflow", "run", name, "--input", JSON.stringify(inputs), ...flags], { success })).stdout,
    );
    const history = flags.length
      ? JSON.parse((await repo.cli(["workflow", "history", "get", result.id, ...flags])).stdout) as History
      : (await repo.history(result.id)).history;
    return { history, state: state(), calls: role ? recorded() : state().calls };
  };
  return { repo, run, state, save, recorded, secret };
}
