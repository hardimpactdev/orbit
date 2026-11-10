import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { roleAddPlans } from "./role_add_plans.ts";
import { toolSetupPlan } from "../node-bootstrap/tool_setup_plan.ts";
import { composePhp } from "../routes/publication-command/assets.ts";

const account = {
    orbit: ["x", "1000", "1000", "", "/home/orbit", "/bin/bash"],
};
const connection = {
    nodeHost: "10.44.0.9",
    nodePort: 22,
    nodeUser: "orbit",
    nodeIdentityFile: "/existing/key",
    become: true,
};
const dns = (address: string) => ({
    revision: "100", listen: [address], tlds: [], records: [],
});
const pool = (id: number, count: number) =>
    `[orbit-instance-${id}]\nchdir = /__ORBIT_NODE_HOME__/app-${id}\npm.max_children = ${count}\n`;
const php = (text: string) => ({
    account_user: "orbit",
    configurations: { "8.4": text },
});
const publication = {
    node_id: 9,
    host_keys: ["ssh-ed25519 fixture"],
    connection,
    before: { php: php(pool(1, 5)), dns: dns("10.0.0.1") },
    after: { php: php(pool(1, 6)), dns: dns("10.0.0.2") },
};
const encoded = (text: string) =>
    btoa(String.fromCharCode(...new TextEncoder().encode(text)));

Deno.test("role-add composes observed PHP while leaving desired-state DNS to its publisher", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const currentPhp = (pool(1, 5) + pool(2, 8)).replaceAll(
            "/__ORBIT_NODE_HOME__",
            "/home/orbit",
        );
        await Deno.writeTextFile(
            folder + "/publication-n9.json",
            JSON.stringify({
                account,
                pools: { "8.4": encoded(currentPhp) },
            }),
        );
        const result = await roleAddPlans(
            { ssh: { control_node_id: 209 }, publications: [publication] },
            folder,
            "publication",
        );
        assertEquals(result, {
            publications: {
                n9: {
                    php: composePhp({
                        ...publication,
                        control_node_id: 209,
                        before: publication.before.php,
                        after: publication.after.php,
                        user: "orbit",
                        direction: "forward",
                        current: JSON.stringify({ "8.4": currentPhp }),
                        account: JSON.stringify({
                            user: "orbit",
                            group: "1000",
                            home: "/home/orbit",
                        }),
                    }),
                },
            },
        });
        // No machine IO is hidden in composition: all observations must have been captured by Ansible first.
        await Deno.remove(folder + "/publication-n9.json");
        await assertRejects(() =>
            roleAddPlans(
                { ssh: { control_node_id: 209 }, publications: [publication] },
                folder,
                "publication",
            )
        );
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("role-add accepts null publication duties and empty decoded PHP maps without touching other resources", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(
            folder + "/publication-n9.json",
            JSON.stringify({ account, pools: {}, records: "", catalog: "" }),
        );
        for (
            const php of [null, { account_user: "orbit", configurations: [] }]
        ) {
            assertEquals(
                await roleAddPlans(
                    {
                        ssh: { control_node_id: 209 },
                        publications: [{
                            ...publication,
                            before: { php, dns: null },
                            after: { php, dns: null },
                        }],
                    },
                    folder,
                    "publication",
                ),
                { publications: { n9: { php: { files: [] } } } },
            );
        }
        assertEquals(
            await roleAddPlans(
                { ssh: { control_node_id: 209 }, publications: [] },
                folder,
                "publication",
            ),
            { publications: {} },
        );
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("role-add uses the observed account home and the existing pinned Tool Manager planner", async () => {
    const folder = await Deno.makeTempDir();
    const setup = {
        manager: "composer",
        input: { user: "orbit", version: "2.9.5-1" },
        apt: {
            snapshot: "20260101T000000Z",
            packages: [{
                name: "composer",
                version: "2.9.5-1",
                artifact: null,
            }],
        },
        packages: [],
    };
    try {
        await Deno.writeTextFile(
            folder + "/tools-account.json",
            JSON.stringify(account),
        );
        assertEquals(
            await roleAddPlans(
                { managers: [{ user: "orbit", setup }] },
                folder,
                "tools",
            ),
            { managers: [await toolSetupPlan(setup, "/home/orbit")] },
        );
        await assertRejects(() =>
            roleAddPlans(
                { managers: [{ user: "missing", setup }] },
                folder,
                "tools",
            )
        );
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});
