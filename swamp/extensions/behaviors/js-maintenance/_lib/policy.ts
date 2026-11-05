import { z } from "npm:zod@4";
import { dueExceptions } from "../../_lib/maintenance/exceptions.ts";
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
// Read release-age exclusions only from [install].
async function bunfig(place: Place) {
    const text = await optionalText(`${place.dir}/bunfig.toml`);
    if (text === null) return null;
    const section =
        /^\[install\][ \t]*(?:#.*)?\r?\n([\s\S]*?)(?=^\[|$(?![\s\S]))/m.exec(
            text,
        );
    if (!section) return null;
    const age = /^minimumReleaseAge\s*=\s*(\d+)[ \t]*(?:#.*)?$/m.exec(
        section[1],
    );
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
            !Array.isArray(excludes) ||
            excludes.some((s) => typeof s !== "string")
        ) {
            throw new MaintenanceFailure(
                "Cannot read bunfig.toml release-age exceptions",
            );
        }
    }
    return { excludes };
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
