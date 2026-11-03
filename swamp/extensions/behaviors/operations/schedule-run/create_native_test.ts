/** Native installation checks consume resource compliance, without a reporting shell. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { resourceFixture } from "./schedule_fixture_test.ts";

Deno.test("compliant resources complete without a reporting shell", async () => {
    await using f = await resourceFixture("create");
    const { run, steps } = await f.run();
    assertEquals(run.status, "succeeded");
    assertEquals(steps["Check the installation"], "succeeded");
    assertEquals(
        Object.keys(steps).filter((name) => name.startsWith("check_files-"))
            .length,
        4,
    );
    assert(!("result" in steps));
});

Deno.test("noncompliant file or timer fails the native end check", async () => {
    await using f = await resourceFixture("create");
    for (const drift of ["file", "timer"]) {
        const { run, steps } = await f.run(drift);
        assertEquals(run.status, "failed");
        assertEquals(steps["Check the installation"], "failed");
    }
});
