import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";

Deno.test("placement lint accepts matching nested selectors and refuses a foreign Node label", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (const directory of ["models", "workflows", "extensions/behaviors"]) {
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
