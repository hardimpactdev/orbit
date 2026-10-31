import { z } from "npm:zod@4.4.3";
import { phpBuildProgram } from "./build_program.ts";
import {
  BoundedNames,
  Https,
  LocalPhpRunner,
  PackageName,
  PackageVersion,
  PhpVersion,
  Release,
  runPhp,
} from "./local.ts";
export { PhpLocalError } from "./local.ts";
export type { LocalPhpCommand, LocalPhpRunner } from "./local.ts";

const Pin = z.object({
  name: PackageName,
  version: PackageVersion,
  architecture: z.literal("amd64"),
  url: Https,
  sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
const Build = z.object({
  packages: z.array(Pin).min(1).refine((pins) =>
    new Set(pins.map((p) => p.name)).size === pins.length
  ),
  extensions: z.array(z.string().regex(/^[a-z0-9_ ]+$/)).min(1),
}).strict();
export const PhpBuildInput = z.object({
  versions: z.record(PhpVersion, Build).refine((v) =>
    Object.keys(v).length > 0
  ),
  mirror: z.union([z.literal(""), Https]).default(""),
  pcov: z.boolean(),
  opentelemetry: z.boolean(),
  extras: z.enum(["refuse", "keep", "purge"]),
  fpm: z.enum(["none", "shared", "all", "disabled"]),
  repack: z.object({
    package: z.literal("dpkg-repack"),
    version: PackageVersion,
  }).strict(),
  release: Release.nullable().optional(),
}).strict();
export type InstallPhpBuildInput = z.input<typeof PhpBuildInput>;
export const PhpBuildResult = z.object({
  outcome: z.enum(["applied", "current"]),
  data: z.object({
    changed: BoundedNames,
    repaired: BoundedNames,
    purged: BoundedNames,
    disabled: BoundedNames,
    restarted: BoundedNames,
    reloaded: BoundedNames,
    deferred: BoundedNames,
    stale_masters: BoundedNames,
    fpm_disabled: z.boolean(),
  }).strict(),
}).strict();
export type InstallPhpBuildResult = z.infer<typeof PhpBuildResult>;
/** Install only admitted digest-pinned artifacts; keep rollback packages before effects. */
export function installPhpBuild(
  input: InstallPhpBuildInput,
  signal?: AbortSignal,
  runner?: LocalPhpRunner,
): Promise<InstallPhpBuildResult> {
  return runPhp(
    PhpBuildInput.parse(input),
    phpBuildProgram,
    PhpBuildResult,
    "php.build_execution_failed",
    signal,
    runner,
  );
}
