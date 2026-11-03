/**
 * Disposable boundaries for the task VM provision and UpCloud native tests; never contacts a fleet Node or UpCloud.
 *
 * `provisionComposition()` runs the actual 209 parent DAG with a fake `ssh`, recorded cfgmgmt effects and stub
 * provider, model and runtime workflows. `upcloudApi(repo)` serves a disposable UpCloud API and puts a `curl`
 * first on PATH that checks no vault value is in its argv, drops `--proto =https` and points the fixed UpCloud
 * origin at the local server, so the real curl's own `--variable` expansion builds the requests.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { type History, type NativeRepo, nativeRepo, ROOT, type Run } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";

// deno-lint-ignore no-explicit-any
type Json = Record<string, any>;

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
export const Peer = "A".repeat(43) + "=";

type Step = { labels?: unknown; task: Record<string, unknown> & { globalArgs?: Record<string, unknown> } };

/** Every job and step of a tracked workflow is placed on the swamp role. */
export function assertSwampLabels(name: string) {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
    jobs: { labels?: unknown; steps: Step[] }[];
  };
  for (const job of workflow.jobs) {
    assertEquals(job.labels, { role: "swamp" }, name);
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" }, name);
  }
}

/** Point every official SSH step of a copied workflow at the fake binaries. */
export const sshTo = (repo: NativeRepo, name: string, binaries: Record<string, string>) =>
  repo.workflow(name, (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps) as Step[]) {
      if (step.task.behaviorType === "@swamp/ssh") Object.assign(step.task.globalArgs!, binaries);
    }
  });

/** Replace a workflow with a public fixture result: a stdout step, or a behavior resource read. */
export async function stub(repo: NativeRepo, name: string, step: string, value: unknown, stdout = false) {
  let task: Record<string, unknown> = {
    type: "behavior",
    behaviorType: "command/shell",
    behaviorName: `${name}-fixture`,
    methodName: "execute",
    inputs: { run: `printf '%s\\n' "$PUBLIC"`, env: { PUBLIC: JSON.stringify(value) } },
  };
  if (!stdout) {
    await Deno.mkdir(`${repo.dir}/extensions/behaviors/fixtures`, { recursive: true });
    await Deno.writeTextFile(
      `${repo.dir}/extensions/behaviors/fixtures/${name}.ts`,
      `import {z} from "npm:zod@4.4.3";export const behavior={type:"@nckrtl/${name}-fixture",version:"2026.11.01.1",resources:{value:{schema:z.record(z.string(),z.unknown()),lifetime:"infinite",garbageCollection:1}},methods:{read:{description:"Public fixture",arguments:z.object({}),async execute(a,ctx){return{dataHandles:[await ctx.writeResource("value","value",${
        JSON.stringify(value)
      })]};}}}};`,
    );
    task = { type: "behavior", behaviorType: `@nckrtl/${name}-fixture`, behaviorName: `${name}-fixture`, methodName: "read", inputs: {} };
  }
  const workflow = { id: crypto.randomUUID(), name, inputs: { type: "object", additionalProperties: true } };
  await Deno.writeTextFile(
    `${repo.dir}/workflows/workflow-${name}.yaml`,
    stringify({ ...workflow, jobs: [{ name: "fixture", steps: [{ name: step, task }] }] }),
  );
}

const fakeSsh = (events: string, verifyFault: string) => `
import { logCall, readStdin, shellSplit } from ${JSON.stringify(tool)};
const argv = shellSplit(Deno.args.at(-1));
const body = await readStdin();
const program = argv.join(" ") + "\\n" + body;
// A test that sends vault values to the guest names them, so the log keeps no private bytes.
const hide = Deno.env.get("FIXTURE_HIDE");
logCall(${JSON.stringify(events)}, { argv, body: hide ? body.replaceAll(hide, "<private>") : body, ssh_argv: Deno.args });
const exists = (path) => { try { Deno.statSync(path); return true; } catch { return false; } };
if (argv.includes("/etc/ssh/ssh_host_ed25519_key.pub")) console.log(${JSON.stringify(HostKey)});
else if (argv.slice(-3).join(" ") === "/usr/bin/sudo -n true" && exists(${JSON.stringify(verifyFault)})) Deno.exit(1);
else if (argv.includes("/usr/bin/sha256sum") && argv.at(-1) === "/etc/orbit/agent/secret") console.log("e".repeat(64) + "  /etc/orbit/agent/secret");
else if (program.includes("wg pubkey <")) console.log(${JSON.stringify(Peer)});
else console.log('{"ready":true}');
`;

/** Official cfgmgmt is an effect boundary too: record what it would apply on the hub. */
const recorder = (events: string) => `
import { logCall } from ${JSON.stringify(tool)};
const env = (name) => Deno.env.get(name) ?? "";
logCall(${JSON.stringify(events)}, { behavior: env("BEHAVIOR"), pathname: env("PATHNAME"), content: env("CONTENT"), command: env("COMMAND") });
`;

export type GuestEvent = { argv: string[]; body: string; ssh_argv: string[] };
export type HubRecord = { behavior: string; pathname: string; content: string; command: string };

export async function provisionComposition() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-vm-lifecycle", "operations/node-peer-command", "operations/node-bootstrap");
  const events = `${repo.dir}/events.jsonl`;
  const verifyFault = `${repo.dir}/verify-fault`;
  const ssh = await repo.fakeBinary("ssh", fakeSsh(events, verifyFault));
  const record = await repo.fakeBinary("record", recorder(events));
  for (
    const name of [
      "task-vm-provision",
      "operation-task-vm-provision",
      "task-vm-ssh-prepare",
      "task-vm-incus-first-visit",
      "task-vm-enrollment",
    ]
  ) {
    await repo.workflow(name, (workflow) => {
      for (const step of workflow.jobs.flatMap((job) => job.steps) as Step[]) {
        const task = step.task;
        if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
        else if (String(task.behaviorType ?? "").startsWith("@swamp/cfgmgmt/")) {
          const args = task.globalArgs!;
          step.task = {
            type: "behavior",
            behaviorType: "command/shell",
            behaviorName: task.behaviorName,
            methodName: "execute",
            inputs: {
              run: record,
              env: { BEHAVIOR: task.behaviorType, PATHNAME: args.path ?? "", CONTENT: args.content ?? "", COMMAND: args.command ?? "" },
            },
          };
        }
      }
    });
  }
  // Only these effect boundaries are substituted. Their real native suites
  // cover create/power and Pi installation separately; no fleet VM is used.
  await stub(repo, "task-vm-provider-incus", "provider", {
    provider_id: null,
    address: "10.240.9.42",
    image_fingerprint: "a".repeat(64),
    running: true,
    settled: true,
  });
  await stub(repo, "task-vm-model-issue", "result", { task_vm_id: 42, issued: true }, true);
  await stub(repo, "task-vm-model-revoke", "result", { task_vm_id: 42, revoked: true }, true);
  await stub(repo, "task-vm-runtime", "result", { task_vm_id: 42, guest_node_id: 142, process_id: 33, ready: true }, true);
  const identity = { control_node_id: 209, identity_file: "/etc/orbit/swamp-role-worker/ssh-identity", public_key: HostKey };
  const execution = { ...identity, target: { name: "target", host: "192.0.2.9", user: "orbit", port: 22, host_keys: [HostKey] } };
  const peer = { server_public_key: Peer, endpoint: "192.0.2.1:51820" };
  const peerSection = `[Peer]\nPublicKey = ${Peer}\nEndpoint = 192.0.2.1:51820\nAllowedIPs = 10.44.0.0/16\nPersistentKeepalive = 25\n`;
  const intent: Json = {
    eligible: true,
    task_vm_id: 42,
    guest_node_id: 142,
    provider: "incus",
    name: "tvm-42",
    user: "orbit",
    vm: { project: "tasks", name: "tvm-42" },
    ssh_identity: identity,
    host_ssh_execution: execution,
    hub_ssh_execution: execution,
    node: { node_id: 142, wireguard_ip: "10.44.0.142" },
    runtime: { process: { process_id: 33 } },
    bootstrap: { user: "orbit", public_key: HostKey, releases: ["resolute"], packages: ["wireguard-tools"], dns_policy: "managed" },
    agent_sha256: "b".repeat(64),
    agent_artifact_url: "https://example.test/observer",
    observer_digest: null,
    agent: { configuration: "public configuration", certificate: "public certificate", unit: "public unit" },
    network_input: {
      existing_key: null,
      peer: {
        ...peer,
        address: "10.44.0.142/16",
        subnet: "10.44.0.0/16",
        dns_server: "10.44.0.1",
        domains: ["orbit"],
        operator_dns: null,
        retain: false,
      },
    },
    hub: {
      hub_id: 1,
      ssh: execution,
      file: "/etc/wireguard/orbit-peers.d/142.conf",
      section: "[Peer]\n# tvm-42\nPublicKey = @KEY@\nAllowedIPs = 10.44.0.142/32\n",
    },
    peer_files: {
      config:
        "[Interface]\nAddress = 10.44.0.142/16\nPostUp = wg set %i private-key /etc/wireguard/orbit.key\nPostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit\n\n" +
        peerSection,
      peers: peerSection,
      dns_hook: "PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit",
      dns_marker: "orbit\n10.44.0.1\norbit\n",
    },
    pi_artifact: { path: "/tmp/pi", sha256: "a".repeat(64), ssh_execution: execution },
    process_unit: "public unit",
    certificate: "public certificate",
    pi_url: "http://10.44.0.142:3774",
    model_intent: { task_vm_id: 42, gateway_node_id: 14, model_router_node_id: 6, model_origin: "http://192.0.2.6" },
  };
  const secretKeys = {
    model_key: "task-vm-model-key-42",
    pi_models: "task-vm-pi-models-42",
    pi_token: "task-vm-pi-token-42",
    model_router_management: "model-router-management-node-6",
    model_router_anchor: "model-router-anchor-node-6",
  };
  const inputs = {
    operation_id: 7005,
    node_id: 9,
    kind: "task_vm.provision",
    intent,
    execution_secrets: Object.fromEntries(
      Object.entries(secretKeys).map(([key, name]) => [key, { vault: "orbit-control-inputs", key: name }]),
    ) as Record<string, unknown>,
  };
  const eventsText = () => Deno.readTextFile(events);
  return {
    repo,
    ssh,
    intent,
    inputs,
    verifyFault,
    eventsText,
    eventsExist: () => Deno.stat(events).then(() => true, () => false),
    guest: () => readCalls<GuestEvent>(events).filter((event) => "argv" in event),
    records: () => readCalls<HubRecord>(events).filter((event) => "behavior" in event),
    async root(success = true, ...args: string[]) {
      const run = JSON.parse(
        (await repo.cli(["workflow", "run", "operation-task-vm-provision", "--input", JSON.stringify(inputs), ...args], {
          success,
        })).stdout,
      ) as Run;
      return { run, ...await repo.history(run.id) };
    },
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

/** The final step's stdout of a run, parsed. */
export const resultOf = (history: History) => JSON.parse(history.jobs[0].steps.at(-1)!.outputs!.stdout as string);

export const ProviderId = "bb757fe7-0d7f-497e-b160-b545075e2cba";
export const ForeignId = "6651dd0d-fb0e-4689-9664-22de529dd200";
export const Image = "00000000-0000-4000-8000-000000000001";

export async function upcloudApi(repo: NativeRepo) {
  // Built at run time: the family tree, this file included, is copied into each scanned repo.
  const token = "UPCloud_secret_" + "fixture_3f31c";
  const hostKey = "fixture-secret-" + "host-key";
  const privateKey = `-----BEGIN OPENSSH PRIVATE KEY-----\n${hostKey}\n-----END OPENSSH PRIVATE KEY-----`;
  const api = {
    token,
    privateKey,
    exists: false,
    state: "stopped",
    lostCreate: false,
    foreign: false,
    omitListLabels: false,
    wrongDetailUuid: false,
    wrongDetailImage: false,
    detailStatus: null as number | null,
    calls: [] as [string, string][],
    createDocuments: [] as Json[],
    nonGet: () => api.calls.filter(([method]) => method !== "GET"),
    deletes: () => api.calls.filter(([method]) => method === "DELETE").map(([, path]) => path),
  };
  const row = () => ({
    uuid: ProviderId,
    title: "tvm-42",
    hostname: "tvm-42",
    state: api.state,
    labels: {
      label: [{ key: "orbit-task-vm", value: api.foreign ? "43" : "42" }, { key: "orbit-task-vm-image", value: Image }],
    },
    storage_devices: { storage_device: [{ type: "disk", storage: "3476acd6-23c5-4f77-baf8-56d812e6fbb4" }] },
    ip_addresses: { ip_address: [{ access: "public", family: "IPv4", address: "93.184.216.34" }] },
  } as Json);
  const answer = (status: number, value: unknown) => new Response(JSON.stringify(value), { status });
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, async (request) => {
    const url = new URL(request.url);
    const path = url.pathname + url.search;
    api.calls.push([request.method, path]);
    if (request.method === "GET") {
      if (request.headers.get("Authorization") !== `Bearer ${token}`) return answer(403, {});
      if (path.endsWith("/plan")) return answer(200, { plans: { plan: [{ name: "test-plan", storage_tier: "standard" }] } });
      if (path.includes("?label=")) {
        const listed = row();
        if (api.omitListLabels) delete listed.labels;
        return answer(200, { servers: { server: api.exists ? [listed] : [] } });
      }
      if (api.detailStatus) return answer(api.detailStatus, { error: { error_code: "FIXTURE" } });
      const detail = row();
      if (api.wrongDetailImage) detail.labels.label[1].value = "foreign-image";
      if (api.wrongDetailUuid) detail.uuid = ForeignId;
      return api.exists ? answer(200, { server: detail }) : answer(404, {});
    }
    if (request.method === "DELETE") {
      api.exists = false;
      return answer(200, {});
    }
    const body = await request.json();
    if (path.endsWith("/server")) {
      api.createDocuments.push(body);
      api.exists = true;
      if (api.lostCreate) return answer(503, { error: token });
    } else if (path.endsWith("/start")) api.state = "started";
    else if (path.endsWith("/stop")) {
      assertEquals(body, { stop_server: { stop_type: "soft", timeout: "120" } });
      api.state = "stopped";
    }
    return answer(200, { server: { uuid: ProviderId } });
  });
  const curl = new TextDecoder().decode((await new Deno.Command("which", { args: ["curl"] }).output()).stdout).trim();
  assert(curl);
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary("tools/curl", `
const args = [...Deno.args];
const check = (ok, message) => { if (!ok) throw new Error(message + ": " + JSON.stringify(args)); };
// Vault values reach curl only from its environment, through --variable.
check(args[args.indexOf("--variable") + 1] === "%ORBIT_UPCLOUD_TOKEN", "variable");
for (const name of ["ORBIT_UPCLOUD_TOKEN", "ORBIT_BOOTSTRAP_PRIVATE_KEY"]) {
  const value = Deno.env.get(name);
  if (value) check(!args.join(" ").includes(value), name);
}
const proto = args.indexOf("--proto");
check(args[proto + 1] === "=https", "proto");
args.splice(proto, 2);
check(args.at(-1).startsWith("https://api.upcloud.com/1.3/"), "origin");
args[args.length - 1] = args.at(-1).replace("https://api.upcloud.com/1.3", "http://127.0.0.1:${server.addr.port}/1.3");
const result = await new Deno.Command(${JSON.stringify(curl)}, { args, stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output();
Deno.exit(result.code);
`);
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  for (const name of ["task-vm-provider-upcloud", "task-vm-provider-power"]) {
    assertSwampLabels(name);
    await repo.workflow(name);
  }
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-upcloud-token"], { input: token });
  await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-provider-bootstrap-42"], { input: privateKey });
  repo.secrets = [token, hostKey];
  const intent = {
    task_vm_id: 42,
    node_id: 14,
    host_node_id: null,
    guest_node_id: 142,
    gateway_node_id: 14,
    model_router_node_id: 6,
    provider: "upcloud",
    provider_id: null,
    name: "tvm-42",
    image_fingerprint: Image,
    recipe_identity: "sha256:" + "a".repeat(64),
    vm: null,
    create: null,
    bootstrap_host_key: HostKey,
    ssh_identity: { control_node_id: 209, identity_file: "/etc/orbit/swamp-role-worker/ssh-identity", public_key: HostKey },
    upcloud: { zone: "fi-hel1", plan: "test-plan", disk_gb: 20, max_vms: 2, source_template: Image },
  };
  const copies = {
    upcloud: { vault: "orbit-control-inputs", key: "task-vm-upcloud-token" },
    bootstrap: { vault: "orbit-control-inputs", key: "task-vm-provider-bootstrap-42" },
  };
  return Object.assign(api, { intent, copies, [Symbol.asyncDispose]: () => server.shutdown() });
}
