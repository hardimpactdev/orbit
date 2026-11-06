import { recordQuery } from "./fixtures/worker_query.ts";
import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
    IndexSchema,
    type Pr,
    ReportSchema,
} from "../extensions/behaviors/_lib/dependency_results_schema.ts";
import { DependencyProjectSchema } from "../extensions/behaviors/_lib/dependency_project_schema.ts";
import {
    batchEvidence,
    BatchEvidenceSchema,
} from "../extensions/behaviors/_lib/service_detection.ts";
const A = "a".repeat(40), time = "2026-10-28T01:00:00.000Z";
const project = DependencyProjectSchema.parse({
    projectId: 20,
    repository: "https://github.com/fixture/dependency-app.git",
    defaultBranch: "main",
    check: "composer check",
    worker: {
        composerPaths: ["."],
        jsPaths: ["mobile"],
        jsPackageManagers: { mobile: "bun" },
        policy: {},
    },
});

for (
    const name of [
        "success",
        "running-partial",
        "failed",
        "blocked",
        "pr-unknown",
    ]
) {
    Deno.test(`producer schemas accept frozen canonical fixture ${name}`, async () => {
        const fixture = JSON.parse(
            await Deno.readTextFile(
                new URL(
                    `./fixtures/dependency-results-v1/${name}.json`,
                    import.meta.url,
                ),
            ),
        );
        for (const snapshot of fixture.snapshots) {
            IndexSchema.parse(
                JSON.parse(snapshot.index.response.payload.data.content),
            );
            for (const report of snapshot.reports) {
                ReportSchema.parse(
                    JSON.parse(report.response.payload.data.content),
                );
            }
        }
    });
}
Deno.test("PR lookup failure retains last success/value, missing group fields are unknown", async () => {
    const value = {
        repository: "github.com/fixture/dependency-app",
        number: 7,
        url: "https://github.com/fixture/dependency-app/pull/7",
        head_commit: A,
        group_id: 10,
        run_id: "batch",
    };
    const previous: Pr = {
        status: "open",
        checked_at: time,
        last_success_at: time,
        error_code: null,
        value,
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: recordQuery(() => Promise.resolve([])),
        writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
        invokeModel: () => Promise.resolve({ ok: true }),
    };
    const e = await batchEvidence(
        project,
        previous,
        ctx,
        [{
            id: 10,
            project_id: 20,
            status: "running",
            maintenance: { mode: "package", targets: [], run_id: "batch" },
        }],
    );
    assertEquals(e.pr.status, "unknown");
    assertEquals(e.pr.value, value);
    assertEquals(e.pr.last_success_at, time);
    const absence = await batchEvidence(
        project,
        previous,
        ctx,
        [],
    );
    assertEquals(absence.pr.status, "absent");
    assertEquals(absence.pr.value, null);
});
Deno.test("PR observation verifies exact URL/head/native run, including a still-open PR on a failed group", async () => {
    const url = "https://github.com/fixture/dependency-app/pull/17",
        calls: string[] = [];
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: recordQuery(() => Promise.resolve([])),
        writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
        invokeModel: () => Promise.resolve({ ok: true }),
    };
    const groups = [{
        id: 2195,
        project_id: 20,
        status: "failed",
        pr_url: url,
        watched_pr_url: url,
        maintenance: {
            mode: "package",
            targets: [],
            run_id: "native-batch-run",
        },
    }];
    const e = await batchEvidence(project, null, ctx, groups, (value) => {
        calls.push(value);
        return Promise.resolve({
            number: 17,
            url: value,
            state: "OPEN",
            headRefOid: A,
        });
    });
    assertEquals(e.pr.status, "open");
    assertEquals(e.pr.value!.head_commit, A);
    assertEquals(e.pr.value!.run_id, "native-batch-run");
    assertEquals(calls, [url]);
    const unknown = await batchEvidence(
        project,
        e.pr,
        ctx,
        groups,
        () => Promise.reject(new Error("not found")),
    );
    assertEquals(unknown.pr.status, "unknown");
    assertEquals(unknown.pr.value, e.pr.value);
    assertEquals(unknown.pr.last_success_at, e.pr.last_success_at);
});

Deno.test("cancelled PR observation starts no Gateway or GitHub lookup", async () => {
    let calls = 0;
    const ctx = {
        definition: { name: "orbit-gateway" },
        signal: AbortSignal.abort(),
        queryData: recordQuery(() => Promise.resolve([])),
        writeResource: () => Promise.resolve({ name: "unused" }),
        invokeModel: () => Promise.resolve({ ok: true }),
    };
    const evidence = await batchEvidence(project, null, ctx, [], () => {
        calls++;
        return Promise.resolve({});
    });
    assertEquals(calls, 0);
    assertEquals(evidence.pr.status, "unknown");
});

Deno.test("PR refresh projects real immutable batch targets into its publication contract", async () => {
    const target = {
        path: ".",
        manager: "js",
        package: "puppeteer",
        version: "25.15.0",
        purpose: "security",
        advisories: ["1243066"],
        advisoryUrls: ["https://github.com/advisories/GHSA-mrhx-c7xc-m3w5"],
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: recordQuery(() => Promise.resolve([])),
        writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
        invokeModel: () => Promise.resolve({ ok: true }),
    };
    const evidence = await batchEvidence(
        project,
        null,
        ctx,
        [{
            id: 2195,
            project_id: 20,
            status: "running",
            pr_url: null,
            watched_pr_url: null,
            maintenance: {
                mode: "package",
                targets: [target],
                run_id: "batch-run",
            },
        }],
    );
    assertEquals(evidence.pr.status, "absent");
    assertEquals(BatchEvidenceSchema.parse(evidence).links[0].targets, [{
        path: ".",
        manager: "js",
        package: "puppeteer",
        version: "25.15.0",
    }]);
    assertEquals(target.purpose, "security");
    assertEquals(target.advisories, ["1243066"]);
});
