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

Deno.test("builtin shells require a proven physical Node selector, never a logical proxy", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (const folder of ["models", "workflows", "extensions/behaviors"]) {
      await Deno.mkdir(root + "/" + folder, { recursive: true });
    }
    const workflow = {
      inputs: {
        required: ["node_id"],
        properties: {
          node_id: { type: "integer", not: { enum: [1, 18] } },
        },
      },
      target: '${{ "orbit-node-" + string(inputs.node_id) }}',
      labels: { node: "${{ string(inputs.node_id) }}" },
      jobs: [{
        steps: [{
          task: { type: "behavior", behaviorType: "command/shell" },
        }],
      }],
    };
    for (
      const [target, excluded, allowed] of [
        [workflow.target, [1, 18], true],
        [workflow.target, [1], false],
        [workflow.target, [18], false],
        [workflow.target, [], false],
        ["orbit-node-1", [1, 18], false],
        ["orbit-node-18", [1, 18], false],
        ["orbit-node-14", [], true],
      ] as const
    ) {
      workflow.target = target;
      workflow.labels.node = target.startsWith("orbit-node-")
        ? target.slice(11)
        : "${{ string(inputs.node_id) }}";
      workflow.inputs.properties.node_id.not.enum = [...excluded];
      await Deno.writeTextFile(
        root + "/workflows/fixture.yaml",
        stringify(workflow),
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
      assertEquals(
        result.success,
        allowed,
        new TextDecoder().decode(result.stderr),
      );
    }
    // A conjunction inside not can make the whole negation ineffective.
    workflow.target = '${{ "orbit-node-" + string(inputs.node_id) }}';
    workflow.labels.node = "${{ string(inputs.node_id) }}";
    Object.assign(workflow.inputs.properties.node_id.not, {
      enum: [1, 18],
      type: "string",
    });
    await Deno.writeTextFile(
      root + "/workflows/fixture.yaml",
      stringify(workflow),
    );
    const unsafeNegation = await new Deno.Command(Deno.execPath(), {
      args: [
        "run",
        "--allow-read",
        import.meta.resolve("./lint-node-workers.ts"),
        root,
      ],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(unsafeNegation.success, false);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("logical proxy builtins require the shared asset, exact envelope and safe declared environment", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (const folder of ["models", "workflows", "extensions/behaviors"]) {
      await Deno.mkdir(root + "/" + folder, { recursive: true });
    }
    await Deno.writeTextFile(
      root + "/extensions/behaviors/assets.ts",
      'export const behavior={type:"@nckrtl/node-command-assets"};',
    );
    const placement = { target: "orbit-node-1", labels: { node: "1" } };
    const assets = {
      name: "assets",
      ...placement,
      task: {
        type: "behavior",
        behaviorType: "@nckrtl/node-command-assets",
        methodName: "read",
      },
    };
    const action = {
      name: "action",
      ...placement,
      dependsOn: [{ step: "assets", condition: { type: "succeeded" } }],
      task: {
        type: "behavior",
        behaviorType: "command/shell",
        inputs: {
          run:
            '/usr/bin/python3 -I "${{ steps.assets.outputs.runner }}" --node-id "$ORBIT_EFFECT_NODE_ID" --env ORBIT_PUBLIC <<\'ORBIT_ACTION\'\nprintf "%s" "$ORBIT_PUBLIC"\nORBIT_ACTION\n',
          env: {
            ORBIT_EFFECT_NODE_ID: "1",
            ORBIT_PUBLIC: "${{ inputs.value }}",
          },
        },
      },
    };
    const workflow = {
      jobs: [{
        name: "job",
        steps: [assets, action] as [typeof assets, typeof action],
      }],
    };
    const check = async (
      mutate: (w: typeof workflow) => void,
      allowed: boolean,
    ) => {
      const value = structuredClone(workflow);
      mutate(value);
      await Deno.writeTextFile(
        root + "/workflows/fixture.yaml",
        stringify(value),
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
      assertEquals(
        result.success,
        allowed,
        new TextDecoder().decode(result.stderr),
      );
    };
    await check(() => {}, true);
    await check((w) => {
      w.jobs[0].steps[1].task.inputs!.run += "touch /tmp/local\n";
    }, false);
    await check((w) => {
      w.jobs[0].steps[1].task.inputs!.run = w.jobs[0].steps[1].task
        .inputs!.run.replace("printf", "${{ inputs.attack }}\nprintf");
    }, false);
    await check((w) => {
      w.jobs[0].steps[1].dependsOn = [];
    }, false);
    await check((w) => {
      w.jobs[0].steps[0].target = "orbit-node-18";
    }, false);
    await check((w) => {
      w.jobs[0].steps[0].task.methodName = "execute";
    }, false);
    await check((w) => {
      w.jobs[0].steps[1].task.inputs!.env.ORBIT_EFFECT_NODE_ID = "18";
    }, false);
    for (
      const name of ["LD_PRELOAD", "BASH_ENV", "PYTHONPATH", "UNDECLARED"]
    ) {
      await check((w) => {
        Object.assign(w.jobs[0].steps[1].task.inputs!.env, {
          [name]: "${{ inputs.value }}",
        });
      }, false);
    }
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
