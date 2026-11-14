/**
 * The `converge` digest of the nested-fleet refresh covers what a nested Node's base converge reads from `swamp/`
 * (swamp/ansible/fleet-converge-inputs.json, docs/reference/development-containers.md#what-a-refresh-skips). The inner Gateway's
 * own part, the base intent, is its digest (`orbit:node-converge-digest`). This test walks what the base converge runs and fails on a
 * file the list does not cover, so a new task file, template, module or import cannot be missed.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const repo = new URL("../../", import.meta.url);
const list = JSON.parse(Deno.readTextFileSync(new URL("swamp/ansible/fleet-converge-inputs.json", repo))) as {
  inputs: string[];
  clone_only: string[];
  cut: { file: string; include: string; guard: string }[];
};
const read = (path: string) => Deno.readTextFileSync(new URL(path, repo));
const exists = (path: string) => {
  try {
    Deno.statSync(new URL(path, repo));
    return true;
  } catch {
    return false;
  }
};
const covered = (path: string) => list.inputs.some((input) => input === path || (input.endsWith("/") && path.startsWith(input)));

/** The files a base converge runs: the workflow, the extension module graph, the playbooks and their task files. */
function reached() {
  const files = new Set<string>(["swamp/workflows/workflow-operation-node-converge-direct.yaml"]);
  const cutUsed = new Set<number>();
  // The files the behavior reads at run time ship by name in swamp/manifest.yaml; its imports are bundled with it.
  const shipped = new Set<string>();
  const ansible = "swamp/ansible/";
  // The workflow's own references: the ansible-input behavior and the playbooks it runs.
  const workflow = read("swamp/workflows/workflow-operation-node-converge-direct.yaml");
  assert(workflow.includes("behaviorType: '@nckrtl/ansible-input'"), "the converge writes its input with the ansible-input behavior");
  const todo: string[] = [];
  for (const playbook of new Set(workflow.match(/operation-node-converge[a-z-]*\.yml/g) ?? [])) todo.push(ansible + playbook);
  // Every file the behavior reads by name (`ctx.extensionFile`) and every module it imports.
  const extension = "swamp/extensions/behaviors/operations/ansible-input/";
  const modules = [extension + "write.ts"];
  while (modules.length > 0) {
    const module = modules.pop()!;
    if (files.has(module)) continue;
    files.add(module);
    const source = read(module);
    for (const [, relative] of source.matchAll(/^\s*(?:import|export)\b[^;]*?from\s+"(\.{1,2}\/[^"]+)"/gm)) {
      modules.push(new URL(relative, new URL(module, repo)).pathname.slice(repo.pathname.length));
    }
    for (const [, name] of source.matchAll(/extensionFile\(\s*["']([^"']+)["']\s*\)/g)) {
      // Only a clone intent reads these (`container.clone`); a Node converge intent has no `container`.
      if (list.clone_only.includes(name)) continue;
      shipped.add(name);
      if (name.endsWith(".ts")) modules.push("swamp/" + name);
      else files.add("swamp/" + name);
    }
  }
  // The play's own folder: its configuration and requirements, and every module in `library/` and `module_utils/`.
  for (const name of ["ansible.cfg", "requirements.txt", "requirements.yml", "library/", "module_utils/"]) files.add(ansible + name);
  const walk = (file: string, node: unknown) => {
    if (Array.isArray(node)) return node.forEach((item) => walk(file, item));
    if (node === null || typeof node !== "object") return;
    const task = node as Record<string, unknown>;
    for (const key of ["ansible.builtin.import_tasks", "ansible.builtin.include_tasks", "ansible.builtin.import_playbook", "import_tasks", "include_tasks"]) {
      if (!(key in task)) continue;
      const value = task[key];
      const target = typeof value === "string" ? value : (value as Record<string, string>).file;
      assert(typeof target === "string" && !target.includes("{{"), `${file}: a dynamic include cannot be followed: ${JSON.stringify(value)}`);
      const guard = String(task.when ?? task.loop ?? "");
      const cut = list.cut.findIndex((entry) => entry.file === file && entry.include === target);
      if (cut >= 0 && key.endsWith("include_tasks")) {
        assertEquals(guard, list.cut[cut].guard, `${file}: the guard of the cut include ${target} changed`);
        cutUsed.add(cut);
        continue;
      }
      const base = target.startsWith("tasks/") || key.endsWith("import_playbook") ? ansible : file.slice(0, file.lastIndexOf("/") + 1);
      todo.push(base + target);
    }
    for (const value of Object.values(task)) walk(file, value);
  };
  while (todo.length > 0) {
    const file = todo.pop()!;
    if (files.has(file)) continue;
    assert(exists(file), `a base converge reaches ${file}, which does not exist`);
    files.add(file);
    const text = read(file);
    walk(file, parse(text));
    // A template, a file or a script named in a task: `templates/x`, `files/x`, `src: x`.
    for (const [, name] of text.matchAll(/\b((?:templates|files|scripts)\/[A-Za-z0-9_.\-/]+)/g)) {
      if (exists(ansible + name)) files.add(ansible + name);
    }
    for (const [, name] of text.matchAll(/\bsrc:\s*["']?([A-Za-z0-9_.\-/]+)/g)) {
      for (const folder of ["templates/", "files/"]) if (exists(ansible + folder + name)) files.add(ansible + folder + name);
    }
  }
  for (const file of files) if (file.startsWith(ansible) && !file.endsWith("/")) shipped.add(file.slice("swamp/".length));
  return { files, cutUsed, shipped };
}

Deno.test("every file a nested Node's base converge reads is in the converge digest's list", () => {
  const { files } = reached();
  const missing = [...files].filter((file) => !covered(file)).sort();
  assertEquals(missing, [], "add these to swamp/ansible/fleet-converge-inputs.json");
});

Deno.test("the list holds only files a base converge reads, each exists, ships in the manifest, and each cut still guards its include", () => {
  const { files, cutUsed, shipped } = reached();
  for (const input of list.inputs) {
    assert(exists(input), `${input} is listed but does not exist`);
    assert([...files].some((file) => file === input || (input.endsWith("/") && file.startsWith(input))), `${input} is listed but no base converge reads it`);
  }
  // Each cut is an include whose guard is empty in every base intent (NodeDesiredConvergeInput::baseIntent: no roles, no tools, no
  // network); a base intent that gains one moves its own digest. A cut that no longer matches an include is stale.
  assertEquals([...cutUsed].sort(), list.cut.map((_, index) => index), "a cut in the list no longer matches an include");
  const manifest = read("swamp/manifest.yaml");
  for (const file of shipped) assert(manifest.includes(`  - ${file}\n`), `${file} is not in swamp/manifest.yaml`);
  assert(manifest.includes("  - operations/ansible-input/write.ts\n"), "the behavior is in swamp/manifest.yaml");
  assert(manifest.includes("  - ansible/fleet-converge-inputs.json\n"), "the list ships with the refresh");
});

Deno.test("the refresh hashes the listed inputs and the inner Gateway's base intent digests", () => {
  const refresh = read("swamp/ansible/tasks/instance-fleet-refresh.yml");
  assert(refresh.includes("fleet-converge-inputs.json"), "the refresh reads the list");
  assert(refresh.includes("orbit:node-converge-digest"), "the refresh reads the inner Gateway's base intent digests");
  assert(!/HEAD:swamp['"]/.test(refresh), "the refresh no longer hashes the whole of swamp");
});
