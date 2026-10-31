import { assertEquals } from "jsr:@std/assert@1";
import { classifyCi } from "./check.ts";

const head = "a".repeat(40);
const run = {
    id: 10,
    name: "Required checks",
    head_sha: head,
    status: "completed",
    conclusion: "success",
    app: { slug: "github-actions" },
};
Deno.test("only the exact SHA and GitHub Actions identity can satisfy the check", () => {
    assertEquals(classifyCi([run], [], head, run.name).state, "passed");
    assertEquals(
        classifyCi([{ ...run, head_sha: "b".repeat(40) }], [], head, run.name)
            .fault,
        "configuration",
    );
    assertEquals(
        classifyCi(
            [run, { ...run, id: 11, app: { slug: "other-app" } }],
            [],
            head,
            run.name,
        ).fault,
        "configuration",
    );
});
Deno.test("every current run of the required name must pass", () => {
    assertEquals(
        classifyCi(
            [run, { ...run, id: 11, status: "in_progress", conclusion: null }],
            [],
            head,
            run.name,
        ).state,
        "pending",
    );
    assertEquals(
        classifyCi(
            [run, { ...run, id: 11, conclusion: "failure" }],
            [],
            head,
            run.name,
        ).fault,
        "change",
    );
    assertEquals(
        classifyCi([{ ...run, conclusion: "cancelled" }], [], head, run.name)
            .fault,
        "infrastructure",
    );
    assertEquals(
        classifyCi([{ ...run, conclusion: "skipped" }], [], head, run.name)
            .fault,
        "configuration",
    );
});
Deno.test("missing required jobs distinguish pending, workflow failure and configuration", () => {
    assertEquals(classifyCi([], [], head, run.name).state, "missing");
    assertEquals(
        classifyCi(
            [],
            [{
                id: 20,
                status: "in_progress",
                conclusion: null,
                app: { slug: "github-actions" },
            }],
            head,
            run.name,
        ).state,
        "missing",
    );
    assertEquals(
        classifyCi(
            [],
            [{
                id: 20,
                status: "completed",
                conclusion: "failure",
                app: { slug: "github-actions" },
            }],
            head,
            run.name,
        ).fault,
        "change",
    );
    assertEquals(
        classifyCi(
            [],
            [{
                id: 20,
                status: "completed",
                conclusion: "success",
                app: { slug: "github-actions" },
            }],
            head,
            run.name,
        ).fault,
        "configuration",
    );
});
Deno.test("CI projection retains only bounded public metadata", () => {
    const result = classifyCi(
        [{
            ...run,
            output: { summary: "do not persist raw output" },
            private: "discard",
        }],
        [],
        head,
        run.name,
    );
    assertEquals(result.runs, [{
        id: 10,
        name: run.name,
        head_sha: head,
        status: "completed",
        conclusion: "success",
        app_slug: "github-actions",
    }]);
});
