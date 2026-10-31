import { parse as yaml } from "jsr:@std/yaml@1.0.9";
import { descriptor, lockDecode } from "../js-maintenance/_lib/workspace.ts";
import { object, optionalText, readJson } from "./maintenance/io.ts";
import {
  PackageSchema,
  type Resolution,
  type SourceIdentity,
} from "./dependency_results_schema.ts";
import { sha256 } from "./repository_analysis.ts";

type Scope = "regular" | "development";
type Node = {
  name: string;
  version: string;
  origin: Resolution["origin"];
  dependencies: Record<string, unknown>;
  scopes: Set<Scope>;
  direct: boolean;
};
const record = (v: unknown): Record<string, unknown> =>
  v && typeof v === "object" && !Array.isArray(v)
    ? v as Record<string, unknown>
    : {};
const sections = ["dependencies", "optionalDependencies", "devDependencies"];
const origin = (raw: unknown, local = false): Resolution["origin"] =>
  local ||
    (typeof raw === "string" &&
      /^(?:file:|link:|workspace:|\.\.?\/|\/)/.test(raw))
    ? "path"
    : typeof raw === "string" && /^(?:git(?:\+|:)|ssh:|github:|git@)/.test(raw)
    ? "git"
    : "registry";
const safeVersion = (raw: unknown) => {
  if (
    typeof raw !== "string" || !raw ||
    /^(?:\/|[A-Za-z]:[\\/]|file:\/\/)/.test(raw)
  ) throw new Error("unsafe_locked_version");
  if (/^(?:https?:|git[+:@]|ssh:|github:)/.test(raw)) {
    const commit = /#([a-f0-9]{40}|[a-f0-9]{64})$/.exec(raw)?.[1];
    if (!commit) throw new Error("unverified_git_resolution");
    return `git:${commit}`;
  }
  return raw;
};
async function rows(nodes: Map<string, Node>): Promise<Resolution[]> {
  const result: Resolution[] = [];
  for (const [key, n] of [...nodes].sort(([a], [b]) => a.localeCompare(b))) {
    if (!n.scopes.size) throw new Error("unclassified_resolution");
    result.push(PackageSchema.parse({
      id: await sha256(JSON.stringify([key, n.name, n.version])),
      name: n.name,
      version: safeVersion(n.version),
      origin: n.origin,
      direct: n.direct,
      scopes: [...n.scopes].sort(),
    }));
  }
  return result;
}

async function inside(root: string, file: string) {
  const path = await Deno.realPath(file);
  if (!path.startsWith(root + "/")) throw new Error("source_path_escape");
  if ((await Deno.stat(path)).size > 16 * 1024 * 1024) {
    throw new Error("source_file_too_large");
  }
  return path;
}
async function composer(root: string, dir: string) {
  const manifest = await readJson(await inside(root, `${dir}/composer.json`)),
    lock = await readJson(await inside(root, `${dir}/composer.lock`));
  const nodes = new Map<string, Node>();
  for (
    const [section, scope] of [["packages", "regular"], [
      "packages-dev",
      "development",
    ]] as const
  ) {
    if (!Array.isArray(lock[section])) throw new Error("invalid_composer_lock");
    for (const raw of lock[section]) {
      const p = object(raw, "Composer resolution");
      if (typeof p.name !== "string" || typeof p.version !== "string") {
        throw new Error("invalid_composer_resolution");
      }
      const key = `${p.name}@${p.version}`, old = nodes.get(key);
      const dist = record(p.dist), source = record(p.source);
      const direct = ["require", "require-dev"].some((s) =>
        p.name as string in record(manifest[s])
      );
      const scopes = old?.scopes ?? new Set<Scope>();
      scopes.add(scope);
      if (p.name as string in record(manifest.require)) scopes.add("regular");
      if (p.name as string in record(manifest["require-dev"])) {
        scopes.add("development");
      }
      nodes.set(key, {
        name: p.name,
        version: p.version,
        direct,
        scopes,
        origin: dist.type === "path"
          ? "path"
          : dist.type
          ? "registry"
          : source.type === "git"
          ? "git"
          : "registry",
        dependencies: record(p.require),
      });
    }
  }
  // A shared transitive resolution can be required by both dependency graphs.
  const byName = new Map([...nodes].map(([key, n]) => [n.name, key]));
  const queue = [...nodes.keys()];
  while (queue.length) {
    const n = nodes.get(queue.shift()!)!;
    for (const name of Object.keys(n.dependencies)) {
      const key = byName.get(name), child = key ? nodes.get(key) : undefined;
      if (!child) continue;
      const before = child.scopes.size;
      for (const s of n.scopes) child.scopes.add(s);
      if (before !== child.scopes.size) queue.push(key!);
    }
  }
  return await rows(nodes);
}

/** Lockfile inventory only: never installs dependencies or imports repository code. */
export async function inventory(
  root: string,
  source: SourceIdentity,
): Promise<Resolution[]> {
  const dir = await Deno.realPath(`${root}/${source.path}`),
    real = await Deno.realPath(root);
  if (dir !== real && !dir.startsWith(real + "/")) {
    throw new Error("source_path_escape");
  }
  if (source.ecosystem === "composer") return await composer(real, dir);
  const manifest = await readJson(await inside(real, `${dir}/package.json`)),
    manager = source.package_manager;
  const file = manager === "bun"
    ? "bun.lock"
    : manager === "pnpm"
    ? "pnpm-lock.yaml"
    : "package-lock.json";
  const raw = await optionalText(await inside(real, `${dir}/${file}`));
  if (
    raw === null ||
    new TextEncoder().encode(raw).length > 16 * 1024 * 1024
  ) {
    throw new Error("lockfile_unavailable");
  }
  if (manager === "pnpm" && /(?:^|[\s:[,{])(?:&|\*)[^\s,[\]{}]+/m.test(raw)) {
    throw new Error("yaml_aliases_unsupported");
  }
  const lock = manager === "pnpm"
    ? object(yaml(raw, { schema: "json" }), "pnpm lock")
    : lockDecode(raw, manager);
  const packages = object(lock.packages, "locked packages"),
    nodes = new Map<string, Node>();
  const roots: [string, Record<string, unknown>][] = [["", manifest]];
  if (manager === "npm") {
    if (![2, 3].includes(Number(lock.lockfileVersion))) {
      throw new Error("unsupported_npm_lock_version");
    }
    for (const [key, raw] of Object.entries(packages)) {
      if (!key) continue;
      const p = object(raw, "npm resolution");
      if (p.link === true) continue; // Its workspace row is inventoried once.
      const name = typeof p.name === "string"
        ? p.name
        : key.includes("node_modules/")
        ? key.split("node_modules/").at(-1)!
        : record(
          await readJson(await inside(real, `${dir}/${key}/package.json`)),
        ).name;
      if (typeof name !== "string") throw new Error("invalid_npm_resolution");
      nodes.set(key, {
        name,
        version: !key.includes("node_modules/") && p.version === undefined
          ? "workspace"
          : safeVersion(p.version),
        origin: origin(p.resolved, !key.includes("node_modules/")),
        dependencies: {
          ...record(p.dependencies),
          ...record(p.optionalDependencies),
          ...record(p.peerDependencies),
        },
        direct: false,
        scopes: new Set(
          p.devOptional
            ? ["regular", "development"]
            : p.dev
            ? ["development"]
            : ["regular"],
        ),
      });
      if (!key.includes("node_modules/")) {
        roots.push([
          key,
          await readJson(await inside(real, `${dir}/${key}/package.json`)),
        ]);
      }
    }
  } else if (manager === "bun") {
    for (const [key, raw] of Object.entries(packages)) {
      if (!Array.isArray(raw) || typeof raw[0] !== "string") {
        throw new Error("invalid_bun_resolution");
      }
      const [name, version] = descriptor(raw[0]);
      const meta = record(raw[2]);
      nodes.set(key, {
        name,
        version: safeVersion(version),
        origin: origin(version),
        dependencies: {
          ...record(meta.dependencies),
          ...record(meta.optionalDependencies),
          ...record(meta.peerDependencies),
        },
        scopes: new Set(),
        direct: false,
      });
    }
    for (const [path, raw] of Object.entries(record(lock.workspaces))) {
      if (path) roots.push([path, record(raw)]);
    }
  } else {
    if (Number(lock.lockfileVersion) !== 9) {
      throw new Error("unsupported_pnpm_lock_version");
    }
    const snapshots = object(lock.snapshots, "pnpm snapshots");
    for (const [key, raw] of Object.entries(snapshots)) {
      const base = key.split("(")[0],
        p = object(packages[base], "pnpm package"),
        plain = base.replace(/^\//, "");
      const [name, version] = descriptor(plain);
      const snapshot = record(raw);
      nodes.set(key, {
        name,
        version: safeVersion(version),
        origin: origin(
          record(p.resolution).type === "directory"
            ? "file:relative"
            : record(p.resolution).tarball,
        ),
        dependencies: {
          ...record(snapshot.dependencies),
          ...record(snapshot.optionalDependencies),
        },
        scopes: new Set(),
        direct: false,
      });
    }
    roots.splice(
      0,
      roots.length,
      ...Object.entries(record(lock.importers)).map(([k, v]) =>
        [k === "." ? "" : k, record(v)] as [string, Record<string, unknown>]
      ),
    );
  }
  const resolve = (
    parent: string,
    name: string,
    selected?: unknown,
  ): string | undefined => {
    if (manager === "npm") {
      let prefix = parent;
      while (true) {
        const key = `${prefix ? prefix + "/" : ""}node_modules/${name}`,
          link = record(packages[key]);
        if (
          link.link === true && typeof link.resolved === "string" &&
          nodes.has(link.resolved)
        ) return link.resolved;
        if (nodes.has(key)) return key;
        if (!prefix) break;
        prefix = prefix.includes("/node_modules/")
          ? prefix.slice(0, prefix.lastIndexOf("/node_modules/"))
          : "";
      }
    } else if (manager === "bun") {
      for (const key of [`${parent}/${name}`, name]) {
        if (nodes.has(key)) return key;
      }
    } else {
      const version = typeof selected === "string"
        ? selected
        : record(selected).version;
      for (
        const key of [
          `${name}@${version}`,
          `/${name}@${version}`,
          String(version).replace(/^npm:/, ""),
        ]
      ) {
        if (nodes.has(key)) return key;
      }
    }
    return undefined;
  };
  const queue: [string, Scope][] = [];
  for (const [path, rootManifest] of roots) {
    for (const section of sections) {
      for (
        const [name, version] of Object.entries(record(rootManifest[section]))
      ) {
        const key = resolve(path, name, version);
        if (!key) {
          if (section !== "optionalDependencies") {
            throw new Error("unresolved_direct_dependency");
          }
          continue;
        }
        nodes.get(key)!.direct = true;
        queue.push([
          key,
          section === "devDependencies" ? "development" : "regular",
        ]);
      }
    }
  }
  const visited = new Set<string>();
  while (queue.length) {
    const [key, scope] = queue.shift()!, id = `${key}:${scope}`;
    if (visited.has(id)) continue;
    visited.add(id);
    const node = nodes.get(key)!;
    node.scopes.add(scope);
    for (const [name, version] of Object.entries(node.dependencies)) {
      const child = resolve(key, name, version);
      if (child) queue.push([child, scope]);
    }
  }
  return await rows(nodes);
}
