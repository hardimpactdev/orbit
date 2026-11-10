import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, readReplies, selectPairings } from "./mod.ts";
import { sessionInventory } from "./read_sessions.ts";

const fixture = async (name: string) =>
    JSON.parse(
        await Deno.readTextFile(new URL(`./fixtures/${name}`, import.meta.url)),
    );
const frame = await fixture("stock-0.1.7-clone-1838-auth-access.json");
const rows = sessionInventory(frame)!;
const cli = await fixture("stock-0.1.7-clone-1838-cli-sessions.json");
const mini = {
    id: 1899,
    label: "mini via Orbit p5eflnvr",
    ipAddress: "10.44.0.9",
    issuedAfter: "2026-11-10T16:52:41.000000Z",
    expiresAt: "2026-11-10T16:57:43.000000Z",
    delivered: true,
};
const conn = {
    id: 1898,
    label: "nick via Orbit xfw0gxzm",
    ipAddress: "10.44.0.6",
    issuedAfter: "2026-11-10T16:51:54.095Z",
    expiresAt: "2026-11-10T16:56:54.095Z",
    delivered: true,
};

Deno.test("real stock CLI-linked and Conn sessions are selected from the upstream snapshot", () => {
    const selected = selectPairings(JSON.stringify(rows), [mini, conn]);
    assertEquals(selected.ids, [
        "10275507-0526-4a7e-b365-be59aa9f5439",
        "855dbc55-99e7-4d3b-8ea2-548df4165297",
    ]);
    assertEquals(selected.pairings, [
        { id: 1899, delivered: true, indices: [1] },
        { id: 1898, delivered: true, indices: [0] },
    ]);
    // The independent local CLI read confirms the same two session IDs.
    assertEquals(
        selectPairings(JSON.stringify(cli), [mini, conn]).ids.sort(),
        [...selected.ids].sort(),
    );
});

Deno.test("MCP attribution includes every match and excludes current, other subject, IP and window", () => {
    const base = {
        sessionId: "inside",
        subject: "mcp-client",
        current: false,
        issuedAt: "2026-11-10T16:53:21.318Z",
        client: { label: "arbitrary OAuth client", ipAddress: "10.44.0.9" },
    };
    const inventory = [
        base,
        { ...base, sessionId: "start", issuedAt: mini.issuedAfter },
        { ...base, sessionId: "end", issuedAt: mini.expiresAt },
        { ...base, sessionId: "current", current: true },
        { ...base, sessionId: "other-subject", subject: "cli-issued-session" },
        { ...base, sessionId: "other-ip", client: { ipAddress: "10.44.0.99" } },
        { ...base, sessionId: "missing-ip", client: { label: mini.label } },
        { ...base, sessionId: "before", issuedAt: "2026-11-10T16:52:40.999Z" },
        { ...base, sessionId: "after", issuedAt: "2026-11-10T16:57:43.001Z" },
        { ...base, sessionId: "missing-time", issuedAt: undefined },
    ];
    assertEquals(selectPairings(JSON.stringify(inventory), [mini]).ids, [
        "inside",
        "start",
        "end",
    ]);
});

Deno.test("a delivered pairing without a match stops selection before publishing any requests", async () => {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-revoke.",
        dir: "/tmp",
    });
    try {
        await Deno.writeTextFile(`${directory}/inventory.json`, "[]");
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(
            () =>
                behavior.methods.select.execute({
                    directory,
                    intent: {
                        url: "http://t3.test",
                        labels: [],
                        links: ["owned-link"],
                        pairings: [mini],
                    },
                }, context),
            Error,
            "conn.revocation_unmatched",
        );
        assertEquals(getWrittenResources(), []);
        assertThrows(
            () =>
                selectPairings(JSON.stringify(rows), [mini, {
                    ...conn,
                    label: "absent",
                }]),
            Error,
            "conn.revocation_unmatched",
        );
        assertEquals(
            selectPairings("[]", [{ ...mini, delivered: false }]).ids,
            [],
        );
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("zero actual revocations for any delivered pairing fail even when another pairing revoked a session", async () => {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-revoke.",
        dir: "/tmp",
    });
    try {
        await Deno.writeTextFile(
            `${directory}/session-0.json`,
            '{"revoked":false}',
        );
        await Deno.writeTextFile(
            `${directory}/session-1.json`,
            '{"revoked":true}',
        );
        for (
            const statuses of [["succeeded"], ["failed"], [
                "succeeded",
                "succeeded",
            ]]
        ) {
            const result = await readReplies(directory, "session", statuses, [
                { id: mini.id, delivered: true, indices: [0] },
                { id: conn.id, delivered: true, indices: [1] },
            ]);
            assertEquals(result.valid, false);
            assertEquals(
                JSON.parse(result.stdout).error_code,
                "conn.revocation_unmatched",
            );
        }
        await Deno.writeTextFile(
            `${directory}/session-0.json`,
            '{"revoked":true}',
        );
        assertEquals(
            (await readReplies(
                directory,
                "session",
                ["succeeded", "succeeded"],
                [
                    { id: mini.id, delivered: true, indices: [0] },
                    { id: conn.id, delivered: true, indices: [1] },
                ],
            )).valid,
            true,
        );
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
