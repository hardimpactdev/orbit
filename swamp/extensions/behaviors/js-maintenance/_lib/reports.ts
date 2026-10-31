import { z } from "npm:zod@4";
import {
  AdvisorySchema,
  type Audit,
  command,
  decode,
  MaintenanceFailure,
  object,
} from "../../_lib/maintenance/io.ts";
import { majorChange } from "../../_lib/maintenance/summary.ts";
import {
  aliases,
  buckets,
  cli,
  descriptor,
  lock,
  nodeName,
  type Workspace,
} from "./workspace.ts";

async function report(js: Workspace, args: string[], signal?: AbortSignal) {
  const result = await command(js.dir, cli(js), args, signal);
  if (result.code < 0 || result.code > 3) {
    throw new MaintenanceFailure(
      `Dependency command failed: ${cli(js)} ${
        args.join(" ")
      }: ${result.stderr}`,
    );
  }
  if (new TextEncoder().encode(result.stdout).byteLength > 8388608) {
    throw new MaintenanceFailure("JavaScript report is too large");
  }
  // deno-lint-ignore no-control-regex
  return result.stdout.replace(/\x1b\[[0-9;]*m/g, "");
}
const stripAge = (s: string) => s.trim().replace(/\s*\*$/, "");
export async function majors(js: Workspace, signal?: AbortSignal) {
  const text = await report(
    js,
    js.vitePlus
      ? ["outdated", "--format", "json"]
      : js.manager === "npm"
      ? ["outdated", "--json"]
      : ["outdated"],
    signal,
  );
  const rows: { name: string; current: string; latest: string }[] = [];
  const add = (name: string, current: string, latest: string) => {
    if (majorChange(current, latest)) {
      rows.push({ name, current: stripAge(current), latest: stripAge(latest) });
    }
  };
  const noise = (line: string) =>
    !line || /^(bun outdated|Note:|warning:)/i.test(line) ||
    /unsupported/i.test(line);
  if (js.manager === "bun" && !/^[\s]*[\[{]/.test(text)) {
    let header = false;
    for (const line of text.split(/\r\n|\n|\r/).map((s) => s.trim())) {
      if (noise(line) || /^\|[-| ]+\|$/.test(line)) continue;
      const match =
        /^\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|$/
          .exec(line);
      if (!match) {
        throw new MaintenanceFailure("Unsupported Bun outdated output");
      }
      const name = match[1].trim().replace(/\s+\((?:dev|optional|peer)\)$/, "");
      if (name === "Package") {
        header = true;
        continue;
      }
      if (!header || !match[2] || !match[4]) {
        throw new MaintenanceFailure("Unsupported Bun outdated row");
      }
      if (!(name in js.direct)) continue;
      const current = stripAge(match[2]), latest = stripAge(match[4]);
      if (!/^v?\d+\.\d+\./.test(current) || !/^v?\d+\.\d+\./.test(latest)) {
        throw new MaintenanceFailure("Unsupported Bun outdated row");
      }
      add(name, current, latest);
    }
    return rows;
  }
  // npm/vp may emit [] when there are no outdated packages.
  if (text.trim() === "[]") return rows;
  const data = decode(text, "JavaScript outdated");
  if ("error" in data) {
    throw new MaintenanceFailure("Dependency outdated command failed");
  }
  for (const [key, value] of Object.entries(data)) {
    const item = object(value, "outdated entry");
    if (typeof item.latest !== "string" || !item.latest) {
      throw new MaintenanceFailure("Unsupported outdated JSON shape");
    }
    const name = item.name ?? key;
    if (typeof name !== "string" || !name) {
      throw new MaintenanceFailure("Outdated entry has no package name");
    }
    if (!(name in js.direct)) continue;
    let current = item.current;
    if (typeof current !== "string" || !current) {
      const packages = await lock(js);
      const entry =
        packages[js.manager === "npm" ? `node_modules/${name}` : name];
      current = js.manager === "npm"
        ? (entry as { version?: unknown })?.version
        : Array.isArray(entry) && typeof entry[0] === "string" &&
            entry[0].startsWith(`${name}@`)
        ? entry[0].slice(name.length + 1)
        : null;
    }
    if (typeof current !== "string" || !current) {
      throw new MaintenanceFailure(
        `Outdated entry has no current version: ${name}`,
      );
    }
    add(name, current, item.latest);
  }
  return rows;
}
export type Finding = {
  id: string;
  url: string | null;
  title: string;
  affected: string | null;
  severity?: string | null;
  module: string;
};
export type Findings = Record<string, Finding[]>;
function advisory(
  value: unknown,
  module: string,
  kind: "npm" | "bun" | "legacy",
): Finding {
  const item = object(value, `advisory for ${module}`);
  const id = kind === "npm" ? item.source : item.id;
  if (
    (typeof id !== "number" && typeof id !== "string") || String(id) === "" ||
    typeof item.url !== "string" || !item.url
  ) throw new MaintenanceFailure(`Invalid ${kind} advisory for ${module}`);
  const parsed = AdvisorySchema.extend({
    severity: z.string().nullable().optional(),
  }).safeParse({
    id: String(id),
    url: item.url,
    title: item.title ?? "",
    affected: (kind === "npm" ? item.range : item.vulnerable_versions) ?? null,
    severity: item.severity ?? null,
  });
  if (!parsed.success) {
    throw new MaintenanceFailure(`Invalid ${kind} advisory for ${module}`);
  }
  return {
    ...parsed.data,
    module: typeof item.name === "string" && item.name ? item.name : module,
  };
}
function finish(
  result: Record<string, Map<string, Finding>>,
  sort = true,
): Findings {
  return Object.fromEntries(
    Object.entries(result).map((
      [name, entries],
    ) => [
      name,
      sort
        ? [...entries.values()].sort((a, b) =>
          a.id < b.id ? -1 : a.id > b.id ? 1 : 0
        )
        : [...entries.values()],
    ]),
  );
}
function npmAdvisories(
  data: Record<string, unknown>,
  js: Workspace,
  packages: Record<string, unknown>,
  includeTransitive = false,
): Findings {
  if (!("vulnerabilities" in data) && !("advisories" in data)) {
    throw new MaintenanceFailure(
      "JavaScript audit has no vulnerabilities/advisories field",
    );
  }
  const vulnerabilities = data.vulnerabilities === undefined ||
      (Array.isArray(data.vulnerabilities) && data.vulnerabilities.length === 0)
    ? {}
    : object(data.vulnerabilities, "npm vulnerabilities");
  const map = aliases(packages, "npm");
  for (const [name, value] of Object.entries(vulnerabilities)) {
    const item = object(value, "npm vulnerability");
    for (const node of Array.isArray(item.nodes) ? item.nodes : []) {
      const declared = typeof node === "string" ? nodeName(node) : null;
      if (declared && !map[declared]) map[declared] = name;
    }
  }
  const result: Record<string, Map<string, Finding>> = {};
  const resolve = (name: string) => {
    const pending = [name],
      seen = new Set<string>(),
      found = new Map<string, Finding>();
    while (pending.length) {
      const current = pending.pop()!;
      if (seen.has(current)) continue;
      seen.add(current);
      const via =
        object(vulnerabilities[current], `npm advisory reference: ${current}`)
          .via;
      if (!Array.isArray(via)) {
        throw new MaintenanceFailure(
          `Cannot resolve npm advisory reference: ${current}`,
        );
      }
      for (const entry of via) {
        if (typeof entry === "string") pending.push(entry);
        else {
          const a = advisory(entry, current, "npm");
          found.set(a.id, a);
        }
      }
    }
    if (!found.size) {
      throw new MaintenanceFailure(`No resolvable npm advisory for ${name}`);
    }
    return found;
  };
  for (const name of Object.keys(vulnerabilities)) {
    const owners = name in js.direct ? [name] : buckets(name, js, map);
    for (
      const owner of owners.length ? owners : includeTransitive ? [name] : []
    ) {
      result[owner] = new Map([...(result[owner] ?? []), ...resolve(name)]);
    }
  }
  for (const value of Object.values(data.advisories ?? {})) {
    const item = object(value, "npm advisory");
    if (typeof item.module_name !== "string") {
      throw new MaintenanceFailure("Invalid npm advisory");
    }
    const name = item.module_name, a = advisory(item, name, "legacy");
    const owners = name in js.direct ? [name] : buckets(name, js, map);
    for (const owner of owners.length ? owners : [name]) {
      (result[owner] ??= new Map()).set(a.id, a);
    }
  }
  return finish(result);
}
function bunAdvisories(
  data: Record<string, unknown>,
  js: Workspace,
  packages: Record<string, unknown>,
): Findings {
  const byPackage = new Map<string, Finding[]>();
  for (const [name, entries] of Object.entries(data)) {
    if (!Array.isArray(entries)) {
      throw new MaintenanceFailure("Unsupported Bun audit JSON shape");
    }
    byPackage.set(name, entries.map((a) => advisory(a, name, "bun")));
  }
  if (![...byPackage.values()].some((a) => a.length)) return {};
  const map = aliases(packages, "bun"), graph: Record<string, string[]> = {};
  for (const [name, entry] of Object.entries(packages)) {
    if (!Array.isArray(entry) || typeof entry[0] !== "string") {
      throw new MaintenanceFailure("Invalid bun.lock package entry");
    }
    const installed = descriptor(entry[0])[0], meta = entry[2] ?? {};
    const deps = new Set<string>(installed === name ? [] : [installed]);
    for (
      const section of [
        "dependencies",
        "optionalDependencies",
        "peerDependencies",
      ]
    ) {
      const list = (meta as Record<string, unknown>)[section];
      if (list && typeof list === "object") {
        for (const dep of Object.keys(list)) {
          deps.add(dep);
        }
      }
    }
    graph[name] = [...deps];
  }
  const reachable: Record<string, Set<string>> = {};
  for (const root of Object.keys(js.direct)) {
    const stack = [root], seen = new Set<string>();
    while (stack.length) {
      const current = stack.pop()!;
      if (seen.has(current)) continue;
      seen.add(current);
      for (const child of graph[current] ?? []) {
        if (!(child in js.direct) || child === root) stack.push(child);
      }
    }
    reachable[root] = seen;
  }
  const result: Record<string, Map<string, Finding>> = {};
  for (const [name, entries] of byPackage) {
    if (!entries.length) continue;
    let owners = name in js.direct ? [name] : buckets(name, js, map);
    if (!owners.length) {
      owners = Object.keys(js.direct).filter((root) =>
        reachable[root].has(name)
      );
    }
    if (!owners.length) {
      throw new MaintenanceFailure(
        `Cannot attach bun advisory to a direct dependency: ${name}`,
      );
    }
    for (const owner of owners) {
      for (const a of entries) (result[owner] ??= new Map()).set(a.id, a);
    }
  }
  return finish(result, false);
}
export async function audit(
  js: Workspace,
  signal?: AbortSignal,
  includeTransitive = false,
): Promise<Findings> {
  const text = await report(
    js,
    js.vitePlus || js.manager === "bun"
      ? ["pm", "audit", "--json"]
      : ["audit", "--json"],
    signal,
  );
  if (js.manager === "bun" && text.trim() === "[]") return {};
  const data = decode(text, "JavaScript audit");
  if ("error" in data) throw new MaintenanceFailure("JavaScript audit failed");
  const packages = await lock(js);
  return js.manager === "bun"
    ? bunAdvisories(data, js, packages)
    : npmAdvisories(data, js, packages, includeTransitive);
}
export function published(findings: Findings): Audit {
  return Object.fromEntries(
    Object.entries(findings).map((
      [name, rows],
    ) => [name, rows.map(({ module: _, ...a }) => a)]),
  );
}
