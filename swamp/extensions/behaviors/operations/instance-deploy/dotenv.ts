const MAX_FILE_BYTES = 1_048_576;
const MAX_VALUE_BYTES = 65_536;
const KEY = /^[A-Za-z_][A-Za-z0-9_]{0,254}$/;
const bytes = (value: string) => new TextEncoder().encode(value).length;
const lines = (value: string) => value.match(/[^\n]*\n|[^\n]+$/g) ?? [];
function refuse(code: string): never {
  throw new Error(code);
}
function bounded(value: string): string {
  if (bytes(value) > MAX_FILE_BYTES) refuse("env.configuration_invalid");
  return value;
}

/** Pure preparation: secret values stay in memory, never in a workflow result. */
export function renderAccountEnvironment(
  values: Record<string, string>,
  environment: "development" | "production",
  domain: string | null,
): string {
  if (!["development", "production"].includes(environment) || domain === "") {
    refuse("env.reference_unavailable");
  }
  if (Object.keys(values).length > 1024) refuse("env.configuration_invalid");
  let output = "";
  for (const key of Object.keys(values).sort()) {
    const value = values[key];
    if (
      !KEY.test(key) || typeof value !== "string" || !value.isWellFormed() ||
      value.includes("\0") ||
      bytes(value) > MAX_VALUE_BYTES
    ) {
      refuse("env.configuration_invalid");
    }
    if (domain === null && value.includes("{{instance.domain}}")) {
      refuse("env.reference_unavailable");
    }
    const resolved = value.replaceAll("{{instance.domain}}", domain ?? "")
      .replaceAll("{{instance.environment}}", environment);
    if (resolved.includes("{{") || resolved.includes("}}")) {
      refuse("env.reference_unavailable");
    }
    const escaped = resolved.replace(/[\\"$\n\r]/g, (char) =>
      ({
        "\\": "\\\\",
        '"': '\\"',
        "$": "\\$",
        "\n": "\\n",
        "\r": "\\r",
      })[char]!);
    output += `${key}="${escaped}"\n`;
    bounded(output);
  }
  return output;
}

/** Pure .env.testing merge; tracked-file and atomic-write checks belong to the local owner action. */
export function mergeManagedTesting(
  existing: string,
  supplied: string,
  managedKeys: string[],
): string {
  bounded(existing);
  bounded(supplied);
  if (managedKeys.length === 0 || managedKeys.some((key) => !KEY.test(key))) {
    refuse("env.configuration_invalid");
  }
  const managed = new Set(managedKeys);
  const updates = new Map<string, string>();
  for (const line of lines(supplied)) {
    const key = line.split("=", 1)[0];
    if (!line.endsWith("\n") || (managed.has(key) && updates.has(key))) {
      refuse("env.configuration_invalid");
    }
    if (managed.has(key)) updates.set(key, line);
  }
  if (updates.size === 0) refuse("env.configuration_invalid");
  const written = new Set<string>();
  let output = "";
  for (const line of lines(existing)) {
    const key = line.match(
      /^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/,
    )?.[1];
    if (key !== undefined && managed.has(key)) {
      if (updates.has(key) && !written.has(key)) {
        output += updates.get(key)!;
        written.add(key);
      }
    } else output += line;
  }
  for (const [key, line] of updates) {
    if (!written.has(key)) {
      if (output && !output.endsWith("\n")) output += "\n";
      output += line;
    }
  }
  return bounded(output);
}
