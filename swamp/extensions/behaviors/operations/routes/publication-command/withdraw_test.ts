import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
    composeCaddy,
    composePhp,
    composeWithdrawal as compose,
    parseRead,
} from "./assets.ts";
import { mergeDns } from "../mutation-command/dns.ts";

const header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
const block = (name: string, target: string) =>
    `# orbit: routes ${name}\nhttps://${name}.test { reverse_proxy ${target} }\n`;
const config = (...blocks: string[]) =>
    header + (blocks.length ? "\n" + blocks.join("\n") : "");
const sha = async (content: string) =>
    Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
async function section(content: string, served: boolean) {
    return {
        content,
        version: (await sha(content)).slice(0, 32),
        listen_addresses: ["10.44.0.9"],
        unserved: !served,
        had_served_groups: served,
    };
}
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
};
const readPath =
    "/tmp/orbit-ansible-00000000-0000-0000-0000-000000000000/read.json";
/** The read text stands in for the file the phase writes; the rest are the step arguments. */
function composeWithdrawal(args: Record<string, unknown> & { read: string }) {
    const { read, ...rest } = args;
    return compose({ ...rest, read_path: readPath }, read);
}
const planPath =
    "/tmp/orbit-ansible-00000000-0000-0000-0000-000000000000/plan.json";
const pool = (id: number, n: number) =>
    `[orbit-instance-${id}]\nchdir = /__ORBIT_NODE_HOME__/app-${id}\npm.max_children = ${n}\n`;
const resolved = (text: string) =>
    text.replaceAll("/__ORBIT_NODE_HOME__", "/home/orbit");
const poolPath = "/etc/php/8.4/fpm/pool.d/orbit-scopes.conf";
const account = "orbit:x:1000:1000::/home/orbit:/bin/bash";
const b64 = (text: string) => btoa(text);
function read(
    fields: Partial<{
        account: string;
        pools: Record<string, string>;
        caddy: string;
        records: string;
        catalog: string;
    }> = {},
) {
    const value = {
        account: "",
        pools: {},
        caddy: "",
        records: "",
        catalog: "",
        ...fields,
    };
    const encoded = {
        account: value.account,
        pools: Object.fromEntries(
            Object.entries(value.pools).map(([k, v]) => [k, b64(v)]),
        ),
        caddy: b64(value.caddy),
        records: b64(value.records),
        catalog: b64(value.catalog),
    };
    return JSON.stringify(encoded);
}
const dnsState = async (names: string[]) => {
    const records = "# Managed by Orbit.\n" +
        names.map((name) => `host-record=${name},10.44.0.7\n`).join("");
    const catalog = JSON.stringify({
        records: Object.fromEntries(names.map((name) => [name, "10.44.0.7"])),
    }) + "\n";
    return {
        node_id: 1,
        records,
        catalog,
        digest: await sha(records + catalog),
    };
};

Deno.test("the read line decodes accounts, pools by version, and files", () => {
    const parsed = parseRead(
        read({
            account,
            pools: { [poolPath]: pool(1, 5), "/etc/php/x/other": "no" },
            caddy: config(),
        }),
    );
    assertEquals(parsed.account, account);
    assertEquals(parsed.pools, { "8.4": pool(1, 5) });
    assertEquals(parsed.caddy, config());
    assertThrows(() => parseRead("{}"));
});

Deno.test("a destroy withdrawal equals the Route deltas it replaces, and keeps unrelated live changes", async () => {
    const before = await section(
        config(block("gone", "a"), block("kept", "a")),
        true,
    );
    const after = await section(config(block("kept", "a")), true);
    const live = config(
        block("gone", "a"),
        block("kept", "a"),
        block("added", "z"),
    );
    const php = {
        before: {
            account_user: "orbit",
            configurations: { "8.4": pool(1, 5) + pool(2, 5) },
        },
        after: { account_user: "orbit", configurations: { "8.4": pool(2, 5) } },
    };
    const livePool = resolved(pool(1, 5) + pool(2, 5) + pool(3, 9));
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ account, pools: { [poolPath]: livePool }, caddy: live }),
        php,
        caddy: { before, prepared: after, withdrawn: after, after },
        dns: {},
        cleanup: [{ scope: "route-418-router", user: "orbit" }],
    });
    const expectedCaddy = await composeCaddy({
        ...placement,
        direction: "forward",
        before,
        after,
        current: live,
        alternatives: [before, after, after, after],
        retain_removed: false,
    });
    assertEquals(plan.caddy?.content, expectedCaddy.caddy.content);
    assertEquals(plan.caddy?.content.includes("added"), true);
    assertEquals(plan.caddy?.content.includes("gone"), false);
    const expectedPools = composePhp({
        ...placement,
        direction: "forward",
        ...php,
        current: JSON.stringify({ "8.4": livePool }),
        account: JSON.stringify({
            user: "orbit",
            group: "1000",
            home: "/home/orbit",
        }),
    }).files;
    assertEquals(plan.pools, expectedPools);
    assertEquals(plan.pools[0].content.includes("orbit-instance-3"), true);
    assertEquals(plan.pools[0].content.includes("orbit-instance-1]"), false);
    assertEquals(plan.dns, null);
    assertEquals(plan.cleanup.length, 1);
});

Deno.test("a pool file that empties is removed", async () => {
    const empty = { account_user: "orbit", configurations: [] };
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ account, pools: { [poolPath]: resolved(pool(1, 5)) } }),
        php: {
            before: {
                account_user: "orbit",
                configurations: { "8.4": pool(1, 5) },
            },
            after: empty,
        },
        caddy: {},
        dns: {},
        cleanup: [],
    });
    assertEquals(plan.pools.map((p) => [p.path, p.ensure]), [[
        poolPath,
        "absent",
    ]]);
});

Deno.test("a live revision that matches no captured state is refused", async () => {
    const before = await section(config(block("gone", "a")), true);
    const after = await section(config(), false);
    await assertRejects(
        () =>
            composeWithdrawal({
                ...placement,
                plan_path: planPath,
                read: read({ caddy: config(block("gone", "edited")) }),
                php: {},
                caddy: { before, prepared: after, withdrawn: after, after },
                dns: {},
                cleanup: [],
            }),
        Error,
        "caddy.owned_revision_conflict",
    );
    await assertRejects(
        () =>
            composeWithdrawal({
                ...placement,
                plan_path: planPath,
                read: read({
                    account,
                    pools: { [poolPath]: resolved(pool(1, 99)) },
                }),
                php: {
                    before: {
                        account_user: "orbit",
                        configurations: { "8.4": pool(1, 5) },
                    },
                    after: { account_user: "orbit", configurations: [] },
                },
                caddy: {},
                dns: {},
                cleanup: [],
            }),
        Error,
        "php.owned_revision_conflict",
    );
});

Deno.test("a Caddyfile that is missing while the captured state served routes is refused", async () => {
    const before = await section(config(block("gone", "a")), true);
    const after = await section(config(), false);
    await assertRejects(
        () =>
            composeWithdrawal({
                ...placement,
                plan_path: planPath,
                read: read({ caddy: "" }),
                php: {},
                caddy: { before, prepared: after, withdrawn: after, after },
                dns: {},
                cleanup: [],
            }),
        Error,
        "caddy.current_config_missing",
    );
});

Deno.test("later Caddy states apply in order only when they differ", async () => {
    const a = await section(config(block("one", "a")), true);
    const b = await section(config(block("one", "a"), block("two", "a")), true);
    const c = await section(config(block("two", "a")), true);
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ caddy: a.content }),
        php: {},
        caddy: { before: a, prepared: b, withdrawn: c, after: c },
        dns: {},
        cleanup: [],
    });
    assertEquals(plan.caddy?.content, c.content);
    assertEquals(plan.caddy?.version, c.version);
});

Deno.test("DNS records leave once and the catalog keeps unrelated names", async () => {
    const before = await dnsState(["gone.test", "kept.test"]);
    const after = await dnsState(["kept.test"]);
    const live = await dnsState(["gone.test", "kept.test", "added.test"]);
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ records: live.records, catalog: live.catalog }),
        php: {},
        caddy: {},
        dns: { before, prepared: after, withdrawn: after, after },
        cleanup: [],
    });
    assertEquals(
        plan.dns,
        mergeDns(before, after, live, [before, after, after, after]),
    );
    assertEquals(plan.dns?.records.includes("added.test"), true);
    assertEquals(plan.dns?.records.includes("gone.test"), false);
    assertEquals(
        JSON.parse(plan.dns!.catalog).records["kept.test"],
        "10.44.0.7",
    );
});
