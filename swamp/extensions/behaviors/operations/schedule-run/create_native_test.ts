/** Native installation checks consume resource compliance, without a reporting shell. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { resourceFixture } from "./schedule_fixture_test.ts";

Deno.test("noncompliant file or timer fails the native end check", async () => {
    await using f = await resourceFixture("create");
    for (const drift of ["file", "timer"]) {
        const { run, steps } = await f.run(drift);
        assertEquals(run.status, "failed");
        assertEquals(steps["Check the installation"], "failed");
    }
});
