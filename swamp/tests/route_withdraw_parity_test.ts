// The Node-local withdrawal (ansible/module_utils/orbit_route_withdraw.py) merges as the Swamp composition did (sections.ts, dns.ts),
// on the captured states of fifteen real destroys of 14 November 2026, with a production site beside the withdrawn one.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { planWithdrawal } from "../extensions/behaviors/operations/routes/publication-command/assets.ts";
import { mergeCaddy, mergePhp } from "../extensions/behaviors/operations/routes/publication-command/sections.ts";
import { mergeDns } from "../extensions/behaviors/operations/routes/mutation-command/dns.ts";

const ROOT = new URL("../", import.meta.url).pathname;
type Dns = { records: string; catalog: string };
type Plan = Awaited<ReturnType<typeof planWithdrawal>>;
type Live = { caddy: string; pools: Record<string, string>; dns: Dns | null };
const recorded: {
    operation_id: number;
    user: string;
    php: Record<string, unknown>;
    caddy: Record<string, unknown>;
    dns: Record<string, unknown>;
    cleanup: unknown[];
}[] = JSON.parse(
    await Deno.readTextFile(`${ROOT}tests/fixtures/route-withdraw/recorded-destroys.json`),
);
const PRODUCTION = "# orbit: app-prod route-501\nhttps://shop.example {\n    reverse_proxy 10.44.0.12:443\n}\n";
const ACCOUNT = { pw_gid: 1000, pw_dir: "/home/orbit" };

const plan = (row: (typeof recorded)[number]) =>
    planWithdrawal({
        node_id: 9,
        user: row.user,
        control_node_id: 209,
        host_keys: ["ssh-ed25519 fixture"],
        connection: { nodeHost: "10.44.0.7", nodePort: 22, nodeUser: row.user, nodeIdentityFile: "/existing/key", become: true },
        plan_path: "/tmp/orbit-ansible-00000000-0000-0000-0000-000000000000/plan.json",
        php: row.php,
        caddy: row.caddy,
        dns: row.dns,
        cleanup: row.cleanup,
    });

/** The composition that ran in Swamp before this change, step for step, from the same TypeScript merges. `group` is the name of the
 * account's primary group, as composePhp takes it. */
function reference(value: Plan, live: Live, group: string | null = null) {
    let caddy: string | null = null;
    if (value.caddy) {
        caddy = live.caddy;
        for (const step of value.caddy.steps) {
            caddy = mergeCaddy(value.caddy.chain[step.from], value.caddy.chain[step.to], caddy, value.caddy.chain, false, step.had_served_groups);
        }
    }
    const pools: Record<string, string> = {};
    if (value.php) {
        const resolve = (text: string, name = String(ACCOUNT.pw_gid)) =>
            text.replaceAll("__ORBIT_NODE_GROUP__", name).replaceAll("/__ORBIT_NODE_HOME__", ACCOUNT.pw_dir);
        for (const version of value.php.versions) {
            const states = [value.php.before[version] ?? "", value.php.after[version] ?? ""];
            const [previous, desired] = states.map((text) => resolve(text));
            const named = group ? states.map((text) => resolve(text, group)) : [];
            if (previous !== desired) pools[version] = mergePhp(previous, desired, live.pools[version] ?? "", named);
        }
    }
    let dns: Dns | null = null;
    if (value.dns) {
        dns = live.dns!;
        for (const step of value.dns.steps) dns = mergeDns(value.dns.chain[step], value.dns.chain[step + 1], dns, value.dns.chain);
    }
    return { caddy, pools, dns };
}

/** The Python merge and its byte-compare check, on the same plan and live files. */
async function node(value: Plan, live: Live, group: string | null = null) {
    const script = `
import importlib.util, json, sys, types
spec = importlib.util.spec_from_file_location("w", sys.argv[1]); w = importlib.util.module_from_spec(spec); spec.loader.exec_module(w)
data = json.load(sys.stdin); account = types.SimpleNamespace(**data["account"])
try:
    out = w.compose(data["plan"], data["live"], account, data["group"]); w.check_untouched(data["plan"], data["live"], out, account)
except w.WithdrawFailure as error:
    out = {"error": error.code}
print(json.dumps(out))`;
    const child = new Deno.Command("python3", {
        args: ["-I", "-c", script, `${ROOT}ansible/module_utils/orbit_route_withdraw.py`],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({ plan: value, live, account: ACCOUNT, group })));
    await writer.close();
    const done = await child.output();
    assertEquals(new TextDecoder().decode(done.stderr), "");
    return JSON.parse(new TextDecoder().decode(done.stdout));
}

/** What the Node held: the Route as captured, with a production site, record and catalog entry beside it. */
function liveOf(value: Plan): Live {
    const dns = value.dns
        ? {
            records: value.dns.chain[0].records.replace(/\n*$/, "\n") + "host-record=shop.example,10.44.0.12\n",
            catalog: JSON.stringify({
                ...JSON.parse(value.dns.chain[0].catalog),
                records: { ...JSON.parse(value.dns.chain[0].catalog).records, "shop.example": "10.44.0.12" },
            }) + "\n",
        }
        : null;
    return { caddy: value.caddy ? value.caddy.chain[0] + "\n" + PRODUCTION : "", pools: {}, dns };
}

Deno.test("the Node merge equals the Swamp composition on fifteen recorded destroys and keeps the production site", async () => {
    assert(recorded.length >= 10);
    for (const row of recorded) {
        const value = await plan(row);
        const live = liveOf(value);
        const expected = reference(value, live);
        assertEquals(await node(value, live), expected, `Operation ${row.operation_id}`);
        if (expected.caddy !== null) assert(expected.caddy.endsWith(PRODUCTION), `Operation ${row.operation_id}`);
        if (expected.dns) {
            assert(expected.dns.records.includes("host-record=shop.example,10.44.0.12\n"));
            assertEquals(JSON.parse(expected.dns.catalog).records["shop.example"], "10.44.0.12");
        }
    }
});

Deno.test("two recorded withdrawals on one Node give the same file in either order, with only the production site left", async () => {
    const rows = recorded.filter((row) => "before" in row.caddy);
    const [a, b] = [await plan(rows[0]), await plan(rows.find((row) => (row.caddy as { before: { content: string } }).before.content !== (rows[0].caddy as { before: { content: string } }).before.content)!)];
    const header = a.caddy!.chain[3];
    assertEquals(b.caddy!.chain[3], header);
    const blocks = (value: Plan) => value.caddy!.chain[0].slice(header.length).replace(/^\n+/, "");
    const live = header + "\n" + blocks(a) + "\n" + blocks(b) + "\n" + PRODUCTION;
    const first = await node(a, { caddy: live, pools: {}, dns: null });
    const ab = await node(b, { caddy: first.caddy, pools: {}, dns: null });
    const second = await node(b, { caddy: live, pools: {}, dns: null });
    const ba = await node(a, { caddy: second.caddy, pools: {}, dns: null });
    assertEquals(ab.caddy, ba.caddy);
    assertEquals(ab.caddy, header + "\n" + PRODUCTION);
    assertEquals(ab.caddy, reference(b, { caddy: reference(a, { caddy: live, pools: {}, dns: null }).caddy!, pools: {}, dns: null }).caddy);
});

Deno.test("a pool withdrawal merges as the Swamp composition did and keeps the other scopes", async () => {
    const scope = (id: number) => `[orbit-app-instance-${id}]\nuser = orbit\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/i${id}\n`;
    const resolved = (text: string) => text.replaceAll("__ORBIT_NODE_GROUP__", "1000").replaceAll("/__ORBIT_NODE_HOME__", "/home/orbit");
    const value = await plan({
        operation_id: 0,
        user: "orbit",
        php: { before: { account_user: "orbit", configurations: { "8.4": scope(7) } }, after: { account_user: "orbit", configurations: [] } },
        caddy: {},
        dns: {},
        cleanup: [],
    });
    const live = { caddy: "", pools: { "8.4": "; Managed by Orbit\n" + resolved(scope(7) + scope(9)) }, dns: null };
    const expected = reference(value, live);
    assertEquals(expected.pools["8.4"], "; Managed by Orbit\n" + resolved(scope(9)));
    assertEquals(await node(value, live), expected);
});

Deno.test("a scope written with the group name withdraws as the Swamp composition does, and a scope that stays keeps its bytes", async () => {
    const scope = (id: number) => `[orbit-app-instance-${id}]\nuser = orbit\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/i${id}\n`;
    const as = (text: string, group: string) => text.replaceAll("__ORBIT_NODE_GROUP__", group).replaceAll("/__ORBIT_NODE_HOME__", "/home/orbit");
    const value = await plan({
        operation_id: 0,
        user: "orbit",
        php: {
            before: { account_user: "orbit", configurations: { "8.4": scope(7) + scope(9) } },
            after: { account_user: "orbit", configurations: { "8.4": scope(9) } },
        },
        caddy: {},
        dns: {},
        cleanup: [],
    });
    const cases: [string, string | null, string | { error: string }][] = [
        [as(scope(7) + scope(9), "orbit"), "orbit", as(scope(9), "orbit")],
        [as(scope(7), "1000") + as(scope(9), "orbit"), "orbit", as(scope(9), "orbit")],
        [as(scope(7), "orbit") + as(scope(9), "orbit"), null, { error: "php.owned_revision_conflict" }],
        [as(scope(7), "staff") + as(scope(9), "orbit"), "orbit", { error: "php.owned_revision_conflict" }],
        [as(scope(7), "orbit").replace("user = orbit", "user = www-data"), "orbit", { error: "php.owned_revision_conflict" }],
    ];
    for (const [pool, group, result] of cases) {
        const live = { caddy: "", pools: { "8.4": "; Managed by Orbit\n" + pool }, dns: null };
        let expected: unknown;
        try {
            expected = reference(value, live, group);
        } catch (error) {
            expected = { error: (error as Error).message };
        }
        const want = typeof result === "string" ? { caddy: null, pools: { "8.4": "; Managed by Orbit\n" + result }, dns: null } : result;
        assertEquals(expected, want, pool);
        assertEquals(await node(value, live, group), want, pool);
    }
});

/** A small seeded generator, so a failing case can be replayed. */
function random(seed: number) {
    return () => {
        seed = (seed * 1103515245 + 12345) % 2147483648;
        return seed / 2147483648;
    };
}

Deno.test("the Node merge equals the Swamp merge on 300 random Caddy, PHP and DNS states, refusals included", async () => {
    const next = random(20261114);
    const pick = <T>(items: T[]) => items[Math.floor(next() * items.length)];
    const subset = <T>(items: T[]) => items.filter(() => next() < 0.5);
    const header = (variant: string) => `# Managed by Orbit: Node Caddy build\n{\n    admin off\n    ${variant}\n}\n`;
    const caddyBlock = (key: string, variant: string) =>
        `# orbit: app-dev ${key}\nhttps://${key}.test {\n    reverse_proxy 10.253.0.9:${variant}\n}\n`;
    const caddyState = (headerVariant: string, keys: string[]) =>
        header(headerVariant) + (keys.length ? "\n" + keys.map((key) => caddyBlock(key, pick(["8080", "9090"]))).join("\n") : "");
    const scope = (id: number, variant: string) =>
        `[orbit-app-instance-${id}]\nuser = orbit\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/i${id}\npm.max_children = ${variant}\n`;
    const poolState = (ids: number[]) => ids.map((id) => scope(id, pick(["4", "5"]))).join("");
    const dnsState = (names: string[]) => ({
        records: "# Managed by Orbit.\n" + names.map((name) => `host-record=${name},${pick(["10.44.0.7", "10.44.0.8"])}`).join("\n") + "\n",
        catalog: JSON.stringify({
            records: Object.fromEntries(names.map((name) => [name, pick(["10.44.0.7", "10.44.0.8"])])),
            overrides: Object.fromEntries(subset(["node:9", "node:12"]).map((node) => [node, Object.fromEntries(subset(names).map((name) => [name, "192.168.6.20"]))])),
            requesters: Object.fromEntries(subset(["10.44.0.40", "10.44.0.41"]).map((address) => [address, pick([9, 12, null])])),
            ...(next() < 0.3 ? { empty: [] } : {}),
        }) + "\n",
    });
    const cases: { plan: Plan; live: Live; group: string | null }[] = [];
    for (let i = 0; i < 300; i++) {
        const keys = ["a", "b", "c", "d"], ids = [1, 2, 3, 4], names = ["a.test", "b.test", "c.test"];
        const headers = ["metrics", next() < 0.9 ? "metrics" : "debug"];
        const chain = [0, 1, 2, 3].map(() => caddyState(pick(headers), subset(keys)));
        const dnsChain = [0, 1, 2, 3].map(() => dnsState(subset(names)));
        cases.push({
            plan: {
                user: "orbit",
                caddy: {
                    chain,
                    steps: [0, 1, 2].filter((s) => s === 0 || chain[s] !== chain[s + 1]).map((s) => ({ from: s, to: s + 1, had_served_groups: next() < 0.5 })),
                    version: "v",
                },
                php: { versions: ["8.4"], before: { "8.4": poolState(subset(ids)) }, after: next() < 0.5 ? {} : { "8.4": poolState(subset(ids)) } },
                dns: { chain: dnsChain, steps: [0, 1, 2].filter((s) => s === 0 || JSON.stringify(dnsChain[s]) !== JSON.stringify(dnsChain[s + 1])) },
                cleanup: [],
            },
            live: {
                caddy: next() < 0.1 ? "" : caddyState(pick(headers), subset([...keys, "prod"])),
                pools: next() < 0.1 ? {} : {
                    "8.4": "; Managed by Orbit\n" + subset([...ids, 9]).map((id) =>
                        scope(id, pick(["4", "5"])).replace("__ORBIT_NODE_GROUP__", pick([String(ACCOUNT.pw_gid), "orbit"]))
                    ).join("").replaceAll("/__ORBIT_NODE_HOME__", ACCOUNT.pw_dir),
                },
                dns: dnsState(subset([...names, "shop.example"])),
            },
            group: next() < 0.5 ? "orbit" : null,
        });
    }
    const outcome = (fn: () => unknown) => {
        try {
            return fn();
        } catch (error) {
            return { error: (error as Error).message };
        }
    };
    const expected = cases.map(({ plan, live, group }) => outcome(() => reference(plan, live, group)));
    const script = `
import importlib.util, json, sys, types
spec = importlib.util.spec_from_file_location("w", sys.argv[1]); w = importlib.util.module_from_spec(spec); spec.loader.exec_module(w)
data = json.load(sys.stdin); account = types.SimpleNamespace(**data["account"]); out = []
for case in data["cases"]:
    try:
        out.append(w.compose(case["plan"], case["live"], account, case["group"]))
    except w.WithdrawFailure as error:
        out.append({"error": error.code})
print(json.dumps(out))`;
    const child = new Deno.Command("python3", {
        args: ["-I", "-c", script, `${ROOT}ansible/module_utils/orbit_route_withdraw.py`],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({ cases, account: ACCOUNT })));
    await writer.close();
    const done = await child.output();
    assertEquals(new TextDecoder().decode(done.stderr), "");
    const actual = JSON.parse(new TextDecoder().decode(done.stdout));
    // Against a live Caddyfile the Node goes further than the Swamp composition did: it removes the Route's own groups in any form and
    // keeps global options that a step does not change as the Node holds them. There the reference refused with a Caddy revision
    // conflict, or wrote the captured options back.
    for (let i = 0; i < cases.length; i++) {
        const want = expected[i] as { error?: string; pools?: unknown; dns?: unknown }, got = actual[i] as typeof want;
        if (JSON.stringify(got) === JSON.stringify(want)) continue;
        if (want.error === undefined) {
            assertEquals([got.pools, got.dns], [want.pools, want.dns], `case ${i}`);
        } else {
            assert(/^caddy\.(owned|global)_revision_conflict$/.test(want.error), `case ${i}: ${want.error}`);
        }
    }
    // The generator reaches both outcomes: merges that land and revision refusals.
    assert(expected.filter((value) => "error" in (value as object)).length > 20);
    assert(expected.filter((value) => !("error" in (value as object))).length > 20);
});
