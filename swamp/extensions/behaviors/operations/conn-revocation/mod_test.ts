import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { authorizeSession, selectSessions, terminalResult } from "./mod.ts";

const sessions = JSON.stringify([
    { session_id: "owned", current: false, owned: true },
    { session_id: "self", current: true, owned: true },
    { session_id: "foreign", current: false, owned: false },
]);
Deno.test("only admitted non-current sessions are selected", () => {
    assertEquals(selectSessions(sessions), ["owned"]);
    assertEquals(authorizeSession(sessions, "gone").absent, true);
    assertEquals(JSON.parse(authorizeSession(sessions, "owned").input_json), {
        type: "session",
        id: "owned",
    });
});
Deno.test("a current or changed owner refuses before revocation", () => {
    for (const id of ["self", "foreign"]) {
        assertThrows(
            () => authorizeSession(sessions, id),
            Error,
            "conn.session_owner_changed",
        );
    }
});
Deno.test("malformed or duplicate observation is never an empty list", () => {
    for (
        const value of [
            "not json",
            "{}",
            '[{"session_id":"x"}]',
            JSON.stringify([
                { session_id: "x", current: false, owned: true },
                { session_id: "x", current: false, owned: true },
            ]),
        ]
    ) assertThrows(() => selectSessions(value));
});
Deno.test("public terminal sums mutations and keeps native failure generic", () => {
    assertEquals(
        JSON.parse(
            terminalResult(true, [
                '{"revoked_sessions":1}',
                '{"revoked_sessions":0}',
            ]).stdout,
        ),
        { backend: "revoked", revoked_sessions: 1 },
    );
    assertEquals(JSON.parse(terminalResult(false, []).stdout), {
        error_code: "conn.revocation_failed",
    });
    assertEquals(JSON.parse(terminalResult(true, [], true).stdout), {
        revoked_sessions: 0,
    });
});
