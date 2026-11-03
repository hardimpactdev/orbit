import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { fleetReleaseFixture } from "./release_fixture.ts";

Deno.test("every step runs on 209 and only a rollout publishes the observer", async () => {
    const source = parse(
        await Deno.readTextFile(
            `${ROOT}/workflows/workflow-fleet-release-node.yaml`,
        ),
    ) as {
        jobs: {
            steps: {
                name: string;
                labels: unknown;
                guard: string;
                task: { behaviorType: string };
            }[];
        }[];
    };
    const steps = Object.fromEntries(
        source.jobs.flatMap((j) => j.steps).map((s) => [s.name, s]),
    );
    for (const name of ["gate", "cli", "record"]) {
        assertEquals(steps[name].labels, { role: "swamp" }, name);
    }
    for (
        const name of [
            "observer_fetch",
            "observer_read",
            "observer_restart",
            "observer_check",
            "observer",
        ]
    ) {
        assertEquals(steps[name].labels, { role: "swamp" }, name);
        assert(
            steps[name].guard.startsWith(
                '${{ inputs.kind != "fleet.rollout" ||',
            ),
            name,
        );
    }
    assertEquals(
        steps.observer_fetch.task.behaviorType,
        "@swamp/cfgmgmt/fetch",
    );
});

const test = (
    name: string,
    body: (f: Awaited<ReturnType<typeof fleetReleaseFixture>>) => Promise<void>,
) => Deno.test(name, async () => {
    const f = await fleetReleaseFixture();
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
test("failed observer fetch stops later nodes", async (f) => {
    f.state.observer_failure = 3;
    const { state: s } = await f.run("rollout", false);
    assertEquals(s.effects, ["cli:2", "observer:2", "cli:3", "observer:3"]);
    assertEquals(s.operation.status, "failed");
    assertEquals(
        s.operation.result.nodes[1].error_code,
        "agent.download_failed",
    );
    assertEquals(s.operation.result.nodes[2].outcome, "pending");
});
test("a pinned running agent restarts only when the binary changed", async (f) => {
    const { state: s } = await f.run();
    assertEquals(s.restarts, [2, 3, 4]);
    assertEquals(s.operation.result.nodes[0].agent_version, "0.5.0");
});
test("an unchanged pinned agent is not restarted", async (f) => {
    f.state.unchanged = true;
    const { state: s } = await f.run();
    assert(!("restarts" in s));
    assertEquals(s.operation.result.nodes[0].outcome, "converged");
});
test("an agent still running another binary fails its check", async (f) => {
    f.state.running = { "3": "c".repeat(64) };
    const { state: s } = await f.run("rollout", false);
    assertEquals(
        s.operation.result.nodes[1].error_code,
        "agent.observer_unhealthy",
    );
});
test("closed window defers shared nodes without effects", async (f) => {
    for (const node of f.state.operation.input.nodes) node.daytime = false;
    await Deno.writeTextFile(
        f.module,
        (await Deno.readTextFile(f.module)).replaceAll(
            "2026-10-31T02:30:00Z",
            "2026-10-31T12:30:00Z",
        ),
    );
    const { state: s } = await f.run();
    assertEquals(s.effects, []);
    assertEquals(s.operation.result.rollout_status, "running");
});
