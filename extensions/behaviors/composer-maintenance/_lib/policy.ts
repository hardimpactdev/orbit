import { write } from "../../_lib/maintenance/context.ts";
import { type Context } from "../../_lib/maintenance/context.ts";
import {
  dueExceptions,
  ExceptionsSchema,
  recordException,
} from "../../_lib/maintenance/exceptions.ts";
import { z } from "npm:zod@4";
import {
  type CommonArgs,
  decode,
  MaintenanceFailure,
  optionalText,
  type Place,
  writeJson,
} from "./io.ts";

export const StaleSchema = z.strictObject({
  path: z.string(),
  manager: z.literal("composer"),
  package: z.string(),
  recorded_on: z.iso.date(),
  remove_on: z.iso.date(),
});

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
  place: Place,
  name: string,
  policy: CommonArgs["policy"],
  document: CommonArgs["exceptions"],
  ctx: Context,
): Promise<void> {
  const current = await vet(place);
  if (current === null || current.age === undefined) return;
  if (policy[place.path]?.minimumReleaseAgeDays !== current.age) {
    throw new MaintenanceFailure(
      `Input policy for ${place.path} must match vet.json minimum-release-age`,
      64,
    );
  }
  await write(
    ctx,
    "exceptions",
    ExceptionsSchema,
    recordException(document, place, "composer", name, current.age),
  );
  if (!current.excludes.includes(name)) {
    current.data["minimum-release-age-exclude"] = [...current.excludes, name];
    await writeJson(`${place.dir}/vet.json`, current.data);
  }
}

export async function staleExceptions(
  document: CommonArgs["exceptions"],
  places: Place[],
) {
  if (document.exceptions.length === 0) return [];
  const excluded = new Set<string>();
  for (const place of places) {
    const current = await vet(place);
    for (const name of current?.excludes ?? []) {
      excluded.add(`${place.path}\0${name}`);
    }
  }
  return dueExceptions(document, "composer", excluded);
}
