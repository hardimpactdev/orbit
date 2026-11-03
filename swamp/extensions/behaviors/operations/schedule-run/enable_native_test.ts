/** Retained-timer enable checks consume actual resource compliance semantics. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { resourceFixture } from "./schedule_fixture_test.ts";

Deno.test("enabled timer completes from its resource check", async () => {
    await using f = await resourceFixture("enable");
    const { run, steps } = await f.run();
    assertEquals(run.status, "succeeded");
    assertEquals(steps["Check the timer"], "succeeded");
    assert(!("result" in steps));
});

Deno.test("resource noncompliance fails even when the check method completed", async () => {
    await using f = await resourceFixture("enable");
    const { run, steps } = await f.run("timer");
    assertEquals(steps.check_timer, "succeeded");
    assertEquals(steps["Check the timer"], "failed");
    assertEquals(run.status, "failed");
});
