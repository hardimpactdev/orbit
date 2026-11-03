import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../../tests/_support/native_harness.ts";
import { source } from "./fixture.ts";

async function recipePr() {
    const repo = await nativeRepo();
    repo.secrets = [`fixture-ephemeral-${crypto.randomUUID()}`];
    repo.env.ORBIT_RECIPE_FIXTURE_SENTINEL = repo.secrets[0];
    await repo.copyBehaviors(
        "automatic-deploy",
        "operations/_lib",
        "operations/fleet/recipe-pr",
    );
    await Deno.copyFile(
        new URL("./native_fixture.ts", import.meta.url),
        `${repo.dir}/extensions/behaviors/operations/fleet/recipe-pr/native_fixture.ts`,
    );
    const direct =
        `${repo.dir}/extensions/behaviors/operations/fleet/recipe-pr/direct.ts`;
    await Deno.writeTextFile(
        direct,
        'import {fixtureApi,fixturePlanner} from "./native_fixture.ts";\n' +
            (await Deno.readTextFile(direct)).replace(
                "export const behavior = recipePr();",
                "export const behavior = recipePr(fixtureApi,fixturePlanner);",
            ),
    );
    await repo.workflow("operation-fleet-recipe-pr", (workflow) => {
        // Local execution has no placement after the harness removes labels.
        delete workflow.queueTimeout;
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                delete step.target;
                if (step.name === "Revoke the token") {
                    const task = step.task as { inputs: { run: string } };
                    task.inputs.run =
                        `test -n "$GH_TOKEN" && python3 -c "import json;p='recipe-native.json';s=json.load(open(p));s['revokes']+=1;json.dump(s,open(p,'w'))"`;
                }
            }
        }
    });
    assert(
        (await Deno.readTextFile(new URL("fixture.ts", import.meta.url)))
            .includes("fixture"),
    );
    const date = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Europe/Amsterdam",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date()).replaceAll("-", "");
    const intent = {
        action: "open",
        variant: "snapshot",
        date,
        pull_number: 0,
        head_sha: "",
        author: "orbit-gateway[bot]",
        required_check: "Required checks",
        deployed_recipe: source().trim() + "\n",
    };
    const state = {
        intent,
        mode: "ok",
        mints: 0,
        revokes: 0,
        writes: 0,
        prepares: 0,
        branches: {},
        pull: null,
        calls: [],
        github: [],
        lose: null as string | null,
    };
    const save = () =>
        Deno.writeTextFile(
            `${repo.dir}/recipe-native.json`,
            JSON.stringify(state),
        );
    const read = () =>
        JSON.parse(Deno.readTextFileSync(`${repo.dir}/recipe-native.json`));
    await repo.cli([
        "vault",
        "create",
        "local_encryption",
        "orbit-control-inputs",
    ]);
    await repo.cli([
        "vault",
        "put",
        "orbit-control-inputs",
        "fleet-recipe-pr-17",
        repo.secrets[0],
    ]);
    await save();
    const run = async (success = true) => {
        await repo.cli(["workflow", "validate", "operation-fleet-recipe-pr"]);
        return await repo.runWorkflow("operation-fleet-recipe-pr", {
            operation_id: 17,
            node_id: 209,
            kind: "fleet.recipe_pr",
            intent,
            execution_secrets: {
                github_token: {
                    vault: "orbit-control-inputs",
                    key: "fleet-recipe-pr-17",
                },
            },
        }, { success });
    };
    return { repo, state, intent, save, read, run };
}

Deno.test("native open writes once, reads the vault token and revokes it once", async () => {
    const f = await recipePr();
    await using repo = f.repo;
    const { history } = await f.run();
    const result = history.jobs.flatMap((j) => j.steps).find((s) =>
        s.name === "result"
    )!;
    assertEquals(
        JSON.parse(result.outputs!.stdout as string).url,
        "https://github.com/hardimpactdev/orbit/pull/999",
    );
    const s = f.read();
    assertEquals([s.writes, s.prepares], [3, 1]);
    assertEquals([s.revokes, s.calls], [1, []]);
    assertEquals(s.pull.number, 999);
    await repo.assertPrivateAbsent();
});

Deno.test("native resume keeps candidate and recognizes uncertain writes", async (t) => {
    const f = await recipePr();
    await using repo = f.repo;
    for (
        const [phase, variant] of [
            ["branch", "snapshot"],
            ["file", "snapshot"],
            ["pull", "snapshot"],
            ["pull", "artifacts"],
        ]
    ) {
        await t.step(`${phase}/${variant}`, async () => {
            f.intent.variant = variant;
            Object.assign(f.state, {
                lose: phase,
                mints: 0,
                revokes: 0,
                writes: 0,
                prepares: 0,
                branches: {},
                pull: null,
                calls: [],
                github: [],
            });
            await f.save();
            const { run } = await f.run(false);
            await repo.cli([
                "workflow",
                "resume",
                "operation-fleet-recipe-pr",
                "--run",
                run.id,
            ]);
            const s = f.read();
            assertEquals([s.writes, s.prepares], [3, 1]);
            assert(s.revokes >= 1);
            await repo.assertPrivateAbsent();
        });
    }
});
