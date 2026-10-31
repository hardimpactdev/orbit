// @deno-types="npm:@types/semver@7.7.1"
import { compare, minVersion, Range, satisfies, valid } from "npm:semver@7.7.2";
import { composerToNpmRange } from "../../_lib/versions.ts";
import { requirePolicy } from "./refusal.ts";

export function exactVersion(raw: unknown): string {
  requirePolicy(
    typeof raw === "string" &&
      /^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(raw),
  );
  const value = valid(raw);
  requirePolicy(value !== null);
  return value;
}
export function breakingVersion(raw: unknown): string {
  const [major, minor, patch] = exactVersion(raw).split(/[.+-]/).map(Number);
  return major > 0 ? `${major}` : minor > 0 ? `0.${minor}` : `0.0.${patch}`;
}
export function lockedChange(before: unknown, after: unknown): void {
  requirePolicy(
    breakingVersion(before) === breakingVersion(after),
    "dependency_merge.major_bump",
  );
}
/** Keep Composer's numeric comparison grammar while using the shared translator. */
function composerRange(raw: string): string | null {
  const alternatives: string[] = [];
  for (const branch of raw.split(/\s*\|\|\s*/)) {
    const excluded: string[] = [];
    const compact = branch.replace(/([<>!=~^]=?)\s+(?=[vV]?[0-9xX*])/g, "$1");
    const positive = compact.replace(
      /!=([vV]?\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)/g,
      (_, value: string) => {
        const parts = value.replace(/^[vV]/, "").match(
          /^(\d+(?:\.\d+){0,2})(.*)$/,
        )!;
        const numbers = parts[1].split(".");
        while (numbers.length < 3) numbers.push("0");
        excluded.push(exactVersion(numbers.join(".") + parts[2]));
        return "";
      },
    ).trim();
    const base = composerToNpmRange(positive || "*");
    if (base === null) return null;
    const points = [...new Set(excluded)].sort(compare);
    if (!points.length) alternatives.push(base);
    else {
      // Removing exact versions creates only n+1 intervals, not an exponential OR expansion.
      for (let i = 0; i <= points.length; i++) {
        alternatives.push(
          [
            base,
            ...(i ? [`>${points[i - 1]}`] : []),
            ...(i < points.length ? [`<${points[i]}`] : []),
          ].join(" "),
        );
      }
    }
  }
  return alternatives.join(" || ");
}
export function registryRange(raw: unknown, composer = false): Range | null {
  requirePolicy(
    typeof raw === "string" && raw.length > 0 && raw.length <= 1024,
  );
  const atom = String
    .raw`(?:[~^]|[<>!]=?|=)?\s*[vV]?(?:[0-9]+|[xX*])(?:\.(?:[0-9]+|[xX*])){0,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?`;
  const numeric = new RegExp(
    `^${atom}(?:(?:\\s*\\|\\|\\s*|\\s*,\\s*|\\s+-\\s+|\\s+)${atom})*$`,
  );
  if (
    !numeric.test(raw.trim()) && !composer &&
    /^[A-Za-z][A-Za-z0-9_.-]*$/.test(raw) && !/\.(?:tgz|tar\.gz)$/i.test(raw) &&
    !raw.startsWith("dev-") &&
    !["file", "link", "workspace", "npm", "git"].includes(raw.toLowerCase())
  ) return null;
  requirePolicy(!/[:\/#@]|dev/i.test(raw) && numeric.test(raw.trim()));
  const normalized = composer ? composerRange(raw) : raw.replace(/,/g, " ");
  requirePolicy(normalized !== null);
  try {
    return new Range(normalized, { includePrerelease: true });
  } catch {
    requirePolicy(false);
  }
}

type Interval = [number, number, number];
function boundaries(range: Range): Interval[] {
  const result: Interval[] = [];
  // Project each numeric interval onto the first nonzero component. Stable versions
  // are sufficient for the breaking-axis projection; prereleases share that axis.
  for (const terms of range.set) {
    for (
      const [axis, floor, ceiling] of [[0, "1.0.0", null], [
        1,
        "0.1.0",
        "1.0.0",
      ], [2, "0.0.0", "0.1.0"]] as const
    ) {
      const slice = new Range(
        [
          ...terms.map((t) => t.value),
          `>=${floor}`,
          ...(ceiling ? [`<${ceiling}-0`] : []),
        ].join(" "),
        { includePrerelease: true },
      );
      const low = minVersion(slice);
      if (!low || !satisfies(low, slice, { includePrerelease: true })) continue;
      let upper = Number.MAX_SAFE_INTEGER;
      for (const comparator of terms) {
        if (
          !["<", "<=", ""].includes(comparator.operator) || !comparator.value
        ) continue;
        const v = comparator.semver;
        if (!v || typeof v !== "object" || !("major" in v)) continue;
        const parts = [v.major, v.minor, v.patch];
        let candidate = parts[axis];
        if (axis > 0 && parts.slice(0, axis).some((p) => p > 0)) {
          candidate = Number.MAX_SAFE_INTEGER;
        } else if (
          comparator.operator === "<" && parts.slice(axis + 1).every((p) =>
            p === 0
          ) && (v.prerelease.length === 0 ||
            (v.prerelease.length === 1 && v.prerelease[0] === 0))
        ) candidate--;
        upper = Math.min(upper, candidate);
      }
      const lower = [low.major, low.minor, low.patch][axis];
      if (upper >= lower) result.push([axis, lower, upper]);
    }
  }
  return result.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
}
export function constraintChange(
  before: unknown,
  after: unknown,
  composer = false,
): void {
  const old = registryRange(before, composer),
    next = registryRange(after, composer);
  requirePolicy(old === null || next !== null);
  if (before === after || old === null) return;
  const a = boundaries(old), b = boundaries(next!);
  requirePolicy(a.length && b.length);
  requirePolicy(
    b[0][0] > a[0][0] || (b[0][0] === a[0][0] && b[0][1] <= a[0][1]),
    "dependency_merge.major_bump",
  );
  for (const [axis, start, end] of b) {
    let cursor = start, covered = false;
    for (const [other, low, high] of a) {
      if (other !== axis || high < cursor) continue;
      if (low > cursor) break;
      if (high >= end) {
        covered = true;
        break;
      }
      cursor = high + 1;
    }
    requirePolicy(covered, "dependency_merge.major_bump");
  }
}
export function matches(
  version: unknown,
  constraint: unknown,
  composer = false,
): boolean {
  const range = registryRange(constraint, composer);
  return range === null ||
    satisfies(exactVersion(version), range, { includePrerelease: true });
}
