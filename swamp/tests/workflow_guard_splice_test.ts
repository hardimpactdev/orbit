/**
 * Swamp 20261114.210101.0-sha.537e03d1 leaves a guard raw when the run starts, but its substitution writes each value it evaluated into
 * every place that holds the same raw `${{ … }}` text, guards included. So a guard whose text equals an input that reads only the run's
 * inputs becomes that input's value, and Swamp refuses the run: Operation 200 of the first production clone failed with
 * `jobs[0].steps[4].guard: Invalid input: expected string, received boolean`, because the guard of `candidate-origin` matched the
 * `sudo` input of the steps beside it. This finds every guard that run-start substitution would overwrite.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const EXPRESSION = /\$\{\{[\s\S]*?\}\}/g;

/** What run-start evaluation leaves for later: an expression that reads a step output or a forEach item. */
const deferred = (raw: string): boolean => /\b(steps|self)\b/.test(raw);

function spliced(file: string): string[] {
  const workflow = parse(Deno.readTextFileSync(new URL(`../workflows/${file}`, import.meta.url))) as { jobs?: unknown };
  const guards: { where: string; raw: string }[] = [];
  const evaluated = new Set<string>();
  const walk = (where: string, value: unknown, guard: boolean): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(EXPRESSION)) {
        if (guard) guards.push({ where, raw: match[0] });
        else if (!deferred(match[0])) evaluated.add(match[0]);
      }
    } else if (Array.isArray(value)) {
      value.forEach((item) => walk(where, item, guard));
    } else if (value !== null && typeof value === "object") {
      const named = (value as { name?: unknown }).name;
      const here = typeof named === "string" ? `${where} / ${named}` : where;
      for (const [key, item] of Object.entries(value)) walk(here, item, guard || key === "guard");
    }
  };
  walk(file, workflow.jobs, false);
  return guards.filter((guard) => evaluated.has(guard.raw)).map((guard) => `${guard.where}: ${guard.raw}`);
}

Deno.test("no guard shares its text with an expression that run-start substitution writes", () => {
  const files = [...Deno.readDirSync(new URL("../workflows/", import.meta.url))]
    .filter((entry) => entry.isFile && entry.name.endsWith(".yaml"))
    .map((entry) => entry.name)
    .sort();
  assertEquals(files.flatMap(spliced), []);
});
