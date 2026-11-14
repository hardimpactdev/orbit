/**
 * The `converge` digest of the nested-fleet refresh covers what a nested Node's base converge reads from `swamp/`
 * (swamp/ansible/fleet-converge-inputs.json, docs/reference/development-containers.md#what-a-refresh-skips). The inner Gateway's
 * own part, the base intent, is its digest (`orbit:node-converge-digest`). This test walks what the base converge runs and fails on a
 * file the list does not cover, so a new task file, template, module or import cannot be missed.
 */
import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const repo = new URL("../../", import.meta.url);
const list = JSON.parse(Deno.readTextFileSync(new URL("swamp/ansible/fleet-converge-inputs.json", repo))) as {
  inputs: string[];
  cut_imports: { file: string; import: string; methods: string[]; guard?: string }[];
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
/** The `@nckrtl/ansible-input` methods the converge workflow calls. */
const calledMethods = () =>
  [...read("swamp/workflows/workflow-operation-node-converge-direct.yaml")
    .matchAll(/behaviorType: '@nckrtl\/ansible-input'[\s\S]*?methodName: ([A-Za-z]+)/g)].map((match) => match[1]);

/** The method blocks of a behavior file: `        name: {` inside `    methods: {`, as line ranges. */
function methodBlocks(lines: string[]) {
  const blocks: { name: string; from: number; to: number }[] = [];
  const start = lines.findIndex((line) => line === "    methods: {");
  assert(start >= 0, "the behavior file has a methods block");
  let index = start + 1;
  for (; index < lines.length && !/^ {4}\},?$/.test(lines[index]); index++) {
    const match = lines[index].match(/^ {8}([A-Za-z]+): \{$/);
    if (match) blocks.push({ name: match[1], from: index, to: lines.length });
    if (match && blocks.length > 1) blocks[blocks.length - 2].to = index;
  }
  // The last method ends where `methods` closes; nothing after it counts as inside a method.
  if (blocks.length > 0) blocks[blocks.length - 1].to = index;
  return blocks;
}

/**
 * A cut import holds when every use of its imported names in the behavior file lies in one of its `methods`, the converge workflow
 * calls none of those methods or every use sits on a line with the `guard`, and the import line is still there.
 */
function checkCutImport(cut: (typeof list.cut_imports)[number]) {
  const source = read(cut.file);
  const lines = source.split("\n");
  const path = cut.import.replaceAll(".", "\\.");
  const line = lines.findIndex((text) => new RegExp(`^import \\{([^}]*)\\} from "${path}";$`).test(text));
  assert(line >= 0, `${cut.file}: the cut import ${cut.import} is no longer a named import`);
  // One named import of the path, and no other import or re-export of it, so every name it brings in is checked below.
  assertEquals(lines.filter((text) => new RegExp(`from\\s+["']${path}["']`).test(text)).length, 1, `${cut.file}: ${cut.import} is imported or re-exported more than once`);
  const names = lines[line].match(/\{([^}]*)\}/)![1].split(",").map((name) => name.trim()).filter(Boolean);
  const blocks = methodBlocks(lines);
  const called = calledMethods();
  if (cut.guard === undefined) {
    const overlap = cut.methods.filter((method) => called.includes(method));
    assertEquals(overlap, [], `the converge workflow now calls ${overlap.join(", ")}: ${cut.import} is no longer a cut import`);
  }
  let uses = 0;
  lines.forEach((text, index) => {
    if (index === line || /^ {8}[A-Za-z]+: \{$/.test(text)) return;
    for (const name of names) {
      if (!new RegExp(`\\b${name}\\b`).test(text)) continue;
      uses++;
      const block = blocks.find((entry) => index > entry.from && index < entry.to);
      assert(block && cut.methods.includes(block.name), `${cut.file}:${index + 1}: ${name} is used outside ${cut.methods.join(", ")}`);
      // The use follows the guard's `?` directly, so it is the guarded branch, not the other one.
      if (cut.guard !== undefined) {
        const guarded = new RegExp(`${cut.guard.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*(?:await\\s+)?${name}\\(`);
        assert(guarded.test(text), `${cut.file}:${index + 1}: ${name} is used without the guard ${cut.guard}`);
      }
    }
  });
  assert(uses > 0, `${cut.file}: nothing uses the cut import ${cut.import}`);
}

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
      // A cut import: used only by methods a converge never runs, or only under a guard a base intent never meets.
      if (list.cut_imports.some((cut) => cut.file === module && cut.import === relative)) continue;
      modules.push(new URL(relative, new URL(module, repo)).pathname.slice(repo.pathname.length));
    }
    for (const [, name] of source.matchAll(/extensionFile\(\s*["']([^"']+)["']\s*\)/g)) {
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

Deno.test("each cut import is used only by methods a converge never runs, or only under its guard", () => {
  assert(list.cut_imports.length > 0);
  for (const cut of list.cut_imports) checkCutImport(cut);
  assertEquals(calledMethods().sort(), ["convergeInput", "convergeResult", "write"], "the converge workflow's ansible-input methods");
});

Deno.test("a cut import fails when the converge workflow calls its method or a use leaves its guard", () => {
  const cut = list.cut_imports.find((entry) => entry.guard === undefined)!;
  assertThrows(() => checkCutImport({ ...cut, methods: [...cut.methods, "convergeResult"] }), Error, "the converge workflow now calls convergeResult");
  const guarded = list.cut_imports.find((entry) => entry.guard !== undefined)!;
  assertThrows(() => checkCutImport({ ...guarded, guard: guarded.guard + " never" }), Error, "without the guard");
  assertThrows(() => checkCutImport({ ...guarded, methods: ["convergeInput"] }), Error, "is used outside convergeInput");
});

Deno.test("the converge workflow's Node plays lock a behavior per Node, so the refresh starts the inner Nodes side by side", () => {
  const flow = parse(read("swamp/workflows/workflow-operation-node-converge-direct.yaml")) as {
    jobs: { steps: { name: string; task: { behaviorName: string } }[] }[];
  };
  const names = Object.fromEntries(flow.jobs.flatMap((job) => job.steps).map((step) => [step.name, step.task.behaviorName]));
  // The seven Ansible plays reach the Node and can hold their lock for many seconds: `<family>-<node_id>` (docs/cli/README.md).
  const plays = ["Converge input", "Converge agent", "Converge software", "Converge roles", "Converge tools", "Converge network", "Check the Node"];
  for (const step of plays) {
    assert(/^\$\{\{ "node-converge-converge-[a-z]+-" \+ string\(inputs\.node_id\) \}\}$/.test(names[step]), `${step}: ${names[step]}`);
  }
  assertEquals(new Set(plays.map((step) => names[step])).size, plays.length, "each play has its own family");
  // The local steps of a second or less keep one name: the secret writes, the ansible-input steps and the result, which the Gateway
  // reads by name and version.
  for (const [step, name] of Object.entries(names)) if (!plays.includes(step)) assert(!name.includes("inputs.node_id"), `${step}: ${name}`);
});

Deno.test("the refresh hashes the listed inputs and the inner Gateway's base intent digests", () => {
  const refresh = read("swamp/ansible/tasks/instance-fleet-refresh.yml");
  assert(refresh.includes("fleet-converge-inputs.json"), "the refresh reads the list");
  assert(refresh.includes("orbit:node-converge-digest"), "the refresh reads the inner Gateway's base intent digests");
  // The whole of `swamp` is the grants' input only; the converge digest is one per Node from the listed files' IDs.
  assert(refresh.includes("fleet_swamp_ids: \"{{ fleet_ids[2:-1] }}\""), "the converge digest takes the listed files' IDs, not the whole of swamp");
  assert(refresh.includes("'converge_' ~ (role | replace('-', '_'))"), "the refresh keeps one converge digest per Node");
});
