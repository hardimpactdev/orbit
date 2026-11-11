import {
    assertEquals,
    assertRejects,
    assertThrows,
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
    const d = await section(
        config(block("two", "a"), block("three", "a")),
        true,
    );
    const live = config(block("one", "a"), block("extra", "z"));
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
    for (const name of ["two", "three", "extra"]) {
        assertEquals(content.includes(`${name}.test`), true, name);
    }
    assertEquals(content.includes("one.test"), false);
});

Deno.test("four distinct DNS states apply in order, and a step that changes only the records is not skipped", async () => {
    const a = await dnsState(["x.test"]);
    const b = await dnsState(["x.test", "y.test"]);
    const c = { ...(await dnsState(["y.test"])), catalog: b.catalog };
    const d = await dnsState(["y.test", "z.test"]);
    const live = await dnsState(["x.test", "extra.test"]);
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

Deno.test("the plan records what was read, so the phase can refuse a file that changed since", async () => {
    const before = await section(config(block("gone", "a")), true);
    const after = await section(config(), false);
    const dns = await dnsState(["gone.test"]);
    const plan = await composeWithdrawal({
        ...placement,
        plan_path: planPath,
        read: read({
            account,
            pools: { [poolPath]: resolved(pool(1, 5)) },
            caddy: before.content,
            records: dns.records,
            catalog: dns.catalog,
        }),
        php: {
            before: {
                account_user: "orbit",
                configurations: { "8.4": pool(1, 5) },
            },
            after: { account_user: "orbit", configurations: [] },
        },
        caddy: { before, prepared: after, withdrawn: after, after },
        dns: { before: dns, prepared: dns, withdrawn: dns, after: dns },
        cleanup: [],
    });
    assertEquals(
        Object.keys(plan.expect).sort(),
        [
            "/etc/caddy/Caddyfile",
            "/etc/dnsmasq.d/orbit-records.conf",
            poolPath,
            "/var/lib/orbit/private-dns/catalog.json",
        ].sort(),
    );
    assertEquals(
        plan.expect["/etc/caddy/Caddyfile"],
        await sha(before.content),
    );
    assertEquals(plan.expect[poolPath], await sha(resolved(pool(1, 5))));
});

Deno.test("the withdraw method reads read.json, writes plan.json and records a summary", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        const before = await section(config(block("gone", "a")), true);
        const after = await section(config(), false);
        await Deno.writeTextFile(
            `${folder}/read.json`,
            read({ caddy: before.content }),
        );
        const written: unknown[][] = [];
        await behavior.methods.withdraw.execute({
            ...placement,
            plan_path: `${folder}/plan.json`,
            read_path: `${folder}/read.json`,
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
        assertEquals(plan.caddy.content, after.content);
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
        "read",
        "compose",
        "apply",
        "cleanup",
    ]);
    assertEquals(
        node.find((step) => step.name === "compose")!.task.methodName,
        "withdraw",
    );
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
