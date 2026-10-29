import {
  assertEquals,
  assertNotEquals,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { compileFamily, digest } from "./compiler.ts";
import { nativeEnvironment, nativeSchema } from "../bin/compile-family.ts";

const binary = Deno.env.get("SWAMP_BIN");
if (!binary) throw new Error("Set SWAMP_BIN to the pinned Swamp executable");
const schema = await nativeSchema(binary);
const fixture = JSON.parse(
  await Deno.readTextFile("tests/fixtures/family/example.json"),
);
const example = () => structuredClone(fixture);

Deno.test("build binds the normalized native workflow and complete contract", async () => {
  const compiled = await compileFamily(example(), schema);
  assertEquals(compiled.workflow.id, fixture.workflow.id);
  assertEquals(
    compiled.contract.workflow.digest,
    await digest(compiled.workflow),
  );
  const reordered = Object.fromEntries(Object.entries(example()).reverse());
  assertEquals(await compileFamily(reordered, schema), compiled);
  for (const field of ["health", "secrets", "commands", "workflow"]) {
    const changed = example();
    if (field === "health") changed.health.stalled_seconds++;
    if (field === "secrets") {
      changed.secrets.access = {
        vault: "orbit-node-access",
        key: "admitted.node_key",
        purpose: "node-access",
      };
    }
    if (field === "commands") {
      changed.commands.inspect = {
        transport: "orbit-agent",
        names: ["apt.package.version"],
      };
    }
    if (field === "workflow") {
      changed.workflow.jobs[0].steps[0].task.expr = "false";
    }
    assertNotEquals(
      (await compileFamily(changed, schema)).contract.spec_digest,
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
    await assertRejects(() => compileFamily(spec, schema));
  });
}

Deno.test("compiled artifact validates and executes in the pinned native runtime", async () => {
  const compiled = await compileFamily(example(), schema);
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
