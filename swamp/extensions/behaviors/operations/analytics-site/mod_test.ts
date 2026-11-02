import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.14";
import { behavior, result, site } from "./mod.ts";
import { Site } from "../analytics/plausible.ts";
Deno.test("the site action group refuses unexpected input and non-JSON credentials before any request", async () => {
    const input = {
        base_url: "https://analytics.orbit",
        site: {
            domain: "shop.test",
            previous_domain: null,
            timezone: "UTC",
            goals: [],
        },
        env: { ORBIT_ANALYTICS_CREDENTIALS: "not json" },
    };
    await assertRejects(
        () => site(input),
        Error,
        "analytics.credentials_invalid",
    );
    await assertRejects(() =>
        site({ ...input, password: "private" } as typeof input)
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
