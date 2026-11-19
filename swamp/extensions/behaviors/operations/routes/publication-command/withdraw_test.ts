import {
    assertEquals,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import {
    resolveWorkflowTarget,
    ROOT,
} from "../../../../../bin/gate-test-support.ts";
import {
    behavior,
    composeCaddy,
    composePhp,
    planWithdrawal,
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
/**
 * The withdrawal as it now runs: the Swamp plan, then the Node merge of ansible/module_utils/orbit_route_withdraw.py against what the
 * read text says the Node holds, with its byte-compare check. The result keeps the shape of the former Swamp composition.
 */
async function composeWithdrawal(args: Record<string, unknown> & { read: string }) {
    const { read: text, ...rest } = args;
    const plan = await planWithdrawal(rest);
    const read = JSON.parse(text) as { account: string; pools: Record<string, string>; caddy: string; records: string; catalog: string };
    const decode = (value: string) => new TextDecoder().decode(Uint8Array.from(atob(value), (c) => c.charCodeAt(0)));
    const pools = Object.fromEntries(
        Object.entries(read.pools).flatMap(([path, content]) => {
            const version = /^\/etc\/php\/([1-9][0-9]*\.[0-9]+)\/fpm\/pool\.d\/orbit-scopes\.conf$/.exec(path)?.[1];
            return version ? [[version, decode(content)]] : [];
        }),
    );
    const fields = read.account.split(":");
    const live = {
        caddy: decode(read.caddy),
        pools,
        dns: plan.dns ? { records: decode(read.records), catalog: decode(read.catalog) } : null,
    };
    const script = `
import importlib.util, json, sys, types
spec = importlib.util.spec_from_file_location("w", sys.argv[1]); w = importlib.util.module_from_spec(spec); spec.loader.exec_module(w)
data = json.load(sys.stdin); account = types.SimpleNamespace(**data["account"])
try:
    out = w.compose(data["plan"], data["live"], account); w.check_untouched(data["plan"], data["live"], out, account)
except w.WithdrawFailure as error:
    out = {"error": str(error)}
print(json.dumps(out))`;
    const child = new Deno.Command("python3", {
        args: ["-I", "-c", script, `${ROOT}/ansible/module_utils/orbit_route_withdraw.py`],
        stdin: "piped",
        stdout: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({
        plan,
        live,
        account: { pw_gid: Number(fields[3] ?? 0), pw_dir: fields[5] ?? "/" },
    })));
    await writer.close();
    const out = JSON.parse(new TextDecoder().decode((await child.output()).stdout));
    if (out.error) throw new Error(out.error);
    return {
        pools: Object.entries(out.pools as Record<string, string>).map(([version, content]) => ({
            version,
            path: `/etc/php/${version}/fpm/pool.d/orbit-scopes.conf`,
            content,
            ensure: content ? "present" as const : "absent" as const,
        })),
        caddy: out.caddy === null ? null : { content: out.caddy as string },
        dns: out.dns as { records: string; catalog: string } | null,
        cleanup: plan.cleanup,
    };
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
            group_name: null,
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

Deno.test("an own site in a form no captured state has is withdrawn; a pool scope in another form is refused", async () => {
    const before = await section(config(block("gone", "a")), true);
    const after = await section(config(), false);
    // An older Orbit, or a Node converge since the capture, wrote the Route's site group another way: it is still the Route's own.
    await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ caddy: config(block("gone", "edited")) }),
        php: {},
        caddy: { before, prepared: after, withdrawn: after, after },
        dns: {},
        cleanup: [],
    });
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

Deno.test("four distinct Caddy states apply in order against the live file", async () => {
    const a = await section(config(block("one", "a")), true);
    const b = await section(config(block("one", "a"), block("two", "a")), true);
    const c = await section(config(block("two", "a")), true);
    const d = await section(config(), false);
    const live = config(block("one", "a"), block("extra", "z"));
    // A withdrawal only removes: a last state that adds or edits a section is refused on the Node, before any file changes.
    const adds = await section(config(block("two", "a"), block("three", "a")), true);
    await assertRejects(
        () => composeWithdrawal({
            ...placement,
            plan_path: planPath,
            read: read({ caddy: live }),
            php: {},
            caddy: { before: a, prepared: b, withdrawn: c, after: adds },
            dns: {},
            cleanup: [],
        }),
        Error,
        "route.withdraw_foreign_change",
    );
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ caddy: live }),
        php: {},
        caddy: { before: a, prepared: b, withdrawn: c, after: d },
        dns: {},
        cleanup: [],
    });
    const chain = [a, b, c, d];
    let content = live;
    for (let i = 0; i < 3; i++) {
        content = (await composeCaddy({
            ...placement,
            direction: "forward",
            before: chain[i],
            after: chain[i + 1],
            current: content,
            alternatives: chain,
            retain_removed: false,
        })).caddy.content;
    }
    assertEquals(plan.caddy?.content, content);
    assertEquals(content, config(block("extra", "z")));
});

Deno.test("four distinct DNS states apply in order, and a step that changes only the records is not skipped", async () => {
    const a = await dnsState(["x.test"]);
    const b = await dnsState(["x.test", "y.test"]);
    const c = { ...(await dnsState(["y.test"])), catalog: b.catalog };
    const d = await dnsState([]);
    const live = await dnsState(["x.test", "extra.test"]);
    const adds = await dnsState(["y.test", "z.test"]);
    await assertRejects(
        () => composeWithdrawal({
            ...placement,
            plan_path: planPath,
            read: read({ records: live.records, catalog: live.catalog }),
            php: {},
            caddy: {},
            dns: { before: a, prepared: b, withdrawn: c, after: adds },
            cleanup: [],
        }),
        Error,
        "route.withdraw_foreign_change",
    );
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({ records: live.records, catalog: live.catalog }),
        php: {},
        caddy: {},
        dns: { before: a, prepared: b, withdrawn: c, after: d },
        cleanup: [],
    });
    const chain = [a, b, c, d];
    let current: { records: string; catalog: string } = live;
    for (let i = 0; i < 3; i++) {
        current = mergeDns(chain[i], chain[i + 1], current, chain);
    }
    assertEquals(plan.dns, current);
    assertEquals(plan.dns?.records.includes("extra.test"), true);
    assertEquals(plan.dns?.records.includes("x.test"), false);
});

Deno.test("the plan carries the captured states and runs each distinct Caddy and DNS step once", async () => {
    const before = await section(config(block("gone", "a")), true);
    const after = await section(config(), false);
    const dns = await dnsState(["gone.test"]);
    const plan = await planWithdrawal({
        ...placement,
        plan_path: planPath,
        php: {
            before: { account_user: "orbit", configurations: { "8.4": pool(1, 5) } },
            after: { account_user: "orbit", configurations: [] },
        },
        caddy: { before, prepared: after, withdrawn: after, after },
        dns: { before: dns, prepared: dns, withdrawn: dns, after: dns },
        cleanup: [],
    });
    assertEquals(plan.caddy?.steps, [{ from: 0, to: 1, had_served_groups: true }]);
    assertEquals(plan.caddy?.chain, [before.content, after.content, after.content, after.content]);
    assertEquals(plan.dns?.steps, [0]);
    assertEquals(plan.php, { versions: ["8.4"], before: { "8.4": pool(1, 5) }, after: {} });
    // A captured state that is not a Node Caddy build, or whose version is not its digest, is refused before any Node is touched.
    await assertRejects(
        () => planWithdrawal({
            ...placement,
            plan_path: planPath,
            php: {},
            caddy: { before: { ...before, version: "0".repeat(32) }, prepared: after, withdrawn: after, after },
            dns: {},
            cleanup: [],
        }),
        Error,
        "caddy.input_invalid",
    );
});

Deno.test("the withdraw method writes plan.json and records a summary", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        const before = await section(config(block("gone", "a")), true);
        const after = await section(config(), false);
        const written: unknown[][] = [];
        await behavior.methods.withdraw.execute({
            ...placement,
            plan_path: `${folder}/plan.json`,
            php: {},
            caddy: { before, prepared: after, withdrawn: after, after },
            dns: {},
            cleanup: [{ scope: "route-1-router", user: "orbit" }],
        }, {
            writeResource: (spec: string, name: string, value: unknown) => {
                written.push([spec, name, value]);
                return Promise.resolve({});
            },
        });
        const plan = JSON.parse(await Deno.readTextFile(`${folder}/plan.json`));
        assertEquals(plan.caddy.chain, [before.content, after.content, after.content, after.content]);
        assertEquals(plan.cleanup, [{
            scope: "route-1-router",
            user: "orbit",
        }]);
        assertEquals(written, [["withdrawal", "withdrawal", {
            pools: [],
            caddy: after.version,
            dns: false,
            cleanup: 1,
        }]]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("the withdrawal workflows require every contribution, and destroy calls them without secrets", async () => {
    type Flow = {
        jobs: {
            steps: {
                name: string;
                dependsOn?: { step: string }[];
                task: {
                    methodName?: string;
                    workflowIdOrName?: string;
                    inputs?: {
                        required?: Record<string, string>;
                        execution_secrets?: Record<string, unknown>;
                    };
                };
            }[];
        }[];
    };
    const load = async (name: string) =>
        parse(
            await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`),
        ) as Flow;
    const steps = (await load("route-withdraw")).jobs.flatMap((job) =>
        job.steps
    );
    const names = steps.map((step) => step.name);
    const required = Object.keys(
        steps.find((step) => step.name === "forward")!.task.inputs!.required!,
    );
    for (const name of required.filter((n) => n !== "withdraw_unsupported")) {
        assertEquals(names.includes(name), true, name);
    }
    // The count of work a destroy never has is required under a step that does not exist: it fails closed.
    assertEquals(names.includes("withdraw_unsupported"), false);
    assertEquals(required.includes("withdraw_unsupported"), true);
    for (const step of steps) {
        for (const dependency of step.dependsOn ?? []) {
            assertEquals(names.includes(dependency.step), true, step.name);
        }
    }
    const node = (await load("route-withdraw-node")).jobs.flatMap((job) =>
        job.steps
    );
    assertEquals(node.map((step) => step.name), [
        "input",
        "compose",
        "apply",
        "failure",
        "cleanup",
    ]);
    assertEquals(
        node.find((step) => step.name === "compose")!.task.methodName,
        "withdraw",
    );
    // Removals of different Routes on one Node run side by side: the plan, the Node transaction, the cleanup and the proof are named by
    // the withdrawn Route, so none waits on another Route's model lock, and two writes of one Route stay ordered. The input writer and the
    // phase result keep their fixed names (bin/source_test.ts).
    for (const step of [...node, ...steps].filter((step) => ["compose", "apply", "failure", "cleanup", "forward", "refusal"].includes(step.name))) {
        assertEquals((step.task as { behaviorName: string }).behaviorName.includes("route_id"), true, step.name);
    }
    const destroy = await load("operation-instance-destroy");
    const withdraw = destroy.jobs.flatMap((job) => job.steps).find((step) =>
        step.name === "Withdraw the Route"
    )!;
    assertEquals(
        resolveWorkflowTarget(withdraw.task.workflowIdOrName!),
        "route-withdraw",
    );
    assertEquals(withdraw.task.inputs?.execution_secrets, {});
});

Deno.test("a failed create takes back its publication: the reverse states remove the site, the pool and the DNS name, and keep what else is live", async () => {
    // A create publishes before -> after; its withdrawal applies after -> before.
    const without = await section(config(block("kept", "a")), true);
    const published = await section(
        config(block("kept", "a"), block("made", "a")),
        true,
    );
    const dnsWithout = await dnsState(["kept.test"]);
    const dnsPublished = await dnsState(["kept.test", "made.test"]);
    const phpPublished = {
        account_user: "orbit",
        configurations: { "8.4": pool(1, 5) + pool(2, 5) },
    };
    const phpWithout = {
        account_user: "orbit",
        configurations: { "8.4": pool(1, 5) },
    };
    const input = (live: string, pools: string, dns: typeof dnsPublished) => ({
        ...placement,
        plan_path: planPath,
        read: read({
            account,
            pools: { [poolPath]: resolved(pools) },
            caddy: live,
            records: dns.records,
            catalog: dns.catalog,
        }),
        php: { before: phpPublished, after: phpWithout },
        caddy: {
            before: published,
            prepared: published,
            withdrawn: published,
            after: without,
        },
        dns: {
            before: dnsPublished,
            prepared: dnsPublished,
            withdrawn: dnsPublished,
            after: dnsWithout,
        },
        cleanup: [{ scope: "route-7-router", user: "orbit" }],
    });
    const extra = block("elsewhere", "z");
    const plan = await composeWithdrawal(
        input(
            config(block("kept", "a"), block("made", "a"), extra),
            pool(1, 5) + pool(2, 5),
            dnsPublished,
        ),
    );
    assertEquals(plan.caddy?.content.includes("made.test"), false);
    assertEquals(plan.caddy?.content.includes("kept.test"), true);
    assertEquals(plan.caddy?.content.includes("elsewhere.test"), true);
    assertEquals(plan.pools[0].content.includes("orbit-instance-2]"), false);
    assertEquals(plan.pools[0].content.includes("orbit-instance-1]"), true);
    assertEquals(plan.dns?.records.includes("made.test"), false);
    assertEquals(plan.dns?.records.includes("kept.test"), true);
    assertEquals(plan.cleanup, [{ scope: "route-7-router", user: "orbit" }]);
    // A create that failed before it published anything: the same withdrawal finds nothing to remove and does not fail.
    const nothing = await composeWithdrawal(
        input(
            config(block("kept", "a"), extra),
            pool(1, 5),
            dnsWithout,
        ),
    );
    assertEquals(nothing.caddy?.content.includes("made.test"), false);
    assertEquals(nothing.caddy?.content.includes("elsewhere.test"), true);
    assertEquals(nothing.dns?.records.includes("made.test"), false);
});

Deno.test("the create workflow withdraws the Route of a failed create before it reports the failure", async () => {
    type Step = {
        name: string;
        guard?: string;
        dependsOn?: { step: string }[];
        task: { workflowIdOrName?: string; inputs?: Record<string, unknown> };
    };
    const load = async (name: string) =>
        parse(
            await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`),
        ) as {
            inputs: { properties: Record<string, { enum?: string[] }> };
            jobs: { steps: Step[] }[];
        };
    const create = await load("operation-instance-create");
    const steps = create.jobs.flatMap((job) => job.steps);
    const withdraw = steps.find((step) => step.name === "withdraw-route")!;
    assertEquals(
        resolveWorkflowTarget(withdraw.task.workflowIdOrName!),
        "route-withdraw",
    );
    assertEquals(withdraw.task.inputs?.side, "restore");
    // The create intent carries the captured reverse publication under route_mutations (NativeDevelopmentInstanceInput::routeWithdrawal).
    assertEquals(
        JSON.stringify(withdraw.task.inputs?.intent).includes(
            "inputs.intent.route_mutations",
        ),
        true,
    );
    assertEquals(
        JSON.stringify(withdraw.task.inputs?.intent).includes(
            "route_lifecycle",
        ),
        false,
    );
    assertEquals(withdraw.dependsOn?.map((d) => d.step), ["finish"]);
    // It runs only for a create that failed after its Route key was made.
    assertEquals(
        /steps\.finish\.outputs\.ready == true/.test(withdraw.guard ?? ""),
        true,
    );
    assertEquals(/route-csr-0/.test(withdraw.guard ?? ""), true);
    // accepted waits for it, so the failing assertion does not cut it short.
    const accepted = steps.find((step) => step.name === "accepted")!;
    assertEquals(
        accepted.dependsOn?.map((d) => d.step).includes("withdraw-route"),
        true,
    );
    const route = await load("route-withdraw");
    assertEquals(route.inputs.properties.side.enum, ["forward", "restore"]);
});

Deno.test("the withdrawal names its Node steps from 16 slots per Node, evaluated as Swamp evaluates them", async () => {
    const { Environment } = await import("npm:@marcbachmann/cel-js@7.6.1");
    const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
    type Step = { name: string; task: { behaviorName?: string; inputs?: { intent?: { publication?: Record<string, string> } } } };
    const load = async (name: string) =>
        (parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as { jobs: { steps: Step[] }[] }).jobs.flatMap((job) => job.steps);
    const evaluate = (value: string, context: Record<string, unknown>) => String(cel.evaluate(value.slice(3, -2).trim(), context));
    const parent = await load("route-withdraw");
    const node = parent.find((step) => step.name === "withdraw_node")!.task.inputs!.intent!.publication!;
    const forward = parent.find((step) => step.name === "forward")!.task.behaviorName!;
    // A destroy (side forward) desires no Route, so its forward stage lists none; its restore stage lists the withdrawn Routes. A failed
    // create (side restore) lists its prior Route there. Both take the slot of that Route.
    const cases = [
        ["forward", { forward: { routes: [] }, restore: { routes: [{ route_id: 1150 }, { route_id: 1151 }] } }, "1150", "14"],
        ["restore", { forward: { routes: [{ route_id: 77 }] }, restore: { routes: [{ route_id: 77 }] } }, "77", "13"],
        ["forward", { forward: { routes: [] }, restore: { routes: [] } }, "none", "none"],
    ] as const;
    for (const [side, mutations, route, slot] of cases) {
        const context = { inputs: { side, intent: { route_mutations: mutations } }, self: { _index: 0, part: { node_id: 9 } } };
        assertEquals(evaluate(node.route_id, context), route);
        assertEquals(evaluate(forward, context), `forward-result-0-${slot}`);
    }
    const child = await load("route-withdraw-node");
    const families = ["compose", "apply", "cleanup"];
    const names = (route: string, nodeId: number) =>
        families.map((name) =>
            evaluate(child.find((step) => step.name === name)!.task.behaviorName!, {
                inputs: { intent: { publication: { route_id: route, node_id: nodeId } } },
                run: { id: "0f0e0d0c-0b0a-4908-8706-050403020100" },
            })
        );
    assertEquals(names("1150", 9), ["route-withdraw-compose-9-14", "route-withdraw-apply-9-14", "route-withdraw-cleanup-9-14"]);
    assertEquals(names("none", 9), ["route-withdraw-compose-9-none", "route-withdraw-apply-9-none", "route-withdraw-cleanup-9-none"]);
    // The bound: a thousand withdrawals on one Node make 16 names per family, plus one for a stage without a Route; two Nodes never
    // share a name; 16 consecutive Routes, as clones made together get, never share a slot; the proof step takes 16 names in all.
    for (const nodeId of [1, 9, 209]) {
        const pooled = new Set(Array.from({ length: 1000 }, (_, index) => names(String(index + 1), nodeId)).flat());
        assertEquals(pooled.size, 3 * 16, `Node ${nodeId}`);
        for (const name of pooled) assertEquals(new RegExp(`^route-withdraw-(compose|apply|cleanup)-${nodeId}-([0-9]|1[0-5])$`).test(name), true, name);
    }
    assertEquals(new Set(Array.from({ length: 16 }, (_, index) => names(String(2000 + index), 9)[1])).size, 16);
    assertEquals(names("41", 9)[1] === names("57", 9)[1], true);
    assertEquals(names("41", 9)[1] === names("41", 12)[1], false);
    const proofs = new Set(Array.from({ length: 1000 }, (_, index) =>
        evaluate(forward, { inputs: { side: "forward", intent: { route_mutations: { forward: { routes: [] }, restore: { routes: [{ route_id: index + 1 }] } } } }, self: { _index: 0 } })));
    assertEquals(proofs.size, 16);
    // The names stay apart from the mutation workflows' `forward-result-<i>` and from the create's `route-site-<node>-<slot>`.
    for (const name of proofs) assertEquals(/^forward-result-0-([0-9]|1[0-5])$/.test(name), true, name);
});

Deno.test("the withdrawal visits a Node for private DNS only when the Route's captured DNS states differ there", async () => {
    const { Environment } = await import("npm:@marcbachmann/cel-js@7.6.1");
    const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
    type Step = { name: string; forEach?: { in: string }; task: { inputs?: { required?: Record<string, string> } } };
    const steps = (parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-route-withdraw.yaml`)) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps);
    const selector = steps.find((step) => step.name === "withdraw_node")!.forEach!.in;
    const required = steps.find((step) => step.name === "forward")!.task.inputs!.required!.withdraw_node;
    // One selection: the proof requires exactly the Nodes the step visits.
    assertEquals(required.slice(3, -2).trim(), `size(${selector.slice(3, -2).trim()})`);
    // Interned artifact indexes, as NativeRoutePublicationArtifacts::capture sends them: equal states share one index.
    const states = (dns: (number | null)[], caddy = 0) =>
        Object.fromEntries(["before", "prepared", "withdrawn", "after"].map((name, i) => [name, { caddy, php: 0, dns: dns[i] }]));
    const vpn = (dns: (number | null)[]) => ({ node_id: 1, serves_routes: false, ...states(dns) });
    const visited = (publications: Record<string, unknown>[], cleanup: Record<string, unknown>[] = [], side = "forward") =>
        (cel.evaluate(selector.slice(3, -2).trim(), {
            inputs: { side, intent: { route_mutations: { [side]: { publications, cleanup } } } },
        }) as { node_id: number }[]).map((p) => p.node_id);
    const router = { node_id: 9, serves_routes: true, ...states([null, null, null, null]) };
    // An in-TLD clone: the vpn Node's four DNS states are one artifact, so only the Router is visited (Operations 23224 and 23227).
    assertEquals(visited([router, vpn([0, 0, 0, 0])]), [9]);
    assertEquals(visited([router, vpn([0, 0, null, null])]), [9]);
    // A Route with a line on the vpn Node (outside the TLD, Node-scoped, on another Node, a Router change): the states differ.
    assertEquals(visited([router, vpn([0, 0, 1, 1])]), [9, 1]);
    assertEquals(visited([router, vpn([1, 1, 0, 0])], [], "restore"), [9, 1]);
    assertEquals(visited([router, vpn([0, 1, 1, 1])]), [9, 1]);
    assertEquals(visited([router, vpn([0, 0, 0, 1])]), [9, 1]);
    // A Node without private DNS, and one that only holds a withdrawn certificate scope.
    assertEquals(visited([{ node_id: 4, serves_routes: false, ...states([null, null, null, null]) }]), []);
    assertEquals(visited([{ node_id: 4, serves_routes: false, ...states([null, null, null, null]) }], [{ node_id: 4, scope: "s" }]), [4]);
    // A Router that also holds the private DNS is visited for its sites, whatever its DNS states.
    assertEquals(visited([{ ...router, ...states([0, 0, 0, 0]), serves_routes: true }]), [9]);
});
