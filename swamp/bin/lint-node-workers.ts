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
// This immutable official SSH library is part of the UpCloud image credential review.
// Keep the exception exact: no mutable refs, other entrypoints or extension loading.
const pinnedNodeLibraries = new Set([
  "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts",
]);
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
  const rawProcess = new Set([
    `${root}/extensions/behaviors/operations/_lib/node-command-process.ts`,
    `${root}/extensions/behaviors/operations/_lib/local-worker.ts`,
  ]);
  if (/new\s+Deno\.Command\s*\(/.test(text) && !rawProcess.has(path)) {
    errors.push(
      `Node subprocess must use the shared command runner or guarded localCommand: ${path}`,
    );
  }
  if (
    /Deno\.(?:open(?:Sync)?|create(?:Sync)?|write\w*|mkdir(?:Sync)?|makeTemp\w*|remove(?:Sync)?|rename(?:Sync)?|chmod(?:Sync)?|chown(?:Sync)?|copyFile(?:Sync)?|symlink(?:Sync)?|link(?:Sync)?|truncate(?:Sync)?|utime(?:Sync)?)\s*\(/
      .test(text)
  ) {
    errors.push(
      `Node filesystem effect needs the shared local refusal: ${path}`,
    );
  }
  if (
    /\bcommandOutput\b/.test(text) &&
    ![
      "node-command-process.ts",
      "node-commands.ts",
      "node-proxy.ts",
      "node-proxy-http.ts",
    ].some((
      name,
    ) => path === `${root}/extensions/behaviors/operations/_lib/${name}`)
  ) {
    errors.push(
      `Raw Node command transport is restricted to reviewed adapters: ${path}`,
    );
  }
  if (/pulled-extensions|\.runModel\s*\(|\bextends:\s*["']@/.test(text)) {
    errors.push(`Node code can load an extension in-process: ${path}`);
  }
  for (
    const match of text.matchAll(
      /(?<!["'])\b(?:from\s*|import\s*(?:\(\s*)?)["']([^"']+)["']/g,
    )
  ) {
    const dependency = match[1];
    if (
      /^node:(?:fs(?:\/|$)|child_process$|net$|dgram$)/.test(dependency)
    ) {
      errors.push(
        `Node host APIs must use the shared command runner or local refusal: ${path}`,
      );
    }
    if (
      /node-command-process\.ts$/.test(dependency) &&
      !["node-commands.ts", "node-proxy.ts", "node-proxy-http.ts"].some((
        name,
      ) => path === `${root}/extensions/behaviors/operations/_lib/${name}`)
    ) {
      errors.push(
        `Raw Node command transport is restricted to reviewed adapters: ${path}`,
      );
    }
    if (dependency.startsWith(".")) {
      await reviewed(
        resolve(dirname(path), dependency),
      );
    } else if (
      !/^(?:npm:|jsr:|node:)/.test(dependency) &&
      !pinnedNodeLibraries.has(dependency)
    ) {
      errors.push(
        `Unreviewed Node import ${dependency}: ${path}`,
      );
    }
  }
}
for (const path of await files(`${root}/workflows`)) {
  if (!path.endsWith(".yaml")) continue;
  const workflow = object(parse(await Deno.readTextFile(path)));
  const walk = async (value: Map, inherited: Map) => {
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
        const literal = /^orbit-node-([1-9][0-9]*)$/.exec(
          placement.target,
        );
        const expression = placement.target.includes("orbit-node-")
          ? /string\((.+)\)\s*\}\}$/s.exec(placement.target)
          : null;
        if (
          literal && labels.node !== literal[1] ||
          expression &&
            labels.node !== "${{ string(" + expression[1] + ") }}"
        ) errors.push(`Node target and label disagree: ${path}`);
      }
    }
    const task = object(value.task);
    if (
      node && task.type === "assert" &&
      (typeof task.expr !== "string" ||
        typeof task.message === "string" &&
          task.message.includes("${{"))
    ) {
      errors.push(
        `Node assertions must use string expressions and static messages: ${path}`,
      );
    }
    if (
      node && Object.keys(task).length &&
      !["workflow", "assert"].includes(String(task.type))
    ) {
      const type = task.behaviorType ?? task.modelType ??
        models.get(String(task.modelIdOrName));
      if (type === "command/shell") {
        if (task.type !== "behavior" || task.methodName !== "execute") {
          errors.push(
            `Native shell steps must use command/shell.execute: ${path}`,
          );
        }
      } else if (
        [
          "@swamp/ssh",
          "@swamp/cfgmgmt/directory",
          "@swamp/cfgmgmt/file",
        ].includes(String(type))
      ) {
        // Official extensions provide these actions directly.
      } else if (typeof type !== "string" || !sources.has(type)) {
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
  };
  await walk(workflow, {});
}
if (errors.length) {
  console.error(errors.join("\n"));
  Deno.exit(1);
}
console.log("Node worker code and exact placement contracts passed");
