import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { planSync, resultSync } from "./mod.ts";
const intent = {
    url: "https://vault.example.test",
    client_id: "client",
    organization_id: "org",
    collection_id: "collection",
    ca_file: "/public/ca.pem",
    wanted: [{ source: "database-user:7", fingerprint: "new" }],
    ledger: [{
        source: "database-user:7",
        cipher_id: "published",
        revision_date: "old",
        fingerprint: "old",
    }],
};
const cipher = (
    id: string,
    source: string | null = "database-user:7",
    deleted = false,
) => ({
    id,
    source,
    organization_id: "org",
    collection_ids: ["collection"],
    revision: "old",
    deleted,
});
Deno.test("a missing recorded cipher creates a replacement and never adopts a marker copy", () => {
    const plan = planSync(intent, [cipher("user-copy")]);
    assertEquals(plan.writes.map((row) => row.cipher_id), [null]);
    assertEquals(plan.deletes, []);
});
Deno.test("a trashed recorded cipher alone is deleted, preserving a same-source user copy", () => {
    const plan = planSync(intent, [
        cipher("user-copy"),
        cipher("published", undefined, true),
    ]);
    assertEquals(plan.writes.map((row) => row.cipher_id), [null]);
    assertEquals(plan.deletes.map((row) => row.id), ["published"]);
});
Deno.test("a marker can be adopted only when the source has no ledger entry", () => {
    const plan = planSync({ ...intent, ledger: [] }, [
        cipher("adopt"),
        cipher("user-copy"),
    ]);
    assertEquals(plan.writes.map((row) => row.cipher_id), ["adopt"]);
    assertEquals(plan.deletes, []);
});
Deno.test("deletion uses the recorded cipher and source within the configured org and collection", () => {
    const plan = planSync({
        ...intent,
        ledger: [...intent.ledger, {
            source: "database-user:gone",
            cipher_id: "old",
            revision_date: "old",
            fingerprint: "old",
        }],
    }, [cipher("old", "database-user:gone"), {
        ...cipher("published", undefined, true),
        organization_id: "foreign",
    }]);
    assertEquals(plan.deletes.map((row) => row.id), ["old"]);
    assertEquals(plan.writes[0].cipher_id, null);
});
Deno.test("a shared recorded cipher refuses the entire plan before any mutations", () => {
    assertThrows(
        () =>
            planSync(intent, [{
                ...cipher("published", undefined, true),
                collection_ids: ["collection", "other"],
            }]),
        Error,
        "vault.item_shared",
    );
});
Deno.test("unchanged recorded revision and fingerprint skips writes", () => {
    const plan = planSync({
        ...intent,
        wanted: [{ source: "database-user:7", fingerprint: "old" }],
    }, [cipher("published")]);
    assertEquals(plan.writes, []);
    assertEquals(resultSync(plan, []).data.cipher_ids, ["published"]);
});
Deno.test("terminal results cannot omit, duplicate or invent a write result", () => {
    const plan = planSync(intent, []),
        row = {
            source: "database-user:7",
            cipher_id: "new",
            revision: "new",
            fingerprint: "new",
        };
    assertThrows(() => resultSync(plan, []), Error, "vault.sync_failed");
    assertThrows(
        () => resultSync(plan, [row, row]),
        Error,
        "vault.result_invalid",
    );
    assertEquals(resultSync(plan, [row]).data.cipher_ids, ["new"]);
});
Deno.test("duplicate admitted source identities are refused", () => {
    assertThrows(() =>
        planSync(
            { ...intent, wanted: [...intent.wanted, ...intent.wanted] },
            [],
        )
    );
});

Deno.test("public terminal ledger preserves the 256 KiB transport bound", () => {
    const rows = Array.from(
        { length: 4096 },
        (_, index) => ({
            source: "source:" + index,
            fingerprint: "f".repeat(64),
        }),
    );
    const plan = planSync({ ...intent, wanted: rows, ledger: [] }, []);
    assertThrows(
        () =>
            resultSync(
                plan,
                rows.map((row) => ({
                    ...row,
                    cipher_id: "c".repeat(64),
                    revision: "r".repeat(64),
                })),
            ),
        Error,
        "vault.result_invalid",
    );
});
