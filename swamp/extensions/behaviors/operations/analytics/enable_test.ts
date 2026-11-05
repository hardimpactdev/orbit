import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "../analytics-site/mod.ts";
import { PlausibleFixture } from "../../../../tests/_support/plausible_http.ts";

Deno.test("enable updates old domain and timezone without creating duplicate", async () => {
    await using provider = await PlausibleFixture.create();
    provider.sites = { "old.test": "Europe/Amsterdam" };
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.site.execute({
        base_url: provider.url,
        site: {
            domain: "shop.test",
            previous_domain: "old.test",
            timezone: "UTC",
            goals: [],
        },
        env: {
            ORBIT_ANALYTICS_CREDENTIALS: JSON.stringify(provider.credentials),
        },
    }, context);
    assertEquals(provider.sites, { "shop.test": "UTC" });
    assertEquals(provider.count("PUT", "/old.test/settings"), 1);
    assertEquals(provider.count("POST", "/sites"), 0);
    assertEquals(getWrittenResources()[0].data, {
        ready: true,
        domain: "shop.test",
        timezone: "UTC",
        readable: true,
    });
});

Deno.test("site group observes current state and batches goals by eight", async () => {
    await using provider = await PlausibleFixture.create();
    provider.sites = { "shop.test": "UTC" };
    const { context } = createModelTestContext();
    await behavior.methods.site.execute({
        base_url: provider.url,
        site: {
            domain: "shop.test",
            previous_domain: null,
            timezone: "UTC",
            goals: Array.from(
                { length: 17 },
                (_, i) => ({
                    goal_type: "Goal.CustomEvent" as const,
                    goal: { event_name: `Signup${i}` },
                }),
            ),
        },
        env: {
            ORBIT_ANALYTICS_CREDENTIALS: JSON.stringify(provider.credentials),
        },
    }, context);
    assertEquals([
        provider.count("POST", "/sites"),
        provider.count("PUT", "/shop.test/settings"),
        provider.count("PUT", "/api/plugins/v1/goals"),
    ], [0, 0, 3]);
});

for (const mode of ["goals", "login"]) {
    Deno.test(`enable ${mode} failure retains no result or credentials`, async () => {
        await using provider = await PlausibleFixture.create();
        if (mode === "goals") provider.badGoals = true;
        else provider.fail = "/login";
        const { context, getWrittenResources } = createModelTestContext();
        const error = await assertRejects(
            () =>
                behavior.methods.site.execute({
                    base_url: provider.url,
                    site: {
                        domain: "shop.test",
                        previous_domain: null,
                        timezone: "UTC",
                        goals: [{
                            goal_type: "Goal.CustomEvent",
                            goal: { event_name: "Signup" },
                        }],
                    },
                    env: {
                        ORBIT_ANALYTICS_CREDENTIALS: JSON.stringify(
                            provider.credentials,
                        ),
                    },
                }, context),
            Error,
            mode === "goals"
                ? "analytics.goals_unproven"
                : "analytics.management_unavailable",
        );
        assertEquals(getWrittenResources(), []);
        assertEquals(
            provider.secrets.some((secret) => error.message.includes(secret)),
            false,
        );
        if (mode === "goals") assertEquals(provider.count("GET", "/logout"), 1);
        else assertEquals(provider.sites, {});
    });
}

Deno.test("failed site retry reauthenticates without duplicating the site", async () => {
    await using provider = await PlausibleFixture.create();
    const { context } = createModelTestContext();
    const args = {
        base_url: provider.url,
        site: {
            domain: "shop.test",
            previous_domain: null,
            timezone: "UTC",
            goals: [],
        },
        env: {
            ORBIT_ANALYTICS_CREDENTIALS: JSON.stringify(provider.credentials),
        },
    };
    provider.fail = "/api/v2/query";
    await assertRejects(
        () => behavior.methods.site.execute(args, context),
        Error,
        "analytics.stats_unauthorized",
    );
    assertEquals(provider.count("GET", "/logout"), 1);
    provider.fail = "";
    await behavior.methods.site.execute(args, context);
    assertEquals(provider.count("POST", "/sites"), 1);
    assertEquals(provider.count("POST", "/login"), 2);
});
