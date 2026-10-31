import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { assets, behavior } from "./mod.ts";
import { Site } from "../analytics/plausible.ts";
Deno.test("asset package exposes every one-action script and batches at CE's eight-goal limit", () => {
    const goals = Array.from(
        { length: 17 },
        (_, i) => ({
            goal_type: "Goal.CustomEvent" as const,
            goal: { event_name: `Signup${i}` },
        }),
    );
    const input = {
        base_url: "http://10.44.0.14:8000",
        site: {
            domain: "shop.test",
            previous_domain: null,
            timezone: "UTC",
            goals,
        },
    };
    const result = assets(input, { extensionFile: (path) => `/cache/${path}` });
    assertEquals(result.goal_batches.map((value) => JSON.parse(value).length), [
        8,
        8,
        1,
    ]);
    assertEquals(JSON.parse(result.input_json), input);
    for (
        const action of [
            "login_form",
            "login",
            "site_form",
            "inspect",
            "create",
            "update",
            "verify",
            "stats",
            "goals",
            "logout",
        ] as const
    ) assertEquals(result[action], `/cache/scripts/${action}.py`);
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
Deno.test("decision schema refuses unsupported mutation and invalid domain", () => {
    const parse = behavior.resources.decision.schema;
    assertThrows(() =>
        parse.parse({ action: "delete", current_domain: "shop.test" })
    );
    assertThrows(() =>
        parse.parse({ action: "update", current_domain: "https://other.test" })
    );
});
