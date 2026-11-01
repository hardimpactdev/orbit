import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { certificateActions } from "./actions.ts";

const scope = {
    node_id: 9,
    scope: "route-7-router",
    user: "orbit",
    agent_sha256: "a".repeat(64),
    in_use: false,
};
Deno.test("retained residue removes only the admitted scope and user", async () => {
    const calls: unknown[] = [];
    const actions = certificateActions((call) => {
        calls.push(call);
        return Promise.resolve({});
    });
    assertEquals(Object.keys(actions), ["remove"]);
    assertEquals(await actions.remove(scope), { applied: true });
    assertEquals(calls, [{
        name: "certificate.remove-leaf",
        input: { scope: scope.scope, home: undefined, user: "orbit" },
    }]);
});
Deno.test("retained removal refuses referenced scopes before effects and preserves native refusal", async () => {
    let calls = 0;
    const actions = certificateActions(() => {
        calls++;
        return Promise.resolve({ error_code: "certificate.account_invalid" });
    });
    assertEquals(await actions.remove({ ...scope, in_use: true }), {
        error_code: "app-dev.certificate_in_use",
    });
    assertEquals(calls, 0);
    await assertRejects(() => actions.remove({ ...scope, user: undefined }));
    assertEquals(calls, 0);
    assertEquals(await actions.remove(scope), {
        error_code: "certificate.account_invalid",
    });
    assertEquals(calls, 1);
});
