import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    forEach?: { in: string };
    dependsOn?: { step: string; condition: { type: string } }[];
    task: {
        type: string;
        workflowIdOrName?: string;
        behaviorType?: string;
        behaviorName?: string;
        inputs: { argv?: string; intent?: unknown };
    };
};
const read = (path: string) =>
    Deno.readTextFileSync(new URL(path, import.meta.url));
const flow = parse(
    read("../workflows/workflow-operation-instance-unregister.yaml"),
) as { jobs: { name: string; steps: Step[] }[] };
const destroy = parse(
    read("../workflows/workflow-operation-instance-destroy.yaml"),
) as { jobs: { steps: Step[] }[] };
const steps = flow.jobs.flatMap((j) => j.steps);
const step = (name: string) => steps.find((s) => s.name === name)!;
const destroyStep = (name: string) =>
    destroy.jobs.flatMap((j) => j.steps).find((s) => s.name === name)!;
const cel = new Environment({
    enableOptionalTypes: true,
    unlistedVariablesAreDyn: true,
    homogeneousAggregateLiterals: false,
});
const evaluate = (
    expr: string,
    intent: Record<string, unknown>,
    statuses: Record<string, unknown> = {},
) => cel.evaluate(expr.slice(3, -2).trim(), {
    inputs: { intent, node_id: 12, operation_id: 7 },
    steps: statuses,
    run: { id: "run" },
});
const intent = (extra: Record<string, unknown> = {}) => ({
    instance_id: 42,
    members: [{ instance_id: 42, route_id: 5 }],
    additional_route_ids: [],
    check_paths: ["/etc/systemd/system/orbit-process-1-worker.service"],
    ...extra,
});
const notHeld = {
    "Withdraw the Route": {
        status: "succeeded",
        outputs: { result: { ready: false } },
    },
};

Deno.test("the workflow runs the chart's Swamp steps and nothing else", () => {
    assertEquals(flow.jobs.map((j) => j.name), ["unregister"]);
    const chart = read("../../docs/cli/instance/unregister.mmd");
    const charted = [...chart.matchAll(/^\s*SW->>[A-Z]+: ([^<\n]+)/gm)].map((
        m,
    ) => m[1].trim());
    assertEquals(steps.map((s) => s.name), charted);
    assertEquals(charted, [
        "Withdraw the Route",
        "Release the Route Nodes",
        "Stop, remove Processes",
        "Remove hibernation files",
        "Check units gone",
    ]);
});

Deno.test("no step tears down, removes source, SQLite or databases, or touches Git or a token", () => {
    // What runs and what it reads: the steps and the inputs, not the description or comments.
    const text = JSON.stringify([
        flow.jobs,
        (flow as unknown as { inputs: unknown }).inputs,
    ]);
    for (
        const word of [
            "teardown",
            "source_paths",
            "push_check_paths",
            "sqlite",
            "database",
            "git_credential",
            "github",
            "rm -rf",
            "worktree",
            "checkout",
            "direct_destroy",
            "execution_secrets[",
        ]
    ) assert(!text.toLowerCase().includes(word), `the workflow names ${word}`);
    for (const s of steps) {
        assert(
            !/teardown|source|sqlite|database|git|container/i.test(s.name),
            s.name,
        );
    }
});

Deno.test("it calls destroy's children and behaviors instead of copying them", () => {
    assertEquals(
        step("Withdraw the Route").task.workflowIdOrName,
        '${{ "route-withdraw" }}',
    );
    assertEquals(
        step("Stop, remove Processes").task.workflowIdOrName,
        "operation-process-destroy",
    );
    for (
        const name of [
            "Release the Route Nodes",
            "Remove hibernation files",
        ]
    ) {
        assertEquals(
            step(name).task.behaviorName,
            destroyStep(name).task.behaviorName,
        );
        assertEquals(
            step(name).task.behaviorType,
            destroyStep(name).task.behaviorType,
        );
    }
    assertEquals(
        step("Check units gone").task.behaviorName,
        destroyStep("Check it is gone").task.behaviorName,
    );
});

Deno.test("the withdrawal runs for a Route and skips without one or after a held withdrawal", () => {
    const guard = step("Withdraw the Route").guard!;
    assertEquals(evaluate(guard, intent()), false);
    assertEquals(
        evaluate(
            guard,
            intent({ members: [{ instance_id: 42, route_id: null }] }),
        ),
        true,
    );
    assertEquals(
        evaluate(
            guard,
            intent({
                members: [{ instance_id: 42, route_id: null }],
                additional_route_ids: [9],
            }),
        ),
        false,
    );
    assertEquals(evaluate(guard, intent({ route_withdrawn: true })), true);
});

Deno.test("a withdrawal that did not hold skips the Node steps and fails the end check", async () => {
    for (const name of ["Stop, remove Processes", "Remove hibernation files"]) {
        assertEquals(evaluate(step(name).guard!, intent(), notHeld), true);
        assertEquals(
            evaluate(step(name).guard!, intent(), {
                "Withdraw the Route": { status: "skipped" },
            }),
            false,
        );
    }
    const argv = evaluate(
        step("Check units gone").task.inputs.argv!,
        intent({ check_paths: [] }),
        notHeld,
    ) as string[];
    const result = await new Deno.Command(argv[2], { args: argv.slice(3) })
        .output();
    assert(!result.success);
    assert(
        new TextDecoder().decode(result.stderr).includes(
            "route.withdrawal_failed",
        ),
    );
});

Deno.test("the end check reads only check_paths: the checkout stays and passes, a unit left behind fails", async () => {
    const root = await Deno.makeTempDir({ prefix: "orbit-unregister-" });
    try {
        const checkout = `${root}/apps/fixture/default`;
        await Deno.mkdir(`${checkout}/.git`, { recursive: true });
        await Deno.writeTextFile(`${checkout}/.env`, "APP_NAME=fixture\n");
        const unit = `${root}/orbit-process-1-worker.service`;
        const check = (paths: string[]) => {
            const argv = evaluate(
                step("Check units gone").task.inputs.argv!,
                intent({ check_paths: paths }),
                {
                    "Withdraw the Route": {
                        status: "succeeded",
                        outputs: { result: { ready: true } },
                    },
                },
            ) as string[];
            assert(!argv.some((a) => a.includes(checkout)));
            return new Deno.Command(argv[2], { args: argv.slice(3) }).output();
        };
        assert((await check([unit])).success);
        await Deno.writeTextFile(unit, "[Unit]\n");
        assert(!(await check([unit])).success);
        assertEquals(
            await Deno.readTextFile(`${checkout}/.env`),
            "APP_NAME=fixture\n",
        );
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});

Deno.test("every type in the inputs schema is one value Swamp's schema allows", () => {
    // Swamp validates `type` as a single name; a list such as ["integer", "null"] fails workflow validation.
    const allowed = [
        "string",
        "number",
        "integer",
        "boolean",
        "array",
        "object",
    ];
    const walk = (node: unknown, path: string): void => {
        if (Array.isArray(node)) {
            node.forEach((item, index) => walk(item, `${path}[${index}]`));
            return;
        }
        if (node === null || typeof node !== "object") return;
        for (const [key, value] of Object.entries(node)) {
            if (key === "type") {
                assert(
                    typeof value === "string" && allowed.includes(value),
                    `${path}.type is ${JSON.stringify(value)}`,
                );
            } else walk(value, `${path}.${key}`);
        }
    };
    walk((flow as unknown as { inputs: unknown }).inputs, "inputs");
});
