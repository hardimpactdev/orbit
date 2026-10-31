import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fileURLToPath } from "node:url";

const lint = fileURLToPath(
  new URL("../bin/lint-node-workers.ts", import.meta.url),
);
async function check(task: string, label = '"9"') {
  const root = await Deno.makeTempDir({ prefix: "node-code-lint-" });
  try {
    for (const name of ["extensions/behaviors", "models", "workflows"]) {
      await Deno.mkdir(`${root}/${name}`, { recursive: true });
    }
    await Deno.writeTextFile(
      `${root}/workflows/fixture.yaml`,
      `
name: fixture
target: orbit-node-9
labels:
  node: ${label}
jobs:
  - name: root
    steps:
      - name: proof
        task:
${task}
`,
    );
    const result = await new Deno.Command(Deno.execPath(), {
      args: ["run", "--allow-read", lint, root],
      stdout: "piped",
      stderr: "piped",
    }).output();
    return {
      code: result.code,
      error: new TextDecoder().decode(result.stderr),
    };
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}

Deno.test("native assertion inherits exact Node placement without extension code", async () => {
  assertEquals(
    (await check('          type: assert\n          expr: "true"')).code,
    0,
  );
});

Deno.test("Node placement still refuses an unreviewed behavior", async () => {
  const result = await check(
    '          type: behavior\n          behaviorType: "@foreign/action"',
  );
  assertEquals(result.code, 1);
  assertStringIncludes(result.error, "only reviewed local behaviors");
});

Deno.test("native assertions still require matching Node identity", async () => {
  const result = await check(
    '          type: assert\n          expr: "true"',
    '"14"',
  );
  assertEquals(result.code, 1);
  assertStringIncludes(result.error, "Node target and label disagree");
});

Deno.test("Node assertions cannot execute unreviewed model methods", async () => {
  const result = await check(`          type: assert
          expr: model.method("foreign", "execute").ok`);
  assertEquals(result.code, 1);
});

Deno.test("Node assertion messages cannot interpolate executable expressions", async () => {
  const result = await check(`          type: assert
          expr: "true"
          message: '\${{ model.method("foreign", "execute").ok }}'`);
  assertEquals(result.code, 1);
});
