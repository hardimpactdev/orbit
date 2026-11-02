import { assertEquals } from "jsr:@std/assert@1.0.19";
import { neededLanRules } from "./assets.ts";

Deno.test("any-source tuples share observation policy without consuming source-specific allowances", () => {
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
