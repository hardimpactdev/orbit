import { z } from "npm:zod@4";
import {
  decode,
  MaintenanceFailure,
  optionalText,
  type Place,
  writeJson,
} from "./io.ts";
const RecordSchema = z.looseObject({
  path: z.string(),
  manager: z.string(),
  package: z.string(),
  recorded_on: z.iso.date(),
  days: z.number().int().nonnegative(),
});
const RecordsSchema = z.looseObject({
  schema: z.literal(1),
  exceptions: z.array(RecordSchema),
});
export async function records(root: string) {
  const text = await optionalText(`${root}/maintainer-exceptions.json`);
  if (text === null) return { schema: 1 as const, exceptions: [] };
  const parsed = RecordsSchema.safeParse(
    decode(text, "maintainer-exceptions.json"),
  );
  if (!parsed.success) {
    throw new MaintenanceFailure("Cannot read maintainer-exceptions.json");
  }
  return parsed.data;
}

/** Append once, keeping the original date and every unrelated record and field. */
export async function recordException(
  root: string,
  document: Awaited<ReturnType<typeof records>>,
  place: Place,
  manager: string,
  name: string,
  days: number,
) {
  if (
    document.exceptions.some((r) =>
      r.path === place.path && r.manager === manager && r.package === name
    )
  ) return;
  document.exceptions.push({
    path: place.path,
    manager,
    package: name,
    recorded_on: new Date().toISOString().slice(0, 10),
    days,
  });
  await writeJson(`${root}/maintainer-exceptions.json`, document);
}
/** Report elapsed exclusions without changing the policy or ledger. */
export function dueExceptions<M extends string>(
  document: Awaited<ReturnType<typeof records>>,
  manager: M,
  excluded: Set<string>,
) {
  const today = new Date().toISOString().slice(0, 10);
  return document.exceptions.flatMap((r) => {
    if (r.manager !== manager || !excluded.has(`${r.path}\0${r.package}`)) {
      return [];
    }
    const remove = new Date(`${r.recorded_on}T00:00:00Z`);
    remove.setUTCDate(remove.getUTCDate() + r.days);
    const remove_on = remove.toISOString().slice(0, 10);
    return today < remove_on ? [] : [{
      path: r.path,
      manager,
      package: r.package,
      recorded_on: r.recorded_on,
      remove_on,
    }];
  });
}
