import { z } from "npm:zod@4";
import {
  type CommonArgs,
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
export const StaleSchema = z.strictObject({
  path: z.string(),
  manager: z.literal("composer"),
  package: z.string(),
  recorded_on: z.iso.date(),
  remove_on: z.iso.date(),
});

async function records(root: string) {
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

async function vet(place: Place) {
  const text = await optionalText(`${place.dir}/vet.json`);
  if (text === null) return null;
  const data = decode(text, "vet.json");
  const age = data["minimum-release-age"];
  if (age !== undefined && (!Number.isInteger(age) || (age as number) < 0)) {
    throw new MaintenanceFailure(
      "vet.json minimum-release-age must be a non-negative integer",
    );
  }
  const excludes = data["minimum-release-age-exclude"] ?? [];
  if (!Array.isArray(excludes) || excludes.some((v) => typeof v !== "string")) {
    throw new MaintenanceFailure(
      "vet.json minimum-release-age-exclude must be a list",
    );
  }
  return {
    data,
    age: age as number | undefined,
    excludes: excludes as string[],
  };
}

export async function allowSecurity(
  root: string,
  place: Place,
  name: string,
  policy: CommonArgs["policy"],
): Promise<void> {
  const current = await vet(place);
  if (current === null || current.age === undefined) return;
  if (policy[place.path]?.minimumReleaseAgeDays !== current.age) {
    throw new MaintenanceFailure(
      `Input policy for ${place.path} must match vet.json minimum-release-age`,
      64,
    );
  }
  // Parse existing records before changing either file.
  const document = await records(root);
  if (!current.excludes.includes(name)) {
    current.data["minimum-release-age-exclude"] = [...current.excludes, name];
    await writeJson(`${place.dir}/vet.json`, current.data);
  }
  if (
    !document.exceptions.some((r) =>
      r.path === place.path && r.manager === "composer" && r.package === name
    )
  ) {
    document.exceptions.push({
      path: place.path,
      manager: "composer",
      package: name,
      recorded_on: new Date().toISOString().slice(0, 10),
      days: current.age,
    });
    await writeJson(`${root}/maintainer-exceptions.json`, document);
  }
}

export async function staleExceptions(root: string, places: Place[]) {
  const document = await records(root);
  if (document.exceptions.length === 0) return [];
  const excluded = new Set<string>();
  for (const place of places) {
    const current = await vet(place);
    for (const name of current?.excludes ?? []) {
      excluded.add(`${place.path}\0${name}`);
    }
  }
  const today = new Date().toISOString().slice(0, 10);
  return document.exceptions.flatMap((r) => {
    if (r.manager !== "composer" || !excluded.has(`${r.path}\0${r.package}`)) {
      return [];
    }
    const remove = new Date(`${r.recorded_on}T00:00:00Z`);
    remove.setUTCDate(remove.getUTCDate() + r.days);
    const remove_on = remove.toISOString().slice(0, 10);
    return today < remove_on ? [] : [{
      path: r.path,
      manager: "composer" as const,
      package: r.package,
      recorded_on: r.recorded_on,
      remove_on,
    }];
  });
}
