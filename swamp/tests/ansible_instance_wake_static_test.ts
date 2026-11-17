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

const execs = /\bas_(root|orbit|owner|agent)\b|in_workspace|'incus', 'exec'|"incus","exec"/;
const bounded = /^\s*(\{\{\s*)?\[\s*['"]timeout['"]/;

Deno.test("every exec of a wake that resumes or finds the container running has a bound from the Node", () => {
  // The part of the start phase that such a wake runs: tagged start (or always), and not the converge that only a boot runs.
  const phase = flatten(load("tasks/instance-container-phase.yml"))
    .filter(({ tags }) => tags.includes("start") || tags.includes("always"))
    .filter(({ when }) => !when.some((condition) => /not start_skips_boot|start_found == 'Stopped'/.test(condition)));
  // The files it includes, each run whole for a nested fleet's start or a wake's end.
  const included = phase.flatMap(({ task }) => {
    const include = task["ansible.builtin.include_tasks"] as Task | string | undefined;
    if (include === undefined) return [];
    const file = typeof include === "string" ? include : String(include.file);
    return flatten(load(file.startsWith("tasks/") ? file : `tasks/${file}`));
  });
  const all = [...phase, ...included].map(({ task }) => ({ name: String(task.name), argv: argv(task) }))
    .filter((entry) => entry.argv !== null && execs.test(entry.argv));
  assert(all.some((entry) => entry.name === "Read the file system of the container's /run"), "the Club key read is on the path");
  assert(all.some((entry) => entry.name === "Wait for each running unit"), "the unit wait is on the path");
  const unbounded = all.filter((entry) => !bounded.test(entry.argv!)).map((entry) => entry.name);
  assert(unbounded.length === 0, `execs without a bound from the Node: ${unbounded.join("; ")}`);
});
