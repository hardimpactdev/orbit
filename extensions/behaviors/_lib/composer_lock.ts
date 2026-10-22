// Pure helpers for Composer lockfiles; unit-tested in composer_lock_test.ts.

export interface LockChange {
  name: string;
  from: string | null;
  to: string | null;
}

interface Lock {
  packages?: { name: string; version: string }[];
  "packages-dev"?: { name: string; version: string }[];
}

function versions(lock: Lock): Map<string, string> {
  return new Map([...(lock.packages ?? []), ...(lock["packages-dev"] ?? [])].map((p) => [p.name, p.version]));
}

/** Every package whose locked version moved, was added, or was removed. */
export function lockChanges(before: Lock, after: Lock): LockChange[] {
  const a = versions(before);
  const b = versions(after);
  const out: LockChange[] = [];
  for (const [name, to] of b) if (a.get(name) !== to) out.push({ name, from: a.get(name) ?? null, to });
  for (const [name, from] of a) if (!b.has(name)) out.push({ name, from, to: null });
  return out.sort((x, y) => x.name.localeCompare(y.name));
}

/** Environment for a child process: an allowlist, so agent markers of the launching session never leak in. */
export function cleanEnv(get: (k: string) => string | undefined, extra: Record<string, string> = {}): Record<string, string> {
  const env: Record<string, string> = {};
  for (const k of ["PATH", "HOME", "USER", "LOGNAME", "LANG", "TMPDIR", "COMPOSER_HOME", "XDG_CONFIG_HOME", "XDG_CACHE_HOME"]) {
    const v = get(k);
    if (v !== undefined) env[k] = v;
  }
  return { ...env, TERM: "dumb", ...extra };
}

export function tail(text: string, bytes = 12000): string {
  return text.length <= bytes ? text : text.slice(text.length - bytes);
}
