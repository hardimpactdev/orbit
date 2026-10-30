import { resolve } from "jsr:@std/path@1.1.4";

/** Native control-plane state is never owned by Orbit's retention tools. */
export const RETENTION_EXCLUSIONS = [".swamp/_control"] as const;

export function assertRetentionTarget(repo: string, path: string): void {
  const target = resolve(repo, path);
  for (const relative of RETENTION_EXCLUSIONS) {
    const excluded = resolve(repo, relative);
    if (
      target === excluded || target.startsWith(`${excluded}/`) ||
      excluded.startsWith(target.endsWith("/") ? target : `${target}/`)
    ) throw new Error("control_plane_retention_refused");
  }
}
