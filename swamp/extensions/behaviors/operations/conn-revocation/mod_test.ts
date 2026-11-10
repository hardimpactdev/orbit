import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, readReplies, selectSessions } from "./mod.ts";

Deno.test("only admitted non-current sessions are selected", () => {
    assertEquals(
        selectSessions(
            JSON.stringify([
                {
                    sessionId: "owned",
                    client: { label: "phone" },
                    private: "discarded",
                },
                {
                    sessionId: "self",
                    current: true,
                    client: { label: "phone" },
                },
                { sessionId: "foreign", client: { label: "other" } },
                { sessionId: "unlabelled", client: null },
            ]),
            ["phone"],
        ),
        ["owned"],
    );
});
Deno.test("a malformed or duplicate list never becomes empty success", () => {
    for (
        const value of [
            "not json",
            "{}",
            '[{"current":false}]',
            '[{"sessionId":"bad id"}]',
            '[{"sessionId":"x","current":"yes"}]',
            '[{"sessionId":"x"},{"sessionId":"x"}]',
        ]
    ) {
        assertThrows(() => selectSessions(value, ["phone"]));
    }
});
Deno.test("private replies yield only valid public completions, including partial failure", async () => {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-revoke.",
        dir: "/tmp",
    });
    try {
        await Deno.writeTextFile(
            `${directory}/link-0.json`,
            '{"revoked":true,"private":"never published"}',
        );
        await Deno.writeTextFile(
            `${directory}/link-1.json`,
            '{"revoked":false}',
        );
        await Deno.writeTextFile(
            `${directory}/link-2.json`,
            '{"revoked":"invalid"}',
        );
        await Deno.writeTextFile(`${directory}/link-3.json`, "invalid-json");
        assertEquals(
            await readReplies(directory, "link", [
                "succeeded",
                "succeeded",
                "succeeded",
                "succeeded",
                "failed",
            ]),
            {
                valid: false,
                stdout: '{"valid":false,"completed":[0,1],"revoked":1}',
            },
        );
        assertEquals(await readReplies(directory, "session", []), {
            valid: true,
            stdout: '{"valid":true,"completed":[],"revoked":0}',
        });
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("selection keeps multiple admitted sessions in inventory order", () => {
    assertEquals(
        selectSessions(
            JSON.stringify([
                { sessionId: "second", client: { label: "phone" } },
                {
                    sessionId: "self",
                    current: true,
                    client: { label: "phone" },
                },
                { sessionId: "first", client: { label: "phone" } },
            ]),
            ["phone"],
        ),
        ["second", "first"],
    );
    assertEquals(selectSessions("[]", ["phone"]), []);
});

Deno.test("select maps malformed inventory to conn.revocation_failed without publishing requests", async () => {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-revoke.",
        dir: "/tmp",
    });
    try {
        await Deno.writeTextFile(`${directory}/inventory.json`, "invalid-json");
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(
            () =>
                behavior.methods.select.execute({
                    intent: {
                        url: "https://t3.test",
                        labels: ["phone"],
                        pairings: [],
                        links: ["owned-link"],
                    },
                    directory,
                }, context),
            Error,
            "conn.revocation_failed",
        );
        assertEquals(getWrittenResources(), []);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("selection gives sessions and links ordered request slots without private inventory fields", async () => {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-revoke.",
        dir: "/tmp",
    });
    try {
        await Deno.writeTextFile(
            `${directory}/inventory.json`,
            JSON.stringify([
                {
                    sessionId: "owned",
                    client: { label: "phone" },
                    private: "discarded",
                },
                {
                    sessionId: "self",
                    current: true,
                    client: { label: "phone" },
                },
                { sessionId: "foreign", client: { label: "other" } },
                { sessionId: "owned-two", client: { label: "phone" } },
            ]),
        );
        const { context, getWrittenResources } = createModelTestContext();
        const result = await behavior.methods.select.execute({
            intent: {
                url: "https://t3.test",
                labels: ["phone"],
                pairings: [],
                links: ["first", "second", "third"],
            },
            directory,
        }, context);
        assertEquals(result, {
            sessions: [
                { index: 0, body: '{"sessionId":"owned"}' },
                { index: 1, body: '{"sessionId":"owned-two"}' },
            ],
            pairings: [],
            links: [
                { index: 0, body: '{"id":"first"}' },
                { index: 1, body: '{"id":"second"}' },
                { index: 2, body: '{"id":"third"}' },
            ],
        });
        assertEquals(
            getWrittenResources().map(({ specName, name, data }) => ({
                specName,
                name,
                data,
            })),
            [
                { specName: "selection", name: "selection", data: result },
            ],
        );
        await Deno.remove(`${directory}/inventory.json`);
        await assertRejects(
            () =>
                behavior.methods.select.execute({
                    intent: {
                        url: "https://t3.test",
                        labels: [],
                        links: [],
                        pairings: [],
                    },
                    directory,
                }, context),
            Error,
            "conn.revocation_unmatched",
        );
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
