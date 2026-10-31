import { parse as yaml } from "jsr:@std/yaml@1.0.12";
import { Refusal, requirePolicy } from "./refusal.ts";
import {
  constraintChange,
  exactVersion,
  lockedChange,
  matches,
  registryRange,
} from "./versions.ts";

export type RecordValue = Record<string, unknown>;
const jsFields = [
  "dependencies",
  "devDependencies",
  "optionalDependencies",
  "peerDependencies",
];
const composerFields = ["require", "require-dev"];
const npmName = /^(?:@[a-z0-9-][a-z0-9_.-]*\/)?[a-z0-9-][a-z0-9_.-]*$/;
const composerName = /^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/;
const platform =
  /^(?:php(?:-[a-z0-9]+)?|hhvm|ext-[a-z0-9_-]+|lib-[a-z0-9_-]+|composer(?:-(?:plugin|runtime)-api)?)$/;
const own = (value: RecordValue, key: string) => Object.hasOwn(value, key);
export function record(value: unknown): RecordValue {
  requirePolicy(
    value !== null && typeof value === "object" && !Array.isArray(value),
  );
  return value as RecordValue;
}
function map(value: unknown): RecordValue {
  return record(value === undefined ? {} : value);
}
function list(value: unknown): unknown[] {
  requirePolicy(Array.isArray(value));
  return value;
}
export function canonical(value: unknown): string {
  const normalize = (v: unknown): unknown =>
    Array.isArray(v)
      ? v.map(normalize)
      : v !== null && typeof v === "object"
      ? Object.fromEntries(
        Object.entries(v).sort(([a], [b]) => a.localeCompare(b)).map((
          [k, item],
        ) => [k, normalize(item)]),
      )
      : v;
  return JSON.stringify(normalize(value ?? null));
}
export function strictJson(text: string, jsonc = false): RecordValue {
  try {
    if (jsonc) {
      requirePolicy(!text.includes("\0") && !text.startsWith("#!"));
      text = text.replace(/^\uFEFF/, "").replace(
        /"(?:[^"\\]|\\.)*"|\/\/[^\r\n]*|\/\*[\s\S]*?\*\//g,
        (m) => m.startsWith('"') ? m : " ",
      )
        .replace(
          /"(?:[^"\\]|\\.)*"|,\s*(?=[}\]])/g,
          (m) => m.startsWith('"') ? m : "",
        );
    }
    const parsed = record(JSON.parse(text));
    const stack: Set<string>[] = [];
    for (const match of text.matchAll(/"(?:[^"\\]|\\.)*"|[{}\[\]]/g)) {
      const token = match[0];
      if (token === "{" || token === "[") stack.push(new Set());
      else if (token === "}" || token === "]") stack.pop();
      else if (/^\s*:/.test(text.slice(match.index! + token.length))) {
        const key = JSON.parse(token), keys = stack.at(-1)!;
        requirePolicy(!keys.has(key));
        keys.add(key);
      }
    }
    return parsed;
  } catch (error) {
    if (error instanceof Refusal) throw error;
    throw new Refusal("dependency_merge.contents_unreadable");
  }
}
function closed(value: RecordValue, keys: string[]): void {
  requirePolicy(Object.keys(value).every((key) => keys.includes(key)));
}
function name(value: unknown, composer = false): string {
  requirePolicy(
    typeof value === "string" &&
      (composer ? composerName : npmName).test(value),
  );
  return value;
}
function declarations(
  value: RecordValue,
  composer = false,
): Map<string, { constraint: string; optional: boolean }> {
  const result = new Map<string, { constraint: string; optional: boolean }>();
  for (const field of composer ? composerFields : jsFields) {
    for (const [key, raw] of Object.entries(map(value[field]))) {
      if (composer && platform.test(key)) {
        requirePolicy(typeof raw === "string");
        continue;
      }
      name(key, composer);
      requirePolicy(typeof raw === "string");
      registryRange(raw, composer);
      const optional = field === "optionalDependencies" ||
        (field === "peerDependencies" &&
          map(map(value.peerDependenciesMeta)[key]).optional === true);
      // optionalDependencies override regular dependencies, as npm and Bun do.
      if (field === "peerDependencies" && result.has(key)) {
        requirePolicy(result.get(key)!.constraint === raw);
      }
      if (!result.has(key) || field === "optionalDependencies") {
        result.set(key, { constraint: raw, optional });
      }
    }
  }
  return result;
}
function url(value: unknown, hosts: string[]): void {
  requirePolicy(
    typeof value === "string" &&
      !Array.from(value).some((c) =>
        c.charCodeAt(0) <= 32 || c.charCodeAt(0) === 127 || c === "\\"
      ),
  );
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    requirePolicy(false);
  }
  requirePolicy(
    parsed.protocol === "https:" && hosts.includes(parsed.hostname) &&
      !parsed.username && !parsed.password && !parsed.port && !parsed.search &&
      !parsed.hash,
  );
}
function integrity(value: unknown): void {
  requirePolicy(typeof value === "string");
  const match = /^(sha512|sha256|sha1)-([A-Za-z0-9+/]+=*)$/.exec(value);
  requirePolicy(match);
  try {
    const bytes = atob(match[2]);
    requirePolicy(
      btoa(bytes) === match[2] &&
        bytes.length === ({ sha512: 64, sha256: 32, sha1: 20 }[match[1]]),
    );
  } catch {
    requirePolicy(false);
  }
}
function references(value: unknown, edge = false): void {
  if (value !== null && typeof value === "object") {
    for (const [key, item] of Object.entries(value)) {
      references(key);
      references(
        item,
        edge ||
          [
            "version",
            "specifier",
            "resolution",
            "resolved",
            "tarball",
            "dependencies",
            "devDependencies",
            "optionalDependencies",
            "peerDependencies",
            "registry",
            "registries",
          ].includes(key),
      );
    }
  } else if (typeof value === "string") {
    requirePolicy(
      !/(?:^|@)(?:git\+|git:|ssh:|file:|link:|workspace:|github:|gitlab:|bitbucket:)/i
        .test(value),
    );
    if (edge && /^https?:\/\//i.test(value)) url(value, ["registry.npmjs.org"]);
  }
}
function manifest(path: string, before: RecordValue, after: RecordValue): void {
  before = structuredClone(before);
  after = structuredClone(after);
  for (const field of path === "composer.json" ? composerFields : jsFields) {
    requirePolicy(own(before, field) === own(after, field));
    const a = map(before[field]), b = map(after[field]);
    requirePolicy(
      canonical(Object.keys(a).sort()) === canonical(Object.keys(b).sort()),
    );
    for (const key of Object.keys(a)) {
      constraintChange(a[key], b[key], path === "composer.json");
    }
    delete before[field];
    delete after[field];
  }
  requirePolicy(canonical(before) === canonical(after));
}

export type RegistryRead = (
  name: string,
  version: string,
) => Promise<RecordValue>;
type Package = {
  name: string;
  version: string;
  record: RecordValue;
  edges: Map<string, { constraint: string; optional: boolean }>;
  explicit?: Map<string, string>;
};
type Graph = {
  packages: Map<string, Package>;
  roots: Map<string, string>;
  children: Map<string, string[]>;
};
function reachable(graph: Graph): void {
  const seen = new Set<string>(), queue = [...graph.roots.values()];
  while (queue.length) {
    const id = queue.pop()!;
    if (seen.has(id)) continue;
    seen.add(id);
    queue.push(...graph.children.get(id) ?? []);
  }
  requirePolicy(graph.packages.size === seen.size);
}
function packageRecord(key: string, raw: unknown): Package {
  const value = record(raw);
  name(key);
  requirePolicy(value.name === undefined || value.name === key);
  const version = exactVersion(value.version);
  requirePolicy(value.link === undefined || value.link === false);
  for (
    const flag of [
      "dev",
      "optional",
      "devOptional",
      "peer",
      "inBundle",
      "hasInstallScript",
    ]
  ) if (own(value, flag)) requirePolicy(typeof value[flag] === "boolean");
  requirePolicy(
    value.resolved ===
      `https://registry.npmjs.org/${key}/-/${
        key.split("/").at(-1)
      }-${version}.tgz`,
  );
  integrity(value.integrity);
  return { name: key, version, record: value, edges: declarations(value) };
}
function packageLocation(location: string): string {
  const atoms = location.split("node_modules/");
  requirePolicy(atoms[0] === "" && atoms.length > 1);
  for (const [i, part] of atoms.slice(1).entries()) {
    name(i === atoms.length - 2 ? part : part.replace(/\/$/, ""));
  }
  return atoms.at(-1)!;
}
function parentLocation(location: string): string {
  const at = location.lastIndexOf("/node_modules/");
  return at < 0 ? "" : location.slice(0, at);
}
function resolveInstalled(
  packages: Map<string, Package>,
  from: string,
  key: string,
): string | undefined {
  let scope = from;
  while (true) {
    const id = scope ? `${scope}/node_modules/${key}` : `node_modules/${key}`;
    if (packages.has(id)) return id;
    if (!scope) return;
    scope = parentLocation(scope);
  }
}
function graphLinks(
  graph: Graph,
  root: RecordValue,
  resolve: (
    from: string,
    key: string,
    edge: { constraint: string; optional: boolean },
  ) => string | undefined,
): void {
  const visit = (
    from: string,
    edges: Map<string, { constraint: string; optional: boolean }>,
  ) => {
    const children: string[] = [];
    for (const [key, edge] of edges) {
      const target = resolve(from, key, edge);
      if (target === undefined) {
        requirePolicy(edge.optional);
        continue;
      }
      const p = graph.packages.get(target);
      requirePolicy(p && p.name === key && matches(p.version, edge.constraint));
      children.push(target);
      if (from === "") graph.roots.set(key, target);
    }
    if (from) graph.children.set(from, children);
  };
  visit("", declarations(root));
  for (const [id, p] of graph.packages) visit(id, p.edges);
  reachable(graph);
}
function npmGraph(lock: RecordValue, root: RecordValue): Graph {
  requirePolicy(
    [2, 3].includes(Number(lock.lockfileVersion)) &&
      typeof lock.lockfileVersion === "number" && !own(root, "workspaces") &&
      !own(lock, "workspaces"),
  );
  const rows = record(lock.packages),
    rootRow = Object.keys(rows).length ? record(rows[""]) : {};
  requirePolicy(
    canonical(Object.fromEntries(declarations(root))) ===
      canonical(Object.fromEntries(declarations(rootRow))),
  );
  for (const field of ["name", "version"]) {
    if (own(root, field) && own(rootRow, field)) {
      requirePolicy(root[field] === rootRow[field]);
    }
  }
  const graph: Graph = {
    packages: new Map(),
    roots: new Map(),
    children: new Map(),
  };
  for (const [id, p] of Object.entries(rows)) {
    if (id !== "") {
      graph.packages.set(id, packageRecord(packageLocation(id), p));
    }
  }
  for (const id of graph.packages.keys()) {
    const parent = parentLocation(id);
    requirePolicy(parent === "" || graph.packages.has(parent));
  }
  graphLinks(
    graph,
    root,
    (from, key) => resolveInstalled(graph.packages, from, key),
  );
  if (lock.lockfileVersion === 2 || own(lock, "dependencies")) {
    const visit = (value: unknown, parent = "") => {
      for (const [key, raw] of Object.entries(record(value))) {
        const p = packageRecord(key, raw),
          id = `${parent}node_modules/${key}`,
          actual = graph.packages.get(id);
        requirePolicy(actual);
        for (const field of ["version", "resolved", "integrity"]) {
          requirePolicy(p.record[field] === actual.record[field]);
        }
        if (own(p.record, "dependencies")) {
          visit(p.record.dependencies, `${id}/`);
        }
      }
    };
    visit(lock.dependencies);
  }
  return graph;
}
function locator(raw: string): { base: string; name: string; version: string } {
  const base = raw.split("(")[0], at = base.lastIndexOf("@");
  requirePolicy(at > 0);
  const key = name(base.slice(0, at)),
    version = exactVersion(base.slice(at + 1));
  requirePolicy(
    !Array.from(raw).some((c) =>
      c.charCodeAt(0) <= 32 || c.charCodeAt(0) === 127
    ),
  );
  return { base, name: key, version };
}
function pnpmGraph(lock: RecordValue, root: RecordValue): Graph {
  requirePolicy(lock.lockfileVersion === "9.0" || lock.lockfileVersion === 9);
  for (const r of [root, lock]) {
    requirePolicy(
      !["workspaces", "catalog", "catalogs"].some((k) => own(r, k)),
    );
  }
  const importers = record(lock.importers);
  requirePolicy(canonical(Object.keys(importers)) === '["."]');
  const importer = record(importers["."]),
    metadata = map(lock.packages),
    snapshots = map(lock.snapshots);
  const graph: Graph = {
      packages: new Map(),
      roots: new Map(),
      children: new Map(),
    },
    used = new Set<string>();
  for (const [id, raw] of Object.entries(metadata)) {
    const loc = locator(id), p = record(raw);
    requirePolicy(id === loc.base);
    requirePolicy(p.name === undefined || p.name === loc.name);
    requirePolicy(p.version === undefined || p.version === loc.version);
    const resolution = record(p.resolution);
    closed(resolution, ["integrity"]);
    integrity(resolution.integrity);
  }
  for (const [id, raw] of Object.entries(snapshots)) {
    const loc = locator(id),
      p = record(metadata[loc.base]),
      snapshot = record(raw),
      edges = new Map<string, { constraint: string; optional: boolean }>(),
      explicit = new Map<string, string>();
    for (const field of ["dependencies", "optionalDependencies"]) {
      for (const [key, v] of Object.entries(map(snapshot[field]))) {
        name(key);
        requirePolicy(typeof v === "string");
        exactVersion(v.split("(")[0]);
        edges.set(key, {
          constraint: locateVersion(v),
          optional: field === "optionalDependencies",
        });
        explicit.set(key, `${key}@${v}`);
      }
    }
    graph.packages.set(id, {
      name: loc.name,
      version: loc.version,
      record: p,
      edges,
      explicit,
    });
    used.add(loc.base);
  }
  requirePolicy(used.size === Object.keys(metadata).length);
  const rootIds = new Map<string, string>();
  for (
    const field of ["dependencies", "devDependencies", "optionalDependencies"]
  ) {
    const wanted = map(root[field]), actual = map(importer[field]);
    requirePolicy(
      canonical(Object.keys(wanted).sort()) ===
        canonical(Object.keys(actual).sort()),
    );
    for (const [key, raw] of Object.entries(actual)) {
      name(key);
      const item = record(raw);
      requirePolicy(
        item.specifier === wanted[key] && typeof item.version === "string",
      );
      exactVersion(item.version.split("(")[0]);
      rootIds.set(key, `${key}@${item.version}`);
    }
  }
  graphLinks(
    graph,
    root,
    (from, key) =>
      from ? graph.packages.get(from)?.explicit?.get(key) : rootIds.get(key),
  );
  return graph;
}
function locateVersion(value: string): string {
  return exactVersion(value.split("(")[0]);
}
function bunName(id: string): string {
  const parts = id.split("/"), last = parts.pop()!;
  return parts.at(-1)?.startsWith("@") ? `${parts.pop()}/${last}` : last;
}
function bunGraph(lock: RecordValue, root: RecordValue): Graph {
  requirePolicy(
    [1, 2].includes(Number(lock.lockfileVersion)) &&
      typeof lock.lockfileVersion === "number",
  );
  const workspaces = record(lock.workspaces);
  requirePolicy(canonical(Object.keys(workspaces)) === '[""]');
  const workspace = record(workspaces[""]);
  requirePolicy(!own(root, "workspaces"));
  requirePolicy(
    canonical(Object.fromEntries(declarations(root))) ===
      canonical(Object.fromEntries(declarations(workspace))),
  );
  const graph: Graph = {
    packages: new Map(),
    roots: new Map(),
    children: new Map(),
  };
  for (const [id, raw] of Object.entries(record(lock.packages))) {
    const row = list(raw);
    requirePolicy(
      row.length === 4 && typeof row[0] === "string" && row[1] === "",
    );
    const loc = locator(row[0]);
    requirePolicy(loc.base === row[0] && loc.name === bunName(id));
    integrity(row[3]);
    const metadata = record(row[2]);
    graph.packages.set(id, {
      name: loc.name,
      version: loc.version,
      record: metadata,
      edges: declarations(metadata),
    });
  }
  graphLinks(graph, root, (from, key) => {
    let scope = from;
    while (true) {
      const id = scope ? `${scope}/${key}` : key;
      if (graph.packages.has(id)) return id;
      if (!scope) return;
      const p = scope.lastIndexOf("/");
      scope = p < 0 ? "" : scope.slice(0, p);
    }
  });
  return graph;
}
function jsGraph(path: string, lock: RecordValue, root: RecordValue): Graph {
  references(lock);
  return path === "pnpm-lock.yaml"
    ? pnpmGraph(lock, root)
    : path === "bun.lock"
    ? bunGraph(lock, root)
    : npmGraph(lock, root);
}
function locked(path: string, text: string): RecordValue {
  if (path !== "pnpm-lock.yaml") return strictJson(text, path === "bun.lock");
  try {
    return record(yaml(text));
  } catch (error) {
    if (error instanceof Refusal) throw error;
    throw new Refusal("dependency_merge.contents_unreadable");
  }
}

const composerKeys = [
  "name",
  "version",
  "source",
  "dist",
  "require",
  "require-dev",
  "conflict",
  "replace",
  "provide",
  "suggest",
  "type",
  "target-dir",
  "autoload",
  "autoload-dev",
  "include-path",
  "notification-url",
  "license",
  "authors",
  "description",
  "homepage",
  "keywords",
  "support",
  "funding",
  "time",
  "extra",
  "bin",
  "abandoned",
  "default-branch",
];
const attestedFields = [
  "dist",
  "source",
  "type",
  "autoload",
  "extra",
  "bin",
  "require",
  "require-dev",
  "conflict",
  "replace",
  "provide",
  "autoload-dev",
  "target-dir",
  "include-path",
];
function composerPackages(lock: RecordValue): Map<string, Package> {
  const packages = new Map<string, Package>();
  for (const raw of [...list(lock.packages), ...list(lock["packages-dev"])]) {
    const p = record(raw), key = name(p.name, true);
    requirePolicy(!packages.has(key));
    closed(p, composerKeys);
    const version = exactVersion(p.version);
    requirePolicy(
      p["notification-url"] === undefined ||
        p["notification-url"] === "https://packagist.org/downloads/",
    );
    if (own(p, "dist")) {
      const dist = record(p.dist);
      closed(dist, ["url", "type", "reference", "shasum"]);
      requirePolicy(["zip", "tar"].includes(String(dist.type)));
      url(dist.url, [
        "repo.packagist.org",
        "api.github.com",
        "codeload.github.com",
      ]);
    } else requirePolicy(p.type === "metapackage");
    if (own(p, "source")) {
      const source = record(p.source);
      closed(source, ["url", "type", "reference"]);
      requirePolicy(source.type === "git");
      url(source.url, ["github.com"]);
    }
    packages.set(key, { name: key, version, record: p, edges: new Map() });
  }
  return packages;
}
async function composerGraph(
  lock: RecordValue,
  root: RecordValue,
  base: RecordValue,
  registry: RegistryRead,
): Promise<Graph> {
  closed(lock, [
    "_readme",
    "content-hash",
    "packages",
    "packages-dev",
    "aliases",
    "minimum-stability",
    "stability-flags",
    "prefer-stable",
    "prefer-lowest",
    "platform",
    "platform-dev",
    "plugin-api-version",
  ]);
  requirePolicy(!own(lock, "aliases") || canonical(lock.aliases) === "[]");
  requirePolicy(!own(root, "workspaces"));
  const graph: Graph = {
    packages: composerPackages(lock),
    roots: new Map(),
    children: new Map(),
  };
  const before = new Set<string>();
  for (const raw of [...list(base.packages), ...list(base["packages-dev"])]) {
    before.add(name(record(raw).name, true));
  }
  const providers = new Map<string, Set<string | null>>();
  const provide = (r: RecordValue, owner: string | null) => {
    for (const field of ["provide", "replace"]) {
      for (const [key, constraint] of Object.entries(map(r[field]))) {
        name(key, true);
        requirePolicy(typeof constraint === "string");
        if (constraint !== "self.version") {
          registryRange(constraint, true);
        }
        const set = providers.get(key) ?? new Set();
        set.add(owner);
        providers.set(key, set);
      }
    }
  };
  if (root.name !== undefined) {
    providers.set(name(root.name, true), new Set([null]));
  }
  provide(root, null);
  for (const [key, p] of graph.packages) {
    const trusted = await registry(key, p.version);
    for (const field of attestedFields) {
      requirePolicy(canonical(p.record[field]) === canonical(trusted[field]));
    }
    provide(p.record, key);
  }
  const resolve = (key: string): string | null => {
    if (platform.test(key)) return null;
    if (graph.packages.has(key)) return key;
    const choices = providers.get(key);
    requirePolicy(choices?.size === 1);
    const target = [...choices][0];
    requirePolicy(target === null || (before.has(target) && !before.has(key)));
    return target;
  };
  const visit = (r: RecordValue, from: string | null) => {
    const children: string[] = [];
    for (const field of from === null ? composerFields : ["require"]) {
      for (const [key, constraint] of Object.entries(map(r[field]))) {
        requirePolicy(platform.test(key) || composerName.test(key));
        requirePolicy(typeof constraint === "string");
        registryRange(constraint, true);
        const target = resolve(key);
        if (target !== null) {
          children.push(target);
          if (from === null) {
            graph.roots.set(target, target);
          }
        }
      }
    }
    if (from !== null) {
      for (const value of Object.values(map(r["require-dev"]))) {
        registryRange(value, true);
      }
      graph.children.set(from, children);
    }
  };
  visit(root, null);
  for (const [key, p] of graph.packages) visit(p.record, key);
  reachable(graph);
  return graph;
}

/** Root-file content policy. All callers execute inside the private callback. */
export async function validateContents(
  path: string,
  headText: string,
  baseText: string,
  manifestText: string | null,
  registry: RegistryRead,
): Promise<void> {
  if (path === "composer.json" || path === "package.json") {
    manifest(path, strictJson(baseText), strictJson(headText));
    return;
  }
  requirePolicy(
    [
      "composer.lock",
      "package-lock.json",
      "npm-shrinkwrap.json",
      "pnpm-lock.yaml",
      "bun.lock",
    ].includes(path),
  );
  const head = locked(path, headText), base = locked(path, baseText);
  requirePolicy(manifestText !== null);
  const root = strictJson(manifestText);
  const graph = path === "composer.lock"
    ? await composerGraph(head, root, base, registry)
    : jsGraph(path, head, root);
  let previous: Map<string, Package>;
  if (path === "composer.lock") {
    previous = new Map();
    for (const p of [...list(base.packages), ...list(base["packages-dev"])]) {
      const r = record(p);
      previous.set(name(r.name, true), {
        name: String(r.name),
        version: exactVersion(r.version),
        record: r,
        edges: new Map(),
      });
    }
  } else {
    // Build names from authoritative base identities without requiring the old
    // graph to match the head manifest's deliberately updated constraints.
    previous = new Map();
    for (const [id, raw] of Object.entries(record(base.packages))) {
      if (id === "" && path !== "bun.lock") continue;
      const key = path === "pnpm-lock.yaml"
        ? locator(id).name
        : path === "bun.lock"
        ? locator(String(list(raw)[0])).name
        : packageLocation(id);
      const version = path === "pnpm-lock.yaml"
        ? locator(id).version
        : path === "bun.lock"
        ? locator(String(list(raw)[0])).version
        : exactVersion(record(raw).version);
      previous.set(id, { name: key, version, record: {}, edges: new Map() });
    }
    const names = new Set([...previous.values()].map((p) => p.name));
    for (const p of graph.packages.values()) requirePolicy(names.has(p.name));
  }
  for (const [key, id] of graph.roots) {
    const current = graph.packages.get(id)!;
    let old: Package | undefined;
    if (path === "composer.lock") old = previous.get(key);
    else if (path === "pnpm-lock.yaml") {
      const importer = record(record(base.importers)["."]);
      for (
        const field of [
          "dependencies",
          "devDependencies",
          "optionalDependencies",
        ]
      ) {
        const raw = map(importer[field])[key];
        if (raw !== undefined) {
          const dependency = record(raw);
          requirePolicy(typeof dependency.version === "string");
          old = previous.get(`${key}@${dependency.version.split("(")[0]}`);
        }
      }
    } else {old = previous.get(
        path === "bun.lock" ? key : `node_modules/${key}`,
      );}
    requirePolicy(old);
    lockedChange(old.version, current.version);
  }
}

/** Fresh P2 metadata, including Composer's inherited minified records. */
export async function packagistVersion(
  key: string,
  version: string,
  request: typeof fetch,
  signal?: AbortSignal,
): Promise<RecordValue> {
  name(key, true);
  exactVersion(version);
  let body: RecordValue;
  try {
    const response = await request(
      `https://repo.packagist.org/p2/${key}.json`,
      {
        redirect: "error",
        signal: signal
          ? AbortSignal.any([signal, AbortSignal.timeout(10000)])
          : AbortSignal.timeout(10000),
      },
    );
    requirePolicy(response.ok, "dependency_merge.packagist_unavailable");
    body = record(await boundedJson(response, 8 * 1024 * 1024));
  } catch {
    throw new Refusal("dependency_merge.packagist_unavailable");
  }
  requirePolicy(
    body.minified === undefined || body.minified === "composer/2.0",
    "dependency_merge.packagist_unavailable",
  );
  const rows = list(record(body.packages)[key]);
  let expanded: RecordValue = {};
  for (const raw of rows) {
    const row = record(raw);
    if (body.minified === undefined) expanded = {};
    for (const [field, value] of Object.entries(row)) {
      if (value === "__unset") delete expanded[field];
      else expanded[field] = value;
    }
    if (
      expanded.name === key && typeof expanded.version === "string" &&
      expanded.version.replace(/^v/, "") === version.replace(/^v/, "")
    ) return expanded;
  }
  throw new Refusal("dependency_merge.contents_forbidden");
}
export async function boundedJson(
  response: Response,
  limit = 8 * 1024 * 1024,
): Promise<unknown> {
  requirePolicy(response.body, "dependency_merge.contents_unreadable");
  const reader = response.body.getReader(), chunks: Uint8Array[] = [];
  let size = 0;
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      size += value.byteLength;
      requirePolicy(size <= limit, "dependency_merge.contents_unreadable");
      chunks.push(value);
    }
  } finally {
    await reader.cancel();
  }
  const joined = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) {
    joined.set(chunk, offset);
    offset += chunk.byteLength;
  }
  try {
    return JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(joined));
  } catch {
    throw new Refusal("dependency_merge.contents_unreadable");
  }
}
