import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { mergeDns } from "./dns.ts";
const snapshot = (moving: string, other: string, extra = false) => ({
    records:
        `# Managed by Orbit.\nhost-record=moving.test,${moving}\nhost-record=other.test,${other}\n${
            extra ? "host-record=added.test,10.0.0.4\n" : ""
        }`,
    catalog: JSON.stringify({
        records: {
            "moving.test": moving,
            "other.test": other,
            ...(extra ? { "added.test": "10.0.0.4" } : {}),
        },
        suffixes: {},
        overrides: { "node:9": { "moving.test": moving, "other.test": other } },
        requesters: { "10.0.0.9": 9, ...(extra ? { "10.0.0.10": 10 } : {}) },
    }),
});
Deno.test("DNS forward, restore and repeated composition retain unrelated current hosts and requesters", () => {
    const before = snapshot("10.0.0.1", "10.0.0.2"),
        after = snapshot("10.0.0.3", "10.0.0.2"),
        current = snapshot("10.0.0.1", "10.0.0.8", true);
    const forward = mergeDns(before, after, current, [before, after]);
    assertEquals(JSON.parse(forward.catalog).records, {
        "moving.test": "10.0.0.3",
        "other.test": "10.0.0.8",
        "added.test": "10.0.0.4",
    });
    assertEquals(JSON.parse(forward.catalog).requesters["10.0.0.10"], 10);
    const restored = mergeDns(before, before, forward, [before, after]);
    assertEquals(JSON.parse(restored.catalog), JSON.parse(current.catalog));
    assertEquals(mergeDns(before, after, forward, [before, after]), forward);
    assertThrows(() =>
        mergeDns(before, after, snapshot("10.0.0.99", "10.0.0.2"), [
            before,
            after,
        ])
    );
});

Deno.test("PHP empty-map arrays preserve unrelated concurrent catalog entries", () => {
    const empty = { records: [], suffixes: [], overrides: [], requesters: [] };
    const before = {
        records: "# Managed by Orbit.\n",
        catalog: JSON.stringify(empty),
    };
    const after = {
        ...before,
        catalog: JSON.stringify({
            ...empty,
            suffixes: { "new.test": "10.0.0.1" },
            overrides: { "node:9": { "new.test": "10.0.0.1" } },
        }),
    };
    const current = {
        ...before,
        catalog: JSON.stringify({
            ...empty,
            suffixes: { "other.test": "10.0.0.2" },
            overrides: { "node:10": { "other.test": "10.0.0.2" } },
        }),
    };
    const result = JSON.parse(
        mergeDns(before, after, current, [before, after]).catalog,
    );
    assertEquals(result.suffixes, {
        "other.test": "10.0.0.2",
        "new.test": "10.0.0.1",
    });
    assertEquals(result.overrides["node:10"], { "other.test": "10.0.0.2" });
});
