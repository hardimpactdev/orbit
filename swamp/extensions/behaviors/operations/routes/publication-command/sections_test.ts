import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { mergeCaddy } from "./sections.ts";
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
});
