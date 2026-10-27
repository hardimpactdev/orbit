import { z } from "npm:zod@4";
import { cleanEnv } from "./environment.ts";

export class MaintenanceFailure extends Error {
  constructor(message: string, readonly exitCode = 70) {
    super(message);
    this.name = "MaintenanceFailure";
  }
}

import { PolicySchema } from "./policy_schema.ts";
export { PolicySchema } from "./policy_schema.ts";
export const CommonSchema = z.strictObject({
  captureFailure: z.boolean().optional(),
  cwd: z.string().min(1),
  paths: z.array(z.string().min(1)).min(1).default(["."]),
  policy: PolicySchema.describe(
    "Assertion against the existing release-age rule; never sets policy",
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
  manifest: string | null = "composer.json",
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
    if (manifest !== null) await readJson(`${dir}/${manifest}`);
    else if (
      await optionalText(`${dir}/composer.json`) === null &&
      await optionalText(`${dir}/package.json`) === null
    ) {
      throw new MaintenanceFailure(
        `Path ${path} has no composer.json or package.json`,
      );
    }
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
  extraEnv: Record<string, string> = {},
): Promise<CommandResult> {
  try {
    const result = await new Deno.Command(binary, {
      args,
      cwd,
      signal,
      clearEnv: true,
      env: cleanEnv((key) => Deno.env.get(key), extraEnv),
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

export const AdvisorySchema = z.strictObject({
  id: z.string().min(1),
  url: z.string().nullable(),
  title: z.string(),
  affected: z.string().nullable(),
});
export type Advisory = z.infer<typeof AdvisorySchema>;
export type Audit = Record<string, Advisory[]>;
