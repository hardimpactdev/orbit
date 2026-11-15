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

/** The composition that ran in Swamp before this change, step for step, from the same TypeScript merges. */
function reference(value: Plan, live: Live) {
    let caddy: string | null = null;
    if (value.caddy) {
        caddy = live.caddy;
        for (const step of value.caddy.steps) {
            caddy = mergeCaddy(value.caddy.chain[step.from], value.caddy.chain[step.to], caddy, value.caddy.chain, false, step.had_served_groups);
        }
    }
    const pools: Record<string, string> = {};
    if (value.php) {
        const resolve = (text: string) =>
            text.replaceAll("__ORBIT_NODE_GROUP__", String(ACCOUNT.pw_gid)).replaceAll("/__ORBIT_NODE_HOME__", ACCOUNT.pw_dir);
        for (const version of value.php.versions) {
            const previous = resolve(value.php.before[version] ?? ""), desired = resolve(value.php.after[version] ?? "");
            if (previous !== desired) pools[version] = mergePhp(previous, desired, live.pools[version] ?? "");
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
async function node(value: Plan, live: Live) {
    const script = `
import importlib.util, json, sys, types
spec = importlib.util.spec_from_file_location("w", sys.argv[1]); w = importlib.util.module_from_spec(spec); spec.loader.exec_module(w)
data = json.load(sys.stdin); account = types.SimpleNamespace(**data["account"])
try:
    out = w.compose(data["plan"], data["live"], account); w.check_untouched(data["plan"], data["live"], out, account)
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
    await writer.write(new TextEncoder().encode(JSON.stringify({ plan: value, live, account: ACCOUNT })));
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
