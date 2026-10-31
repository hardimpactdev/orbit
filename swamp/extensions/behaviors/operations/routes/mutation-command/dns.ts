import { mergeSections } from "../publication-command/sections.ts";

function records(text: string): Map<string, string> {
    const result = new Map<string, string>();
    for (const line of text.split("\n")) {
        if (!line || line.startsWith("#")) continue;
        const match = /^(host-record)=([^,]+),.+$|^(address|local)=\/([^/]+)\//
            .exec(line);
        if (!match) throw new Error("dns.foreign_records");
        const key = `${match[1] ?? match[3]}:${match[2] ?? match[4]}`;
        if (result.has(key)) throw new Error("dns.duplicate_record");
        result.set(key, line);
    }
    return result;
}
const object = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === "object" && !Array.isArray(value);
function catalog(
    before: unknown,
    after: unknown,
    current: unknown,
    alternatives: unknown[],
): unknown {
    // PHP serializes empty associative arrays as []; these catalog fields are maps.
    const normalize = (value: unknown) =>
        Array.isArray(value) && value.length === 0 ? {} : value;
    before = normalize(before);
    after = normalize(after);
    current = normalize(current);
    alternatives = alternatives.map(normalize);
    if (
        [before, after, current, ...alternatives].every((value) =>
            value === undefined || object(value)
        )
    ) {
        const maps = [before, after, current, ...alternatives].map((value) =>
            object(value) ? value : {}
        );
        const result: Record<string, unknown> = {};
        for (
            const key of new Set(maps.flatMap((value) => Object.keys(value)))
        ) {
            const value = catalog(
                maps[0][key],
                maps[1][key],
                maps[2][key],
                maps.slice(3).map((row) => row[key]),
            );
            if (value !== undefined) result[key] = value;
        }
        return result;
    }
    const map = (value: unknown) =>
        new Map(value === undefined ? [] : [["value", value]]);
    return mergeSections(
        map(before),
        map(after),
        map(current),
        "dns",
        alternatives.map(map),
    ).get("value");
}
export function mergeDns(
    before: { records: string; catalog: string },
    after: { records: string; catalog: string },
    current: { records: string; catalog: string },
    alternatives: { records: string; catalog: string }[] = [],
) {
    const values = mergeSections(
        records(before.records),
        records(after.records),
        records(current.records),
        "dns",
        alternatives.map((value) => records(value.records)),
    );
    return {
        records: "# Managed by Orbit.\n" +
            [...values.values()].sort().join("\n") + "\n",
        catalog: JSON.stringify(
            catalog(
                JSON.parse(before.catalog),
                JSON.parse(after.catalog),
                JSON.parse(current.catalog),
                alternatives.map((value) => JSON.parse(value.catalog)),
            ),
        ) + "\n",
    };
}
