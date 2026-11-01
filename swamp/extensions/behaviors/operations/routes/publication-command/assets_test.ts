import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { composeCaddy, composePhp, planPhp } from "./assets.ts";
import { mergeCaddy, mergePhp } from "./sections.ts";
const header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
const block = (name: string, target: string) =>
    `# orbit: routes ${name}\nhttps://${name}.test { reverse_proxy ${target} }\n`;
const config = (...blocks: string[]) =>
    header + (blocks.length ? "\n" + blocks.join("\n") : "");
const placement = {
    node_id: 9,
    user: "orbit",
    control_node_id: 209,
    host_keys: ["ssh-ed25519 synthetic-public-fixture"],
    connection: {
        nodeHost: "10.44.0.9",
        nodePort: 22,
        nodeUser: "orbit",
        nodeIdentityFile: "/existing/key",
        become: true,
    },
    direction: "forward",
};
const pool = (id: number, n: number) =>
    `[orbit-instance-${id}]\nchdir = /__ORBIT_NODE_HOME__/app-${id}\npm.max_children = ${n}\n`;
const php = (text: string) => ({
    account_user: "orbit",
    configurations: { "8.4": text },
});
const account = JSON.stringify({
    user: "orbit",
    home: "/home/orbit",
    group: "orbit",
});
async function caddy(content: string) {
    return {
        content,
        version: Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(content),
                ),
            ),
            (b) => b.toString(16).padStart(2, "0"),
        ).join("").slice(0, 32),
        listen_addresses: ["10.44.0.9"],
        unserved: false,
    };
}
Deno.test("Caddy forward, inverse and repeated composition preserve observed unrelated revisions", () => {
    const before = config(block("moving", "a"), block("survivor", "a"));
    const after = config(block("moving", "b"), block("survivor", "a"));
    const current = config(
        block("moving", "a"),
        block("survivor", "c"),
        block("added", "d"),
    );
    const expected = config(
        block("moving", "b"),
        block("survivor", "c"),
        block("added", "d"),
    );
    assertEquals(mergeCaddy(before, after, current), expected);
    assertEquals(mergeCaddy(after, before, expected), current);
    assertEquals(mergeCaddy(before, after, expected), expected);
});
Deno.test("Caddy retains repeated same-scope blocks and unrelated deletions", () => {
    const a = block("moving", "a"),
        b = block("moving", "b"),
        same = block("moving", "same"),
        deleted = block("deleted", "a");
    assertEquals(
        mergeCaddy(
            config(a, same, deleted),
            config(b, same, deleted),
            config(a, same),
        ),
        config(b, same),
    );
});
Deno.test("Caddy owned conflict, global conflict and foreign input refuse", () => {
    assertThrows(
        () =>
            mergeCaddy(
                config(block("moving", "a")),
                config(block("moving", "b")),
                config(block("moving", "c")),
            ),
        Error,
        "owned_revision_conflict",
    );
    assertThrows(() => mergeCaddy(config(), config(), "foreign"));
    assertThrows(() =>
        mergeCaddy(
            config(),
            config(),
            header.replace("admin off", "admin localhost:2019"),
        )
    );
    assertThrows(
        () => mergeCaddy(config(block("moving", "a")), config(), ""),
        Error,
        "current_config_missing",
    );
});
Deno.test("Caddy metadata preserves the canonical connection without encoding or payload files", async () => {
    const before = await caddy(config()),
        after = await caddy(config(block("moving", "a")));
    const result = await composeCaddy({
        ...placement,
        before: { ...before, blocks: [] },
        after,
        current: "",
    });
    assertEquals(result.caddy.content, after.content);
    assertEquals(result.connection, placement.connection);
    assertEquals(
        mergeCaddy(after.content, before.content, after.content),
        before.content,
    );
    await assertRejects(() =>
        composeCaddy({
            ...placement,
            before,
            after: { ...after, version: "a".repeat(32) },
            current: "",
        })
    );
    for (const node_id of [1, 18]) {
        assertEquals(
            (await composeCaddy({
                ...placement,
                node_id,
                before,
                after,
                current: "",
            })).control_node_id,
            209,
        );
    }
});
Deno.test("PHP forward, inverse and repeated composition keep local prefix and unrelated pools", () => {
    const a = pool(1, 5),
        b = pool(1, 7),
        old = pool(2, 3),
        revised = pool(2, 11),
        addition = pool(3, 13);
    const current = "; local tuning\n" + a + revised + addition,
        expected = "; local tuning\n" + b + revised + addition;
    assertEquals(mergePhp(a + old, b + old, current), expected);
    assertEquals(mergePhp(b + old, a + old, expected), current);
    assertEquals(mergePhp(a + old, b + old, expected), expected);
    assertEquals(mergePhp(a + old, b + old, a), b);
    assertEquals(mergePhp(a, "", "; header\n" + a), "");
});
Deno.test("PHP rejects conflicting and malformed owned pools", () => {
    assertThrows(
        () => mergePhp(pool(1, 1), pool(1, 2), pool(1, 3)),
        Error,
        "owned_revision_conflict",
    );
    for (
        const current of [
            "[foreign]\na=1\n",
            pool(1, 5).repeat(2),
            "key=value\n",
            "[orbit-instance-1]\nchdir = relative\n",
            "[orbit-instance-1]\nchdir = /a\nchdir = /b\n",
        ]
    ) {
        assertThrows(
            () => mergePhp("", "", current),
            Error,
            "shared_pool_invalid",
        );
    }
});
Deno.test("PHP metadata resolves canonical account placeholders from the Node observation", () => {
    const before = php(pool(1, 5)), after = php(pool(1, 7));
    const result = composePhp({
        ...placement,
        before,
        after,
        account,
        current: JSON.stringify({
            "8.4": pool(1, 5).replaceAll("/__ORBIT_NODE_HOME__", "/home/orbit"),
        }),
    });
    assertEquals(result.files, [{
        version: "8.4",
        path: "/etc/php/8.4/fpm/pool.d/orbit-scopes.conf",
        content: pool(1, 7).replaceAll("/__ORBIT_NODE_HOME__", "/home/orbit"),
        ensure: "present",
    }]);
    const withdrawn = composePhp({
        ...placement,
        before,
        after: php(""),
        account,
        current: JSON.stringify({
            "8.4": result.files[0].content.replace("= 7", "= 5"),
        }),
    });
    assertEquals(withdrawn.files[0].ensure, "absent");
});
Deno.test("PHP account and version-move policy are checked before any file operation", () => {
    const before = php(pool(1, 5)), after = php(pool(1, 7));
    assertEquals(planPhp({ ...placement, before, after }).versions, ["8.4"]);
    assertThrows(
        () =>
            planPhp({
                ...placement,
                before,
                after: { ...after, account_user: "other" },
            }),
        Error,
        "account_conflict",
    );
    assertThrows(
        () =>
            planPhp({
                ...placement,
                before,
                after: {
                    account_user: "orbit",
                    configurations: { "8.5": pool(1, 5) },
                },
            }),
        Error,
        "version_move_requires_withdrawal",
    );
    assertThrows(
        () =>
            composePhp({
                ...placement,
                before,
                after,
                account: JSON.stringify({
                    user: "other",
                    home: "/home/other",
                    group: "other",
                }),
                current: "{}",
            }),
        Error,
        "account_conflict",
    );
});

Deno.test("empty enrollment PHP snapshots need no account migration or pool effects", () => {
    for (const direction of ["forward", "restore"] as const) {
        assertEquals(planPhp({
            ...placement,
            direction,
            user: direction === "forward" ? "newuser" : "orbit",
            before: { account_user: "orbit", configurations: {} },
            after: { account_user: "newuser", configurations: {} },
        }), { versions: [], versions_json: "[]" });
    }
});
