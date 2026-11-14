import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";
import {
    candidateResult,
    checkRuns,
    ciResult,
    diffRows,
    finish,
    localResult,
    minted,
    observed,
    prepare,
    setupResult,
} from "./check.ts";
const commit = "a".repeat(40), start = "2026-10-31T22:00:00Z";
const input = {
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    check_id: 5,
    checkout_path: "/srv/check",
    ssr_port: null,
    phase: "handoff" as "baseline" | "handoff",
    command: null,
    setup: [],
    deliverables: [],
    base: commit,
    message: "Check",
    repository: "acme/check",
    check_name: "Required checks",
    branch: "task-1-handoff",
};
const pending = {
    inputs: { operation_id: 7, node_id: 3, kind: "tasks.check", intent: input },
    root_run_id: "a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa",
    admitted_at: start,
    pushed_at: start,
    pending: true,
    result: {
        outcome: "passed",
        check_id: 5,
        commit,
        tree: "b".repeat(40),
        failed_step: null,
    },
};
const run = {
    id: 1,
    name: "Required checks",
    head_sha: commit,
    status: "completed",
    conclusion: "success",
    app: { slug: "github-actions" },
};
Deno.test("one pending observation remains pending and a later tick can finish the exact SHA", () => {
    const active = ciResult(pending, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(active.pending, true);
    const passed = ciResult(active, { runs: [run] }, "2026-10-31T22:02:00Z");
    assertEquals(passed.pending, false);
    assertEquals(passed.status, "succeeded");
    assertEquals(passed.result.ci.commit, commit);
});
Deno.test("a named code failure finishes as failed evidence while unreadable CI refuses", () => {
    const failed = ciResult(pending, {
        runs: [{ ...run, conclusion: "failure" }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(failed.pending, false);
    assertEquals(failed.result.outcome, "failed");
    assertEquals(failed.result.failed_step, "ci");
    const unavailable = ciResult(pending, {
        error_code: "tasks.handoff_ci_unavailable",
    }, "2026-10-31T22:01:00Z");
    assertEquals(unavailable.pending, false);
    assertEquals(unavailable.status, "failed");
});
Deno.test("missing CI and credential lifetime end through later scheduled observations", () => {
    assertEquals(
        ciResult(pending, { runs: [] }, "2026-10-31T22:01:00Z").pending,
        true,
    );
    assertEquals(
        ciResult(pending, { runs: [] }, "2026-10-31T22:11:00Z").pending,
        false,
    );
    const expired = ciResult(pending, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:56:00Z");
    assertEquals(expired.pending, false);
    assertEquals(expired.status, "failed");
});
Deno.test("active candidate jobs wait for the aggregate beyond the missing CI window", () => {
    for (const status of ["queued", "in_progress"]) {
        const job = {
            ...run,
            name: "Swamp / Swamp shape gate",
            status,
            conclusion: null,
        };
        const active = ciResult(
            pending,
            { runs: [job] },
            "2026-10-31T22:11:00Z",
        );
        assertEquals(active.pending, true);
        assertEquals(active.result.ci.state, "pending");
        const passed = ciResult(
            active,
            { runs: [run] },
            "2026-10-31T22:12:00Z",
        );
        assertEquals(passed.pending, false);
        assertEquals(passed.result.ci.state, "passed");
        for (
            const unrelated of [
                { ...job, head_sha: "b".repeat(40) },
                { ...job, app: { slug: "other-app" } },
            ]
        ) {
            const missing = ciResult(
                pending,
                { runs: [unrelated] },
                "2026-10-31T22:11:00Z",
            );
            assertEquals(missing.pending, false);
            assertEquals(
                missing.result.error_code,
                "tasks.handoff_ci_unavailable",
            );
        }
        const expired = ciResult(
            pending,
            { runs: [job] },
            "2026-10-31T22:56:00Z",
        );
        assertEquals(expired.pending, false);
        assertEquals(expired.result.error_code, "tasks.handoff_ci_timeout");
    }
});
Deno.test("a committed candidate must keep the checked tree and parent", () => {
    const tree = "b".repeat(40), head = "c".repeat(40);
    const local = { outcome: "passed", tree, head };
    const read = (lines: string[], exitCode = 0) => ({
        exitCode,
        stdout: lines.join("\n") + "\n",
    });
    assertEquals(
        candidateResult(input, local, read([commit, tree, head])).commit,
        commit,
    );
    for (
        const lines of [[commit, "d".repeat(40), head], [
            commit,
            tree,
            "d".repeat(40),
        ]]
    ) {
        assertEquals(
            candidateResult(input, local, read(lines)).outcome,
            "changed",
        );
    }
    // A clean checked tree made no commit: the checked head is the candidate.
    assertEquals(
        candidateResult(input, local, read([head, tree])).commit,
        head,
    );
    assertEquals(
        candidateResult(input, local, read(["d".repeat(40), tree])).outcome,
        "changed",
    );
    assertEquals(
        candidateResult(input, local, read([head, "d".repeat(40)])).outcome,
        "changed",
    );
    assertEquals(
        candidateResult(input, local, read([], 128)).error_code,
        "tasks.repository_execution_unknown",
    );
    // A commit that never ran cannot pass as a candidate.
    assertEquals(
        candidateResult(input, local, {}).error_code,
        "tasks.repository_execution_unknown",
    );
    assertEquals(
        candidateResult({ ...input, phase: "baseline" }, local, {}),
        local,
    );
});

Deno.test("the hosted CI window starts at push after long local execution", () => {
    const longLocal = {
        ...pending,
        admitted_at: "2026-10-31T20:00:00Z",
        pushed_at: "2026-10-31T22:00:00Z",
    };
    const value = ciResult(longLocal, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(value.pending, true);
});

Deno.test("missing setup output is a failed preparation", () => {
    const setup = setupResult({
        ...input,
        phase: "baseline",
        setup: [{ name: "prepare", command: "true", timeout_seconds: 30 }],
    }, {});
    assertEquals(setup.passed, false);
    assertEquals(setup.exit_code, 255);
});

const ran = (exitCode: number, stdout = "", stderr = "") => ({
    status: "succeeded",
    outputs: { exitCode, stdout, stderr },
});
const snapshots = (tree = commit) => ({
    original: ran(0, commit + "\n"),
    "snapshot-before-tree": ran(0, tree + "\n"),
    "snapshot-before-head": ran(0, commit + "\n"),
    "snapshot-after-tree": ran(0, tree + "\n"),
    "snapshot-after-head": ran(0, commit + "\n"),
});
const valid = { valid: true, error_code: "" };
/** A private worker directory holding the downloaded output file of each named step. */
const outputs = (files: Record<string, string> = {}) => {
    const directory = Deno.makeTempDirSync();
    for (const [name, text] of Object.entries(files)) {
        Deno.writeTextFileSync(`${directory}/${name}`, text);
    }
    return directory;
};
const commandDeliverable = {
    id: "project-check",
    type: "command" as const,
    path: "",
    change: "any",
    command: "touch never",
    directory: ".",
    fails_on_base: false,
    paths: [],
};

Deno.test("a failed handoff preserves the admitted base for its retry", async () => {
    const base = "b".repeat(40);
    const value = await localResult(
        { ...input, base, command: "exit 1" },
        valid,
        { ...snapshots(), project: ran(1), changed: ran(0) },
        outputs({ project: "failed" }),
    );
    if (!("outcome" in value)) throw new Error("Expected local evidence");
    assertEquals(value.outcome, "failed");
    assertEquals(value.head, commit);
    assertEquals(value.subtask_start_commit, base);
});

Deno.test("a failed Project check retains failed evidence when command deliverables are skipped", async () => {
    const value = await localResult(
        {
            ...input,
            phase: "handoff",
            command: "exit 23",
            deliverables: [commandDeliverable],
        },
        valid,
        {
            ...snapshots(),
            project: ran(23),
            "deliverable-0": { status: "skipped" },
            diff: ran(0, ""),
            changed: ran(0, ""),
        },
        outputs({ project: "project check failed" }),
    );
    if (!("outcome" in value)) {
        throw new Error("Expected public local check evidence");
    }
    assertEquals(value.outcome, "failed");
    assertEquals(value.exit_code, 23);
    assertEquals(value.output, "project check failed");
    assertEquals(value.evidence?.commands, {});
    assertEquals(value.deliverables[0].outcome, "failed");
    assertEquals(value.commit, null);
});

Deno.test("command output is the redacted tail of its downloaded file, which is deleted", async () => {
    const directory = outputs({
        project: "API_KEY=synthetic-private-value\n" + "f0".repeat(32) +
            "\ndone\n",
    });
    const value = await localResult({ ...input, command: "true" }, valid, {
        ...snapshots(),
        project: ran(0),
    }, directory);
    if (!("output" in value)) throw new Error("Expected local evidence");
    assertEquals(value.output, "API_KEY=[REDACTED]\n[REDACTED]\ndone\n");
    assertEquals([...Deno.readDirSync(directory)], []);
});

Deno.test("a command that ran without a downloaded output file refuses the check", async () => {
    assertEquals(
        await localResult({ ...input, command: "true" }, valid, {
            ...snapshots(),
            project: ran(0),
        }, outputs()),
        { error_code: "tasks.repository_execution_unknown", check_id: 5 },
    );
});

Deno.test("the checked diff and changed paths come from NUL-separated diff-tree rows", async () => {
    assertEquals(diffRows(ran(0, "A\0added\0M\0a b\tc\0").outputs), [
        { status: "A", path: "added" },
        { status: "M", path: "a b\tc" },
    ]);
    assertEquals(diffRows({}), null);
    const value = await localResult(
        { ...input, deliverables: [{ ...commandDeliverable, type: "file" }] },
        valid,
        {
            ...snapshots(),
            project: ran(0),
            diff: ran(0, "M\0code\0"),
            changed: ran(0, "A\0new\0"),
        },
        outputs({ project: "" }),
    );
    if (!("changed_paths" in value)) throw new Error("Expected evidence");
    assertEquals(value.changed_paths, ["new"]);
    assertEquals(value.evidence?.diff, [{ status: "M", path: "code" }]);
});

Deno.test("start-commit preparation failures refuse the check", async () => {
    const base = { ...commandDeliverable, fails_on_base: true, paths: ["t"] };
    const intent = { ...input, deliverables: [base] };
    assertEquals(
        (await localResult(intent, valid, {
            ...snapshots(),
            "base_archive-0": { status: "failed" },
        }, outputs())).error_code,
        "tasks.repository_execution_unknown",
    );
    for (const name of ["base_overlay-0", "base_directory-0"]) {
        assertEquals(
            (await localResult(intent, valid, {
                ...snapshots(),
                [name]: ran(1),
            }, outputs())).error_code,
            "tasks.deliverable_invalid",
        );
    }
    assertEquals(
        await localResult(
            intent,
            {
                valid: false,
                error_code: "tasks.deliverable_invalid",
            },
            {},
            outputs(),
        ),
        { error_code: "tasks.deliverable_invalid", check_id: 5 },
    );
});

Deno.test("a fails_on_base command records its start-commit run", async () => {
    const base = { ...commandDeliverable, fails_on_base: true, paths: ["t"] };
    const value = await localResult(
        { ...input, command: "true", deliverables: [base] },
        valid,
        {
            ...snapshots(),
            project: ran(0),
            "base_overlay-0": ran(0),
            "base_directory-0": ran(0),
            "base-0": ran(1),
            "deliverable-0": ran(0),
            diff: ran(0, ""),
            changed: ran(0, ""),
        },
        outputs({
            project: "",
            "base-0": "fails on base",
            "deliverable-0": "",
        }),
    );
    if (!("evidence" in value)) throw new Error("Expected evidence");
    assertEquals(value.evidence?.commands["project-check"], {
        exit_code: 0,
        output: "",
        base_started: true,
        base_exit_code: 1,
        base_output: "fails on base",
    });
    assertEquals(value.outcome, "passed");
});

const intent = {
    ...input,
    ssr_port: 5173,
    setup: [
        { name: "caches", command: "@orbit/ci-caches", timeout_seconds: 30 },
    ],
    command: "composer test",
    deliverables: [
        { ...commandDeliverable, directory: "apps/web" },
        {
            ...commandDeliverable,
            id: "base",
            fails_on_base: true,
            paths: ["tests/a.php", "tests/b.php"],
        },
    ],
    ssh_execution: { target: { user: "orbit" } },
};
const environment = [
    "COMPOSER_NO_INTERACTION=1",
    "ORBIT_CHECK_PROCESSES=1",
    "COMPOSER_CACHE_DIR=/home/orbit/.cache/composer",
    "ORBIT_SEED_PATH=",
    "ORBIT_SEED_COMMIT=",
    "INERTIA_SSR_PORT=5173",
    "INERTIA_SSR_URL=http://127.0.0.1:5173",
];
const argv = (name: string, directory: string, command: string) => [
    "/bin/bash",
    "-c",
    'mkdir -p -m 0700 -- "$1" && exec >"$1/$2" 2>&1 && shift 2 && exec "$@"',
    "orbit-output",
    "/tmp/run/output",
    name,
    "env",
    "-C",
    directory,
    ...environment,
    "/bin/bash",
    "-lc",
    command,
];

Deno.test("every check command is one SSH exec argv in its directory, its output in a file", () => {
    const steps = prepare(intent, 3, "/tmp/run");
    assertEquals(steps.valid, true);
    // The Gateway-owned setup step expands to the shared provision program.
    assertEquals(steps.setup, [
        argv("setup-0", "/srv/check", ciCacheImportProgram),
    ]);
    assertEquals(steps.project, argv("project", "/srv/check", "composer test"));
    assertEquals(steps.deliverables, [
        argv("deliverable-0", "/srv/check/apps/web", "touch never"),
        argv("deliverable-1", "/srv/check", "touch never"),
    ]);
    assertEquals(steps.bases[1], {
        archive: [
            "git",
            "-C",
            "/srv/check",
            "archive",
            "--format=tar",
            "--prefix=base-1/",
            "--output=/tmp/run/base-1.tar",
            commit,
        ],
        extract: ["tar", "-x", "-f", "/tmp/run/base-1.tar", "-C", "/tmp/run"],
        dependencies: [
            "env",
            "-C",
            "/srv/check",
            "find",
            ".",
            "-name",
            ".git",
            "-prune",
            "-o",
            "(",
            "-name",
            "vendor",
            "-o",
            "-name",
            "node_modules",
            ")",
            "-type",
            "d",
            "-prune",
            "-exec",
            "cp",
            "-a",
            "--parents",
            "-t",
            "/tmp/run/base-1",
            "{}",
            "+",
        ],
        overlay: [
            "env",
            "-C",
            "/srv/check",
            "cp",
            "-p",
            "--parents",
            "-t",
            "/tmp/run/base-1",
            "--",
            "tests/a.php",
            "tests/b.php",
        ],
        directory: ["test", "-d", "/tmp/run/base-1"],
        run: argv("base-1", "/tmp/run/base-1", "touch never"),
    });
    assertEquals(steps.bases[0].overlay, ["true"]);
});

Deno.test("deliverable paths are checked on 209 before any command", () => {
    for (
        const deliverable of [
            { ...commandDeliverable, directory: "../outside" },
            { ...commandDeliverable, directory: "/srv" },
            { ...commandDeliverable, paths: ["../code"] },
            { ...commandDeliverable, paths: ["/etc/passwd"] },
            { ...commandDeliverable, paths: ["tests/./a.php"] },
            { ...commandDeliverable, paths: ["tests//a.php"] },
        ]
    ) {
        const steps = prepare(
            { ...intent, deliverables: [deliverable] },
            3,
            "/tmp/run",
        );
        assertEquals(
            [steps.valid, steps.error_code],
            [false, "tasks.deliverable_invalid"],
        );
    }
    // A start-commit run needs a commit to archive.
    assertEquals(
        prepare(
            {
                ...intent,
                base: "",
                deliverables: [{ ...commandDeliverable, fails_on_base: true }],
            },
            3,
            "/tmp/run",
        ).valid,
        false,
    );
    assertThrows(() => prepare(intent, 4, "/tmp/run"));
});

Deno.test("terminal CI names the candidate only while the workspace still holds it", () => {
    const value = {
        pending: false,
        status: "succeeded",
        result: { outcome: "passed", commit, tree: "b".repeat(40) },
    };
    const snapshot = (head: string, tree: string) => ({
        "snapshot-7-tree": ran(0, tree + "\n"),
        "snapshot-7-head": ran(0, head + "\n"),
    });
    assertEquals(
        observed(value, snapshot(commit, "b".repeat(40)), "7"),
        value,
    );
    assertEquals(
        observed(value, snapshot(commit, "c".repeat(40)), "7").result,
        { ...value.result, outcome: "changed" },
    );
    const missing = observed(value, {}, "7");
    assertEquals(missing.status, "failed");
    assertEquals(
        (missing.result as Record<string, unknown>).error_code,
        "tasks.check_execution_unknown",
    );
});

const shell = (exitCode: number, stdout = "", stderr = "") => ({
    exitCode,
    stdout,
    stderr,
});
const references = {
    github_token: { vault: "orbit-control-inputs", key: "task-check-write-a" },
    checks_token: { vault: "orbit-control-inputs", key: "task-check-read-a" },
};
Deno.test("the mint reply must name exactly the admitted vault descriptors", () => {
    const expected = JSON.stringify(references);
    const reply = (secrets: unknown) =>
        JSON.stringify({ data: { execution_secrets: secrets } });
    assertEquals(
        minted(
            shell(
                0,
                reply({
                    checks_token: references.checks_token,
                    github_token: references.github_token,
                }),
            ),
            expected,
        ),
        { minted: true, error_code: "" },
    );
    for (
        const item of [
            shell(0, reply({ github_token: references.github_token })),
            shell(22, ""),
            shell(0, "not json"),
        ]
    ) {
        assertEquals(minted(item, expected), {
            minted: false,
            error_code: "tasks.handoff_ci_unavailable",
        });
    }
    assertEquals(minted({}, expected), { minted: false, error_code: "" });
});
Deno.test("one Checks read keeps public run fields and refuses an incomplete page", () => {
    const reply = {
        total_count: 1,
        check_runs: [{ ...run, output: { summary: "synthetic-private" } }],
    };
    const read = checkRuns(shell(0, JSON.stringify(reply)));
    assertEquals(read, { runs: [run] });
    assertEquals(JSON.stringify(read).includes("synthetic-private"), false);
    for (
        const item of [
            shell(0, JSON.stringify({ ...reply, total_count: 101 })),
            shell(0, JSON.stringify({ ...reply, total_count: 2 })),
            shell(1, JSON.stringify(reply)),
        ]
    ) {
        assertEquals(checkRuns(item), {
            error_code: "tasks.handoff_ci_unavailable",
        });
    }
});
Deno.test("handoff admission maps the mint, push and import outputs to one public code", () => {
    const local = { outcome: "passed", commit, tree: "b".repeat(40) };
    const steps = (push: unknown) => ({
        import: { outputs: shell(0, `${commit}\n${"b".repeat(40)}\n`) },
        mint_github: {
            outputs: shell(
                0,
                JSON.stringify({ data: { execution_secrets: references } }),
            ),
        },
        push: { outputs: push },
    });
    const args = (push: unknown) => ({
        intent: {
            ...input,
            execution_references_json: JSON.stringify(references),
        },
        local,
        steps: steps(push),
    });
    const ok = finish(args(shell(0)));
    assertEquals([ok.pending, ok.minted], [true, true]);
    assertEquals(
        finish(args(shell(1, "", "! [rejected] (fetch first)"))).result
            .error_code,
        "tasks.not_fast_forward",
    );
    assertEquals(
        finish(args(shell(1, "", "fatal: unable to access"))).result.error_code,
        "tasks.push_failed",
    );
});

Deno.test("a mutating check cannot create a candidate", async () => {
    const value = await localResult(
        { ...input, command: "printf moved > code" },
        valid,
        {
            ...snapshots(),
            "snapshot-after-tree": ran(0, "b".repeat(40) + "\n"),
            project: ran(0),
        },
        outputs({ project: "" }),
    );
    if (!("outcome" in value)) throw new Error("Expected local check evidence");
    assertEquals(value.outcome, "changed");
    assertEquals(value.commit, null);
    assertEquals(candidateResult(input, value, {}).commit, null);
});
