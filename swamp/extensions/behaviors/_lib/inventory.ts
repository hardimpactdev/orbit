// Dependency inventory for the Orbit monorepo.
// Primary source: the Gateway's stored inventory (GET /api/v1/instances/{id}/dependencies).
// Fallback: manifests and lockfiles read with `git show` from a scratch clone at
// the planned commit, used whenever the Gateway snapshot is missing, failed, or
// its file hashes no longer match that commit.

export type Scope = "regular" | "development";

export interface DirectDep {
  name: string; // name in the manifest (an npm alias keeps its alias name)
  registryName: string; // name to look up in Packagist or npm
  constraint: string;
  scope: Scope;
  locked: string | null;
}

export interface EcosystemInventory {
  ecosystem: "composer" | "npm";
  manager: "composer" | "bun" | "npm";
  source: "gateway" | "checkout";
  reason: string | null; // why the checkout was read instead of the Gateway
  observedAt: string | null;
  direct: DirectDep[];
}

export interface SubProjectInventory {
  path: string; // "." for the repository root
  ecosystems: EcosystemInventory[];
}

const PLATFORM = /^(php|php-64bit|hhvm|ext-.+|lib-.+|composer(-plugin|-runtime)?-api|composer)$/;

// ---------------------------------------------------------------- git helpers

export async function git(dir: string, args: string[]): Promise<string> {
  const out = await new Deno.Command("git", { args: ["-C", dir, ...args], stdout: "piped", stderr: "piped" })
    .output();
  if (!out.success) {
    throw new Error(`git ${args.join(" ")} failed: ${new TextDecoder().decode(out.stderr).trim()}`);
  }
  return new TextDecoder().decode(out.stdout);
}

async function show(dir: string, commit: string, path: string): Promise<string | null> {
  try {
    return await git(dir, ["show", `${commit}:${path}`]);
  } catch {
    return null;
  }
}

async function sha256(text: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, "0")).join("");
}

function join(dir: string, file: string): string {
  return dir === "." ? file : `${dir}/${file}`;
}

/** Sub-projects the Gateway's monorepo rule would scan, plus the root. */
export async function candidateSubProjects(dir: string, commit: string): Promise<string[]> {
  const found: string[] = ["."];
  for (const top of ["apps", "packages"]) {
    let names: string[] = [];
    try {
      names = (await git(dir, ["ls-tree", "-d", "--name-only", `${commit}:${top}`])).split("\n").filter(Boolean);
    } catch {
      continue;
    }
    for (const name of names) {
      const files = (await git(dir, ["ls-tree", "--name-only", `${commit}:${top}/${name}`])).split("\n");
      const js = files.includes("package.json") &&
        ["package-lock.json", "npm-shrinkwrap.json", "pnpm-lock.yaml", "bun.lock"].some((f) => files.includes(f));
      if (files.includes("composer.json") || js) found.push(`${top}/${name}`);
    }
  }
  return found;
}

// ------------------------------------------------------------ Gateway reader

interface GatewayEco {
  state: string;
  error_code: string | null;
  snapshot: null | {
    observed_at: string;
    source: { file_hashes: Record<string, string | null> };
    graph: {
      resolutions: { name: string; version: string }[];
      requirements: { from: string | null; name: string; constraint: string; kind: string; scope: string }[];
    };
  };
}

interface GatewayInventory {
  composer: GatewayEco;
  javascript: GatewayEco;
  sub_projects: Record<string, { composer: GatewayEco; javascript: GatewayEco }>;
}

export async function fetchGatewayInventory(
  gatewayUrl: string,
  caCertPath: string,
  instanceId: number,
): Promise<GatewayInventory> {
  const url = `${gatewayUrl.replace(/\/$/, "")}/api/v1/instances/${instanceId}/dependencies`;
  const out = await new Deno.Command("curl", {
    args: ["-sS", "-m", "120", "--fail-with-body", "--cacert", caCertPath, "-H", "Accept: application/json", url],
    stdout: "piped",
    stderr: "piped",
  }).output();
  if (!out.success) {
    throw new Error(`GET ${url} failed: ${new TextDecoder().decode(out.stderr).trim()}`);
  }
  return JSON.parse(new TextDecoder().decode(out.stdout)).data as GatewayInventory;
}

async function hashesMatch(
  dir: string,
  commit: string,
  path: string,
  hashes: Record<string, string | null>,
): Promise<string | null> {
  for (const [file, expected] of Object.entries(hashes)) {
    const content = await show(dir, commit, join(path, file));
    if (expected === null && content !== null) return `${file} exists at ${commit.slice(0, 9)} but not in the scan`;
    if (expected !== null && content === null) return `${file} is gone at ${commit.slice(0, 9)}`;
    if (expected !== null && content !== null && (await sha256(content)) !== expected) {
      return `${file} changed since the scan`;
    }
  }
  return null;
}

function fromGateway(eco: GatewayEco, ecosystem: "composer" | "npm"): DirectDep[] {
  const snap = eco.snapshot!;
  const locked = new Map(snap.graph.resolutions.map((r) => [r.name, r.version]));
  return snap.graph.requirements
    .filter((r) => r.from === null && r.kind === "dependency" && !PLATFORM.test(r.name))
    .map((r) => ({
      name: r.name,
      registryName: ecosystem === "npm" ? aliasTarget(r.name, r.constraint) : r.name,
      constraint: r.constraint,
      scope: r.scope === "development" ? "development" : "regular",
      locked: locked.get(r.name) ?? null,
    }));
}

// ----------------------------------------------------------- checkout reader

function aliasTarget(name: string, constraint: string): string {
  if (!constraint.startsWith("npm:")) return name;
  const spec = constraint.slice(4);
  const at = spec.lastIndexOf("@");
  return at > 0 ? spec.slice(0, at) : spec;
}

async function composerFromCheckout(dir: string, commit: string, path: string): Promise<DirectDep[] | null> {
  const manifest = await show(dir, commit, join(path, "composer.json"));
  if (manifest === null) return null;
  const json = JSON.parse(manifest);
  const lockText = await show(dir, commit, join(path, "composer.lock"));
  const locked = new Map<string, string>();
  if (lockText) {
    const lock = JSON.parse(lockText);
    for (const p of [...(lock.packages ?? []), ...(lock["packages-dev"] ?? [])]) locked.set(p.name, p.version);
  }
  const deps: DirectDep[] = [];
  for (const [scope, key] of [["regular", "require"], ["development", "require-dev"]] as const) {
    for (const [name, constraint] of Object.entries((json[key] ?? {}) as Record<string, string>)) {
      if (PLATFORM.test(name)) continue;
      deps.push({ name, registryName: name, constraint, scope, locked: locked.get(name) ?? null });
    }
  }
  return deps;
}

async function npmFromCheckout(
  dir: string,
  commit: string,
  path: string,
): Promise<{ manager: "bun" | "npm"; deps: DirectDep[] } | null> {
  const manifest = await show(dir, commit, join(path, "package.json"));
  if (manifest === null) return null;
  const json = JSON.parse(manifest);
  const locked = new Map<string, string>();
  let manager: "bun" | "npm" | null = null;
  const bun = await show(dir, commit, join(path, "bun.lock"));
  if (bun !== null) {
    manager = "bun";
    // bun.lock is JSON with trailing commas.
    const lock = JSON.parse(bun.replace(/,(\s*[}\]])/g, "$1"));
    for (const [name, entry] of Object.entries((lock.packages ?? {}) as Record<string, unknown[]>)) {
      const id = String(entry[0] ?? "");
      locked.set(name, id.slice(id.lastIndexOf("@") + 1));
    }
  } else {
    const npmLock = await show(dir, commit, join(path, "npm-shrinkwrap.json")) ??
      await show(dir, commit, join(path, "package-lock.json"));
    if (npmLock === null) return null;
    manager = "npm";
    const lock = JSON.parse(npmLock);
    for (const [key, entry] of Object.entries((lock.packages ?? {}) as Record<string, { version?: string }>)) {
      if (key.startsWith("node_modules/") && entry.version) locked.set(key.slice(13), entry.version);
    }
  }
  const deps: DirectDep[] = [];
  for (const [scope, key] of [["regular", "dependencies"], ["development", "devDependencies"]] as const) {
    for (const [name, constraint] of Object.entries((json[key] ?? {}) as Record<string, string>)) {
      deps.push({
        name,
        registryName: aliasTarget(name, constraint),
        constraint,
        scope,
        locked: locked.get(name) ?? null,
      });
    }
  }
  return { manager, deps };
}

// --------------------------------------------------------------- the merge

export async function buildInventory(
  dir: string,
  commit: string,
  gateway: GatewayInventory | null,
  gatewayError: string | null,
): Promise<SubProjectInventory[]> {
  const result: SubProjectInventory[] = [];
  for (const path of await candidateSubProjects(dir, commit)) {
    const gw = gateway === null ? null : path === "." ? { composer: gateway.composer, javascript: gateway.javascript }
    : gateway.sub_projects[path] ?? null;
    const ecosystems: EcosystemInventory[] = [];

    for (const ecosystem of ["composer", "npm"] as const) {
      const g = gw === null ? null : ecosystem === "composer" ? gw.composer : gw.javascript;
      let reason: string | null = gateway === null ? `Gateway unavailable: ${gatewayError}` : null;
      if (reason === null) {
        if (g === null || g === undefined) reason = "no Gateway inventory for this sub-project";
        else if (g.error_code !== null || g.snapshot === null) {
          reason = `Gateway scan state ${g.state} (${g.error_code ?? "no snapshot"})`;
        } else reason = await hashesMatch(dir, commit, path, g.snapshot.source.file_hashes);
      }

      if (reason === null && g && g.state === "present" && g.snapshot) {
        ecosystems.push({
          ecosystem,
          manager: ecosystem === "composer" ? "composer" : guessManager(g.snapshot.source.file_hashes),
          source: "gateway",
          reason: null,
          observedAt: g.snapshot.observed_at,
          direct: fromGateway(g, ecosystem),
        });
        continue;
      }
      if (reason === null) continue; // Gateway says absent and the files agree

      if (ecosystem === "composer") {
        const deps = await composerFromCheckout(dir, commit, path);
        if (deps) ecosystems.push({ ecosystem, manager: "composer", source: "checkout", reason, observedAt: null, direct: deps });
      } else {
        const r = await npmFromCheckout(dir, commit, path);
        if (r) ecosystems.push({ ecosystem, manager: r.manager, source: "checkout", reason, observedAt: null, direct: r.deps });
      }
    }
    if (ecosystems.length > 0) result.push({ path, ecosystems });
  }
  return result;
}

function guessManager(hashes: Record<string, string | null>): "bun" | "npm" {
  return hashes["bun.lock"] ? "bun" : "npm";
}
