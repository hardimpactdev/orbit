import { assertEquals } from "jsr:@std/assert@1.0.13";
const lint = new URL("../bin/lint-node-workers.ts", import.meta.url).pathname;
async function check(
  placement: string,
  type = "@fixture/local",
  source = 'export const behavior = {type: "@fixture/local"};',
) {
  const root = await Deno.makeTempDir();
  try {
    for (const dir of ["extensions/behaviors", "models", "workflows"]) {
      await Deno.mkdir(`${root}/${dir}`, { recursive: true });
    }
    await Deno.writeTextFile(`${root}/extensions/behaviors/local.ts`, source);
    await Deno.writeTextFile(
      `${root}/workflows/node.yaml`,
      `name: fixture\n${placement}\njobs:\n  - name: node\n    steps:\n      - name: local\n        task:\n          type: behavior\n          behaviorType: '${type}'\n`,
    );
    const result = await new Deno.Command(Deno.execPath(), {
      args: ["run", "--allow-read", lint, root],
      stdout: "null",
      stderr: "null",
    }).output();
    return result.success;
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}
Deno.test("Node placement lint refuses missing or mismatched selectors and third-party execution", async () => {
  const both = 'target: orbit-node-9\nlabels: {node: "9"}';
  assertEquals(await check(both), true);
  assertEquals(await check("target: orbit-node-9"), false);
  assertEquals(await check('labels: {node: "9"}'), false);
  assertEquals(
    await check('target: orbit-node-9\nlabels: {node: "13"}'),
    false,
  );
  assertEquals(await check(both, "@swamp/foreign"), false);
  assertEquals(
    await check(
      both,
      "@fixture/local",
      'import "https://example.test/foreign.ts"; export const behavior = {type: "@fixture/local"};',
    ),
    false,
  );
  assertEquals(
    await check(
      both,
      "@fixture/local",
      'import "../../.swamp/pulled-extensions/foreign.ts"; export const behavior = {type: "@fixture/local"};',
    ),
    false,
  );
});

Deno.test("Node library exception accepts only the exact pinned SSH operations source", async () => {
  const both = 'target: orbit-node-9\nlabels: {node: "9"}';
  const pinned =
    "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts";
  const source = (url: string) =>
    `import "${url}"; export const behavior = {type: "@fixture/local"};`;
  assertEquals(await check(both, "@fixture/local", source(pinned)), true);
  assertEquals(
    await check(
      both,
      "@fixture/local",
      source(
        pinned.replace("d6f7afdcd6d04a63fdbc6ed56c403832770f67a6", "main"),
      ),
    ),
    false,
  );
  assertEquals(
    await check(
      both,
      "@fixture/local",
      source(pinned.replace("operations.ts", "runner.ts")),
    ),
    false,
  );
  assertEquals(
    await check(
      both,
      "@fixture/local",
      source(
        pinned.replace(
          "d6f7afdcd6d04a63fdbc6ed56c403832770f67a6",
          "a".repeat(40),
        ),
      ),
    ),
    false,
  );
});
