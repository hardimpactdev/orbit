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
                created_at: "2026-11-19T10:00:00Z",
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
                            pull_request_created_at: "2026-11-19T10:00:00Z",
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

function copyInput(body: string) {
    return PublishInput.parse({
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
        pull_request: { title: "Add the export", body },
    });
}

Deno.test("a copy's publish writes the PR text with the proposed body's waiver lines, and the caller's text without the file", async () => {
    for (
        const proposed of [
            null,
            "Prose.\nDocs-unaffected: docs/reference/mcp.mdx: generated.\n",
        ]
    ) {
        const directory = await Deno.makeTempDir();
        try {
            if (proposed !== null) {
                await Deno.writeTextFile(
                    `${directory}/proposed-body`,
                    proposed,
                );
            }
            const { context } = createModelTestContext();
            await behavior.methods.waivers.execute({
                input: copyInput("Adds the export."),
                directory,
            }, context);
            assertEquals(
                await Deno.readTextFile(`${directory}/pull-title`),
                "Add the export",
            );
            assertEquals(
                await Deno.readTextFile(`${directory}/pull-body`),
                proposed === null
                    ? "Adds the export."
                    : "Adds the export.\n\nDocs-unaffected: docs/reference/mcp.mdx: generated.",
            );
        } finally {
            await Deno.remove(directory, { recursive: true });
        }
    }
});

Deno.test("the PR plan sends the text the copy's content check read", async () => {
    const directory = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(`${directory}/pulls.json`, "[]");
        await Deno.writeTextFile(`${directory}/pull-title`, "Add the export");
        await Deno.writeTextFile(
            `${directory}/pull-body`,
            "Adds the export.\n\nDocs-unaffected: docs/a.md: ok.",
        );
        const { context } = createModelTestContext();
        await behavior.methods.pull.execute({
            input: copyInput("Adds the export."),
            directory,
            exit_code: 0,
        }, context);
        const sent = JSON.parse(
            await Deno.readTextFile(`${directory}/pull-body.json`),
        );
        assertEquals(
            sent.body,
            "Adds the export.\n\nDocs-unaffected: docs/a.md: ok.",
        );
        assertEquals(sent.title, "Add the export");
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
