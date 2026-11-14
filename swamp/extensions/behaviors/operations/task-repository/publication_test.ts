import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { PublishInput } from "../tasks/publish.ts";
import { behavior } from "./publication.ts";

Deno.test("publication maps a private PR reply to its admitted public repository", async () => {
    const directory = await Deno.makeTempDir();
    try {
        const input = PublishInput.parse({
            group_id: 1,
            instance_id: 2,
            node_id: 3,
            check: 4,
            checkout_path: "/srv/workspace",
            commit: "a".repeat(40),
            tree: "b".repeat(40),
            repository: "acme/shop",
            base: "main",
            branch: "task-1",
            pull_request: null,
        });
        await Deno.writeTextFile(
            `${directory}/pull.json`,
            JSON.stringify({
                number: 12,
                html_url: "https://untrusted.invalid/pull/12",
                private: "discarded",
            }),
        );
        for (const number of [0, 12]) {
            const { context, getWrittenResources } = createModelTestContext();
            await behavior.methods.written.execute({
                input,
                directory,
                number,
                exit_code: 0,
            }, context);
            assertEquals(
                getWrittenResources().map(({ specName, name, data }) => ({
                    specName,
                    name,
                    data,
                })),
                [{
                    specName: "public",
                    name: "output",
                    data: {
                        stdout: JSON.stringify({
                            pull_request_number: 12,
                            pull_request_url:
                                "https://github.com/acme/shop/pull/12",
                        }),
                    },
                }],
            );
        }
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(
            () =>
                behavior.methods.written.execute({
                    input,
                    directory,
                    number: 13,
                    exit_code: 0,
                }, context),
            Error,
            "tasks.publish_failed",
        );
        await assertRejects(
            () =>
                behavior.methods.written.execute({
                    input,
                    directory,
                    number: 0,
                    exit_code: 1,
                }, context),
            Error,
            "tasks.publish_failed",
        );
        assertEquals(getWrittenResources(), []);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("the .env check passes on exit 0 and fails with its own refusal line or as unchecked", async () => {
    const directory = await Deno.makeTempDir();
    try {
        const run = (exit_code: number) => {
            const { context, getWrittenResources } = createModelTestContext();
            return {
                result: behavior.methods.content.execute(
                    { directory, exit_code },
                    context,
                ),
                getWrittenResources,
            };
        };
        const passed = run(0);
        await passed.result;
        assertEquals(passed.getWrittenResources().map(({ data }) => data), [{
            checked: true,
        }]);
        const line =
            "tasks.publish_secret_content: a value of the .env is in the file 'notes/a.txt' (" +
            "c".repeat(40) + ") in commit " + "d".repeat(40);
        // A refusal without its line, an error exit and a line of any other shape are unchecked.
        for (const exit of [3, 1, 2, 124]) {
            await assertRejects(
                () => run(exit).result,
                Error,
                "tasks.publish_secret_unchecked",
            );
        }
        await Deno.writeTextFile(`${directory}/content.result`, line + "\n");
        await assertRejects(() => run(3).result, Error, line);
        await assertRejects(
            () => run(1).result,
            Error,
            "tasks.publish_secret_unchecked",
        );
        for (const text of ["other: x\n", line + "\nsecond line\n", ""]) {
            await Deno.writeTextFile(`${directory}/content.result`, text);
            await assertRejects(
                () => run(3).result,
                Error,
                "tasks.publish_secret_unchecked",
            );
        }
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
