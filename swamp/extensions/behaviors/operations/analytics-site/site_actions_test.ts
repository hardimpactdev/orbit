import {
    assert,
    assertEquals,
    assertFalse,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { PlausibleFixture } from "../../../../tests/_support/plausible_http.ts";
const module = new URL("./mod.ts", import.meta.url).href;
async function invoke(provider: PlausibleFixture) {
    const scratch = await Deno.makeTempDir();
    try {
        const result = await new Deno.Command(Deno.execPath(), {
            args: [
                "eval",
                "--quiet",
                `
      import { site } from ${JSON.stringify(module)};
      try { console.log(JSON.stringify(await site(JSON.parse(Deno.env.get("ORBIT_ANALYTICS_INPUT"))))); }
      catch (error) { console.log(JSON.stringify({ error_code: error.message })); Deno.exit(1); }`,
            ],
            cwd: scratch,
            env: {
                ORBIT_ANALYTICS_INPUT: JSON.stringify({
                    base_url: provider.url,
                    site: {
                        domain: "shop.test",
                        previous_domain: null,
                        timezone: "UTC",
                        goals: [],
                    },
                    env: {
                        ORBIT_ANALYTICS_CREDENTIALS: JSON.stringify(
                            provider.credentials,
                        ),
                    },
                }),
            },
            stdout: "piped",
            stderr: "piped",
            signal: AbortSignal.timeout(60_000),
        }).output();
        assertEquals(Array.from(Deno.readDirSync(scratch)), []);
        const stdout = new TextDecoder().decode(result.stdout),
            stderr = new TextDecoder().decode(result.stderr);
        assert(
            !provider.secrets.some((s) => (stdout + stderr).includes(s)),
            "Private bytes in action output",
        );
        return { code: result.code, stdout, stderr };
    } finally {
        await Deno.remove(scratch, { recursive: true });
    }
}
Deno.test("repeat observes existing site and always logs out", async () => {
    await using p = await PlausibleFixture.create();
    assertEquals((await invoke(p)).code, 0);
    assertEquals((await invoke(p)).code, 0);
    assertEquals(p.sites, { "shop.test": "UTC" });
    assertEquals([p.count("POST", "/sites"), p.count("GET", "/logout")], [
        1,
        2,
    ]);
});
for (
    const mode of [
        "stats",
        "both",
        "malformed-login",
        "malformed-logout",
        "logout",
    ]
) {
    Deno.test(`safe diagnostics and cleanup: ${mode}`, async () => {
        await using p = await PlausibleFixture.create();
        if (["stats", "malformed-logout"].includes(mode)) {
            p.fail = "/api/v2/query";
        }
        if (mode === "both") {
            p.failPaths = new Set(["/api/v2/query", "/logout"]);
        }
        if (mode.startsWith("malformed")) {
            p.malformedPaths.add(
                mode === "malformed-login" ? "/login" : "/logout",
            );
        }
        if (mode === "logout") p.fail = "/logout";
        const result = await invoke(p);
        assertEquals(result.code, 1);
        assertEquals(JSON.parse(result.stdout), {
            error_code: mode === "malformed-login"
                ? "analytics.management_unavailable"
                : mode === "logout"
                ? "analytics.logout_failed"
                : "analytics.stats_unauthorized",
        });
        if (["stats", "both", "malformed-logout"].includes(mode)) {
            assertStringIncludes(
                result.stderr,
                "Plausible Stats API returned HTTP 503",
            );
        }
        if (mode === "stats") assertEquals(p.count("GET", "/logout"), 1);
        if (mode === "both") {
            assertStringIncludes(
                result.stderr,
                "Cleanup: Plausible logout returned HTTP 503",
            );
        }
        if (mode === "malformed-login") {
            assertStringIncludes(
                result.stderr,
                "Plausible GET /login: response unavailable or unreadable",
            );
        }
        if (mode === "malformed-logout") {
            assertStringIncludes(
                result.stderr,
                "Cleanup: Plausible GET /logout",
            );
        }
        if (mode === "logout") {
            assertStringIncludes(
                result.stderr,
                "Plausible logout returned HTTP 503",
            );
        }
        assertFalse(result.stderr.includes("Traceback"));
    });
}
