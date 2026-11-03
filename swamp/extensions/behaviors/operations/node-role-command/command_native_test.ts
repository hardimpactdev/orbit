/** Native prerequisite composition with the official SSH binary seam. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { roleAccountSteps, rolePackageSteps } from "./steps.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
Deno.test("role account is read with two commands before installing admitted packages", async () => {
  await using repo = await nativeRepo();
  await repo.copyBehaviors("operations/node-bootstrap", "operations/node-role-command");
  await repo.pull("@swamp/ssh");
  const audit = `${repo.dir}/calls.jsonl`;
  const ssh = await repo.fakeBinary(
    "fixture-ssh",
    `
import {shellSplit, logCall} from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
let argv = shellSplit(Deno.args.at(-1)!);
if (argv[0] === "sudo") argv = argv.slice(argv.indexOf("--") + 1);
logCall(${JSON.stringify(audit)}, argv);
if (argv[0] === "getent") console.log("orbit:x:1000:1000::/home/orbit:/bin/bash");
else if (argv[0] === "id") console.log("orbit");
`,
  );
  const execution = {
    name: "role-prerequisite-fixture",
    sshBinary: ssh,
    transport: {
      kind: "ssh",
      user: "orbit",
      identityFile: "/fixture/key",
      hostKeys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
    },
    hosts: [{ name: "target", address: "dev.invalid" }],
  };
  const steps = [
    ...roleAccountSteps("account", "inputs.user", execution),
    ...rolePackageSteps("packages", "inputs.prerequisites", execution, "account"),
  ];
  for (const step of steps) {
    assertEquals(step.labels, { role: "swamp" });
    delete step.labels;
  }
  await Deno.writeTextFile(
    `${repo.dir}/workflows/workflow-role-prerequisite-fixture.yaml`,
    JSON.stringify({
      id: "9444280d-02bc-4cfa-b3b1-9538b840b3de",
      name: "role-prerequisite-fixture",
      inputs: { type: "object" },
      jobs: [{ name: "role", steps }],
    }),
  );
  await repo.cli(["workflow", "validate", "role-prerequisite-fixture"]);
  const { run, history } = await repo.runWorkflow("role-prerequisite-fixture", {
    user: "orbit",
    prerequisites: { packages: ["openssl"], releases: ["resolute"] },
  });
  assertEquals(run.status, "succeeded");
  assertEquals(history.jobs[0].steps.find((s) => s.name === "account")!.outputs, {
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
  });
  const calls = readCalls<string[]>(audit);
  assertEquals(calls.slice(0, 2), [["getent", "passwd", "orbit"], ["id", "-gn", "orbit"]]);
  assertEquals(calls[2], ["/usr/bin/grep", "-qxE", "VERSION_CODENAME=(resolute)", "/etc/os-release"]);
  assertEquals(calls[3].slice(-3), ["-o", "APT::Update::Error-Mode=any", "update"]);
  assertEquals(calls[4].slice(-6), ["--no-remove", "install", "--yes", "--no-install-recommends", "--", "openssl"]);
});
