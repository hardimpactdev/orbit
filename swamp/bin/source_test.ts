import { assert, assertEquals, assertFalse, assertMatch } from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT, command } from "./gate-test-support.ts";
type Definition = { id: string; name: string; globalArguments: unknown; methods: unknown; queueTimeout: number };

const yaml = (path: string) => parse(Deno.readTextFileSync(path)) as Definition;
Deno.test("model IDs are unique and project definitions contain public metadata", async () => {
  const ids = new Set(), projects = [];
  for await (const path of expandGlob(`${ROOT}/models/**/*.yaml`)) {
    const model = yaml(path.path), id = model.id.toLowerCase();
    assertMatch(id, /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/);
    assertFalse(ids.has(id), path.path);
    ids.add(id);
    if (path.path.includes("/@nckrtl/project-")) {
      projects.push(model.name);
      assertEquals(model.globalArguments, {});
      assertEquals(model.methods, {});
    }
  }
  assertEquals(projects.length, 19);
});
Deno.test("workflow queue bounds have measured per-kind budgets", async () => {
  for await (const path of expandGlob(`${ROOT}/workflows/*.yaml`)) {
    const workflow = yaml(path.path);
    const budgets: Record<string, number> = {
      "operation-task-vm-sweep": 60,
      "operation-task-vm-dispatch": 60,
      "operation-instance-create": 10,
      "proof-reaper": 300,
      "task-command-run": 300,
      "swamp-credential-monitor": 120,
      "operation-gateway-swamp-load": 120,
      "swamp-datastore-watch": 120,
    };
    if (workflow.name in budgets) assertEquals(workflow.queueTimeout, budgets[workflow.name]);
    else if ("queueTimeout" in workflow) assert([30, 60].includes(workflow.queueTimeout), path.name);
  }
});
Deno.test("runtime credentials and generated records are ignored", async () => {
  const paths = [
    "models/swamp/enrollment-token/example.yaml",
    "vaults/local_encryption/example.yaml",
    "bin/__pycache__/serve-command.cpython-313.pyc",
  ];
  const result = await command(["git", "check-ignore", "--no-index", ...paths], ROOT);
  assertEquals(new Set(result.stdout.trim().split("\n")), new Set(paths));
});
