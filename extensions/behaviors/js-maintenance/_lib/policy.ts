import { z } from "npm:zod@4";
import {
  dueExceptions,
  recordException,
  records,
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
  root: string,
  place: Place,
  name: string,
  policy: CommonArgs["policy"],
) {
  const current = await bunfig(place);
  if (current === null) return;
  if (policy[place.path]?.minimumReleaseAgeDays !== current.days) {
    throw new MaintenanceFailure(
      `Input policy for ${place.path} must match bunfig.toml minimumReleaseAge in days`,
      64,
    );
  }
  const document = await records(root);
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
  await recordException(root, document, place, "js", name, current.days);
}
export async function staleExceptions(root: string, places: Place[]) {
  const document = await records(root), excluded = new Set<string>();
  if (!document.exceptions.length) return [];
  for (const place of places) {
    for (const name of (await bunfig(place))?.excludes ?? []) {
      excluded.add(`${place.path}\0${name}`);
    }
  }
  return dueExceptions(document, "js", excluded);
}
