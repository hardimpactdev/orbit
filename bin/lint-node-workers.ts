import { parse } from "jsr:@std/yaml@1.0.12";
import { dirname, resolve } from "node:path";

type Map = Record<string, unknown>;
const object = (v: unknown): Map =>
  v && typeof v === "object" && !Array.isArray(v) ? v as Map : {};
async function files(root: string): Promise<string[]> {
  const found: string[] = [];
  for await (const entry of Deno.readDir(root)) {
    const path = `${root}/${entry.name}`;
    if (entry.isDirectory) found.push(...await files(path));
    else if (entry.isFile) found.push(path);
  }
  return found;
}
const root = resolve(Deno.args[0] ?? ".");
const sources = new Map<string, string>();
for (const path of await files(`${root}/extensions/behaviors`)) {
  if (!path.endsWith(".ts") || /(?:_test|fixture)\.ts$/.test(path)) continue;
  const text = await Deno.readTextFile(path);
  for (const hit of text.matchAll(/\btype:\s*["'](@[^"']+)["']/g)) {
    sources.set(hit[1], path);
  }
}
const models = new Map<string, string>();
for (const path of await files(`${root}/models`)) {
  if (!path.endsWith(".yaml")) continue;
  const value = object(parse(await Deno.readTextFile(path)));
  if (typeof value.name === "string" && typeof value.type === "string") {
    models.set(value.name, value.type);
  }
}
const errors: string[] = [];
const seen = new Set<string>();
async function reviewed(path: string) {
  if (seen.has(path)) return;
  seen.add(path);
  if (
    !path.startsWith(`${root}/extensions/behaviors/`) ||
    path.includes("/.swamp/")
  ) {
    errors.push(`Unreviewed Node code: ${path}`);
    return;
  }
  const text = await Deno.readTextFile(path);
  if (/pulled-extensions|\.runModel\s*\(|\bextends:\s*["']@/.test(text)) {
    errors.push(`Node code can load an extension in-process: ${path}`);
  }
  for (
    const match of text.matchAll(
      /(?:from\s*|import\s*(?:\(\s*)?)["']([^"']+)["']/g,
    )
  ) {
    const dependency = match[1];
    if (dependency.startsWith(".")) {
      await reviewed(
        resolve(dirname(path), dependency),
      );
    } else if (!/^(?:npm:|jsr:|node:)/.test(dependency)) {
      errors.push(
        `Unreviewed Node import ${dependency}: ${path}`,
      );
    }
  }
}
for (const path of await files(`${root}/workflows`)) {
  if (!path.endsWith(".yaml")) continue;
  const workflow = object(parse(await Deno.readTextFile(path)));
  async function walk(value: Map, inherited: Map) {
    const placement = {
      ...inherited,
      ...Object.fromEntries(
        ["target", "labels"].filter((k) => k in value).map(
          (k) => [k, value[k]],
        ),
      ),
    };
    const labels = object(placement.labels);
    // Migration pools retain their old control-worker identity until their owning cutover.
    const node = typeof placement.target === "string" &&
        placement.target.includes("orbit-node-") ||
      "node" in labels &&
        (!("pool" in labels) || typeof placement.target === "string");
    if (node) {
      if (
        typeof placement.target !== "string" || !("node" in labels) ||
        typeof labels.node !== "string"
      ) {
        errors.push(
          `Node placement requires token target AND string node label: ${path}`,
        );
      }
      if (typeof placement.target === "string") {
        const literal = /^orbit-node-([1-9][0-9]*)$/.exec(placement.target);
        const expression = placement.target.includes("orbit-node-")
          ? /string\(([^)]+)\)/.exec(placement.target)
          : null;
        if (
          literal && labels.node !== literal[1] ||
          expression && labels.node !== "${{ string(" + expression[1] + ") }}"
        ) errors.push(`Node target and label disagree: ${path}`);
      }
    }
    const task = object(value.task);
    if (node && Object.keys(task).length && task.type !== "workflow") {
      const type = task.behaviorType ?? task.modelType ??
        models.get(String(task.modelIdOrName));
      if (typeof type !== "string" || !sources.has(type)) {
        errors.push(
          `Node workers may run only reviewed local behaviors, not third-party extensions: ${path} (${
            String(type)
          })`,
        );
      } else await reviewed(sources.get(type)!);
    }
    for (const key of ["jobs", "steps"]) {
      for (
        const child of Array.isArray(value[key]) ? value[key] as unknown[] : []
      ) await walk(object(child), placement);
    }
  }
  await walk(workflow, {});
}
if (errors.length) {
  console.error(errors.join("\n"));
  Deno.exit(1);
}
console.log("Node worker code and exact placement contracts passed");
