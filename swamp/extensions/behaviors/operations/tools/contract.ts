import { z } from "npm:zod@4.4.3";
import { constraintMatches } from "./constraints.ts";

export const Manager = z.enum(["apt", "composer", "vp", "brew", "brew-cask"]);
export const Repository = z.object({
  name: z.string().max(63).regex(/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/).refine((v) => !["caddy", "php"].includes(v)),
  uris: z.array(z.url().startsWith("https://")).min(1).max(16),
  suites: z.array(z.string().regex(/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/)).min(1).max(16),
  components: z.array(z.string().regex(/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/)).min(1).max(16),
  architectures: z.array(z.string().regex(/^[a-z0-9][a-z0-9-]{0,31}$/)).min(1).max(16).optional(),
  signing_key: z.object({ url: z.url().startsWith("https://"), fingerprint: z.string().regex(/^(?:[A-F0-9]{40}|[A-F0-9]{64})$/) }).strict(),
}).strict();
export const Input = z.object({
  node_id: z.number().int().positive(),
  manager: Manager,
  package: z.string().default(""),
  platform: z.enum(["linux", "macos"]),
  architecture: z.string(),
  version_constraint: z.string().nullable().default(null),
  tool_id: z.number().int().positive().nullable().default(null),
  tool_status: z.string().nullable().default(null),
  installed_version: z.string().nullable().default(null),
  intent_only: z.boolean().default(false),
  repository: Repository.nullable().default(null),
  remove_repository: z.boolean().default(false),
  repository_packages: z.array(z.string().regex(/^[a-z0-9][a-z0-9+.-]{0,254}$/)).max(256).default([]),
  materialize: z.record(z.string(), z.unknown()).nullable().default(null),
});
export type ToolInput = z.infer<typeof Input>;
/** Rewritten script source never carries repository intent; scripts receive it as argv JSON. */
export function sourceInput<T extends { repository?: unknown; remove_repository?: unknown; repository_packages?: unknown }>(input: T): Omit<T, "repository" | "remove_repository" | "repository_packages"> {
  const { repository: _repository, remove_repository: _remove, repository_packages: _packages, ...rest } = input;
  return rest;
}
/** The repository script gets the shared definition plus the packages its apt preference admits. */
export function repositoryJson(input: { repository: z.infer<typeof Repository> | null; repository_packages: string[] }): string {
  return JSON.stringify(input.repository ? { ...input.repository, packages: input.repository_packages } : null);
}
export type Result = Record<string, unknown>;
export class ToolRefusal extends Error {
  constructor(readonly code: string, readonly result: Result = {}) {
    super(code);
  }
}

export function safeVersion(value: unknown): value is string {
  return typeof value === "string" && value.length > 0 && value.length <= 255 &&
    !Array.from(value).some((char) =>
      char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127
    );
}

export function normalizeVersion(
  raw: string,
  manager: ToolInput["manager"],
): string | null {
  if (!safeVersion(raw)) return null;
  let version = raw;
  if (manager === "apt") {
    if (version.includes(":")) {
      if (!/^(0|[1-9]\d*):/.test(version)) return null;
      version = version.slice(version.indexOf(":") + 1);
    }
    const dash = version.lastIndexOf("-");
    if (dash >= 0) version = version.slice(0, dash);
    if (version.includes("+")) return null;
  }
  const match =
    /^v?(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?(-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?(\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/
      .exec(version);
  return match
    ? `${match[1]}.${match[2]}.${match[3] ?? "0"}${match[4] ?? ""}${
      match[5] ?? ""
    }`
    : null;
}

export function validateConstraint(input: ToolInput): void {
  if (input.version_constraint === null) return;
  try {
    constraintMatches(input.version_constraint);
  } catch {
    throw new ToolRefusal("tool.constraint_invalid");
  }
}

export function allows(input: ToolInput, raw: string): boolean {
  if (input.version_constraint === null) return true;
  const normalized = normalizeVersion(raw, input.manager);
  return normalized !== null &&
    constraintMatches(input.version_constraint)(normalized);
}

export function verifyInstalled(input: ToolInput, version: string): void {
  if (input.version_constraint === null) return;
  if (normalizeVersion(version, input.manager) === null) {
    throw new ToolRefusal("tool.installed_version_unparseable");
  }
  if (!allows(input, version)) {
    throw new ToolRefusal("tool.installed_version_constraint_violated");
  }
}

export function refusalCode(step: string, code: string, kind: string): string {
  if (code === "operation.cancelled") return code;
  if (/^tool\.(?:vp|homebrew|composer)_scope_(?:absent|conflict)$/.test(code)) {
    return "tool.manager_unavailable";
  }
  if (step === "manager-materialize" || step === "manager-ready") {
    return kind === "tool.install" || kind === "tool.manager_converge"
      ? "tool.manager_provision_failed"
      : "tool.version_probe_failed";
  }
  if (step === "package-candidate") {
    return "tool.candidate_version_probe_failed";
  }
  if (step === "package-mutate") return `${kind}_failed`;
  return "tool.version_probe_failed";
}
