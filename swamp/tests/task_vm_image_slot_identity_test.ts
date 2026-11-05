/** The slot identity unit that the task image build installs keeps the ordering and skip rules that M0 measured. */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.11";

type Step = {
  name: string;
  dependsOn?: { step: string }[];
  task: { inputs: { argv?: string[]; stdin?: string } };
};
const workflow = parse(
  await Deno.readTextFile(
    new URL(
      "../workflows/workflow-operation-task-vm-image-build.yaml",
      import.meta.url,
    ),
  ),
) as { jobs: { steps: Step[] }[] };
const steps = new Map(
  workflow.jobs.flatMap((job) => job.steps).map((step) => [step.name, step]),
);
const unit = steps.get("slot-identity-unit")!.task.inputs.stdin!;
const lines = unit.split("\n");
const values = (key: string) =>
  lines.filter((line) => line.startsWith(`${key}=`)).map((line) =>
    line.slice(key.length + 1)
  );

Deno.test("the unit orders before ssh and the key generator, never before ssh.socket", () => {
  const before = values("Before").join(" ").split(" ");
  for (
    const name of [
      "sshd-keygen.service",
      "ssh.service",
      "wg-quick@orbit.service",
      "orbit-agent.service",
    ]
  ) assert(before.includes(name), `Before= lacks ${name}`);
  // Before=ssh.socket with default dependencies is an ordering cycle that deletes the socket job.
  assertFalse(unit.includes("ssh.socket"));
  assertEquals(values("DefaultDependencies"), ["no"]);
});

Deno.test("a VM with no identity volume skips the unit", () => {
  assertEquals(values("ExecCondition"), [
    "/usr/bin/grep -qrsx incus_identity /sys/fs/virtiofs",
  ]);
  // The Incus agent mounts /opt/orbit-slot after the unit has to run, so the unit reads its own mount.
  assertFalse(unit.includes("ConditionPathExists=/opt/orbit-slot"));
  assertFalse(unit.includes("After=incus-agent.service"));
  assertEquals(values("WantedBy"), ["sysinit.target"]);
});

Deno.test("the unit installs the slot tree with owners and modes and shows no content", () => {
  const installs = values("ExecStart").filter((line) =>
    line.startsWith("/usr/bin/install ")
  );
  const text = installs.join("\n");
  for (
    const path of [
      "/etc/wireguard/orbit.key",
      "/etc/wireguard/orbit.conf",
      "/etc/wireguard/orbit.dns-link",
      "/etc/ssh/ssh_host_ed25519_key",
      "/etc/ssh/ssh_host_ecdsa_key.pub",
      "/etc/ssh/ssh_host_rsa_key",
      "/etc/cloud/cloud.cfg.d/99-orbit-slot.cfg",
      "/etc/orbit/agent/secret",
      "/etc/orbit/agent/config.toml",
      "/etc/orbit/agent/ca.pem",
      "/home/orbit/.pi/agent/orbit-token",
      "/home/orbit/.pi/agent/models.json",
    ]
  ) {
    const source = `/run/orbit-slot${path}`;
    assert(text.includes(source), `${source} is not installed`);
  }
  for (const line of installs) {
    assert(/ -m (0600|0700|0644) /.test(line), `no explicit mode: ${line}`);
    assert(line.includes(" -o "), `no explicit owner: ${line}`);
  }
  // The mount is read-only, and nothing prints a file.
  assert(values("ExecStart")[0].includes("-o ro incus_identity"));
  assertFalse(/\b(cat|echo|printf|tee)\b/.test(unit));
});

Deno.test("the build pushes and enables the unit before the image is cleaned, and the smoke checks it", () => {
  const push = steps.get("slot-identity-unit")!;
  assertEquals(push.dependsOn?.map((d) => d.step), ["ssh-enable"]);
  assert(push.task.inputs.argv!.includes("push"));
  assert(
    push.task.inputs.argv!.at(-1)!.includes(
      "/etc/systemd/system/orbit-slot-identity.service",
    ),
  );
  const enable = steps.get("slot-identity-enable")!;
  assertEquals(enable.dependsOn?.map((d) => d.step), ["slot-identity-unit"]);
  assertEquals(enable.task.inputs.argv!.slice(-2), [
    "enable",
    "orbit-slot-identity.service",
  ]);
  // The slot units follow (task_vm_image_slot_units_test.ts); the clean step comes after the last of them.
  assertEquals(
    steps.get("slot-units-group")!.dependsOn?.map((d) => d.step),
    ["slot-identity-enable"],
  );
  assertEquals(steps.get("clean-marker")!.dependsOn?.map((d) => d.step), [
    "slot-units-enable",
  ]);
  const smoke = steps.get("smoke-slot-identity")!;
  assertEquals(smoke.dependsOn?.map((d) => d.step), ["ssh-smoke"]);
  assertEquals(smoke.task.inputs.argv!.slice(-2), [
    "is-enabled",
    "orbit-slot-identity.service",
  ]);
  assertEquals(steps.get("smoke-slot-units")!.dependsOn?.map((d) => d.step), [
    "smoke-slot-identity",
  ]);
  assertEquals(steps.get("seed")!.dependsOn?.map((d) => d.step), [
    "smoke-slot-units",
  ]);
});
