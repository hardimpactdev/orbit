// Dependency inventory from committed manifests and lockfiles in the Orbit monorepo.

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
  source: "checkout";
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

function join(dir: string, file: string): string {
  return dir === "." ? file : `${dir}/${file}`;
}

/** Committed Composer and JavaScript sub-projects, plus the root. */
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

// --------------------------------------------------------------- inventory

export async function buildInventory(
  dir: string,
  commit: string,
): Promise<SubProjectInventory[]> {
  const result: SubProjectInventory[] = [];
  for (const path of await candidateSubProjects(dir, commit)) {
    const ecosystems: EcosystemInventory[] = [];

    for (const ecosystem of ["composer", "npm"] as const) {
      if (ecosystem === "composer") {
        const deps = await composerFromCheckout(dir, commit, path);
        if (deps) ecosystems.push({ ecosystem, manager: "composer", source: "checkout", direct: deps });
      } else {
        const r = await npmFromCheckout(dir, commit, path);
        if (r) ecosystems.push({ ecosystem, manager: r.manager, source: "checkout", direct: r.deps });
      }
    }
    if (ecosystems.length > 0) result.push({ path, ecosystems });
  }
  return result;
}
