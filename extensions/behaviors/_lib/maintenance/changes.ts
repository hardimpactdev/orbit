import type { Advisory, Audit, Place } from "./io.ts";
import { type Change, majorChange } from "./summary.ts";
export type LockView = {
  versions: Record<string, string>;
  instances: Record<string, string>;
};
export type AuditFinding = Advisory & { module?: string };
export type AuditView = Record<string, AuditFinding[]>;
const canonical = (value: unknown): string => {
  if (value === null || typeof value !== "object") return JSON.stringify(value);
  if (Array.isArray(value)) return JSON.stringify(value.map(canonical));
  return JSON.stringify(
    Object.entries(value).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0).map((
      [k, v],
    ) => [k, canonical(v)]),
  );
};
/** Compare all locked instances; audit only changed paths, and keep real advisory identity across owners. */
export async function changesFor(
  place: Place,
  manager: Change["manager"],
  beforeDirect: Record<string, string>,
  afterDirect: Record<string, string>,
  before: LockView,
  after: LockView,
  audits: () => Promise<
    { previous: AuditView | Audit; current: AuditView | Audit }
  >,
): Promise<Change[]> {
  const cleared: Record<string, { id: string; url: string | null }[]> = {};
  if (
    canonical(beforeDirect) !== canonical(afterDirect) ||
    canonical(before) !== canonical(after)
  ) {
    const { previous, current } = await audits();
    const identity = (a: AuditFinding, owner: string) =>
      `${a.module ?? owner}\0${a.id}`;
    const present = new Set(
      Object.entries(current).flatMap(([name, list]) =>
        list.map((a) => identity(a, name))
      ),
    );
    for (const [name, list] of Object.entries(previous)) {
      const unique = new Map(
        list.filter((a) => !present.has(identity(a, name))).map(
          (a) => [a.id, {
            id: a.id,
            url: a.url?.startsWith("https://") ? a.url : null,
          }],
        ),
      );
      if (unique.size) {
        cleared[name] = [...unique.values()].sort((a, b) =>
          a.id < b.id ? -1 : a.id > b.id ? 1 : 0
        );
      }
    }
  }
  return [
    ...new Set([
      ...Object.keys(beforeDirect),
      ...Object.keys(afterDirect),
      ...Object.keys(cleared),
    ]),
  ].sort().flatMap((name) => {
    const was = name in beforeDirect,
      is = name in afterDirect,
      found = cleared[name] ?? [];
    const from = !was && is ? null : before.versions[name] ?? null;
    const to = was && !is ? null : after.versions[name] ?? null;
    if ((!was && !is && !found.length) || (from === to && !found.length)) {
      return [];
    }
    return [{
      manager,
      path: place.path,
      package: name,
      from: from ?? "—",
      to: to ?? "—",
      major: from !== null && to !== null && majorChange(from, to),
      advisories: found,
    }];
  });
}
