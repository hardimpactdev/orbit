import { z } from "npm:zod@4.4.3";

export const Manager = z.enum(["apt", "composer", "vp", "brew", "brew-cask"]);
export const Interval = z.object({
  start: z.string(),
  start_operator: z.enum([">", ">=", "=="]),
  end: z.string(),
  end_operator: z.enum(["<", "<=", "=="]),
});
export const Input = z.object({
  node_id: z.number().int().positive(),
  manager: Manager,
  package: z.string().default(""),
  platform: z.enum(["linux", "macos"]),
  architecture: z.string(),
  version_constraint: z.string().nullable().default(null),
  constraint_intervals: z.array(Interval).nullable().default(null),
  tool_id: z.number().int().positive().nullable().default(null),
  tool_status: z.string().nullable().default(null),
  installed_version: z.string().nullable().default(null),
  intent_only: z.boolean().default(false),
  materialize: z.record(z.string(), z.unknown()).nullable().default(null),
});
export type ToolInput = z.infer<typeof Input>;
export type Result = Record<string, unknown>;
// PHP encodes an empty result map as []; non-empty arrays are never records.
export const ReceiptResult = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: ReceiptResult,
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
});

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

// Composer has already expanded the constraint into numeric intervals in the Gateway.
// Compare those boundaries with a normalized, three-component package version.
function compare(left: string, right: string): number {
  const parts = (value: string) => {
    const match = /^(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?(.*)$/.exec(
      value.replace(/\+.*/, ""),
    );
    if (!match) throw new Error("Invalid compiled version boundary");
    return [
      match[1],
      match[2],
      match[3],
      match[4] ?? "0",
      ...match[5].replace(/^[-.]/, "").replace(/([a-zA-Z]+)(\d)/g, "$1.$2")
        .split(/[.-]/),
    ];
  };
  const a = parts(left), b = parts(right);
  const rank = (
    word: string,
  ) => ({
    dev: -6,
    alpha: -5,
    a: -5,
    beta: -4,
    b: -4,
    RC: -3,
    rc: -3,
    "": -2,
    pl: 1,
    p: 1,
  }[word] ?? -7);
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const x = a[i] ?? "", y = b[i] ?? "";
    if (x === y) continue;
    const xn = /^\d+$/.test(x), yn = /^\d+$/.test(y);
    if (xn && yn) return BigInt(x) < BigInt(y) ? -1 : 1;
    const xr = xn ? -2 : rank(x), yr = yn ? -2 : rank(y);
    if (xr !== yr) return xr < yr ? -1 : 1;
  }
  return 0;
}
export function allows(input: ToolInput, raw: string): boolean {
  if (input.constraint_intervals === null) return true;
  const normalized = normalizeVersion(raw, input.manager);
  if (normalized === null) return false;
  return input.constraint_intervals.some((interval) => {
    const start = compare(normalized, interval.start),
      end = compare(normalized, interval.end);
    return (interval.start_operator === ">"
      ? start > 0
      : interval.start_operator === "=="
      ? start === 0
      : start >= 0) &&
      (interval.end_operator === "<"
        ? end < 0
        : interval.end_operator === "=="
        ? end === 0
        : end <= 0);
  });
}

export function verifyInstalled(input: ToolInput, version: string): void {
  if (input.constraint_intervals === null) return;
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
  if (step === "package-removal-plan") return "tool.remove_failed";
  if (step === "package-mutate") return `${kind}_failed`;
  return "tool.version_probe_failed";
}
