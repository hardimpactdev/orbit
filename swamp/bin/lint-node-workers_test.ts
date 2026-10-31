import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";

Deno.test("placement lint accepts matching nested selectors and refuses a foreign Node label", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (
      const directory of ["models", "workflows", "extensions/behaviors"]
    ) {
      await Deno.mkdir(`${root}/${directory}`, { recursive: true });
    }
    await Deno.writeTextFile(
      `${root}/extensions/behaviors/local.ts`,
      'export const behavior = { type: "@fixture/local" };',
    );
    const node = 'data.latest("root", "intent").attributes.source.node_id';
    const workflow = {
      target: '${{ "orbit-node-" + string(' + node + ") }}",
      labels: { node: "${{ string(" + node + ") }}" },
      jobs: [{
        steps: [{
          task: { type: "behavior", behaviorType: "@fixture/local" },
        }],
      }],
    };
    const check = () =>
      new Deno.Command(Deno.execPath(), {
        args: [
          "run",
          "--allow-read",
          import.meta.resolve("./lint-node-workers.ts"),
          root,
        ],
        stdout: "piped",
        stderr: "piped",
      }).output();
    await Deno.writeTextFile(
      `${root}/workflows/fixture.yaml`,
      stringify(workflow),
    );
    assertEquals((await check()).success, true);
    workflow.labels.node = "${{ string(inputs.node_id) }}";
    await Deno.writeTextFile(
      `${root}/workflows/fixture.yaml`,
      stringify(workflow),
    );
    const rejected = await check();
    assertEquals(rejected.success, false);
    assertStringIncludes(
      new TextDecoder().decode(rejected.stderr),
      "Node target and label disagree",
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("Node workflow imports cannot launch unguarded local processes or filesystem writes", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (const folder of ["models", "workflows", "extensions/behaviors"]) {
      await Deno.mkdir(root + "/" + folder, { recursive: true });
    }
    await Deno.writeTextFile(
      root + "/workflows/fixture.yaml",
      stringify({
        target: "orbit-node-1",
        labels: { node: "1" },
        jobs: [{
          name: "job",
          steps: [{
            name: "effect",
            task: {
              type: "behavior",
              behaviorType: "@fixture/local",
              methodName: "execute",
            },
          }],
        }],
      }),
    );
    for (
      const [effect, reason] of [
        [
          "new Deno.Command('/usr/bin/id').spawn()",
          "must use the shared command runner or guarded localCommand",
        ],
        [
          "Deno.open('/tmp/foreign', {write:true,create:true})",
          "filesystem effect needs the shared local refusal",
        ],
        [
          "Deno.create('/tmp/foreign')",
          "filesystem effect needs the shared local refusal",
        ],
        [
          "Deno.utime('/tmp/foreign',0,0)",
          "filesystem effect needs the shared local refusal",
        ],
        [
          "import('node:fs/promises')",
          "Node host APIs must use the shared command runner or local refusal",
        ],
        [
          "import('node:child_process')",
          "Node host APIs must use the shared command runner or local refusal",
        ],
        [
          "import('node:net')",
          "Node host APIs must use the shared command runner or local refusal",
        ],
        [
          "import('node:dgram')",
          "Node host APIs must use the shared command runner or local refusal",
        ],
        [
          "commandOutput()",
          "Raw Node command transport is restricted to reviewed adapters",
        ],
      ]
    ) {
      await Deno.writeTextFile(
        root + "/extensions/behaviors/local.ts",
        `export const behavior={type:"@fixture/local"}; export const execute=()=>${effect};`,
      );
      const result = await new Deno.Command(Deno.execPath(), {
        args: [
          "run",
          "--allow-read",
          import.meta.resolve("./lint-node-workers.ts"),
          root,
        ],
        stdout: "piped",
        stderr: "piped",
      }).output();
      assertEquals(result.success, false);
      assertStringIncludes(
        new TextDecoder().decode(result.stderr),
        reason,
      );
    }
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

const checker = import.meta.resolve("./lint-node-workers.ts");

async function check(
  task: Record<string, unknown>,
  labels: Record<string, string> = { node: "209" },
) {
  const root = await Deno.makeTempDir({ prefix: "node-worker-lint-" });
  try {
    for (const folder of ["extensions/behaviors", "models", "workflows"]) {
      await Deno.mkdir(`${root}/${folder}`, { recursive: true });
    }
    await Deno.writeTextFile(
      `${root}/workflows/workflow-test.yaml`,
      stringify({
        jobs: [{
          name: "test",
          steps: [{
            name: "action",
            target: "orbit-node-209",
            labels,
            task,
          }],
        }],
      }),
    );
    const result = await new Deno.Command(Deno.execPath(), {
      args: ["run", "--allow-read", checker, root],
      stdout: "piped",
      stderr: "piped",
    }).output();
    return {
      success: result.success,
      diagnostic: new TextDecoder().decode(result.stderr),
    };
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}

const shell = {
  type: "behavior",
  behaviorType: "command/shell",
  methodName: "execute",
  inputs: { run: "printf '%s\\n' public" },
};

Deno.test("reviewed inline native shell is accepted on the matching Node", async () => {
  assertEquals((await check(shell)).success, true);
});

Deno.test("native shell keeps exact Node placement checks", async () => {
  const result = await check(shell, { node: "9" });
  assertEquals(result.success, false);
  assertStringIncludes(result.diagnostic, "Node target and label disagree");
});

Deno.test("native shell exemption is limited to its execute method", async () => {
  const result = await check({ ...shell, methodName: "other" });
  assertEquals(result.success, false);
  assertStringIncludes(result.diagnostic, "command/shell.execute");
});

Deno.test("third-party behavior types remain refused", async () => {
  const result = await check({
    ...shell,
    behaviorType: "@third-party/shell",
  });
  assertEquals(result.success, false);
  assertStringIncludes(result.diagnostic, "not third-party extensions");
});

Deno.test("official SSH methods are accepted directly without a local adapter", async () => {
  for (const methodName of ["exec", "script"]) {
    const result = await check({
      type: "behavior",
      behaviorType: "@swamp/ssh",
      behaviorName: "node-light-ssh",
      methodName,
      inputs: { hosts: "name:vpn1", script: "id -u" },
    });
    assertEquals(result.success, true, result.diagnostic);
  }
});

Deno.test("official file and directory apply methods need no local adapter", async () => {
  for (
    const behaviorType of ["@swamp/cfgmgmt/directory", "@swamp/cfgmgmt/file"]
  ) {
    const result = await check({
      type: "behavior",
      behaviorType,
      behaviorName: "publication-fixture",
      methodName: "apply",
      inputs: {},
    });
    assertEquals(result.success, true, result.diagnostic);
  }
});
