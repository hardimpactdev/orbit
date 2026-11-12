import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    dependsOn?: { step: string; condition: { type: string } }[];
    task: { inputs: { argv?: string } };
};
const flow = parse(
    Deno.readTextFileSync(
        new URL(
            "../workflows/workflow-operation-instance-destroy.yaml",
            import.meta.url,
        ),
    ),
) as { jobs: { steps: Step[] }[] };
const steps = flow.jobs.flatMap((j) => j.steps);
const step = (name: string) => steps.find((s) => s.name === name)!;
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
    inputs: { intent },
    steps: statuses,
});

Deno.test("destroy skips withdrawal without publications including old admission without mutation keys", () => {
    for (
        const intent of [
            { members: [{ route_id: null }] },
            { members: [{ route_id: null }], route_mutations: {} },
            {
                members: [{ route_id: null }],
                route_mutations: { restore: { routes: [] } },
            },
            {
                members: [{ route_id: 619 }],
                route_mutations: { restore: { routes: [] } },
            },
        ]
    ) assertEquals(evaluate(step("Withdraw the Route").guard!, intent), true);
    for (
        const intent of [
            { members: [{ route_id: 1 }] },
            { members: [{ route_id: null }], additional_route_ids: [2] },
            {
                members: [{ route_id: 1 }],
                route_mutations: { restore: { routes: [{ route_id: 1 }] } },
            },
        ]
    ) assertEquals(evaluate(step("Withdraw the Route").guard!, intent), false);
});

Deno.test("Route-less checkout and linked worktree skip the child and still remove the real source", async () => {
    const root = await Deno.makeTempDir({ prefix: "orbit-destroy-routeless-" });
    const run = async (argv: string[]) => {
        const result = await new Deno.Command(argv[0], { args: argv.slice(1) })
            .output();
        assert(result.success, new TextDecoder().decode(result.stderr));
    };
    try {
        const owner = root + "/owner", linked = root + "/linked";
        await run(["git", "init", "-q", owner]);
        await run([
            "git",
            "-C",
            owner,
            "-c",
            "user.name=Fixture",
            "-c",
            "user.email=f@example.test",
            "commit",
            "--allow-empty",
            "-qm",
            "fixture",
        ]);
        await run([
            "git",
            "-C",
            owner,
            "worktree",
            "add",
            "-q",
            "--detach",
            linked,
        ]);
        for (const path of [linked, owner]) {
            const intent = {
                members: [{ route_id: null }],
                source_paths: [path],
            };
            assertEquals(
                evaluate(step("Withdraw the Route").guard!, intent),
                true,
            );
            const statuses = { "Withdraw the Route": { status: "skipped" } };
            for (
                const name of [
                    "Stop, remove Processes",
                    "Run teardown steps",
                    "Remove the source",
                ]
            ) {
                const target = step(name);
                assertEquals(
                    target.dependsOn!.find((d) =>
                        d.step === "Withdraw the Route"
                    )!.condition.type,
                    "always",
                );
                assertEquals(
                    evaluate(target.guard!, intent, statuses),
                    false,
                    name,
                );
            }
            await run(
                evaluate(
                    step("Remove the source").task.inputs.argv!,
                    intent,
                    statuses,
                ) as string[],
            );
            await run(
                evaluate(
                    step("Check it is gone").task.inputs.argv!,
                    intent,
                    statuses,
                ) as string[],
            );
            if (path === linked) {
                assert((await Deno.stat(owner + "/.git")).isDirectory);
                const list = await new Deno.Command("git", {
                    args: ["-C", owner, "worktree", "list", "--porcelain"],
                }).output();
                assert(!new TextDecoder().decode(list.stdout).includes(linked));
            }
        }
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});
