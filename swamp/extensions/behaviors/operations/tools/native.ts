import { z } from "npm:zod@4.4.3";
import { type ToolInput, ToolRefusal } from "./contract.ts";

type Input = Pick<ToolInput, "manager" | "package" | "platform" | "architecture" | "repository" | "repository_packages" | "remove_repository"> & { user: string; kind?: string };
type Step = { status: string; outputs?: Record<string, unknown> } | null;
const object = (v: unknown) => z.record(z.string(), z.unknown()).parse(v);
export function stdout(step: Step, absent = false): string {
  if (step?.status !== "succeeded" || (!absent && step.outputs?.exitCode !== 0)) throw new ToolRefusal("tool.version_probe_failed");
  return z.string().parse(step.outputs?.stdout);
}
/** Public argv only. Scope ownership is admission's concern, not a Node phase. */
export function commands(input: Input) {
  const brew = input.manager.startsWith("brew"), cask = input.manager === "brew-cask";
  const prefix = input.platform === "linux" ? "/home/linuxbrew/.linuxbrew" : ["arm64", "aarch64"].includes(input.architecture) ? "/opt/homebrew" : "/usr/local";
  const home = (input.platform === "linux" ? "/home/" : "/Users/") + input.user;
  const scope = input.platform === "linux" ? "/opt/orbit/vite-plus" : home + "/.vite-plus";
  const binary = input.manager === "apt" ? "/usr/bin/apt-get" : brew ? prefix + "/bin/brew" : input.manager === "composer" ? "/usr/bin/composer" : scope + "/bin/vp";
  const env = ["/usr/bin/env", "LC_ALL=C", ...(brew ? ["HOMEBREW_NO_AUTO_UPDATE=1", "HOMEBREW_NO_ANALYTICS=1", "HOMEBREW_NO_INSTALL_CLEANUP=1", "HOMEBREW_NO_AUTOREMOVE=1", "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1", ...(input.platform === "macos" ? ["HOMEBREW_FORCE_API_AUTO_UPDATE=1"] : [])] : input.manager === "composer" ? ["COMPOSER_HOME=/opt/orbit/composer"] : input.manager === "vp" ? ["VP_HOME=" + scope] : [])];
  const run = (...args: string[]) => [...env, binary, ...args];
  const formula = cask ? "--cask" : "--formula", qualified = (cask ? "homebrew/cask/" : "homebrew/core/") + input.package;
  const install = input.kind === "tool.install";
  const composer = ["global", install ? "require" : "update", input.package + (install ? ":*" : ""), "--no-interaction", "--no-ansi", "--no-progress", "--no-audit", "--with-all-dependencies"];
  const repo = input.repository;
  const aptOptions = repo ? ["-o", "Dir::Etc::sourcelist=/etc/apt/sources.list.d/orbit-" + repo.name + ".sources", "-o", "Dir::Etc::sourceparts=-", "-o", "Dir::State::status=/dev/null", "-o", "Dir::Cache::pkgcache=", "-o", "Dir::Cache::srcpkgcache="] : [];
  return {
    prefix, home, scope, remove_repository: input.remove_repository, repository_packages: input.repository_packages,
    repository: input.repository ?? { name: "unused", uris: [], suites: [], components: [], signing_key: { url: "https://unused.invalid/key", fingerprint: "" } },
    version: run("--version"),
    inventory: input.manager === "apt" ? [...env, "/usr/bin/dpkg-query", "--show", "--showformat=${Package}\t${Status}\t${Version}\n", "--", input.package] : brew ? run("info", "--json=v2", formula, "--installed") : input.manager === "composer" ? run("global", "show", "--direct", "--format=json", "--no-ansi") : run("list", "-g", "--json"),
    candidate: input.manager === "apt" ? [...env, "/usr/bin/apt-cache", ...aptOptions, "policy", "--", input.package] : brew ? run("info", "--json=v2", formula, qualified) : input.manager === "composer" ? run(...composer, "--dry-run") : run("info", input.package, "version", "--json"),
    plan: input.manager === "apt" ? run("--simulate", "remove", "--", input.package) : input.manager === "brew" ? run("uses", "--installed", input.package) : run("--version"),
    write: brew ? run(install ? "install" : "upgrade", formula, ...(!cask ? ["--force-bottle"] : []), qualified) : input.manager === "composer" ? run(...composer) : run(install ? "install" : "update", "-g", input.package, ...(install ? ["--node", "lts"] : ["--reinstall-node-mismatch"])),
    remove: brew ? run("uninstall", formula, input.package) : input.manager === "composer" ? run("global", "remove", input.package, "--no-interaction", "--no-ansi", "--no-progress", "--no-audit") : run("remove", "-g", input.package),
  };
}
export function inventory(input: Input, version: Step, query: Step, os: Step = null) {
  const c = commands(input), manager_version = stdout(version).trim().split("\n")[0];
  const text = stdout(query, input.manager === "apt");
  let versions: unknown[] = [], extra: Record<string, unknown> = {};
  if (input.manager === "apt") {
    if (query?.outputs?.exitCode === 1 && !text && String(query.outputs.stderr).trim() === `dpkg-query: no packages found matching ${input.package}`) versions = [];
    else {
      const [name, status, value] = text.trim().split("\t");
      if (query?.outputs?.exitCode !== 0 || name !== input.package) throw new ToolRefusal("tool.version_probe_failed");
      if (status === "install ok installed") versions = [value];
      else if (!/^(deinstall|purge|unknown) ok (config-files|not-installed)$/.test(status)) throw new ToolRefusal("tool.version_probe_failed");
    }
  } else {
    const raw = JSON.parse(text);
    if (input.manager.startsWith("brew")) {
      const rows = z.array(z.record(z.string(), z.unknown())).parse(raw[input.manager === "brew" ? "formulae" : "casks"]);
      const selected = rows.filter((r) => r[input.manager === "brew" ? "name" : "token"] === input.package);
      if (selected.length > 1) throw new ToolRefusal("tool.version_probe_failed");
      const record = selected[0];
      versions = !record ? [] : input.manager === "brew" ? z.array(z.object({ version: z.string() })).parse(record.installed).map((r) => r.version) : record.installed ? [record.installed] : [];
      extra = { homebrew: record ?? null, prefix: c.prefix, home: c.home, token_count: selected.length, os_major: input.platform === "macos" ? Number(stdout(os).trim().split(".")[0]) : 0 };
    } else {
      const rows = z.array(z.object({ name: z.string(), version: z.string().optional() })).parse(input.manager === "vp" ? raw : raw.installed ?? []);
      versions = rows.filter((r) => r.name === input.package).map((r) => r.version);
      extra = input.manager === "composer" ? { roots: rows.map((r) => r.name) } : { scope_path: c.scope };
    }
  }
  return { manager_version, versions, complete: true, ...extra };
}
export function candidate(input: Input, step: Step, facts: Record<string, unknown>) {
  const text = stdout(step);
  if (input.manager === "apt") {
    const matches = text.split("\n").map((s) => /^\s*Candidate:\s+(\S+)\s*$/.exec(s)).filter((v) => v !== null);
    if (matches.length !== 1) throw new ToolRefusal("tool.candidate_version_probe_failed");
    return { version: matches[0]![1] === "(none)" ? null : matches[0]![1] };
  }
  if (input.manager === "vp") return { version: JSON.parse(text) };
  if (input.manager.startsWith("brew")) {
    const data = object(JSON.parse(text));
    const rows = z.array(z.record(z.string(), z.unknown())).parse(data[input.manager === "brew" ? "formulae" : "casks"]);
    if (rows.length !== 1) throw new ToolRefusal("tool.candidate_version_probe_failed");
    return { ...facts, homebrew: rows[0] };
  }
  const lines = (text + "\n" + String(step?.outputs?.stderr ?? "")).split("\n");
  const selected: string[] = [];
  for (const line of lines) {
    const match = /^\s*-\s+(?:Locking|Installing)\s+(\S+)\s+\(([^\s()]+)\)\s*$/.exec(line) ?? /^\s*-\s+(?:Upgrading|Downgrading)\s+(\S+)\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*$/.exec(line);
    if (match?.[1] === input.package) selected.push(match[2]);
  }
  if (!selected.length && lines.includes("Nothing to install, update or remove")) return { version: (facts.versions as string[])[0] };
  if (!selected.length || new Set(selected).size !== 1) throw new ToolRefusal("tool.candidate_version_probe_failed");
  return { version: selected[0] };
}
export function removalPlan(input: Input, step: Step) {
  const text = stdout(step);
  return { packages: input.manager === "apt" ? [...new Set(text.split("\n").filter((s) => /^\s*Remv\b/.test(s)).map((s) => s.trim().split(/\s+/)[1].split(":")[0]))] : [input.package, ...text.trim().split(/\s+/).filter(Boolean)] };
}

/** Package state from an official behavior check, or the manager's one read command. */
export function after(input: Input, value: Record<string, unknown> | null) {
  if (value === null) throw new ToolRefusal("tool.version_probe_failed");
  if (input.manager === "apt") {
    const state = z.object({ status: z.enum(["compliant", "non_compliant"]), packages: z.array(z.object({ name: z.string(), installed: z.boolean(), version: z.string().nullable() })) }).parse(value);
    const selected = state.packages.filter((p) => p.name === input.package);
    if (selected.length !== 1) throw new ToolRefusal("tool.version_probe_failed");
    return { manager_version: input.manager, versions: selected[0].installed ? [selected[0].version] : [], complete: true };
  }
  return inventory(input, { status: "succeeded", outputs: { stdout: input.manager, exitCode: 0 } }, { status: "succeeded", outputs: value });
}
