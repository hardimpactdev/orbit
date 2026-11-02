import { write } from "../../_lib/maintenance/context.ts";
import { type Context } from "../../_lib/maintenance/context.ts";
import { z } from "npm:zod@4";
import {
  dueExceptions,
  ExceptionsSchema,
  recordException,
} from "../../_lib/maintenance/exceptions.ts";
import {
  type CommonArgs,
  MaintenanceFailure,
  optionalText,
  type Place,
} from "../../_lib/maintenance/io.ts";
export const StaleSchema = z.strictObject({
  path: z.string(),
  manager: z.literal("js"),
  package: z.string(),
  recorded_on: z.iso.date(),
  remove_on: z.iso.date(),
});
// Work only inside [install]; preserve other TOML settings and comments verbatim.
async function bunfig(place: Place) {
  const text = await optionalText(`${place.dir}/bunfig.toml`);
  if (text === null) return null;
  const section =
    /^\[install\][ \t]*(?:#.*)?\r?\n([\s\S]*?)(?=^\[|$(?![\s\S]))/m.exec(text);
  if (!section) return null;
  const age = /^minimumReleaseAge\s*=\s*(\d+)[ \t]*(?:#.*)?$/m.exec(section[1]);
  if (!age) return null;
  const line = /^minimumReleaseAgeExcludes[ \t]*=([^\n]*)/m.exec(section[1]);
  let excludes: string[] = [];
  if (line) {
    const list = /^\s*(\[[^\n]*\])[ \t]*(?:#.*)?$/.exec(line[1]);
    if (!list) {
      throw new MaintenanceFailure(
        "bunfig.toml release-age exception is not a single line",
      );
    }
    try {
      excludes = JSON.parse(list[1].replace(/,\s*]$/, "]"));
    } catch {
      throw new MaintenanceFailure(
        "Cannot read bunfig.toml release-age exceptions",
      );
    }
    if (
      !Array.isArray(excludes) || excludes.some((s) => typeof s !== "string")
    ) {
      throw new MaintenanceFailure(
        "Cannot read bunfig.toml release-age exceptions",
      );
    }
  }
  return {
    text,
    section,
    age,
    line,
    excludes,
    days: Math.ceil(+age[1] / 86400),
  };
}
export async function allowSecurity(
  place: Place,
  name: string,
  policy: CommonArgs["policy"],
  document: CommonArgs["exceptions"],
  ctx: Context,
) {
  const current = await bunfig(place);
  if (current === null) return;
  if (policy[place.path]?.minimumReleaseAgeDays !== current.days) {
    throw new MaintenanceFailure(
      `Input policy for ${place.path} must match bunfig.toml minimumReleaseAge in days`,
      64,
    );
  }
  await write(
    ctx,
    "exceptions",
    ExceptionsSchema,
    recordException(document, place, "js", name, current.days),
  );
  if (!current.excludes.includes(name)) {
    const rendered = `minimumReleaseAgeExcludes = ${
      JSON.stringify([...current.excludes, name])
    }`;
    const section = current.line
      ? current.section[0].replace(current.line[0], () => rendered)
      : current.section[0].replace(
        current.age[0],
        () => `${current.age[0]}\n${rendered}`,
      );
    await Deno.writeTextFile(
      `${place.dir}/bunfig.toml`,
      current.text.replace(current.section[0], () => section),
    );
  }
}
export async function staleExceptions(
  document: CommonArgs["exceptions"],
  places: Place[],
) {
  const excluded = new Set<string>();
  if (!document.exceptions.length) return [];
  for (const place of places) {
    for (const name of (await bunfig(place))?.excludes ?? []) {
      excluded.add(`${place.path}\0${name}`);
    }
  }
  return dueExceptions(document, "js", excluded);
}
