/**
 * The static checks of a wake: the shipped task files, without a run. A wake runs `--tags start` of instance-container.yml under the
 * run's 240 s bound. On 2026-11-17 the first exec of wake 26343 into Instance 2454, which the kernel still held frozen, hung until that
 * bound (exit 124, no stderr). The fixture's `timeout` is the real one, so only the task files show where a bound sits.
 */
import { assert } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Task = Record<string, unknown>;

const ansible = new URL("../ansible/", import.meta.url);
const load = (path: string) => parse(Deno.readTextFileSync(new URL(path, ansible))) as Task[];

/** Each task of `tasks`, blocks flattened, with the tags and `when` conditions it inherits. */
function flatten(tasks: Task[], tags: string[] = [], when: string[] = []): { task: Task; tags: string[]; when: string[] }[] {
  return tasks.flatMap((task) => {
    const own = [...tags, ...((task.tags as string[] | undefined) ?? [])];
    const conditions = [...when, ...(task.when === undefined ? [] : [String(task.when)])];
    const children = ["block", "rescue", "always"].flatMap((part) => (task[part] as Task[] | undefined) ?? []);
    return children.length ? flatten(children, own, conditions) : [{ task, tags: own, when: conditions }];
  });
}

/** The argv of a command task as one string, or null for any other task. */
function argv(task: Task): string | null {
  const command = task["ansible.builtin.command"] as Task | undefined;
  if (!command) return null;
  return typeof command.argv === "string" ? command.argv : JSON.stringify(command.argv);
}

const execs = /\bas_(root|orbit|owner|agent)\b|in_workspace|private_names_root|'incus', 'exec'|"incus","exec"/;
const bounded = /^\s*(\{\{\s*)?\[\s*['"]timeout['"]/;
// `as_root` of the start phase begins with the Node's `timeout`, so an exec through it, or through `private_names_root`, which the
// start sets to it, has its bound.
const throughRoot = /^\s*\{\{\s*(as_root|private_names_root)\s*\+/;

Deno.test("every exec of the start phase has a bound from the Node, on each path of a wake", () => {
  const names = flatten(load("tasks/instance-container-phase.yml")).find(({ task }) => task.name === "Name the container commands")!;
  const facts = names.task["ansible.builtin.set_fact"] as Task;
  assert(
    /^\{\{\s*\(\['timeout', '\d+'\] if phase == 'start' else \[\]\) \+ \['incus', 'exec'/.test(String(facts.as_root)),
    `as_root of the start phase starts with a bound: ${facts.as_root}`,
  );
  // Every task tagged start (or always), on each path: the resume, a container already running, the boot of a Stopped container and
  // the stop and boot after a silent resume.
  const phase = flatten(load("tasks/instance-container-phase.yml"))
    .filter(({ tags }) => tags.includes("start") || tags.includes("always"));
  // The files it includes, each run whole, and the handler that the PHP settings notify.
  const included = phase.flatMap(({ task }) => {
    const include = task["ansible.builtin.include_tasks"] as Task | string | undefined;
    if (include === undefined) return [];
    const file = typeof include === "string" ? include : String(include.file);
    return flatten(load(file.startsWith("tasks/") ? file : `tasks/${file}`));
  });
  const handlers = flatten(load("tasks/instance-container-handlers.yml"));
  const all = [...phase, ...included, ...handlers].map(({ task }) => ({ name: String(task.name), argv: argv(task) }))
    .filter((entry) => entry.argv !== null && execs.test(entry.argv));
  for (const name of [
    "Read the file system of the container's /run",
    "Wait for each running unit",
    "Read the php link",
    "Read the container's hosts file",
    "Restart the running Orbit units after changed PHP settings",
  ]) assert(all.some((entry) => entry.name === name), `${name} is on the path`);
  const unbounded = all.filter((entry) => !bounded.test(entry.argv!) && !throughRoot.test(entry.argv!)).map((entry) => entry.name);
  assert(unbounded.length === 0, `execs without a bound from the Node: ${unbounded.join("; ")}`);
});
