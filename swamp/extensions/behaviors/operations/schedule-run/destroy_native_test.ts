/** Native removal refuses busy work before writes and fails on resource drift. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { resourceFixture } from "./schedule_fixture_test.ts";

Deno.test("standalone removal refuses a busy service before mutation", async () => {
    await using f = await resourceFixture("destroy");
    for (const state of ["transition"]) {
        const { run, steps } = await f.run(state);
        assertEquals(run.status, "failed");
        assertEquals(steps.idle, "succeeded");
        assertEquals(steps["Check the service is idle"], "failed");
        assert(
            !Object.entries(steps).some(([name, status]) =>
                !["idle", "Check the service is idle"].includes(name) &&
                status === "succeeded"
            ),
        );
    }
});

Deno.test("file timer drift or loaded unit fails the end assertion", async () => {
    await using f = await resourceFixture("destroy");
    for (const drift of ["file", "timer", "loaded"]) {
        const { run, steps } = await f.run(drift);
        assertEquals(run.status, "failed");
        assertEquals(steps.check_timer, "succeeded");
        assertEquals(steps["Check the removal"], "failed");
    }
});
