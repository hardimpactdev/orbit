import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { fleetReleaseFixture } from "./release_fixture.ts";

const test = (
    name: string,
    body: (f: Awaited<ReturnType<typeof fleetReleaseFixture>>) => Promise<void>,
) => Deno.test(name, async () => {
    const f = await fleetReleaseFixture();
    f.repo.timeoutMs = 180_000; // Three-node parents execute their child steps serially.
    await using _repo = f.repo;
    await body(f);
});

test("ordered rollout in one native run", async (f) => {
    const { state: s } = await f.run();
    assertEquals(s.effects, [
        "cli:2",
        "observer:2",
        "cli:3",
        "observer:3",
        "cli:4",
        "observer:4",
    ]);
    assertEquals(s.operation.status, "succeeded");
    assertEquals(s.operation.result.rollout_status, "completed");
    assertEquals(s.restarts, [2, 3, 4]);
    assertEquals(s.operation.result.nodes[0].agent_version, "0.5.0");
});
test("CLI parent never publishes observer", async (f) => {
    const { state: s } = await f.run("cli-converge");
    assertEquals(s.effects, ["cli:2", "cli:3", "cli:4"]);
    assertEquals(s.operation.status, "succeeded");
});
test("refusal stops later nodes", async (f) => {
    f.state.failure = 3;
    const { state: s } = await f.run("rollout", false);
    assertEquals(s.effects, ["cli:2", "observer:2", "cli:3"]);
    assertEquals(s.operation.status, "failed");
    assertEquals(s.operation.result.halted_node_id, 3);
    assertEquals(s.operation.result.nodes[1].error_code, "cli.install_failed");
    assertEquals(s.operation.result.nodes[2].outcome, "pending");
});
test("an unchanged pinned agent is not restarted", async (f) => {
    f.state.unchanged = true;
    const { state: s } = await f.run();
    assert(!("restarts" in s));
    assertEquals(s.operation.result.nodes[0].outcome, "converged");
});
