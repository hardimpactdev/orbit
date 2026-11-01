import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { routeMutationSteps } from "./steps.ts";
import { mergeCaddy } from "../publication-command/sections.ts";
const header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
const block = (name: string, value: string) =>
    `# orbit: routes ${name}\n${name}.test { reverse_proxy ${value} }\n`;
Deno.test("restoration accepts every captured physical phase and preserves unrelated current sites", () => {
    const before = header + "\n" + block("old", "old");
    const prepared = before + "\n" + block("new", "staged");
    const withdrawn = header + "\n" + block("new", "staged");
    const after = header + "\n" + block("new", "live");
    for (const current of [before, prepared, withdrawn, after]) {
        const result = mergeCaddy(
            after,
            before,
            current + "\n" + block("other", "changed"),
            [before, prepared, withdrawn, after],
        );
        assertEquals(result.includes("old.test"), true);
        assertEquals(result.includes("new.test"), false);
        assertEquals(result.includes("changed"), true);
    }
    assertThrows(() =>
        mergeCaddy(after, before, header + "\n" + block("new", "foreign"), [
            before,
            prepared,
            withdrawn,
            after,
        ])
    );
});
Deno.test("the native factory orders every contribution and retains parent identity", () => {
    const rows = routeMutationSteps(
        "routes",
        "inputs.intent.route_mutations",
        "forward",
    );
    const child = rows.filter((row) => row.name !== "routes");
    assertEquals(child.length, 13);
    for (let i = 0; i < child.length; i++) {
        assertEquals((child[i].dependsOn as unknown[]).length, i);
        assertEquals((child[i].task as { type: string }).type, "workflow");
    }
    assertEquals(JSON.stringify(rows).includes("GatewayOperation"), false);
    assertEquals(
        JSON.stringify(rows).includes('"parent_run_id":"${{ run.id }}"'),
        true,
    );
});

Deno.test("restore first keeps the observed candidate serving, then withdraws it after DNS grace", () => {
    const before = header + "\n" + block("old", "old");
    const after = header + "\n" + block("new", "live");
    const retained = mergeCaddy(after, before, after, [before, after], true);
    assertEquals(retained.includes("old.test"), true);
    assertEquals(retained.includes("new.test"), true);
    assertEquals(
        mergeCaddy(after, before, retained, [before, after]).includes(
            "new.test",
        ),
        false,
    );
    const rows = routeMutationSteps(
        "routes",
        "inputs.intent.route_mutations",
        "restore",
    );
    const names = rows.map((row) => row.name);
    assertEquals(
        names.indexOf("routes_restore_prepare_caddy") <
            names.indexOf("routes_dns"),
        true,
    );
    assertEquals(
        names.indexOf("routes_dns") < names.indexOf("routes_dns_grace"),
        true,
    );
    assertEquals(
        names.indexOf("routes_dns_grace") <
            names.indexOf("routes_restore_caddy"),
        true,
    );
});

Deno.test("any-source tuples share observation policy without consuming source-specific allowances", async () => {
    const { neededLanRules } = await import("./assets.ts");
    const rule = {
        model_name: "public-80",
        port: 80,
        protocol: "tcp" as const,
        source: "any" as const,
        ensure: "present" as const,
    };
    const plan = { node_id: 9, rules: [rule], migrate: false };
    const status = (line: string) => "Status: active\n" + line;
    assertEquals(neededLanRules(plan, status("80/tcp ALLOW IN 192.168.6.2")), [
        "public-80",
    ]);
    assertEquals(neededLanRules(plan, status("80/tcp ALLOW OUT Anywhere")), [
        "public-80",
    ]);
    for (
        const line of [
            "80/tcp ALLOW IN Anywhere",
            "80/tcp (v6) ALLOW IN Anywhere (v6)",
            "80/tcp ALLOW IN Anywhere # orbit:ingress-http",
        ]
    ) {
        assertEquals(neededLanRules(plan, status(line)), []);
        assertEquals(
            neededLanRules(
                { ...plan, rules: [{ ...rule, ensure: "absent" }] },
                status(line),
            ),
            ["public-80"],
        );
    }
});
