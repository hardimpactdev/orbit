import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

/**
 * A command behind `incus exec` never opens /dev/stdin or /proc/self/fd/0 by path. An Instance container has an isolated id map:
 * the Node's stdin pipe shows there as owned by nobody, mode 0600, so the open is refused ("install: Permission denied"). The refresh
 * record write failed so on its first live run (Operation 20393) and the refresh rolled back. A command reads the open stream instead
 * (`cat > file`). The fake `incus` of the unit tests cannot show the refusal for every caller, so this check reads the source.
 */
const root = new URL("../", import.meta.url);
const folders = ["ansible", "workflows"];
const stdinPath = /\/dev\/stdin|\/proc\/self\/fd\/0/;
const incusExec = /\bincus\b[\s\S]*\bexec\b/;
const commandKeys = ["argv", "cmd", "command", "shell", "ansible.builtin.command", "ansible.builtin.shell"];
const varKeys = ["vars", "set_fact", "ansible.builtin.set_fact"];

/** Where the command runs, and why a path open of stdin is safe there. Keyed by file and task name. */
const safe: Record<string, string> = {
  "ansible/task-vm-host.yml: Give the base VM the Club key": "a virtual machine: the Incus agent makes the pipe inside the guest",
};

type Node = unknown;
const isMap = (node: Node): node is Record<string, Node> => typeof node === "object" && node !== null && !Array.isArray(node);
const children = (node: Node): Node[] => Array.isArray(node) ? node : isMap(node) ? Object.values(node) : [];

/** The names of variables whose value runs a command through `incus exec`, directly or through another such variable. */
function execNames(documents: Node[]): Set<string> {
  const definitions: [string, string][] = [];
  const collect = (node: Node) => {
    if (isMap(node)) {
      for (const key of varKeys) {
        const vars = node[key];
        if (isMap(vars)) for (const [name, value] of Object.entries(vars)) definitions.push([name, JSON.stringify(value)]);
      }
    }
    children(node).forEach(collect);
  };
  documents.forEach(collect);
  const names = new Set<string>();
  for (let grew = true; grew;) {
    grew = false;
    for (const [name, value] of definitions) {
      if (names.has(name)) continue;
      if (incusExec.test(value) || [...names].some((known) => new RegExp(`\\b${known}\\b`).test(value))) {
        names.add(name);
        grew = true;
      }
    }
  }
  return names;
}

/** Each command that names a stdin path behind `incus exec`, as "<file>: <task name>". */
function findings(files: Record<string, Node>): string[] {
  const names = execNames(Object.values(files));
  const behindExec = (text: string) => incusExec.test(text) || [...names].some((name) => new RegExp(`\\b${name}\\b`).test(text));
  const found = new Set<string>();
  const walk = (file: string, node: Node, task: string) => {
    if (isMap(node)) {
      const here = typeof node.name === "string" ? node.name : task;
      for (const key of commandKeys) {
        if (!(key in node) || isMap(node[key])) continue;
        const text = JSON.stringify(node[key]);
        if (stdinPath.test(text) && behindExec(text)) found.add(`${file}: ${here}`);
      }
      for (const child of children(node)) walk(file, child, here);
    } else children(node).forEach((child) => walk(file, child, task));
  };
  for (const [file, document] of Object.entries(files)) walk(file, document, "(top)");
  return [...found].sort();
}

async function sources(): Promise<Record<string, Node>> {
  const files: Record<string, Node> = {};
  const read = async (folder: string) => {
    for await (const entry of Deno.readDir(new URL(folder + "/", root))) {
      const path = `${folder}/${entry.name}`;
      if (entry.isDirectory) await read(path);
      // Ansible writes an empty module argument as `!!null ""`, which this parser refuses.
      else if (/\.ya?ml$/.test(entry.name)) files[path] = parse((await Deno.readTextFile(new URL(path, root))).replaceAll('!!null ""', "null"));
    }
  };
  for (const folder of folders) await read(folder);
  return files;
}

Deno.test("no command behind incus exec opens stdin by path", async () => {
  const files = await sources();
  assert(Object.keys(files).length > 100, "the check read the Ansible and workflow files");
  const bad = findings(files).filter((finding) => !(finding in safe));
  assertEquals(bad, [], "read stdin as a stream (`cat > file`), never by path, in an Instance container");
  for (const finding of Object.keys(safe)) assert(findings(files).includes(finding), `stale safe entry: ${finding}`);
});

Deno.test("the check finds the record write of Operation 20393, through the variable that holds incus exec", () => {
  const old = parse(`
- name: Name the fleet commands
  vars:
    fleet_outer_exec: [incus, --project, p, exec, c, --]
  ansible.builtin.set_fact:
    fleet_outer: "{{ fleet_outer_exec }}"
- name: Write the refresh record
  ansible.builtin.command:
    argv: "{{ ['timeout', '30'] + fleet_outer + ['install', '-D', '-m', '0644', '/dev/stdin', fleet_record] }}"
- name: Write on the Node
  ansible.builtin.command:
    argv: [install, -m, "0644", /dev/stdin, /etc/x]
`);
  assertEquals(findings({ "old.yml": old }), ["old.yml: Write the refresh record"]);
});
