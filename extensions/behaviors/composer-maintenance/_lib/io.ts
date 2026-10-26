import { z } from "npm:zod@4";
import { cleanEnv } from "./environment.ts";

export class MaintenanceFailure extends Error {
  constructor(message: string, readonly exitCode = 70) {
    super(message);
    this.name = "MaintenanceFailure";
  }
}

export const PolicySchema = z.record(
  z.string(),
  z.strictObject({ minimumReleaseAgeDays: z.number().int().nonnegative() }),
);
export const CommonSchema = z.strictObject({
  cwd: z.string().min(1),
  paths: z.array(z.string().min(1)).min(1).default(["."]),
  policy: PolicySchema.describe(
    "Assertion against the existing vet.json release-age rule; never sets policy",
  ).default({}),
});
export type CommonArgs = z.infer<typeof CommonSchema>;
export type Place = { path: string; dir: string };
export type CommandResult = { code: number; stdout: string; stderr: string };

export async function optionalText(path: string): Promise<string | null> {
  try {
    return await Deno.readTextFile(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return null;
    throw new MaintenanceFailure(`Cannot read ${path}`);
  }
}

export function object(value: unknown, label: string): Record<string, unknown> {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    throw new MaintenanceFailure(`Cannot read ${label}`);
  }
  return value as Record<string, unknown>;
}

export function decode(text: string, label: string): Record<string, unknown> {
  try {
    return object(JSON.parse(text), label);
  } catch {
    throw new MaintenanceFailure(`Cannot read ${label}`);
  }
}

export async function readJson(path: string): Promise<Record<string, unknown>> {
  const text = await optionalText(path);
  if (text === null) throw new MaintenanceFailure(`Cannot read ${path}`);
  return decode(text, path);
}

export async function writeJson(path: string, value: unknown): Promise<void> {
  await Deno.writeTextFile(path, JSON.stringify(value, null, 4) + "\n");
}

async function realPath(path: string, label: string): Promise<string> {
  try {
    return await Deno.realPath(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) {
      throw new MaintenanceFailure(`Path ${label} is missing`, 70);
    }
    throw error;
  }
}

export async function places(
  args: CommonArgs,
): Promise<{ root: string; places: Place[] }> {
  const root = await realPath(args.cwd, args.cwd);
  const seen = new Set<string>();
  const result: Place[] = [];
  for (const path of args.paths) {
    if (path.startsWith("/") || /^[A-Za-z]:/.test(path)) {
      throw new MaintenanceFailure(`Path ${path} is absolute`);
    }
    if (
      path !== "." &&
      (path.includes("\\") ||
        path.split("/").some((p) => ["", ".", ".."].includes(p)))
    ) {
      throw new MaintenanceFailure(`Path ${path} escapes the repository`);
    }
    const dir = await realPath(`${root}/${path}`, path);
    if (dir !== root && !dir.startsWith(`${root}/`)) {
      throw new MaintenanceFailure(`Path ${path} escapes the repository`);
    }
    if (seen.has(dir)) {
      throw new MaintenanceFailure(`Config repeats path ${path}`);
    }
    seen.add(dir);
    // Validate every directory before the first command.
    await readJson(`${dir}/composer.json`);
    result.push({ path, dir });
  }
  for (const path of Object.keys(args.policy)) {
    if (!args.paths.includes(path)) {
      throw new MaintenanceFailure(`Unknown policy path ${path}`, 64);
    }
  }
  return { root, places: result };
}

export async function command(
  cwd: string,
  binary: string,
  args: string[],
  signal?: AbortSignal,
): Promise<CommandResult> {
  try {
    const result = await new Deno.Command(binary, {
      args,
      cwd,
      signal,
      clearEnv: true,
      env: cleanEnv((key) => Deno.env.get(key)),
      stdin: "null",
      stdout: "piped",
      stderr: "piped",
    }).output();
    const stdout = new TextDecoder().decode(result.stdout);
    const stderr = new TextDecoder().decode(result.stderr);
    return { code: result.code, stdout, stderr };
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) {
      throw new MaintenanceFailure(`Missing executable ${binary}`, 127);
    }
    throw error;
  }
}

export async function run(
  cwd: string,
  args: string[],
  signal?: AbortSignal,
): Promise<void> {
  const result = await command(cwd, "composer", args, signal);
  if (result.code !== 0) {
    throw new MaintenanceFailure(
      `composer ${args.join(" ")} failed:\n${result.stderr}\n${result.stdout}`,
      result.code,
    );
  }
}

export function reportJson(
  result: CommandResult,
  args: string[],
): Record<string, unknown> {
  if (new TextEncoder().encode(result.stdout).byteLength > 8388608) {
    throw new MaintenanceFailure("Output from composer is too large");
  }
  if (result.code < 0 || result.code > 3) {
    throw new MaintenanceFailure(
      `Dependency command failed: composer ${args.join(" ")}: ${result.stderr}`,
    );
  }
  // Composer may color JSON when invoked from a terminal.
  const data = decode(
    // deno-lint-ignore no-control-regex
    result.stdout.replace(/\x1b\[[0-9;]*m/g, ""),
    `JSON from composer ${args.join(" ")}`,
  );
  if ("error" in data) {
    throw new MaintenanceFailure(
      `Dependency command failed: composer ${args.join(" ")}`,
    );
  }
  return data;
}

export const AdvisorySchema = z.strictObject({
  id: z.string().min(1),
  url: z.string().nullable(),
  title: z.string(),
  affected: z.string().nullable(),
});
export type Advisory = z.infer<typeof AdvisorySchema>;
export type Audit = Record<string, Advisory[]>;

export async function audit(dir: string, signal?: AbortSignal): Promise<Audit> {
  const args = ["audit", "--locked", "--format=json"];
  const result = await command(dir, "composer", args, signal);
  let data: Record<string, unknown>;
  if (result.code === 0 && result.stdout.trim() === "") {
    const text = await optionalText(`${dir}/composer.lock`);
    const lock = text === null ? null : decode(text, "composer.lock");
    if (
      lock === null ||
      !["packages", "packages-dev"].every((key) =>
        !(key in lock) || (Array.isArray(lock[key]) && lock[key].length === 0)
      )
    ) {
      throw new MaintenanceFailure(
        "Invalid JSON from composer audit --locked --format=json",
      );
    }
    data = { advisories: {} };
  } else {
    data = reportJson(result, args);
  }
  const entries = data.advisories;
  // Composer uses [] for an empty object and sparse numeric objects for advisory lists.
  if (
    entries === null || typeof entries !== "object" ||
    (Array.isArray(entries) && entries.length > 0)
  ) {
    throw new MaintenanceFailure("Composer audit has no advisories field");
  }
  const output: Audit = {};
  for (const [name, list] of Object.entries(entries)) {
    if (list === null || typeof list !== "object") {
      throw new MaintenanceFailure(`Invalid Composer advisory for ${name}`);
    }
    output[name] = Object.values(list).map((entry) => {
      const item = object(entry, `Composer advisory for ${name}`);
      const parsed = AdvisorySchema.safeParse({
        id: item.advisoryId,
        url: item.link ?? null,
        title: item.title ?? "",
        affected: item.affectedVersions ?? null,
      });
      if (!parsed.success) {
        throw new MaintenanceFailure(`Invalid Composer advisory for ${name}`);
      }
      return parsed.data;
    });
  }
  return output;
}

export function direct(
  manifest: Record<string, unknown>,
): Record<string, string> {
  const names: Record<string, string> = {};
  for (const section of ["require", "require-dev"]) {
    if (!(section in manifest)) continue;
    const list = manifest[section];
    if (Array.isArray(list) && list.length === 0) continue;
    for (
      const [name, constraint] of Object.entries(object(list, "composer.json"))
    ) {
      if (name === "php" || name.startsWith("ext-")) continue;
      if (typeof constraint !== "string") {
        throw new MaintenanceFailure("Cannot read composer.json");
      }
      names[name] = constraint;
    }
  }
  return names;
}

export function versions(text: string | null): Record<string, string> {
  if (text === null) return {};
  const lock = decode(text, "composer.lock");
  const result: Record<string, string> = {};
  for (const section of ["packages", "packages-dev"]) {
    if (!(section in lock)) continue;
    const entries = lock[section];
    if (!Array.isArray(entries)) {
      throw new MaintenanceFailure(`composer.lock has no ${section} list`);
    }
    for (const entry of entries) {
      const p = object(entry, "composer.lock entry");
      if (
        typeof p.name !== "string" || typeof p.version !== "string" ||
        !p.version
      ) {
        throw new MaintenanceFailure("composer.lock entry is incomplete");
      }
      result[p.name] = p.version.trim().replace(/^v(?=\d)/, "");
    }
  }
  return result;
}
