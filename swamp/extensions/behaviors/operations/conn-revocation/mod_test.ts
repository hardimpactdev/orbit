import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { authorizeSession, selectSessions, terminalResult } from "./mod.ts";

const labels = ["phone"];
const sessions = JSON.stringify([
    { sessionId: "owned", client: { label: "phone" } },
    { sessionId: "self", current: true, client: { label: "phone" } },
    { sessionId: "foreign", client: { label: "other" } },
    { sessionId: "unlabelled", client: null },
]);
Deno.test("only admitted non-current sessions are selected", () => {
    assertEquals(selectSessions(sessions, labels), ["owned"]);
    assertEquals(authorizeSession(sessions, "gone", labels).absent, true);
    assertEquals(JSON.parse(authorizeSession(sessions, "owned", labels).body), { sessionId: "owned" });
});
Deno.test("a current or changed owner refuses before revocation", () => {
    for (const id of ["self", "foreign", "unlabelled"]) {
        assertThrows(() => authorizeSession(sessions, id, labels), Error, "conn.session_owner_changed");
    }
});
Deno.test("malformed or duplicate observation is never an empty list", () => {
    for (
        const value of [
            "not json",
            "{}",
            '[{"current":false}]',
            '[{"sessionId":"bad id"}]',
            '[{"sessionId":"x","current":"yes"}]',
            JSON.stringify([{ sessionId: "x" }, { sessionId: "x" }]),
        ]
    ) assertThrows(() => selectSessions(value, labels));
});
Deno.test("public terminal sums mutations, checks link replies and keeps native failure generic", () => {
    assertEquals(JSON.parse(terminalResult(true, ['{"revoked_sessions":1}', '{"revoked_sessions":0}']).stdout), {
        backend: "revoked",
        revoked_sessions: 1,
    });
    assertEquals(JSON.parse(terminalResult(true, ['{"revoked":true}'], true).stdout), { revoked_sessions: 1 });
    assertEquals(JSON.parse(terminalResult(true, [], true).stdout), { revoked_sessions: 0 });
    assertThrows(() => terminalResult(true, ['{"revoked":"yes"}'], true));
    assertThrows(() => terminalResult(true, [], false, ["<html>"]));
    assertEquals(JSON.parse(terminalResult(false, []).stdout), { error_code: "conn.revocation_failed" });
});
