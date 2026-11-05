import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.14";
import { behavior, site } from "./mod.ts";
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
