export * from "./io.ts";
import {
  AdvisorySchema,
  type Audit,
  command,
  type CommandResult,
  decode,
  MaintenanceFailure,
  object,
  optionalText,
} from "./io.ts";
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

export async function audit(dir: string, signal?: AbortSignal): Promise<Audit> {
  const args = [
    "audit",
    "--locked",
    "--format=json",
    "--no-plugins",
    "--no-scripts",
  ];
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
