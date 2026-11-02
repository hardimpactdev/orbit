import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { composeCaddy, composePhp } from "./assets.ts";
import { mergeDns } from "../mutation-command/dns.ts";

const header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
const block = (name: string, target: string) =>
    `# orbit: routes ${name}\nhttps://${name}.test { reverse_proxy ${target} }\n`;
const pool = (id: number, n: number) =>
    `[orbit-instance-${id}]\nchdir = /home/orbit/app-${id}\npm.max_children = ${n}\n`;
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
async function snapshot(
    phase: string,
    content = header + "\n" + block("moving", phase) + "\n" +
        block("moving", "same") + "\n" + block("survivor", "unchanged"),
) {
    const version = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("").slice(0, 32);
    return {
        caddy: {
            content,
            version,
            listen_addresses: ["10.44.0.9"],
            unserved: false,
        },
        php: {
            account_user: "orbit",
            configurations: { "8.4": pool(1, phase.length) + pool(2, 5) },
        },
        dns: {
            records:
                `host-record=moving.test,${phase}\nhost-record=survivor.test,unchanged\n`,
            catalog: JSON.stringify({
                records: { "moving.test": phase, "survivor.test": "unchanged" },
                overrides: {
                    "node:9": {
                        "moving.test": phase,
                        "survivor.test": "unchanged",
                    },
                },
                requesters: { "10.44.0.9": 9 },
            }),
        },
    };
}

async function capture(
    states: Record<string, Awaited<ReturnType<typeof snapshot>>>,
) {
    const child = new Deno.Command("php", {
        args: [
            new URL(
                "../../../../../tests/fixtures/route-publication-artifacts.php",
                import.meta.url,
            ).pathname,
        ],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(
        new TextEncoder().encode(JSON.stringify([{ node_id: 9, ...states }])),
    );
    await writer.close();
    const output = await child.output();
    assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    return JSON.parse(new TextDecoder().decode(output.stdout));
}

Deno.test("Gateway sparse artifacts equal full canonical composition through every phase and restore", async () => {
    const phases = ["before", "prepared", "withdrawn", "after"] as const;
    const states = Object.fromEntries(
        await Promise.all(
            phases.map(async (phase) => [phase, await snapshot(phase)]),
        ),
    );
    const compact = await capture(states);
    const sparse = Object.fromEntries(
        phases.map(
            (phase) => [
                phase,
                Object.fromEntries(
                    ["caddy", "php", "dns"].map(
                        (kind) => [
                            kind,
                            compact
                                .artifacts[kind][
                                    compact.publications[0][phase][kind]
                                ],
                        ],
                    ),
                ),
            ],
        ),
    );
    assertEquals(JSON.stringify(compact).includes("survivor"), false);
    const account = JSON.stringify({
        user: "orbit",
        home: "/home/orbit",
        group: "orbit",
    });
    for (
        const [from, to] of [
            ["before", "prepared"],
            ["prepared", "withdrawn"],
            ["withdrawn", "after"],
            ["after", "before"],
        ]
    ) {
        const current = await snapshot(from);
        current.caddy.content = current.caddy.content.replace(
            "reverse_proxy unchanged",
            "reverse_proxy revised",
        ) + "\n" + block("added", "new");
        current.php.configurations["8.4"] = pool(1, from.length) + pool(2, 77) +
            pool(3, 6);
        current.dns.records = current.dns.records.replace(
            "survivor.test,unchanged",
            "survivor.test,revised",
        ) + "host-record=added.test,new\n";
        const catalog = JSON.parse(current.dns.catalog);
        catalog.records["survivor.test"] = "revised";
        catalog.records["added.test"] = "new";
        catalog.requesters["10.44.0.10"] = 10;
        current.dns.catalog = JSON.stringify(catalog);
        const caddy = (values: typeof states) =>
            composeCaddy({
                ...placement,
                before: values[from].caddy,
                after: values[to].caddy,
                alternatives: phases.map((phase) => values[phase].caddy),
                current: current.caddy.content,
            });
        const fullCaddy = await caddy(states),
            sparseCaddy = await caddy(sparse);
        assertEquals(sparseCaddy, fullCaddy);
        assertEquals(
            sparseCaddy.caddy.content.includes("reverse_proxy revised"),
            true,
        );
        const php = (values: typeof states) =>
            composePhp({
                ...placement,
                before: values[from].php,
                after: values[to].php,
                current: JSON.stringify(current.php.configurations),
                account,
            });
        assertEquals(php(sparse), php(states));
        const dns = (values: typeof states) =>
            mergeDns(
                values[from].dns,
                values[to].dns,
                current.dns,
                phases.map((phase) => values[phase].dns),
            );
        assertEquals(dns(sparse), dns(states));
    }
    const conflict = await snapshot("foreign");
    assertThrows(
        () =>
            mergeDns(
                sparse.before.dns,
                sparse.after.dns,
                conflict.dns,
                phases.map((phase) => sparse[phase].dns),
            ),
        Error,
        "owned_revision_conflict",
    );
    assertThrows(
        () =>
            composePhp({
                ...placement,
                before: sparse.before.php,
                after: sparse.after.php,
                current: JSON.stringify({ "8.4": pool(1, 100) }),
                account,
            }),
        Error,
        "owned_revision_conflict",
    );
});

Deno.test("sparse create and withdrawal restore refuse a missing Caddyfile when unaffected sites were served", async () => {
    const survivor = await snapshot(
        "same",
        header + "\n" + block("survivor", "unchanged"),
    );
    const withMoving = await snapshot(
        "same",
        survivor.caddy.content + "\n" + block("moving", "new"),
    );
    for (const direction of ["forward", "restore"]) {
        const [before, after] = direction === "forward"
            ? [survivor, withMoving]
            : [withMoving, survivor];
        const compact = await capture({
            before,
            prepared: before,
            withdrawn: after,
            after,
        });
        const row = compact.publications[0];
        const original = compact.artifacts
            .caddy[row[direction === "forward" ? "before" : "after"].caddy];
        assertEquals(original.content, header);
        await assertRejects(
            () =>
                composeCaddy({
                    ...placement,
                    direction,
                    before: compact.artifacts.caddy[row.before.caddy],
                    after: compact.artifacts.caddy[row.after.caddy],
                    current: "",
                }),
            Error,
            "caddy.current_config_missing",
        );
    }
});

Deno.test("sparse create permits a missing Caddyfile on a genuinely empty Node", async () => {
    const before = await snapshot("same", header);
    const after = await snapshot(
        "same",
        header + "\n" + block("moving", "new"),
    );
    const compact = await capture({
        before,
        prepared: before,
        withdrawn: after,
        after,
    });
    const row = compact.publications[0];
    const result = await composeCaddy({
        ...placement,
        before: compact.artifacts.caddy[row.before.caddy],
        after: compact.artifacts.caddy[row.after.caddy],
        current: "",
    });
    assertEquals(result.caddy.content, after.caddy.content);
});
