import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { parseTemplate } from "./template.ts";
import { assemble } from "./environment.ts";
Deno.test("strict template preserves quotes/escapes and preceding references", () => {
    assertEquals(
        parseTemplate(
            "A=first\nB=\"${A}\\nline\\$literal\"\nC='${LITERAL}'\nEMPTY=\n",
        ),
        { A: "first", B: "first\nline$literal", C: "${LITERAL}", EMPTY: "" },
    );
    for (
        const raw of [
            "A=1\nA=2",
            "B=${A}\nA=1",
            "A=$BARE",
            "UNDEFINED",
            "A=two words",
            'A="bad\\q"',
            'A="unclosed',
        ]
    ) assertThrows(() => parseTemplate(raw));
});
Deno.test("canonical rendering resolves environment and refuses unavailable domain", () => {
    const material = {
        values: { MODE: "{{instance.environment}}" },
        name: "Project",
        framework_key: null,
    };
    assertEquals(
        assemble("", material, { laravel: false }).content.includes(
            'MODE="development"',
        ),
        true,
    );
    assertThrows(() =>
        assemble("", { ...material, values: { URL: "{{instance.domain}}" } }, {
            laravel: false,
        })
    );
});
