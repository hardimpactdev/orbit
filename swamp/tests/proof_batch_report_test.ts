import {
    assert,
    assertEquals,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    task?: { inputs?: { env?: Record<string, string> } };
};
type Workflow = { jobs: { name: string; steps: Step[] }[] };

const read = async (name: string) =>
    parse(
        await Deno.readTextFile(
            new URL(`../workflows/${name}`, import.meta.url),
        ),
    ) as Workflow;
const environment = new Environment({
    enableOptionalTypes: true,
    unlistedVariablesAreDyn: true,
});
const expression = (text: string) =>
    text.trim().replace(/^\$\{\{\s*/, "").replace(/\s*\}\}$/, "");

const batch = await read("workflow-proof-fleet-verify-batch.yaml");
const report = expression(
    batch.jobs.find((job) => job.name === "report")!.steps[0].task!.inputs!.env!
        .LINES,
);
const cases = ["family-ipv6", "family-ipv4"];

const passed = (id: string) =>
    `{"id": "${id}", "verdict": "passed", "secs": 3}`;
type Line = { id: string; verdict: string; cause?: string; failed?: string[] };
/** The lines of the report step for the given state of the batch's steps, as the workflow's `report` job prints them. */
const lines = (steps: Record<string, unknown>, ids = cases): Line[] =>
    (environment.evaluate(report, { inputs: { cases: ids }, steps }) as string)
        .split("\n").filter((line) => line !== "").map((line) =>
            JSON.parse(line)
        );

const setupOk = {
    status: "succeeded",
    outputs: { stdout: '{"batch": "dual-stack", "setup": "ok"}', exitCode: 0 },
};
const setupFailed = {
    status: "succeeded",
    outputs: {
        stdout: '{"batch": "dual-stack", "setup": "failed"}',
        exitCode: 1,
        durationMs: 12000,
    },
};

Deno.test("setup passed and the batch process exited 255 with no verdict: unrun, infrastructure failure, with the exit code and the time", () => {
    const found = lines({
        setup: setupOk,
        batch: {
            status: "succeeded",
            outputs: {
                stdout: "",
                stderr: "",
                exitCode: 255,
                durationMs: 408898,
            },
        },
    });
    assertEquals(found.map((line) => line.id), cases);
    for (const line of found) {
        assertEquals([line.verdict, line.cause], ["unrun", "infrastructure"]);
        assertStringIncludes(
            line.failed![0],
            "could not run: infrastructure failure",
        );
        assertStringIncludes(line.failed![0], "exited 255 after 408 s");
        assert(
            !line.failed![0].includes("batch setup"),
            "a batch that ran after a good setup is no setup failure",
        );
    }
});

Deno.test("a failed batch step (killed, or the connection lost) is the same infrastructure failure, with what is known", () => {
    for (
        const batchStep of [{ status: "failed" }, {
            status: "failed",
            outputs: { stdout: "" },
        }]
    ) {
        const [line] = lines({ setup: setupOk, batch: batchStep });
        assertStringIncludes(
            line.failed![0],
            "could not run: infrastructure failure",
        );
        assertStringIncludes(
            line.failed![0],
            "exited unknown after an unknown time",
        );
    }
});

Deno.test("a batch that exited 0 with no verdict is not a pass either", () => {
    const found = lines({
        setup: setupOk,
        batch: {
            status: "succeeded",
            outputs: { stdout: "some log", exitCode: 0, durationMs: 5000 },
        },
    });
    assertEquals(found.map((line) => line.verdict), ["unrun", "unrun"]);
    assertStringIncludes(found[0].failed![0], "exited 0 after 5 s");
});

Deno.test("a genuine setup failure keeps its label and gets the setup exit code; the skipped batch is not an infrastructure failure", () => {
    const found = lines({ setup: setupFailed, batch: { status: "skipped" } });
    for (const line of found) {
        assertEquals([line.verdict, line.cause], ["unrun", "infrastructure"]);
        assertEquals(line.failed, [
            "run stopped: the batch setup did not succeed (setup exit 1)",
        ]);
    }
});

Deno.test("a setup that printed nothing and has no outputs still reads as a setup failure", () => {
    const [line] = lines({
        setup: { status: "failed" },
        batch: { status: "skipped" },
    });
    assertEquals(line.failed, ["run stopped: the batch setup did not succeed"]);
});

Deno.test("a failed restore is named, and is no setup failure and no batch failure", () => {
    const [line] = lines({
        restore: { status: "failed" },
        setup: { status: "skipped" },
        batch: { status: "skipped" },
    });
    assertEquals(line.failed, ["run stopped: the restore did not succeed"]);
});

Deno.test("a batch with some verdict lines keeps them and marks each case without one failed; the lines are no pass", () => {
    const found = lines({
        setup: setupOk,
        batch: {
            status: "succeeded",
            outputs: {
                stdout: `${passed("family-ipv6")}\nnoise`,
                exitCode: 2,
                durationMs: 9000,
            },
        },
    });
    assertEquals(found.map((line) => [line.id, line.verdict]), [[
        "family-ipv6",
        "passed",
    ], ["family-ipv4", "failed"]]);
    assertEquals(found[1].failed, ["the case printed no verdict"]);
});

Deno.test("every case has exactly one line, and no synthesized line is passed, in any state of the steps", () => {
    const states = [
        {
            setup: setupOk,
            batch: {
                status: "succeeded",
                outputs: { stdout: "", exitCode: 255, durationMs: 1 },
            },
        },
        { setup: setupFailed, batch: { status: "skipped" } },
        { restore: { status: "failed" }, batch: { status: "skipped" } },
        {},
    ];
    for (const steps of states) {
        const found = lines(steps);
        assertEquals(found.map((line) => line.id), cases);
        assert(
            found.every((line) =>
                line.verdict !== "passed" && line.cause === "infrastructure"
            ),
        );
    }
});

Deno.test("every message that stops the run still holds `run stopped`, which the parent workflow's guards read to skip the batches after it", async () => {
    const verify = await read("workflow-proof-fleet-verify.yaml");
    const guards = verify.jobs.flatMap((job) => job.steps).map((step) =>
        step.guard ?? ""
    ).filter((guard) => guard.includes("run stopped"));
    assertEquals(guards.length, 2);
    const states = [
        {
            setup: setupOk,
            batch: {
                status: "succeeded",
                outputs: { stdout: "", exitCode: 255, durationMs: 1 },
            },
        },
        { setup: setupFailed, batch: { status: "skipped" } },
        { restore: { status: "failed" }, batch: { status: "skipped" } },
    ];
    for (const state of states) {
        const stdout = lines(state).map((line) => JSON.stringify(line)).join(
            "\n",
        );
        for (const guard of guards) {
            const prefix = guard.includes("fail-batch-")
                ? "fail-batch-"
                : "batch-";
            assert(
                environment.evaluate(expression(guard), {
                    steps: {
                        [prefix + "0"]: { outputs: { report: { stdout } } },
                    },
                }) === true,
            );
        }
    }
});
