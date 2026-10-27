import { z } from "npm:zod@4";
import { type Place } from "./io.ts";
export const ExceptionSchema = z.looseObject({
  path: z.string(),
  manager: z.enum(["composer", "js"]),
  package: z.string(),
  recorded_on: z.iso.date(),
  days: z.number().int().nonnegative(),
});
export const ExceptionsSchema = z.looseObject({
  schema: z.literal(1),
  exceptions: z.array(ExceptionSchema),
});
export type Exceptions = z.infer<typeof ExceptionsSchema>;
export const emptyExceptions = (): Exceptions => ({
  schema: 1,
  exceptions: [],
});

/** Keep the first date and all metadata for each package, path and ecosystem. */
export function mergeExceptions(...documents: unknown[]): Exceptions {
  let merged = emptyExceptions();
  const seen = new Set<string>();
  for (const input of documents) {
    const document = ExceptionsSchema.parse(input);
    const rows = document.exceptions.filter((row) => {
      const key = JSON.stringify([row.path, row.manager, row.package]);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
    merged = {
      ...merged,
      ...document,
      exceptions: [...merged.exceptions, ...rows],
    };
  }
  return merged;
}

export function recordException(
  document: Exceptions,
  place: Place,
  manager: "composer" | "js",
  name: string,
  days: number,
): Exceptions {
  return mergeExceptions(document, {
    schema: 1,
    exceptions: [{
      path: place.path,
      manager,
      package: name,
      recorded_on: new Date().toISOString().slice(0, 10),
      days,
    }],
  });
}

/** Report elapsed exclusions without changing the policy or exception records. */
export function dueExceptions<M extends string>(
  document: Exceptions,
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
