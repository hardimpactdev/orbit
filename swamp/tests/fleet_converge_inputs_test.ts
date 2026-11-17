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
  cut_imports: { file: string; import: string; methods: string[] }[];
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
 * calls none of those methods, and the import line is still there.
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
  const overlap = cut.methods.filter((method) => called.includes(method));
  assertEquals(overlap, [], `the converge workflow now calls ${overlap.join(", ")}: ${cut.import} is no longer a cut import`);
  let uses = 0;
  lines.forEach((text, index) => {
    if (index === line || /^ {8}[A-Za-z]+: \{$/.test(text)) return;
    for (const name of names) {
      if (!new RegExp(`\\b${name}\\b`).test(text)) continue;
      uses++;
      const block = blocks.find((entry) => index > entry.from && index < entry.to);
      assert(block && cut.methods.includes(block.name), `${cut.file}:${index + 1}: ${name} is used outside ${cut.methods.join(", ")}`);
    }
  });
  assert(uses > 0, `${cut.file}: nothing uses the cut import ${cut.import}`);
}

// These inputs are bundled with ansible-input, but only the inventory methods use them.
const inventoryReceipt = "../node-base-inventory/receipt.ts";
const inventoryCollector = "extensions/behaviors/operations/node-base-inventory/collect.py";

function checkInventoryCut(source: string, called: string[]) {
  const methods = ["baseInventorySource", "baseInventoryReceipt"];
  assertEquals(called.filter((name) => methods.includes(name)), [], "converge now calls a base inventory method");
  const lines = source.split("\n");
  const blocks = methodBlocks(lines).filter((block) => methods.includes(block.name));
  assertEquals(blocks.map(({ name }) => name).sort(), [...methods].sort(), "the base inventory methods still exist");
  const sourceSchemas = /^const (?:InventoryAdmission|InventorySource) = z\.object\(/;
  const inventoryResource = '        inventorySource: { schema: InventorySource, lifetime: "1d", garbageCollection: 5 },';
  const receiptImport = `import { InventoryProfile, InventoryReceiptInput, inventoryReceipt } from "${inventoryReceipt}";`;
  assertEquals(lines.filter((line) => line === receiptImport).length, 1, "the receipt must have exactly one named import");
  let inSchema = false;
  lines.forEach((line, index) => {
    inSchema ||= sourceSchemas.test(line);
    const inMethod = blocks.some(({ from, to }) => index > from && index < to);
    if (!inMethod && line !== receiptImport && line !== inventoryResource) {
      assert(!line.includes(inventoryReceipt) && !line.includes(inventoryCollector), `ansible-input/write.ts:${index + 1}: inventory file escaped its method`);
      assert(!/\b(?:InventoryReceiptInput|inventoryReceipt|inventorySource)\b/.test(line), `ansible-input/write.ts:${index + 1}: inventory receipt or resource escaped its method`);
      const schemaDeclaration = sourceSchemas.test(line);
      assert(schemaDeclaration || !/\b(?:InventoryAdmission|InventorySource)\b/.test(line), `ansible-input/write.ts:${index + 1}: inventory schema escaped its method`);
      assert(inSchema || !/\bInventoryProfile\b/.test(line), `ansible-input/write.ts:${index + 1}: inventory profile escaped its schema`);
    }
    if (inSchema && /;\s*$/.test(line)) inSchema = false;
  });
}

const covered = (path: string) => list.inputs.some((input) => input === path || (input.endsWith("/") && path.startsWith(input)));

/** The files the walker reads, so a test can give it a small tree of its own. Paths are relative to the repository. */
type Fs = { read: (path: string) => string; exists: (path: string) => boolean; isDirectory: (path: string) => boolean };
const repoFs: Fs = { read, exists, isDirectory: (path) => exists(path) && Deno.statSync(new URL(path, repo)).isDirectory };

/** Task keywords: every other key of a task names its module (docs.ansible.com, playbook keywords, Task). */
const taskKeywords = new Set([
  "name", "when", "loop", "loop_control", "register", "tags", "vars", "become", "become_user", "become_method", "become_flags", "delegate_to",
  "delegate_facts", "run_once", "changed_when", "failed_when", "ignore_errors", "ignore_unreachable", "no_log", "notify", "listen", "until",
  "retries", "delay", "environment", "args", "check_mode", "diff", "any_errors_fatal", "throttle", "timeout", "module_defaults", "collections",
  "connection", "async", "poll", "debugger", "remote_user", "port",
]);
const includes = ["ansible.builtin.import_tasks", "ansible.builtin.include_tasks", "ansible.builtin.import_playbook", "import_tasks", "include_tasks", "import_playbook"];
/** Modules that copy a file from the controller onto the Node: their `src` is content the Node keeps. */
const copying = /^(?:ansible\.builtin\.|ansible\.legacy\.|ansible\.posix\.)?(?:copy|template|synchronize|unarchive|assemble|script)$/;
/** Modules and keys that load Ansible content the walker does not follow. */
const unfollowed = /^(?:ansible\.builtin\.|ansible\.legacy\.)?(?:include_role|import_role|include_vars|include|roles|vars_files)$/;

/**
 * Walks the playbooks a base converge runs and returns every file it reads: playbooks, task files, the templates and files a task
 * copies, the `library/` modules a task calls and the `module_utils/` files those import. Ansible loads a `library/` module only when a
 * task names it, and a `module_utils/` file only when a loaded module imports it, so the others cannot change a converge. Whatever the
 * walker cannot resolve fails with its file and line; it never leaves a file out.
 */
function walkPlays(fs: Fs, playbooks: string[], cuts: typeof list.cut) {
  const ansible = "swamp/ansible/";
  const files = new Set<string>();
  const cutUsed = new Set<number>();
  const todo = [...playbooks];
  const modules: string[] = [];
  let text = "";
  let taskName = "";
  const refuse = (file: string, needle: string, why: string): never => {
    // The key's own line (`needle:`) first, so a task name that holds the same word does not win.
    const lines = text.split("\n");
    // Search from the current task's `name:` line, so the line is that task's even when other tasks use the same module.
    const from = Math.max(0, taskName === "" ? 0 : lines.findIndex((entry) => /^\s*-?\s*name:/.test(entry) && entry.includes(taskName)));
    const at = (test: (entry: string) => boolean) => {
      const index = lines.slice(from).findIndex(test);
      return index >= 0 ? from + index : lines.findIndex(test);
    };
    const keyed = at((entry) => entry.trimStart().replace(/^- /, "").replace(/^["']/, "").startsWith(needle) && entry.includes(":"));
    const line = (keyed >= 0 ? keyed : at((entry) => entry.includes(needle))) + 1;
    throw new Error(`${file}:${line}: ${why}`);
  };
  const follow = (file: string, task: Record<string, unknown>, key: string) => {
    const value = task[key];
    const target = typeof value === "string" ? value : (value as Record<string, unknown> | null)?.file;
    if (typeof target !== "string" || target.includes("{{")) refuse(file, key, `a dynamic include cannot be followed: ${JSON.stringify(value)}`);
    const name = target as string;
    const guard = String(task.when ?? task.loop ?? "");
    const cut = cuts.findIndex((entry) => entry.file === file && entry.include === name);
    if (cut >= 0 && key.endsWith("include_tasks")) {
      assertEquals(guard, cuts[cut].guard, `${file}: the guard of the cut include ${name} changed`);
      cutUsed.add(cut);
      return;
    }
    const base = name.startsWith("tasks/") || key.endsWith("import_playbook") ? ansible : file.slice(0, file.lastIndexOf("/") + 1);
    todo.push(base + name);
  };
  const copied = (file: string, module: string, task: Record<string, unknown>) => {
    const value = task[module];
    const args = { ...(task.args as Record<string, unknown> ?? {}), ...(value !== null && typeof value === "object" ? value as Record<string, unknown> : {}) };
    const source = args.src ?? (module.endsWith("script") ? (typeof value === "string" ? value.split(" ")[0] : args.cmd) : undefined);
    if (source === undefined) return;
    if (typeof source !== "string" || source.includes("{{")) refuse(file, module, `${module} copies a templated src the walker cannot follow: ${JSON.stringify(source)}`);
    const found = ["templates/", "files/", "scripts/", ""].map((folder) => ansible + folder + source).find((path) => fs.exists(path));
    if (found === undefined) refuse(file, String(source), `${module} copies ${source}, which the walker cannot find under swamp/ansible`);
    if (fs.isDirectory(found!)) refuse(file, String(source), `${module} copies the folder ${source} onto the Node; list its files instead`);
    files.add(found!);
  };
  const runTask = (file: string, task: Record<string, unknown>) => {
    if ("block" in task) {
      for (const part of ["block", "rescue", "always"]) for (const item of (task[part] as unknown[] | undefined) ?? []) runTask(file, item as Record<string, unknown>);
      return;
    }
    taskName = typeof task.name === "string" ? task.name : "";
    const keys = Object.keys(task).filter((key) => !taskKeywords.has(key) && !key.startsWith("with_"));
    for (const key of keys) if (unfollowed.test(key)) refuse(file, key, `${key} loads content the walker does not follow`);
    for (const key of keys) if (key.includes("{{")) refuse(file, key, `a templated action cannot be followed: ${key}`);
    let module = keys[0];
    if (keys.length === 1 && (module === "action" || module === "local_action")) {
      const value = task[module];
      module = typeof value === "string" ? value.trim().split(/\s+/)[0] : String((value as Record<string, unknown> | null)?.module ?? "");
      if (module === "" || module.includes("{{")) refuse(file, keys[0], `a templated action cannot be followed: ${JSON.stringify(value)}`);
    } else if (keys.length !== 1) {
      refuse(file, String(keys[0] ?? "- "), `the walker cannot tell the module of this task: ${JSON.stringify(keys)}`);
    }
    if (includes.includes(module)) return follow(file, task, module);
    if (unfollowed.test(module)) refuse(file, keys[0], `${module} loads content the walker does not follow`);
    if (copying.test(module)) copied(file, module, task);
    // A short name or `ansible.legacy.` resolves to `library/` first; anything else must be an FQCN of a collection or of Ansible.
    const local = module.startsWith("ansible.legacy.") ? module.slice("ansible.legacy.".length) : module.includes(".") ? undefined : module;
    if (local === undefined) return;
    const path = `${ansible}library/${local}.py`;
    if (!fs.exists(path)) refuse(file, keys[0], `${module} is not a module in swamp/ansible/library: name it by its FQCN`);
    modules.push(path);
  };
  while (todo.length > 0) {
    const file = todo.pop()!;
    if (files.has(file)) continue;
    assert(fs.exists(file), `a base converge reaches ${file}, which does not exist`);
    files.add(file);
    text = fs.read(file);
    taskName = "";
    const document = parse(text);
    if (!Array.isArray(document)) refuse(file, "", "a playbook or task file is not a list");
    for (const item of document as Record<string, unknown>[]) {
      if (item === null || typeof item !== "object") refuse(file, "", "a play or task is not a map");
      if (!("hosts" in item)) {
        runTask(file, item);
        continue;
      }
      for (const key of Object.keys(item)) if (unfollowed.test(key)) refuse(file, key, `${key} loads content the walker does not follow`);
      for (const part of ["pre_tasks", "tasks", "post_tasks", "handlers"]) {
        for (const task of (item[part] as unknown[] | undefined) ?? []) runTask(file, task as Record<string, unknown>);
      }
    }
    // A template, a file or a script named anywhere in the file (`templates/x`, `files/x`, `scripts/x`), and any `src: x` of any module
    // found in `templates/` or `files/`. A folder counts as itself, so the list must name it.
    for (const [, name] of text.matchAll(/\b((?:templates|files|scripts)\/[A-Za-z0-9_.\-/]+)/g)) {
      if (fs.exists(ansible + name)) files.add(ansible + name);
    }
    for (const [, name] of text.matchAll(/\bsrc:\s*["']?([A-Za-z0-9_.\-/]+)/g)) {
      for (const folder of ["templates/", "files/"]) if (fs.exists(ansible + folder + name)) files.add(ansible + folder + name);
    }
  }
  // The called modules and their `module_utils` imports, transitively. Ansible's own utils (`basic`, ...) are not in the folder.
  while (modules.length > 0) {
    const module = modules.pop()!;
    if (files.has(module)) continue;
    files.add(module);
    const source = fs.read(module);
    const names = [
      ...[...source.matchAll(/\bansible\.module_utils\.([A-Za-z0-9_]+)/g)].map((match) => match[1]),
      ...[...source.matchAll(/^\s*from\s+ansible\.module_utils\s+import\s+\(?([A-Za-z0-9_, \n]+)\)?/gm)]
        .flatMap((match) => match[1].split(",").map((name) => name.trim().split(/\s+/)[0]).filter(Boolean)),
    ];
    for (const name of names) {
      const path = `${ansible}module_utils/${name}`;
      if (fs.exists(path + ".py")) modules.push(path + ".py");
      else if (fs.isDirectory(path)) files.add(path + "/");
    }
  }
  return { files, cutUsed };
}

/**
 * A file in `library/` or `module_utils/` that has the name of an Ansible module or util overrides it for every task, the builtin
 * actions included (`ansible.builtin.copy` runs `ansible.legacy.stat` and `ansible.legacy.file`; a local `module_utils/basic.py`
 * replaces Ansible's). The walker counts a module only when a task names it, so each local name must be Orbit's own: `orbit_`.
 */
function localNames(folder: string, names: string[]) {
  // Python's byte-code cache is no module; Ansible never loads it by name.
  for (const name of names.filter((entry) => entry !== "__pycache__")) {
    assert(/^orbit_[a-z0-9_]+(?:\.py)?$/.test(name), `swamp/ansible/${folder}/${name}: a local module or util is named orbit_<name>, so it never overrides one of Ansible's`);
  }
}

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
    const isSharedBehavior = module === extension + "write.ts";
    if (isSharedBehavior) checkInventoryCut(source, calledMethods());
    for (const [, relative] of source.matchAll(/^\s*(?:import|export)\b[^;]*?from\s+"(\.{1,2}\/[^"]+)"/gm)) {
      // A cut import: used only by methods a converge never runs.
      if (isSharedBehavior && relative === inventoryReceipt) continue;
      if (list.cut_imports.some((cut) => cut.file === module && cut.import === relative)) continue;
      modules.push(new URL(relative, new URL(module, repo)).pathname.slice(repo.pathname.length));
    }
    for (const [, name] of source.matchAll(/extensionFile\(\s*["']([^"']+)["']\s*\)/g)) {
      shipped.add(name);
      if (isSharedBehavior && name === inventoryCollector) continue;
      if (name.endsWith(".ts")) modules.push("swamp/" + name);
      else files.add("swamp/" + name);
    }
  }
  // The play's own folder: its configuration and requirements. A module in `library/` and a file in `module_utils/` count only when a
  // reached task calls the module (walkPlays).
  for (const name of ["ansible.cfg", "requirements.txt", "requirements.yml"]) files.add(ansible + name);
  const config = read(ansible + "ansible.cfg");
  // The walker follows tasks, modules and module_utils only: a plugin folder or another module path would load code it cannot see.
  for (const key of ["library", "module_utils", "roles_path", "collections_paths"]) {
    assert(!new RegExp(`^\\s*${key}\\s*=`, "m").test(config), `swamp/ansible/ansible.cfg sets ${key}: the walker does not follow it`);
  }
  assert(!/^\s*[a-z_]+_plugins\s*=/m.test(config), "swamp/ansible/ansible.cfg sets a plugin path: the walker does not follow it");
  assert(!/ANSIBLE_(?:LIBRARY|MODULE_UTILS|[A-Z_]+_PLUGINS|ROLES_PATH)\b/.test(workflow), "the converge workflow sets an Ansible module or plugin path");
  // Ansible adds these folders next to the playbook by itself; the walker follows none of them.
  for (const entry of Deno.readDirSync(new URL(ansible, repo))) {
    assert(!(entry.isDirectory && /^(?:[a-z_]*plugins|roles|collections|group_vars|host_vars|doc_fragments)$/.test(entry.name)),
      `swamp/ansible/${entry.name}/ exists: the walker does not follow it`);
  }
  for (const folder of ["library", "module_utils"]) localNames(folder, [...Deno.readDirSync(new URL(ansible + folder, repo))].map((entry) => entry.name));
  const walked = walkPlays(repoFs, todo, list.cut);
  for (const file of walked.files) files.add(file);
  for (const cut of walked.cutUsed) cutUsed.add(cut);
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

Deno.test("each cut import is used only by methods a converge never runs", () => {
  assert(list.cut_imports.length > 0);
  for (const cut of list.cut_imports) checkCutImport(cut);
  assertEquals(calledMethods().sort(), ["convergeInput", "convergeResult", "write"], "the converge workflow's ansible-input methods");
});

Deno.test("a cut import fails when the converge workflow calls its method or a use leaves its methods", () => {
  const cut = list.cut_imports.find((entry) => entry.import === "./fleet_reboot_result.ts")!;
  assertThrows(() => checkCutImport({ ...cut, methods: [...cut.methods, "convergeResult"] }), Error, "the converge workflow now calls convergeResult");
  assertThrows(() => checkCutImport({ ...cut, methods: ["composeRole"] }), Error, "is used outside composeRole");
  const source = read("swamp/extensions/behaviors/operations/ansible-input/write.ts");
  assertThrows(() => checkInventoryCut(source, ["baseInventoryReceipt"]), Error, "converge now calls a base inventory method");
  assertThrows(() => checkInventoryCut(source + `\nconst collector = "${inventoryCollector}";`, calledMethods()), Error, "inventory file escaped its method");
  assertThrows(() => checkInventoryCut(source + "\nconst schema = InventorySource;", calledMethods()), Error, "inventory schema escaped its method");
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
  // The whole of `swamp` is the grants' input only; the converge digest is one per Node from the listed files' IDs. The two IDs before
  // `swamp`, `swamp/ansible` and the machine-software recipe, are the Instance host's input only.
  assert(refresh.includes("fleet_swamp_ids: \"{{ fleet_ids[2:-3] }}\""), "the converge digest takes the listed files' IDs, not the whole of swamp");
  assert(refresh.includes("'HEAD:swamp/ansible', 'HEAD:apps/gateway/resources/machine-software', 'HEAD:swamp'"), "the host's two inputs come just before swamp");
  assert(refresh.includes("'converge_' ~ (role | replace('-', '_'))"), "the refresh keeps one converge digest per Node");
});

/** A small tree for the walker: a playbook `swamp/ansible/play.yml` with the given tasks, and the given other files. */
function tree(tasks: string, extra: Record<string, string> = {}) {
  const files: Record<string, string> = {
    "swamp/ansible/play.yml": `- hosts: all\n  tasks:\n${tasks}`,
    "swamp/ansible/library/orbit_used.py": "from ansible.module_utils.basic import AnsibleModule\nfrom ansible.module_utils.orbit_shared import go\n",
    "swamp/ansible/library/orbit_unused.py": "from ansible.module_utils.orbit_other import go\n",
    "swamp/ansible/module_utils/orbit_shared.py": "from ansible.module_utils.orbit_deep import deeper\n",
    "swamp/ansible/module_utils/orbit_deep.py": "",
    "swamp/ansible/module_utils/orbit_other.py": "",
    "swamp/ansible/files/unit.service": "[Unit]\n",
    ...extra,
  };
  const isDirectory = (path: string) => Object.keys(files).some((file) => file.startsWith(path.replace(/\/?$/, "/")));
  const fs: Fs = { read: (path) => files[path], exists: (path) => path in files || isDirectory(path), isDirectory };
  return () => walkPlays(fs, ["swamp/ansible/play.yml"], []);
}

Deno.test("a module counts when a reached task calls it, with every module_utils file it imports; an uncalled one does not", () => {
  for (const call of ["orbit_used", "ansible.legacy.orbit_used"]) {
    const { files } = tree(`    - name: Call it\n      ${call}: { state: present }\n`)();
    assertEquals([...files].filter((file) => !file.endsWith("play.yml")).sort(), [
      "swamp/ansible/library/orbit_used.py",
      "swamp/ansible/module_utils/orbit_deep.py",
      "swamp/ansible/module_utils/orbit_shared.py",
    ], call);
  }
  // A module named by an FQCN of Ansible or a collection never loads `library/`, so nothing there counts.
  const { files } = tree("    - ansible.builtin.copy: { dest: /etc/x, content: x }\n    - ansible.posix.sysctl: { name: a, value: 1 }\n")();
  assertEquals([...files], ["swamp/ansible/play.yml"]);
});

Deno.test("a file a reached task copies onto the Node counts; a copied folder or a templated src fails with its file and line", () => {
  const { files } = tree("    - name: Unit\n      ansible.builtin.copy: { src: unit.service, dest: /etc/systemd/system/x.service }\n")();
  assert(files.has("swamp/ansible/files/unit.service"));
  // Any other module's `src` in `files/` or `templates/` counts too, and a folder named there counts as itself.
  const patched = tree("    - name: Patch\n      ansible.posix.patch: { src: kernel.patch, dest: /etc/x }\n", { "swamp/ansible/files/kernel.patch": "" })();
  assert(patched.files.has("swamp/ansible/files/kernel.patch"));
  const folder = tree("    - name: Read\n      ansible.builtin.stat: { path: \"{{ playbook_dir }}/files/units\" }\n", { "swamp/ansible/files/units/a.service": "" })();
  assert(folder.files.has("swamp/ansible/files/units"));
  // A tree that holds modules, copied for a later local run, would have to be listed file by file.
  assertThrows(tree("    - name: Ship plays\n      ansible.posix.synchronize: { src: library, dest: /usr/local/lib/x }\n"), Error,
    "swamp/ansible/play.yml:4: ansible.posix.synchronize copies the folder library onto the Node");
  assertThrows(tree("    - name: Ship\n      ansible.builtin.copy:\n        src: \"{{ playbook_dir }}/module_utils\"\n        dest: /x\n"), Error,
    "swamp/ansible/play.yml:4: ansible.builtin.copy copies a templated src");
  assertThrows(tree("    - name: Ship\n      ansible.builtin.template: { src: missing.j2, dest: /x }\n"), Error,
    "swamp/ansible/play.yml:4: ansible.builtin.template copies missing.j2, which the walker cannot find");
});

Deno.test("the walker fails with the file and line on what it cannot follow, and never drops a file", () => {
  const cases: [string, string][] = [
    ["    - name: Dynamic\n      ansible.builtin.include_tasks: \"tasks/{{ kind }}.yml\"\n", "swamp/ansible/play.yml:4: a dynamic include cannot be followed"],
    ["    - name: Templated key\n      \"{{ module }}\": { state: present }\n", "swamp/ansible/play.yml:4: a templated action cannot be followed"],
    ["    - name: Templated action\n      action: \"{{ module }} state=present\"\n", "swamp/ansible/play.yml:4: a templated action cannot be followed"],
    ["    - name: Unknown\n      orbit_missing: { state: present }\n", "swamp/ansible/play.yml:4: orbit_missing is not a module in swamp/ansible/library"],
    ["    - name: Two\n      ansible.builtin.copy: { dest: /x, content: x }\n      ansible.builtin.file: { path: /x }\n", "swamp/ansible/play.yml:4: the walker cannot tell the module"],
    ["    - name: Role\n      ansible.builtin.include_role: { name: x }\n", "swamp/ansible/play.yml:4: ansible.builtin.include_role loads content the walker does not follow"],
  ];
  for (const [tasks, message] of cases) assertThrows(tree(tasks), Error, message);
  // A local file named like Ansible's own would override it for every builtin task, so it fails.
  for (const [folder, name] of [["library", "stat.py"], ["library", "command.py"], ["module_utils", "basic.py"], ["module_utils", "common"]]) {
    assertThrows(() => localNames(folder, ["orbit_used.py", name]), Error, `swamp/ansible/${folder}/${name}: a local module or util is named orbit_<name>`);
  }
  localNames("library", ["orbit_route_site.py"]);
  // The real tree: no plugin folder and no module path the walker would miss.
  assert(!/^\s*(?:library|module_utils)\s*=/m.test(read("swamp/ansible/ansible.cfg")));
});

Deno.test("each family a base converge reads stays in the list, and no library module or module_utils file does", () => {
  // The digest moves, and the refresh converges, when any of these moves (ansible_instance_fleet_test.ts: the converge cases).
  for (const family of [
    "swamp/workflows/workflow-operation-node-converge-direct.yaml", // the converge workflow
    "swamp/extensions/behaviors/operations/ansible-input/write.ts", // the input behavior and its imports
    "swamp/ansible/operation-node-converge.yml", // the playbook
    "swamp/ansible/tasks/node-converge-base.yml", // packages, units and files of the base
    "swamp/ansible/tasks/node-update-policy.yml", // the update policy files
    "swamp/ansible/ansible.cfg",
    "swamp/ansible/requirements.yml", // the collections whose modules the tasks call
  ]) assert(list.inputs.includes(family), family);
  assertEquals(list.inputs.filter((input) => /\/(?:library|module_utils)\//.test(input)), [], "no base converge task calls a library module today");
});
