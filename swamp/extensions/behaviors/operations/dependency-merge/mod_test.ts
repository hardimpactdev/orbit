import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Input, inspect, merged, plan, registry, validate } from "./mod.ts";
import {
    fixtureInput,
    head,
    merge,
    policyFixture,
    publicPr,
} from "./policy_fixture.ts";

Deno.test("native private evidence is shaped without tokens, HTTP or callbacks", async () => {
    const directory = await Deno.makeTempDir();
    try {
        const input = Input.parse({
            ...fixtureInput,
            node_id: 209,
            project_id: 20,
            publish_operation_id: 8,
        });
        const args = { input, directory },
            pr = publicPr(),
            observations = policyFixture(pr),
            root = "/repos/acme/widgets";
        const write = (name: string, value: unknown) =>
            Deno.writeTextFile(
                `${directory}/${name}.json`,
                JSON.stringify(value),
            );
        await write("pr", pr);
        assertEquals(await inspect(args), { merged: false });
        await write("repository", observations[root]);
        await write(
            "workflows",
            observations[root + "/actions/workflows?per_page=100"],
        );
        await write(
            "runs",
            observations[
                root +
                `/actions/runs?head_sha=${head}&event=pull_request&per_page=100`
            ],
        );
        await write("files", [
            observations[root + "/pulls/3/files?per_page=100&page=1"],
        ]);
        await write("checks", [
            observations[
                root + `/commits/${head}/check-runs?per_page=100&page=1`
            ],
        ]);
        const planned = await plan(args);
        for (const request of planned.requests) {
            await write(
                "content-" + request.index,
                observations["/" + request.path],
            );
        }
        assertEquals((await registry(args)).requests, []);
        assertEquals(await validate(args), { approved: true });
        await write("merge", { merged: true, sha: merge });
        assertEquals(
            JSON.parse((await merged({ ...args, exit_code: 0 })).stdout),
            { merged: true, head_sha: head, merge_sha: merge },
        );
        await assertRejects(() => merged({ ...args, exit_code: 1 }));
        pr.merged = true;
        pr.merge_commit_sha = merge;
        await write("pr", pr);
        assertEquals(
            JSON.parse((await merged({ ...args, exit_code: 1 })).stdout)
                .merge_sha,
            merge,
        );
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
