import { assertEquals } from "jsr:@std/assert@1.0.19";
import { Setup } from "./tool_setup_input.ts";
import { toolSetupPlan } from "./tool_setup_plan.ts";

const artifact = {
    url: "https://mirror.invalid/git.deb",
    algorithm: "sha256",
    digest: "a".repeat(64),
};
const urls = [artifact.url, "https://upstream.invalid/git.deb"];
const composer = (pin: unknown) => ({
    manager: "composer",
    input: { user: "orbit", version: "2.9.5-1" },
    apt: {
        snapshot: "20260101T000000Z",
        packages: [{ name: "git", version: "2.55.0", artifact: pin }],
    },
    packages: [],
});

Deno.test("schema expansion accepts old and dual Composer pins without activating candidates", async () => {
    const old = await toolSetupPlan(composer(artifact), "/home/orbit");
    const dual = await toolSetupPlan(
        composer({ ...artifact, urls }),
        "/home/orbit",
    );
    assertEquals(dual.sha256_fetches, old.sha256_fetches);
    assertEquals(dual.sha256_fetches[0].url, artifact.url);
});

Deno.test("schema expansion accepts candidates on every JavaScript pin and retains strict validation", () => {
    const pin = { ...artifact, version: "1.0.0", urls };
    const setup = {
        manager: "javascript",
        input: {
            user: "orbit",
            tools: {
                node: pin,
                "vp-cli": pin,
                pnpm: pin,
                vp: { ...pin, lock_sha256: "b".repeat(64) },
            },
            vp_lock: "{}",
        },
        apt: null,
        packages: [],
    };
    assertEquals(Setup.safeParse(setup).success, true);
    const bad = (field: unknown) => composer({ ...artifact, urls: field });
    for (
        const value of [[], ["http://mirror.invalid/a.deb"], [
            artifact.url,
            artifact.url,
            artifact.url,
        ]]
    ) {
        assertEquals(Setup.safeParse(bad(value)).success, false);
    }
    assertEquals(
        Setup.safeParse(composer({ ...artifact, urls, unknown: true })).success,
        false,
    );
});
