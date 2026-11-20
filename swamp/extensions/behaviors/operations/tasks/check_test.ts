import { assertEquals } from "jsr:@std/assert@1";
import { classifyCi, deliverableResults } from "./check.ts";

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

const sha = "c".repeat(40);
const intent = {
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    check_id: 5,
    checkout_path: "/srv/check",
    ssr_port: null,
    phase: "handoff" as const,
    command: "bin/check",
    setup: [],
    deliverables: [
        {
            id: "tests",
            type: "command" as const,
            path: "",
            change: "any",
            command: "bin/test",
            directory: ".",
            fails_on_base: true,
            paths: [],
        },
        {
            id: "lint",
            type: "command" as const,
            path: "",
            change: "any",
            command: "bin/lint",
            directory: ".",
            fails_on_base: false,
            paths: [],
        },
    ],
    base: sha,
    message: "Check",
    repository: null,
    check_name: null,
    branch: "task-1-handoff",
    handoff_gate: false,
};
const local = {
    outcome: "passed" as const,
    exit_code: 0,
    output: "",
    head_before: sha,
    tree_before: sha,
    head: sha,
    tree: sha,
    changed_paths: [],
    failed_step: null,
    evidence: { start: sha, diff: [], commands: {} },
    subtask_start_commit: sha,
};
Deno.test("a command deliverable without a run record is not_run with the cause the run knows", () => {
    const project = deliverableResults(intent, {
        ...local,
        outcome: "failed",
        exit_code: 1,
    });
    assertEquals(project, [
        {
            id: "tests",
            outcome: "not_run",
            failures: [
                "tests: the command did not run: the Project check failed first.",
            ],
        },
        {
            id: "lint",
            outcome: "not_run",
            failures: [
                "lint: the command did not run: the Project check failed first.",
            ],
        },
    ]);
    assertEquals(
        deliverableResults(intent, {
            ...local,
            outcome: "failed",
            exit_code: 2,
            failed_step: "install",
        })[1].failures,
        ['lint: the command did not run: the step "install" failed first.'],
    );
    assertEquals(
        deliverableResults(intent, { ...local, evidence: null })[1],
        {
            id: "lint",
            outcome: "not_run",
            failures: [
                "lint: the command did not run: no evidence was recorded.",
            ],
        },
    );
});
Deno.test("a command deliverable that ran and failed stays failed, with its start-commit line", () => {
    assertEquals(
        deliverableResults(intent, {
            ...local,
            evidence: {
                start: sha,
                diff: [],
                commands: {
                    tests: { exit_code: 1, output: "", base_started: false },
                    lint: { exit_code: 0, output: "" },
                },
            },
        }),
        [
            {
                id: "tests",
                outcome: "failed",
                failures: [
                    "tests: the required command did not pass.",
                    "tests: the command did not reproduce the failure on the start commit.",
                ],
            },
            { id: "lint", outcome: "passed", failures: [] },
        ],
    );
});
