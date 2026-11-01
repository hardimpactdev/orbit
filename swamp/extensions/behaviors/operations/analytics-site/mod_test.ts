import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { assets, behavior, result } from "./mod.ts";
import { Site } from "../analytics/plausible.ts";
Deno.test("one provider action group receives complete public intent and its shipped script", () => {
    const input = {
        base_url: "https://analytics.orbit",
        site: {
            domain: "shop.test",
            previous_domain: null,
            timezone: "UTC",
            goals: [{
                goal_type: "Goal.CustomEvent" as const,
                goal: { event_name: "Signup" },
            }],
        },
    };
    const value = assets(input, { extensionFile: (path) => "/cache/" + path });
    assertEquals(JSON.parse(value.input_json), input);
    assertEquals(value.site_script, "/cache/scripts/site.py");
    assertThrows(() =>
        assets({ ...input, password: "private" } as typeof input, {
            extensionFile: (path) => path,
        })
    );
});
Deno.test("provider intent rejects private or unsupported goal fields", () => {
    assertThrows(() =>
        Site.parse({ domain: "shop.test", timezone: "UTC", password: "secret" })
    );
    assertThrows(() =>
        Site.parse({
            domain: "shop.test",
            timezone: "UTC",
            goals: [{
                goal_type: "Goal.Revenue",
                goal: { event_name: "Sale" },
            }],
        })
    );
});
Deno.test("native terminal results preserve enable and detach semantics and reject private output", () => {
    for (
        const value of [
            {
                ready: true,
                enabled: true,
                readable: true,
                domain: "shop.test",
                timezone: "UTC",
            },
            {
                ready: true,
                enabled: false,
                detached: true,
                domain: null,
                timezone: null,
            },
        ]
    ) {
        const output = result(JSON.stringify(value));
        assertEquals(JSON.parse(output.stdout), value);
        assertEquals(behavior.resources.result.schema.parse(output), output);
        assertThrows(() =>
            result(JSON.stringify({ ...value, password: "private" }))
        );
    }
    assertThrows(() =>
        result('{"ready":true,"enabled":true,"domain":null,"timezone":null}')
    );
});
