import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior, MAXIMUM_ROWS, report } from "./assets.ts";

const NOW = new Date("2026-11-02T07:07:00Z");
const at = (hours: number) =>
    new Date(NOW.getTime() + hours * 3_600_000).toISOString();
const access = (name: string, hours: number, state = "active") => ({
    name,
    state,
    principalId: "worker:x",
    expiresAt: at(hours),
});
const enrollment = (name: string, hours: number, state = "enrolled") => ({
    name,
    state,
    expiresAt: at(hours),
});
const certificate = "notAfter=Nov  7 20:56:22 2027 GMT\n";
const lists = (
    accessRows: unknown[],
    enrollmentRows: unknown[],
    clubRows: unknown[] = [],
) => ({
    access: JSON.stringify(accessRows),
    enrollment: JSON.stringify(enrollmentRows),
    club: JSON.stringify(clubRows),
    certificate,
});
const pair = [access("orbit-node-209-access", 500)];
const pairEnrollment = [enrollment("orbit-node-209", 500)];

Deno.test("each expiry class alerts by its own reason; ok and no_expiry stay quiet", () => {
    const result = report(
        lists(
            [
                access("orbit-node-209-access", 5),
                access("old", -1, "revoked"),
                access("orbit-gateway-read", 400),
            ],
            [enrollment("orbit-node-209", 30), enrollment("orbit-node-13", -2)],
            [{ name: "orbit-monorepo-ci", enabled: true, expiresAt: null }, {
                name: "off",
                enabled: false,
                expiresAt: at(1),
            }],
        ),
        NOW,
    );
    assertEquals(result.alerts.map((item) => [item.name, item.reason]), [
        ["orbit-node-13", "expired"],
        ["orbit-node-209-access", "within_6h"],
        ["orbit-node-209", "within_72h"],
    ]);
    assertEquals(result.checked_at, NOW.toISOString());
});

Deno.test("class boundaries follow the hours left", () => {
    for (
        const [hours, reason] of [[72, "within_72h"], [6, "within_6h"], [
            0,
            "expired",
        ]] as const
    ) {
        const result = report(
            lists([...pair, access("x", hours)], pairEnrollment),
            NOW,
        );
        assertEquals(result.alerts.map((item) => item.reason), [reason]);
    }
    assertEquals(
        report(lists([...pair, access("x", 100)], pairEnrollment), NOW).alerts,
        [],
    );
});

Deno.test("the 209 worker pair is reported missing when it is not listed", () => {
    assertEquals(
        report(lists([], []), NOW).alerts.map((
            item,
        ) => [item.kind, item.name, item.reason]),
        [
            ["access", "orbit-node-209-access", "missing"],
            ["enrollment", "orbit-node-209", "missing"],
        ],
    );
});

Deno.test("a changed native shape or certificate fails the run", () => {
    assertThrows(
        () =>
            report(
                { ...lists(pair, pairEnrollment), access: '{"tokens":[]}' },
                NOW,
            ),
        Error,
        "access list changed shape",
    );
    assertThrows(
        () =>
            report({ ...lists(pair, pairEnrollment), access: "not json" }, NOW),
        Error,
        "access list is not JSON",
    );
    assertThrows(
        () => report({ ...lists(pair, pairEnrollment), certificate: "" }, NOW),
        Error,
        "openssl returned no notAfter",
    );
    assertThrows(
        () =>
            report(
                lists([...pair, {
                    ...access("x", 1),
                    expiresAt: "2026-11-02T08:00:00",
                }], pairEnrollment),
                NOW,
            ),
        Error,
        "no time zone",
    );
});

Deno.test("the report stays bounded, soonest first, and skips week-old expiries", () => {
    const many = [
        ...[0, 1, 2].map((n) => access(`stale-${n}`, -200)),
        ...Array.from(
            { length: 400 },
            (_, n) => access(`live-${n}`, (n + 1) / 10),
        ),
    ];
    const result = report(lists([...many, ...pair], pairEnrollment), NOW);
    assertEquals(result.alerts.length, MAXIMUM_ROWS);
    assertEquals(result.alerts[0].name, "live-0");
    assertEquals(
        result.alerts.some((item) => item.name.startsWith("stale-")),
        false,
    );
});

Deno.test("the report is written as a native resource and the manifest version matches", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.credential_report.execute(
        lists(pair, pairEnrollment),
        {
            writeResource: (...args) => Promise.resolve(writes.push(args)),
        },
    );
    assertEquals(writes, [["credential_report", "report", result]]);
    const manifest = parse(
        await Deno.readTextFile(new URL("manifest.yaml", import.meta.url)),
    ) as { version: string };
    assertEquals(manifest.version, behavior.version);
});
