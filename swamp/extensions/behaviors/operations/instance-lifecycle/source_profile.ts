import { z } from "npm:zod@4.4.3";
import { constraintMatches } from "../tools/constraints.ts";

export class ProvisioningRefusal extends Error {
  constructor(public code: string, public stage: string) {
    super("Instance provisioning refused");
  }
}

/** Source metadata stays local; only selected scalar identity enters a native resource. */
export function classifyProvisioningProfile(
  raw: unknown,
  plan: { php_versions: string[]; project_type: string },
) {
  const profile = z.object({
    kind: z.enum(["none", "partial", "unsafe", "composer"]),
    composer: z.string().nullable().optional(),
    entry_point: z.enum(["regular", "absent", "unsafe"]).nullable()
      .optional(),
  }).parse(raw);
  if (profile.kind === "none") return { php_version: null, laravel: false };
  if (profile.kind !== "composer" || typeof profile.composer !== "string") {
    throw new ProvisioningRefusal(
      "app-dev.source_metadata_unsafe",
      "profile",
    );
  }
  let composer: Record<string, unknown>;
  try {
    const bytes = Uint8Array.from(
      atob(profile.composer),
      (c) => c.charCodeAt(0),
    );
    if (bytes.length > 1048576) throw new Error();
    composer = z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(bytes)),
    );
  } catch {
    throw new ProvisioningRefusal(
      "app-dev.php_version_unsupported",
      "profile",
    );
  }
  const record = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === "object" && !Array.isArray(value)
      ? value as Record<string, unknown>
      : {};
  const require = record(composer.require),
    dev = record(composer["require-dev"]);
  const constraint = require.php;
  let selected: string | undefined;
  try {
    if (constraint !== undefined && typeof constraint !== "string") {
      throw new Error();
    }
    selected = constraint === undefined
      ? plan.php_versions[0]
      : plan.php_versions.find((version) =>
        constraintMatches(constraint)(`${version}.0`)
      );
  } catch {
    throw new ProvisioningRefusal(
      "app-dev.php_version_unsupported",
      "profile",
    );
  }
  if (selected === undefined) {
    throw new ProvisioningRefusal(
      "app-dev.php_version_unsupported",
      "profile",
    );
  }
  const regular = profile.entry_point === "regular";
  if (plan.project_type === "symfony-app") {
    const declares = Object.hasOwn(require, "symfony/framework-bundle");
    if (
      profile.entry_point === "unsafe" || declares !== regular ||
      (declares &&
        typeof require["symfony/framework-bundle"] !== "string")
    ) {
      throw new ProvisioningRefusal(
        "app-dev.symfony_source_invalid",
        "profile",
      );
    }
    return { php_version: selected, laravel: false };
  }
  const declarations = [require, dev].filter((row) =>
    Object.hasOwn(row, "laravel/framework")
  ).map((row) => row["laravel/framework"]);
  if (
    (plan.project_type !== "laravel-package" &&
      (profile.entry_point === "unsafe" ||
        regular !== (declarations.length === 1))) ||
    declarations.some((value) => typeof value !== "string")
  ) {
    throw new ProvisioningRefusal(
      "app-dev.laravel_source_invalid",
      "profile",
    );
  }
  return {
    php_version: selected,
    laravel: plan.project_type !== "laravel-package" && regular,
  };
}
