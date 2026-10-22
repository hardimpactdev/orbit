// Version and constraint helpers for the dependency planner.
// npm ranges go straight to node-semver. Composer constraints are translated
// to node-semver ranges first; the few Composer forms that have no npm
// equivalent (dev branches, stability flags on their own) return null.
import semver from "npm:semver@7.7.2";

export type Ecosystem = "composer" | "npm";

/** Normalise a registry or lockfile version to strict SemVer, or null. */
export function normaliseVersion(raw: string): string | null {
  const v = raw.trim().replace(/^v/i, "");
  if (v.startsWith("dev-") || v.endsWith("-dev")) return null;
  // Composer allows four numeric parts (1.2.3.4); keep the first three.
  const four = v.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)(.*)$/);
  const candidate = four ? `${four[1]}.${four[2]}.${four[3]}${four[5]}` : v;
  return semver.valid(candidate) ?? semver.valid(semver.coerce(candidate)) ?? null;
}

export function isStable(version: string): boolean {
  return semver.prerelease(version) === null;
}

/** Translate one Composer constraint to a node-semver range. */
export function composerToNpmRange(constraint: string): string | null {
  const c = constraint.trim();
  if (c === "" || c.startsWith("dev-")) return null;
  const alternatives = c.split(/\s*\|\|?\s*/).map((alt) => {
    if (/\s-\s/.test(alt)) return alt; // hyphen range, same in both
    const parts = alt.split(/\s*,\s*|\s+/).filter(Boolean).map(composerTerm);
    if (parts.some((p) => p === null)) return null;
    return parts.join(" ");
  });
  if (alternatives.some((a) => a === null)) return null;
  const range = alternatives.join(" || ");
  return semver.validRange(range) ? range : null;
}

function composerTerm(term: string): string | null {
  const t = term.replace(/@(stable|RC|beta|alpha|dev)$/i, "");
  if (t === "" || t.startsWith("dev-")) return null;
  const m = t.match(/^(\^|~|>=|<=|>|<|==|=|!=)?\s*v?([\d.x*]+.*)$/i);
  if (!m) return null;
  const op = m[1] ?? "";
  const ver = m[2];
  if (op === "!=") return null;
  if (op === "~") {
    // Composer ~1.2 means >=1.2 <2.0; npm ~1.2 means <1.3. Three parts agree.
    const nums = ver.split(".");
    if (nums.length === 2) {
      const major = Number(nums[0]);
      return `>=${nums[0]}.${nums[1]}.0 <${major + 1}.0.0`;
    }
  }
  return `${op === "==" ? "=" : op}${ver}`;
}

export function toRange(ecosystem: Ecosystem, constraint: string): string | null {
  if (ecosystem === "composer") return composerToNpmRange(constraint);
  const c = constraint.trim();
  if (c.startsWith("npm:")) {
    // Alias such as npm:@voidzero-dev/vite-plus-core@1.1.0
    const at = c.lastIndexOf("@");
    return at > 4 ? semver.validRange(c.slice(at + 1)) : null;
  }
  if (/^(file|link|workspace|git|github|https?):/.test(c)) return null;
  return semver.validRange(c);
}

export function maxSatisfying(versions: string[], range: string): string | null {
  return semver.maxSatisfying(versions, range);
}

export function satisfies(version: string, range: string): boolean {
  return semver.satisfies(version, range);
}

export function gt(a: string, b: string): boolean {
  return semver.gt(a, b);
}

/** A change is "major" when it leaves the caret range of the current version (0.x minors count). */
export function isMajorJump(from: string, to: string): boolean {
  return !semver.satisfies(to, `^${from}`);
}

/** The constraint a constraint-change subtask would write, e.g. ^14.0. */
export function suggestedConstraint(latest: string): string {
  const v = semver.parse(latest)!;
  return v.major === 0 ? `^0.${v.minor}` : `^${v.major}.${v.minor}`;
}
