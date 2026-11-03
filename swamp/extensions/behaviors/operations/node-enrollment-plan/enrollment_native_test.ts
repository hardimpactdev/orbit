/** node.add enrollment trust, the Mac branch and WireGuard key staging through the unmodified official SSH extension. */
import { assert, assertEquals, assertFalse, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { encodeBase64 } from "jsr:@std/encoding@1.0.10/base64";
import { walk } from "jsr:@std/fs@1.0.19/walk";
import { type NativeRepo, nativeRepo } from "../../../../tests/_support/native_harness.ts";

const NAME = "operation-node-add";
type Step = { name: string; status: string; outputs?: { stdout?: string } };
type Workflow = Record<string, unknown> & {
  jobs: { name: string; steps: Record<string, unknown>[]; [key: string]: unknown }[];
};

/** Harmless binary seam: answers the enrollment reads, and every unexpected Node command fails the test. */
const ENROLLMENT_SSH = `
const argv = Deno.args, env = Deno.env.toObject();
if (["-O", "-N", "-MNf"].some((flag) => argv.includes(flag))) Deno.exit(0);
const command = argv.at(-1)!;
if (command.includes("uname")) console.log(env.FIXTURE_PLATFORM);
else if (command.includes("ifconfig")) {
  console.log("utun3: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST>");
  console.log("    inet " + env.FIXTURE_ADDRESS + " --> 10.44.0.1 netmask 0xffffff00");
} else if (command.includes("getent")) console.log("mac:x:1000:1000::/srv/mac:/bin/bash");
else if (command.includes("cat ")) console.log(env.FIXTURE_PUBLIC_KEY);
else throw new Error("Unexpected enrollment command: " + command);
`;
/** The peer-sync fakes act locally: ssh runs its command in a shell, sudo drops its options, wg keeps its state in a file. */
const SYNC_SSH = `
if (["-N", "-O", "-MNf"].some((flag) => Deno.args.includes(flag))) Deno.exit(0);
const child = new Deno.Command("/bin/sh", { args: ["-c", Deno.args.at(-1)!] }).spawn();
Deno.exit((await child.status).code);
`;
const SUDO = `
const argv = Deno.args.slice(Deno.args.indexOf("--") + 1);
Deno.exit((await new Deno.Command(argv[0], { args: argv.slice(1) }).spawn().status).code);
`;
const WG = `
const state = Deno.env.get("HUB_SYNC_STATE")!;
if (Deno.args[0] === "syncconf") {
  Deno.writeTextFileSync(state, Deno.readTextFileSync(Deno.args[2]).includes("PrivateKey = ") ? "public-hub-key" : "(none)");
} else console.log(Deno.readTextFileSync(state));
`;

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  await repo.copyBehaviors("operations/node-enrollment-plan", "operations/node-bootstrap");
  await Deno.mkdir(`${repo.dir}/tools`);
  const ssh = await repo.fakeBinary("tools/ssh", ENROLLMENT_SSH);
  const identity = `${repo.dir}/identity`;
  await new Deno.Command("ssh-keygen", { args: ["-q", "-t", "ed25519", "-N", "", "-f", identity] }).output();
  const publicKey = (await Deno.readTextFile(`${identity}.pub`)).trim();
  const privateKey = await Deno.readTextFile(identity);
  await Deno.remove(identity);
  repo.secrets = [privateKey];
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "bootstrap-identity-node-701"], { input: privateKey });
  Object.assign(repo.env, {
    FIXTURE_PUBLIC_KEY: publicKey,
    FIXTURE_PLATFORM: "Darwin arm64",
    FIXTURE_ADDRESS: "10.44.0.42",
  });
  const keyBytes = Uint8Array.from(atob(publicKey.split(" ")[1]), (char) => char.charCodeAt(0));
  const fingerprint = "SHA256:" +
    encodeBase64(new Uint8Array(await crypto.subtle.digest("SHA-256", keyBytes))).replace(/=+$/, "");
  const network = {
    is_hub: false,
    sync_path: "",
    private_key_file: "",
    sync_body: "",
    address: "",
    retired_address: null,
    files: [],
    hubs: [] as unknown[],
    hub_files: [],
    trust_argv: [],
  } as Record<string, unknown>;
  const intent = {
    platform: "macos",
    expected_architecture: null,
    managed_user: "mac",
    address: "10.44.0.42",
    bootstrap: { host: "mac.example", port: 22, user: "mac", fingerprint },
    roles: [],
    cli: null as unknown,
    dns: [],
    private_dns: [],
    packages: [],
    authorized_keys: [],
    sudo: { path: "", candidate: "", content: "" },
    agent: { url: "", sha256: "", unit: "", files: [] },
    network,
    public_ssh_argv: [],
    known_hosts_path: "/synthetic/known_hosts",
    control_ssh: {
      identity_file: "/synthetic/control",
      target: { host: "control.example", port: 22, user: "orbit", host_keys: [publicKey] },
    },
    identity: { identity_file: "/synthetic/control", public_key: publicKey },
  };
  const inputs = {
    operation_id: 9001,
    node_id: 701,
    kind: "node.add",
    execution_secrets: { bootstrap_identity: { vault: "orbit-control-inputs", key: "bootstrap-identity-node-701" } },
    intent,
  };
  const install = (edit: (workflow: Workflow) => void = () => {}) =>
    repo.workflow(NAME, (workflow) => {
      for (const step of workflow.jobs.flatMap((job) => job.steps)) {
        const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
        if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
      }
      edit(workflow as unknown as Workflow);
    });
  const run = async (name = NAME, success = true) => {
    const { history } = await repo.runWorkflow(name, inputs, { success });
    const steps: Record<string, Step> = {};
    for (const step of (history as unknown as { jobs: { steps: Step[] }[] }).jobs.flatMap((job) => job.steps)) {
      steps[step.name] = step;
    }
    return { steps, history };
  };
  return { repo, intent, network, publicKey, install, run };
}

Deno.test("Mac enrollment checks the existing tunnel without Linux mutations", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.install();
  const { steps } = await f.run();
  assertEquals(steps["Check the Node"].status, "succeeded");
  assertEquals(JSON.parse(steps["Bind the Node identity"].outputs!.stdout!).architecture, "arm64");
  assertEquals(steps["Inspect the Mac tunnel-0"].status, "succeeded");
  assertFalse("Install base packages-0" in steps);
  assertFalse("Write the observer secret-0" in steps);
});

Deno.test("an unapproved host key stops before any authenticated observation", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.install();
  f.intent.bootstrap.fingerprint = "SHA256:unapproved";
  const { steps } = await f.run(NAME, false);
  assertEquals(steps["Require approved host key"].status, "failed");
  assertEquals(steps["Read the platform"].status, "skipped");
  assertNotEquals(steps["Check the Node"].status, "succeeded");
});

Deno.test("a missing existing Mac tunnel refuses activation", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.install();
  f.repo.env.FIXTURE_ADDRESS = "10.44.0.99";
  const { steps } = await f.run(NAME, false);
  assertEquals(steps["Check the Node"].status, "failed");
});

Deno.test("an unsupported Linux architecture stops before bootstrap mutations", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.install();
  f.intent.platform = "linux";
  Object.assign(f.network, { other_public_keys: [], required_public_key: null });
  f.repo.env.FIXTURE_PLATFORM = "Linux aarch64";
  const { steps } = await f.run(NAME, false);
  assertEquals(steps["Bind the Node identity"].status, "failed");
  assertNotEquals(steps["Install base packages-0"]?.status, "succeeded");
});

Deno.test("roleless enrollment does not call CLI publication with a null release", async () => {
  const f = await fixture();
  await using _ = f.repo;
  Object.assign(f.intent, {
    platform: "linux",
    cli: { cli: { release: null, skip_reason: "roleless", plan_error: null } },
  });
  await f.install((workflow) => {
    const job = workflow.jobs.find((job) => job.name === "cli")!;
    delete job.dependsOn;
    Object.assign(workflow, { id: "3254f534-980b-4653-b718-4ca943678ace", name: "cli-exclusion-test", jobs: [job] });
  });
  await Deno.rename(
    `${f.repo.dir}/workflows/workflow-${NAME}.yaml`,
    `${f.repo.dir}/workflows/workflow-cli-exclusion-test.yaml`,
  );
  // A called child fails. A correctly excluded plan creates no child step at all.
  await Deno.writeTextFile(
    `${f.repo.dir}/workflows/workflow-cli.yaml`,
    JSON.stringify({
      id: "49b21cc9-4bdd-437f-8c16-77d49aaefea2",
      name: "node-cli-install",
      jobs: [{ name: "unexpected", steps: [{ name: "must not run", task: { type: "assert", expr: "false" } }] }],
    }),
  );
  const { history } = await f.run("cli-exclusion-test");
  assertEquals((history as unknown as { jobs: { steps: unknown[] }[] }).jobs[0].steps, []);
});

async function peerSync(hub: boolean) {
  const f = await fixture();
  await using _ = f.repo;
  const repo: NativeRepo = f.repo;
  const privateKey = encodeBase64(new TextEncoder().encode("enrollment-private-key-sentinel!!"));
  const key = `${repo.dir}/hub.key`, config = `${repo.dir}/hub.sync`, identity = `${repo.dir}/control.identity`;
  await Deno.writeTextFile(key, privateKey + "\n");
  await Deno.writeTextFile(config, "[Interface]\nListenPort = 51820\n", { mode: 0o600 });
  await Deno.writeTextFile(identity, "fixture-control-key", { mode: 0o600 });
  const ssh = await repo.fakeBinary("tools/ssh", SYNC_SSH);
  await repo.fakeBinary("tools/sudo", SUDO);
  await repo.fakeBinary("tools/wg", WG);
  Object.assign(repo.env, {
    PATH: `${repo.dir}/tools:${Deno.env.get("PATH")}`,
    HUB_SYNC_STATE: `${repo.dir}/hub-state`,
  });
  const syncBody = "ListenPort = 51820\n\n[Peer]\nPublicKey = peer-key\nAllowedIPs = 10.44.0.42/32\n";
  f.intent.platform = "linux";
  Object.assign(f.network, { sync_path: config, private_key_file: key, sync_body: syncBody });
  f.network.hubs = [{
    ssh: {
      identity_file: identity,
      target: { host: "hub.example", port: 22, user: "orbit", host_keys: [f.publicKey] },
    },
    sync_path: config,
    private_key_file: key,
    public_key: "public-hub-key",
    sync_body: syncBody,
  }];
  const selected = hub
    ? [
      "Stage the hub private key",
      "Finish hub configuration",
      "Apply the hub peers",
      "Read the hub public key",
      "Check the hub key",
    ]
    : ["Stage the tunnel key", "Finish tunnel config", "Apply the tunnel peers"];
  await f.install((workflow) => {
    const jobs: Workflow["jobs"] = [];
    for (const job of workflow.jobs) {
      const steps = job.steps.filter((step) => selected.includes(step.name as string));
      if (steps.length === 0) continue;
      const kept: Workflow["jobs"][number] = { name: job.name, steps };
      if (jobs.length) kept.dependsOn = [{ job: jobs.at(-1)!.name, condition: { type: "succeeded" } }];
      for (const step of steps) {
        const task = step.task as {
          behaviorType?: string;
          globalArgs: {
            sshBinary: string;
            transport: Record<string, unknown>;
            hosts: { transport: Record<string, unknown> }[];
          };
          inputs: { stdin?: string };
        };
        if (task.behaviorType !== "@swamp/ssh") continue;
        task.globalArgs.sshBinary = ssh;
        delete task.globalArgs.transport.identityContent;
        task.globalArgs.transport.identityFile = identity;
        task.globalArgs.hosts[0].transport.hostKeys = [f.publicKey];
        task.inputs.stdin = (task.inputs.stdin ?? "").replace(
          'steps["Read WireGuard public key-0"].outputs.stdout.trim()',
          '"peer-key"',
        );
      }
      jobs.push(kept);
    }
    Object.assign(workflow, { name: "enrollment-hub-key-test", id: "38bd409a-001d-4907-b56b-f385712a6520", jobs });
  });
  await Deno.rename(
    `${repo.dir}/workflows/workflow-${NAME}.yaml`,
    `${repo.dir}/workflows/workflow-enrollment-hub.yaml`,
  );
  const { history } = await f.run("enrollment-hub-key-test");
  // The key files hold the key by design; native history and Swamp's own data must not.
  assertFalse(JSON.stringify(history).includes(privateKey));
  for await (const entry of walk(`${repo.dir}/.swamp`, { includeDirs: false })) {
    assertFalse(new TextDecoder().decode(await Deno.readFile(entry.path)).includes(privateKey), entry.path);
  }
  const written = await Deno.readTextFile(config);
  assert(written.includes("PrivateKey = " + privateKey));
  assert(written.includes("AllowedIPs = 10.44.0.42/32"));
  assertEquals(await Deno.readTextFile(`${repo.dir}/hub-state`), "public-hub-key");
}

Deno.test("hub sync keeps the file key without returning it in run data", () => peerSync(true));
Deno.test("node sync keeps the file key without returning it in run data", () => peerSync(false));
