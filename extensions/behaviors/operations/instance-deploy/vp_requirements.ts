import { parse as yaml } from "jsr:@std/yaml@1.0.12";
// @deno-types="npm:@types/semver@7.7.1"
import { satisfies } from "npm:semver@7.7.2";
import { z } from "npm:zod@4.4.3";

const Version = z.string().regex(/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
export const VpRuntime = z.object({
  store: z.string().startsWith("/").max(4096).refine((path) =>
    !Array.from(path).some((c) =>
      c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127
    ) &&
    !path.slice(1).split("/").some((part) => ["", ".", ".."].includes(part))
  ),
  vp_version: Version,
  node_version: Version,
  package_manager: z.enum(["npm", "pnpm"]),
  package_manager_version: Version,
  package_manager_hash: z.string().regex(/^sha(?:224|256|384|512)\.[a-f0-9]+$/i)
    .nullable(),
}).strict();
export type VpRuntime = z.infer<typeof VpRuntime>;
function object(raw: unknown): Record<string, unknown> {
  return raw !== null && typeof raw === "object" && !Array.isArray(raw)
    ? raw as Record<string, unknown>
    : {};
}
function field(raw: unknown, ...keys: string[]): unknown {
  let value = raw;
  for (const key of keys) value = object(value)[key];
  return value;
}
function locked(raw: string) {
  return Version.parse(
    raw.replace(/\(.*\)$/, "").replace(
      /^(?:(?:npm:)?vite-plus@|workspace:)/,
      "",
    ),
  );
}

/** Resolve immutable runtime pins locally from data read as the app account; never registry tags. */
export function vpRequirements(raw: unknown, store: string): VpRuntime | null {
  try {
    const config = z.object({ package: z.record(z.string(), z.unknown()) })
        .passthrough().parse(raw),
      pkg = config.package;
    const dependencies = {
      ...object(pkg.dependencies),
      ...object(pkg.devDependencies),
    };
    const names = Object.keys(dependencies).filter((name) =>
      name === "vite-plus" ||
      (typeof dependencies[name] === "string" &&
        dependencies[name].startsWith("npm:vite-plus@"))
    );
    if (names.length === 0) return null;
    const versions = new Set<string>();
    let engine: unknown, lockedManager: unknown, family: "npm" | "pnpm";
    const pnpm = field(config, "locks", "pnpm-lock.yaml");
    const npm = field(config, "locks", "package-lock.json");
    if (typeof pnpm === "string") {
      if (new TextEncoder().encode(pnpm).length > 4 * 1024 * 1024) {
        throw new Error();
      }
      family = "pnpm";
      const lock = yaml(pnpm);
      lockedManager = field(lock, "packageManager");
      for (const name of names) {
        for (const section of ["dependencies", "devDependencies"]) {
          const entry = field(lock, "importers", ".", section, name) ??
            field(lock, section, name);
          let value = typeof entry === "string"
            ? entry
            : field(entry, "version");
          if (typeof value === "string" && value.startsWith("link:")) {
            value = field(config, "workspaces", value);
          }
          if (typeof value === "string") {
            const version = locked(value);
            versions.add(version);
            engine = field(
              lock,
              "packages",
              `vite-plus@${version}`,
              "engines",
              "node",
            );
          }
        }
      }
    } else if (typeof npm === "string") {
      if (new TextEncoder().encode(npm).length > 4 * 1024 * 1024) {
        throw new Error();
      }
      family = "npm";
      const lock = JSON.parse(npm);
      const npmVersion = field(lock, "npmVersion");
      lockedManager = typeof npmVersion === "string"
        ? `npm@${npmVersion}`
        : field(lock, "packageManager");
      for (const name of names) {
        let entry = field(lock, "packages", `node_modules/${name}`) ??
          field(lock, "dependencies", name);
        if (field(entry, "link") === true) {
          const resolved = field(entry, "resolved");
          entry = typeof resolved === "string"
            ? field(lock, "packages", resolved)
            : undefined;
        }
        const value = field(entry, "version");
        if (typeof value === "string") {
          versions.add(locked(value));
          engine = field(entry, "engines", "node");
        }
      }
    } else throw new Error();
    if (versions.size !== 1) throw new Error();
    const pin = config[".node-version"] ?? config[".nvmrc"] ??
      field(pkg, "engines", "node");
    const node = Version.parse(
      typeof pin === "string" ? pin.trim().replace(/^v/, "") : pin,
    );
    const managerEngine = field(pkg, "engines", family);
    const selector = pkg.packageManager ?? lockedManager ??
      (typeof managerEngine === "string" ? `${family}@${managerEngine}` : "");
    const match = typeof selector === "string"
      ? /^(npm|pnpm)@(.+?)(?:\+(sha(?:224|256|384|512)\.[a-f0-9]+))?$/i.exec(
        selector,
      )
      : null;
    if (!match || match[1] !== family) throw new Error();
    const manager = Version.parse(match[2]);
    for (const range of [engine, field(pkg, "engines", "node")]) {
      if (
        range !== null && range !== undefined &&
        (typeof range !== "string" || !satisfies(node, range))
      ) throw new Error();
    }
    if (
      managerEngine !== null && managerEngine !== undefined &&
      (typeof managerEngine !== "string" || !satisfies(manager, managerEngine))
    ) throw new Error();
    return VpRuntime.parse({
      store,
      vp_version: [...versions][0],
      node_version: node,
      package_manager: family,
      package_manager_version: manager,
      package_manager_hash: match[3] ?? null,
    });
  } catch {
    throw new Error("deployment.vp_requirements_invalid");
  }
}
