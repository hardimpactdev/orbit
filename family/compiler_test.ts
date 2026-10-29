import {
  assertEquals,
  assertNotEquals,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { compileFamily, digest } from "./compiler.ts";
import { catalogueDigest } from "./catalogue.ts";
import { nativeEnvironment, nativeSchema } from "../bin/compile-family.ts";

const binary = Deno.env.get("SWAMP_BIN");
if (!binary) throw new Error("Set SWAMP_BIN to the pinned Swamp executable");
const schema = await nativeSchema(binary);
const fixture = JSON.parse(
  await Deno.readTextFile("tests/fixtures/family/example.json"),
);
const example = () => structuredClone(fixture);
const catalogueBytes = await Deno.readTextFile("family/catalogue.json");
const compile = (spec: unknown) => compileFamily(spec, schema, catalogueBytes);

Deno.test("build binds the normalized native workflow and complete contract", async () => {
  const compiled = await compile(example());
  assertEquals(compiled.workflow.id, fixture.workflow.id);
  assertEquals(
    compiled.contract.workflow.digest,
    await digest(compiled.workflow),
  );
  const reordered = Object.fromEntries(Object.entries(example()).reverse());
  assertEquals(await compile(reordered), compiled);
  for (const field of ["health", "secrets", "workflow"]) {
    const changed = example();
    if (field === "health") changed.health.stalled_seconds++;
    if (field === "secrets") {
      changed.secrets.access = {
        vault: "orbit-node-access",
        key: "admitted.node_key",
        purpose: "node-access",
      };
    }
    if (field === "workflow") {
      changed.workflow.jobs[0].steps[0].task.expr = "false";
    }
    assertNotEquals(
      (await compile(changed)).contract.spec_digest,
      compiled.contract.spec_digest,
    );
  }
});

const invalid: [string, (spec: ReturnType<typeof example>) => void][] = [
  ["unsupported version", (s) => s.schema = "orbit.family/v2"],
  ["unknown field", (s) => s.executor_proxy = true],
  [
    "unknown native field",
    (s) => s.workflow.jobs[0].steps[0].task.exprr = "true",
  ],
  ["native task type", (s) => s.workflow.jobs[0].steps[0].task.type = "shell"],
  ["unbound target Node", (s) => s.target.node = "inputs.node_id"],
  ["unbound owner", (s) => s.target.owner = "inputs.owner_id"],
  ["open input", (s) => s.input.additionalProperties = true],
  ["invalid JSON Schema", (s) => s.input.properties.action.type = "strng"],
  ["unknown schema keyword", (s) => s.input.required_fields = ["action"]],
  ["open result", (s) => s.result.schema.additionalProperties = true],
  ["action not required", (s) => s.input.required = []],
  ["action mismatch", (s) => s.actions.push("remove")],
  ["duplicate receipt key", (s) => s.steps.push(s.steps[0])],
  [
    "duplicate native binding",
    (s) => s.steps.push({ ...s.steps[0], key: "second" }),
  ],
  ["missing step", (s) => s.steps[0].step = "missing"],
  ["undeclared command", (s) => s.steps[0].command = "missing"],
  ["unkeyed effect catalogue", (s) => s.steps[0].effect = "keyed"],
  ["completion spoof", (s) => s.done_when.receipts = ["missing"]],
  [
    "optional completion",
    (s) => s.workflow.jobs[0].steps[0].allowFailure = true,
  ],
  ["missing cancellation boundary", (s) => s.cancel.boundary = "missing"],
  ["oversize output", (s) => s.result.max_bytes = 1_048_577],
  ["inline secret", (s) => s.secrets.access = { value: "do-not-store" }],
  [
    "wrong vault",
    (s) =>
      s.secrets.access = {
        vault: "orbit-control-inputs",
        key: "admitted.key",
        purpose: "node-access",
      },
  ],
  [
    "caller secret selector",
    (s) =>
      s.secrets.access = {
        vault: "orbit-node-access",
        key: "inputs.key",
        purpose: "node-access",
      },
  ],
  [
    "same Node child",
    (s) =>
      s.children.work = {
        kind: "example.child",
        node: s.target.node,
        input: {},
      },
  ],
  [
    "missing dependency",
    (s) =>
      s.workflow.jobs[0].steps[0].dependsOn = [{
        step: "missing",
        condition: { type: "succeeded" },
      }],
  ],
  [
    "cyclic dependency",
    (s) =>
      s.workflow.jobs[0].steps[0].dependsOn = [{
        step: "check",
        condition: { type: "succeeded" },
      }],
  ],
];
for (const [name, mutate] of invalid) {
  Deno.test(`compiler refuses ${name}`, async () => {
    const spec = example();
    mutate(spec);
    await assertRejects(() => compile(spec));
  });
}

Deno.test("compiled artifact validates and executes in the pinned native runtime", async () => {
  const compiled = await compile(example());
  const repo = await Deno.makeTempDir({ prefix: "family-compiler-native-" });
  try {
    await Deno.copyFile(".swamp.yaml", `${repo}/.swamp.yaml`);
    await Deno.mkdir(`${repo}/workflows`);
    await Deno.writeTextFile(
      `${repo}/workflows/workflow-example.yaml`,
      stringify(compiled.workflow),
    );
    for (const command of ["validate", "run"]) {
      const output = await new Deno.Command(binary, {
        args: ["workflow", command, "family-compiler-fixture", "--json"],
        cwd: repo,
        clearEnv: true,
        env: nativeEnvironment(),
        stdout: "piped",
        stderr: "piped",
      }).output();
      assertEquals(
        output.code,
        0,
        new TextDecoder().decode(output.stdout) +
          new TextDecoder().decode(output.stderr),
      );
    }
  } finally {
    await Deno.remove(repo, { recursive: true });
  }
});

Deno.test("catalogue closes command names, projection hooks and invariant hooks", async () => {
  for (
    const mutate of [
      (s: ReturnType<typeof example>) => s.catalogue = "0".repeat(64),
      (s: ReturnType<typeof example>) => s.projection = "caller.arbitrary",
      (s: ReturnType<typeof example>) => s.invariants = ["caller.arbitrary"],
      (s: ReturnType<typeof example>) =>
        s.commands.inspect = {
          transport: "orbit-agent",
          names: ["valid.looking.typo"],
        },
      (s: ReturnType<typeof example>) =>
        s.commands.inspect = {
          transport: "gateway",
          names: ["tool.apt-version"],
        },
    ]
  ) {
    const spec = example();
    mutate(spec);
    await assertRejects(() => compile(spec));
  }
});

// Test-only binding. Production has no command method until its typed adapter
// implementation and matching Gateway catalogue registration land together.
async function commandExample(transport = "orbit-agent") {
  const catalogue = JSON.parse(catalogueBytes);
  catalogue.native_methods.push({
    behavior_type: "@test/fixed-command",
    method: "execute",
    transport,
    command_input: "command",
    keyed: true,
  });
  const bytes = JSON.stringify(catalogue);
  const spec = example();
  spec.catalogue = await catalogueDigest(bytes);
  spec.commands.inspect = {
    transport,
    names: [
      transport === "gateway" ? "certificate.sign-leaf" : "tool.apt-version",
    ],
  };
  spec.steps[0].command = "inspect";
  spec.steps[0].effect = transport === "gateway" ? "keyed" : "read";
  spec.workflow.jobs[0].steps[0].task = {
    type: "behavior",
    behaviorType: "@test/fixed-command",
    behaviorName: "fixed-command",
    methodName: "execute",
    inputs: { command: spec.commands.inspect.names[0] },
  };
  return { spec, bytes };
}

Deno.test("native bindings require exact registered method and literal command", async () => {
  for (const transport of ["orbit-agent", "gateway"]) {
    const { spec, bytes } = await commandExample(transport);
    assertEquals(
      (await compileFamily(spec, schema, bytes)).contract.catalogue,
      spec.catalogue,
    );
    for (
      const mutate of [
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps[0].task.methodName = "exec",
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps[0].task.behaviorType = "command/shell",
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps[0].task.inputs.command = "tool.apt-install",
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps[0].task.inputs.command =
            "${{ inputs.command }}",
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps[0].task.inputs = "${{ inputs }}",
        (s: ReturnType<typeof example>) => delete s.steps[0].command,
        (s: ReturnType<typeof example>) =>
          s.commands.inspect.names.push("tool.apt-install"),
        (s: ReturnType<typeof example>) =>
          s.workflow.jobs[0].steps.push({
            name: "hidden",
            task: { type: "assert", expr: "true", message: "hidden" },
          }),
      ]
    ) {
      const changed = structuredClone(spec);
      mutate(changed);
      await assertRejects(() => compileFamily(changed, schema, bytes));
    }
  }
});

Deno.test("native methods cannot downgrade mutating commands to reads", async () => {
  const { spec, bytes } = await commandExample();
  spec.commands.inspect.names = ["tool.apt-install"];
  spec.workflow.jobs[0].steps[0].task.inputs.command = "tool.apt-install";
  await assertRejects(
    () => compileFamily(spec, schema, bytes),
    Error,
    "requires a keyed effect",
  );
  spec.steps[0].effect = "keyed";
  await compileFamily(spec, schema, bytes);
  const catalogue = JSON.parse(bytes);
  catalogue.native_methods[0].keyed = false;
  const changed = JSON.stringify(catalogue);
  spec.catalogue = await catalogueDigest(changed);
  await assertRejects(
    () => compileFamily(spec, schema, changed),
    Error,
    "does not support keyed",
  );
});

Deno.test("production catalogue refuses command methods until their implementation lands", async () => {
  const { spec } = await commandExample();
  spec.catalogue = fixture.catalogue;
  await assertRejects(
    () => compile(spec),
    Error,
    "Unregistered native command method",
  );
});

Deno.test("registered literal command binding reaches the matching native method", async () => {
  const { spec, bytes } = await commandExample();
  const compiled = await compileFamily(spec, schema, bytes);
  const repo = await Deno.makeTempDir({ prefix: "family-command-native-" });
  try {
    await Deno.copyFile(".swamp.yaml", `${repo}/.swamp.yaml`);
    await Deno.mkdir(`${repo}/workflows`);
    await Deno.mkdir(`${repo}/extensions/behaviors`, { recursive: true });
    await Deno.writeTextFile(
      `${repo}/workflows/workflow-command.yaml`,
      stringify(compiled.workflow),
    );
    await Deno.writeTextFile(
      `${repo}/extensions/behaviors/fixed-command.ts`,
      `
import {z} from "npm:zod@4.4.3";
export const model = {
  type: "@test/fixed-command", version: "2026.10.29.1",
  globalArguments: z.object({}), resources: {},
  methods: {execute: {
    description: "Test-only typed command, no Node transport",
    arguments: z.object({command: z.literal("tool.apt-version")}),
    async execute(args: {command: string}) {
      await Deno.writeTextFile("bound-command.json", JSON.stringify(args));
      return {dataHandles: []};
    },
  }},
};
`,
    );
    for (const command of ["validate", "run"]) {
      const output = await new Deno.Command(binary, {
        args: ["workflow", command, "family-compiler-fixture", "--json"],
        cwd: repo,
        clearEnv: true,
        env: nativeEnvironment(),
        stdout: "piped",
        stderr: "piped",
      }).output();
      assertEquals(
        output.code,
        0,
        new TextDecoder().decode(output.stdout) +
          new TextDecoder().decode(output.stderr),
      );
    }
    assertEquals(
      JSON.parse(await Deno.readTextFile(`${repo}/bound-command.json`)),
      { command: "tool.apt-version" },
    );
  } finally {
    await Deno.remove(repo, { recursive: true });
  }
});
