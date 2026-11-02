import { dueExceptions } from "../../_lib/maintenance/exceptions.ts";
import { z } from "npm:zod@4";
import {
  type CommonArgs,
  decode,
  MaintenanceFailure,
  optionalText,
  type Place,
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
  return { excludes: excludes as string[] };
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
