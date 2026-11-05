/** The slot units that the task image build bakes start only from the slot identity tree, and match what the Gateway renders today. */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.11";

type Step = {
  name: string;
  dependsOn?: { step: string }[];
  task: { inputs: { argv?: string[]; stdin?: string } };
};
const read = (path: string) =>
  Deno.readTextFile(new URL(path, import.meta.url));
const workflow = parse(
  await read("../workflows/workflow-operation-task-vm-image-build.yaml"),
) as { jobs: { steps: Step[] }[] };
const steps = new Map(
  workflow.jobs.flatMap((job) => job.steps).map((step) => [step.name, step]),
);
const stepNames = [...steps.keys()];

const units = {
  agent: ["slot-units-agent", "/etc/systemd/system/orbit-agent.service"],
  agentDropin: [
    "slot-units-agent-dropin",
    "/etc/systemd/system/orbit-agent.service.d/10-slot.conf",
  ],
  pi: ["slot-units-pi", "/etc/systemd/system/pi-server.service"],
  wireguardDropin: [
    "slot-units-wireguard-dropin",
    "/etc/systemd/system/wg-quick@orbit.service.d/10-slot.conf",
  ],
  target: ["slot-units-target", "/etc/systemd/system/orbit-slot.target"],
} as const;
const text = (key: keyof typeof units) =>
  steps.get(units[key][0])!.task.inputs.stdin!;
const lines = (key: keyof typeof units) => text(key).split("\n");
const values = (key: keyof typeof units, name: string) =>
  lines(key).filter((line) => line.startsWith(`${name}=`)).map((line) =>
    line.slice(name.length + 1)
  );

Deno.test("the build pushes each unit to its path in order, enables only the target, and cleans after", () => {
  const order = [
    "slot-identity-enable",
    ...Object.values(units).map(([step]) => step),
    "slot-units-enable",
    "clean-marker",
  ];
  for (let i = 1; i < order.length; i++) {
    assertEquals(
      steps.get(order[i])!.dependsOn?.map((d) => d.step),
      [order[i - 1]],
      `${order[i]} does not follow ${order[i - 1]}`,
    );
  }
  for (const [step, path] of Object.values(units)) {
    const argv = steps.get(step)!.task.inputs.argv!;
    assert(argv.includes("push") && argv.includes("--create-dirs"), step);
    assertEquals(argv.at(-1), `\${{ inputs.intent.name + "${path}" }}`);
  }
  // Only the target is enabled. The units have no enable symlink of their own, so a VM that is not a slot starts none.
  const enable = steps.get("slot-units-enable")!.task.inputs.argv!;
  assertEquals(enable.slice(-2), ["enable", "orbit-slot.target"]);
  assertEquals(stepNames.filter((name) => name.includes("enable")), [
    "ssh-enable",
    "slot-identity-enable",
    "slot-units-enable",
  ]);
});

Deno.test("each baked unit starts only when its identity files exist", () => {
  assertEquals(values("agentDropin", "ConditionPathExists"), [
    "/etc/orbit/agent/secret",
    "/etc/orbit/agent/config.toml",
    "/etc/orbit/agent/ca.pem",
  ]);
  assertEquals(values("pi", "ConditionPathExists"), [
    "/etc/orbit/pi.env",
    "/home/orbit/.pi/agent/orbit-token",
  ]);
  assertEquals(values("wireguardDropin", "ConditionPathExists"), [
    "/etc/wireguard/orbit.conf",
  ]);
  // The target stays inactive on a VM with no slot identity; the units it pulls in skip themselves.
  assertEquals(values("target", "ConditionPathExists"), ["/etc/orbit/pi.env"]);
  assertEquals(values("target", "Wants"), [
    "wg-quick@orbit.service orbit-agent.service pi-server.service",
  ]);
  assertEquals(values("target", "WantedBy"), ["multi-user.target"]);
  // Everything the target pulls in is ordered before it, so an active target means the slot is up.
  const after = values("target", "After").join(" ").split(" ");
  for (
    const name of [
      "orbit-slot-identity.service",
      "wg-quick@orbit.service",
      "orbit-agent.service",
      "pi-server.service",
    ]
  ) assert(after.includes(name), `After= lacks ${name}`);
  assertFalse(text("target").includes("ssh.socket"));
  // The units that only a slot starts have no [Install] on a drop-in, and the base units are never enabled here.
  assertFalse(text("agentDropin").includes("[Install]"));
  assertFalse(text("wireguardDropin").includes("[Install]"));
});

Deno.test("the Pi unit holds no address: host and port come from the identity file", () => {
  assertEquals(values("pi", "EnvironmentFile"), ["/etc/orbit/pi.env"]);
  const [exec] = values("pi", "ExecStart");
  assert(exec.includes("--host=${PI_HOST}"));
  assert(exec.includes("--port=${PI_PORT}"));
  assertFalse(/\d+\.\d+\.\d+\.\d+/.test(text("pi")));
  assertFalse(/--port=\d/.test(exec));
  assertEquals(values("pi", "User"), ["orbit"]);
});

Deno.test("the Pi unit keeps the command line the Gateway records for a Pi Process", async () => {
  const intent = await read(
    "../../apps/gateway/app/Domain/TaskVms/TaskVmEnrollmentIntent.php",
  );
  const runtime = await read(
    "../../apps/gateway/app/Infrastructure/TaskVms/TaskVmRuntime.php",
  );
  const provider = runtime.match(/PiProvider = '([^']+)'/)![1];
  // `$home.'/.local/bin/pi-server', 'serve', '--host='...`, `'--token-file='.$home.'/.pi/agent/orbit-token'`
  assert(intent.includes("$home.'/.local/bin/pi-server', 'serve'"));
  assert(intent.includes("'--token-file='.$home.'/.pi/agent/orbit-token'"));
  assert(intent.includes("'--allow-provider='.TaskVmRuntime::PiProvider"));
  const [exec] = values("pi", "ExecStart");
  assertEquals(exec.split(" "), [
    "/home/orbit/.local/bin/pi-server",
    "serve",
    "--host=${PI_HOST}",
    "--port=${PI_PORT}",
    "--token-file=/home/orbit/.pi/agent/orbit-token",
    `--allow-provider=${provider}`,
  ]);
});

Deno.test("the baked observer unit is the text the Gateway renders for a new Node", async () => {
  const executor = await read(
    "../../apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php",
  );
  const footprint = await read(
    "../../apps/gateway/app/Infrastructure/Nodes/NodeAgentFootprint.php",
  );
  const marker = footprint.match(/Marker = '([^']+)'/)![1];
  const binary = footprint.match(/BinaryPath = '([^']+)'/)![1];
  const group = executor.match(/EnvGroup = '([^']+)'/)![1];
  const block = executor.slice(
    executor.indexOf("$unit = implode"),
    executor.indexOf("return ['configuration'"),
  );
  const expected: string[] = [];
  for (const line of block.split("\n").map((l) => l.trim())) {
    const literal = line.match(/^'([^'$]+)',$/);
    if (literal) expected.push(literal[1]);
    else if (line === "NodeAgentFootprint::Marker,") expected.push(marker);
    else if (line === "'ExecStart='.NodeAgentFootprint::BinaryPath,") {
      expected.push(`ExecStart=${binary}`);
    } else if (line === "'SupplementaryGroups='.self::EnvGroup,") {
      expected.push(`SupplementaryGroups=${group}`);
    } else if (line.startsWith("...$this->checkoutAccess(")) {
      // A new Node has no Instance root and no tool path, which renders `ProtectHome=yes`.
      expected.push("ProtectHome=yes");
    }
  }
  assert(expected.length > 15, "the Gateway template was not read");
  assertEquals(lines("agent").filter((line) => line !== ""), expected);
});

Deno.test("the identity unit makes the group the observer unit names", () => {
  const unit = steps.get("slot-identity-unit")!.task.inputs.stdin!;
  assert(
    unit.split("\n").some((line) =>
      line === "ExecStart=/usr/sbin/groupadd -f --system orbit-env"
    ),
  );
  assertEquals(values("agent", "SupplementaryGroups"), ["orbit-env"]);
});

Deno.test("the smoke VM has no identity, so the target is enabled and nothing starts", () => {
  const smoke = steps.get("smoke-slot-units")!.task.inputs.argv!;
  const script = smoke.at(-1)!;
  assertEquals(smoke.at(-3), "/bin/sh");
  assert(script.startsWith("systemctl is-enabled --quiet orbit-slot.target"));
  assert(script.includes("! systemctl is-active --quiet"));
  for (
    const name of [
      "orbit-slot.target",
      "orbit-agent.service",
      "pi-server.service",
      "wg-quick@orbit.service",
    ]
  ) assert(script.includes(name), `the smoke does not check ${name}`);
  assert(
    smoke.some((arg) => arg.includes('inputs.intent.name + "-smoke"')),
    "the check must run in the smoke VM, not the builder",
  );
});
