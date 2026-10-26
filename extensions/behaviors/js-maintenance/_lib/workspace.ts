import {
  command,
  decode,
  MaintenanceFailure,
  object,
  optionalText,
  type Place,
  readJson,
} from "../../_lib/maintenance/io.ts";
export const sections = [
  "dependencies",
  "devDependencies",
  "optionalDependencies",
];
export function direct(
  manifest: Record<string, unknown>,
): Record<string, string> {
  const result: Record<string, string> = {};
  for (const section of sections) {
    if (manifest[section] === undefined) continue;
    for (
      const [name, range] of Object.entries(object(manifest[section], section))
    ) {
      if (typeof range !== "string") {
        throw new MaintenanceFailure("Cannot read package.json");
      }
      result[name] = range;
    }
  }
  return result;
}
export type Workspace = Place & {
  manager: "npm" | "bun";
  vitePlus: boolean;
  expo: boolean;
  direct: Record<string, string>;
  manifest: Record<string, unknown>;
};
export async function exists(file: string) {
  try {
    return await Deno.stat(file);
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) return null;
    throw e;
  }
}
function managerName(value: unknown, label: string): "npm" | "bun" | null {
  if (value === undefined || value === null || value === "") return null;
  if (typeof value === "string" && /^(npm|bun)(@.*)?$/.test(value)) {
    return value.split("@")[0] as "npm" | "bun";
  }
  throw new MaintenanceFailure(`package.json ${label} must declare npm or bun`);
}
export async function workspace(place: Place): Promise<Workspace> {
  const manifest = await readJson(`${place.dir}/package.json`);
  const a = managerName(manifest.packageManager, "packageManager");
  const engines = manifest.devEngines as
    | { packageManager?: unknown }
    | undefined;
  const engine = engines?.packageManager;
  const b = managerName(
    engine && typeof engine === "object"
      ? (engine as { name?: unknown }).name
      : engine,
    "devEngines.packageManager",
  );
  if (a && b && a !== b) {
    throw new MaintenanceFailure(
      "packageManager and devEngines name different JavaScript managers",
    );
  }
  const npm = (await exists(`${place.dir}/package-lock.json`))?.isFile;
  const bun = (await exists(`${place.dir}/bun.lock`))?.isFile;
  const declared = a ?? b;
  if (declared) {
    const own = declared === "npm" ? "package-lock.json" : "bun.lock";
    const other = declared === "npm" ? "bun.lock" : "package-lock.json";
    const hasOwn = declared === "npm" ? npm : bun;
    const hasOther = declared === "npm" ? bun : npm;
    if (!hasOwn) {
      throw new MaintenanceFailure(
        `package.json declares ${declared} but this directory has ${
          hasOther ? `${other} and no ${own}` : `no ${own}`
        }.`,
      );
    }
    if (hasOther) {
      throw new MaintenanceFailure(
        `package.json declares ${declared} but this directory also has ${other}.`,
      );
    }
  } else {
    if (npm && bun) {
      throw new MaintenanceFailure(
        "package-lock.json and bun.lock disagree. Declare packageManager or keep one lockfile.",
      );
    }
    if (!npm && !bun) {
      throw new MaintenanceFailure(
        "Cannot tell whether this directory uses npm or Bun. Declare packageManager or add package-lock.json or bun.lock.",
      );
    }
  }
  const deps = direct(manifest);
  return {
    ...place,
    manifest,
    manager: declared ?? (npm ? "npm" : "bun"),
    direct: deps,
    vitePlus: "vite-plus" in deps,
    expo: "expo" in deps,
  };
}
export function coupled(name: string) {
  return ["expo", "react", "react-dom", "react-native"].includes(name) ||
    /^(expo-|@expo\/|react-native-|@react-native\/|@react-native-community\/)/
      .test(name);
}
export async function executable(binary: string) {
  const candidates = binary.includes("/")
    ? [binary]
    : (Deno.env.get("PATH") ?? "").split(":").filter(Boolean).map((p) =>
      `${p}/${binary}`
    );
  for (const file of candidates) {
    const stat = await exists(file);
    if (stat?.isFile && ((stat.mode ?? 0) & 0o111)) return;
  }
  throw new MaintenanceFailure(
    `Missing executable ${binary}${
      binary.includes("/")
        ? "; install the repository dependencies first."
        : " on PATH."
    }`,
    127,
  );
}
export async function run(
  js: Place,
  binary: string,
  args: string[],
  signal?: AbortSignal,
  extraEnv: Record<string, string> = {},
  bootstrap = false,
) {
  const result = await command(js.dir, binary, args, signal, extraEnv);
  if (bootstrap) {
    await Deno.stderr.write(
      new TextEncoder().encode(result.stdout + result.stderr),
    );
  }
  if (result.code !== 0) {
    throw new MaintenanceFailure(
      `${binary} ${args.join(" ")} failed:\n${result.stderr}\n${result.stdout}`,
      result.code,
    );
  }
}
export async function prepare(js: Workspace, signal?: AbortSignal) {
  if (!js.vitePlus) return await executable(js.manager);
  if (!(await exists(`${js.dir}/node_modules`))?.isDirectory) {
    await executable(js.manager);
    await run(
      js,
      js.manager,
      js.manager === "npm"
        ? ["ci", "--ignore-scripts"]
        : ["install", "--frozen-lockfile", "--ignore-scripts"],
      signal,
      {},
      true,
    );
  }
  await executable(`${js.dir}/node_modules/.bin/vp`);
}
export function cli(js: Workspace) {
  return js.vitePlus ? "./node_modules/.bin/vp" : js.manager;
}
export function lockName(js: Workspace) {
  return js.manager === "npm" ? "package-lock.json" : "bun.lock";
}
// Strip trailing commas only outside JSON strings, preserving descriptor contents.
export function lockDecode(
  text: string,
  manager: string,
): Record<string, unknown> {
  if (manager !== "bun") return decode(text, "package-lock.json");
  let output = "", quoted = false, escaped = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (!quoted && c === "," && /^\s*[}\]]/.test(text.slice(i + 1))) continue;
    output += c;
    if (quoted && escaped) escaped = false;
    else if (quoted && c === "\\") escaped = true;
    else if (c === '"') quoted = !quoted;
  }
  return decode(output, "bun.lock");
}
export async function lock(js: Workspace) {
  const text = await optionalText(`${js.dir}/${lockName(js)}`);
  if (text === null) {
    throw new MaintenanceFailure(`Cannot read ${lockName(js)}`);
  }
  return object(
    lockDecode(text, js.manager).packages,
    `${lockName(js)} packages`,
  );
}
export function descriptor(raw: string): [string, string] {
  raw = raw.replace(/^npm:/, "");
  const at = raw.indexOf("@", raw.startsWith("@") ? raw.indexOf("/") + 1 : 0);
  if (at <= 0 || at === raw.length - 1) {
    throw new MaintenanceFailure("Invalid package descriptor");
  }
  return [raw.slice(0, at), raw.slice(at + 1)];
}
export function topName(key: string): string | null {
  return /^(?:@[^/]+\/)?[^/]+$/.test(key) ? key : null;
}
export function nodeName(key: string): string | null {
  return key.startsWith("node_modules/") ? topName(key.slice(13)) : null;
}
export function aliases(
  packages: Record<string, unknown>,
  manager: string,
): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(packages)) {
    const declared = manager === "bun" ? topName(key) : nodeName(key);
    if (!declared || !value || typeof value !== "object") continue;
    const entry = value as Record<string, unknown>;
    const raw = manager === "bun" ? (value as unknown[])[0] : entry.name ??
      (typeof entry.version === "string" && entry.version.startsWith("npm:")
        ? entry.version
        : null);
    if (typeof raw !== "string" || !raw) continue;
    const installed = raw.includes("@", raw.startsWith("@") ? 1 : 0)
      ? descriptor(raw)[0]
      : raw;
    if (installed !== declared) result[declared] = installed;
  }
  return result;
}
export function buckets(
  name: string,
  js: Workspace,
  map: Record<string, string>,
) {
  if (name in js.direct) return [];
  return Object.keys(map).filter((key) => key in js.direct && map[key] === name)
    .sort();
}
