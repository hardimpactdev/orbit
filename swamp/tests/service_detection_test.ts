import { recordQuery } from "./fixtures/worker_query.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { command } from "../extensions/behaviors/_lib/maintenance/io.ts";
import { batchEvidence } from "../extensions/behaviors/_lib/service_detection.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";

Deno.test("unavailable native PR observations retain the last good publication", async () => {
    const url = "https://github.com/nckrtl/website/pull/17",
        at = new Date().toISOString();
    const previous = {
        status: "open" as const,
        checked_at: at,
        last_success_at: at,
        error_code: null,
        value: {
            repository: "github.com/nckrtl/website",
            number: 17,
            url,
            head_commit: "a".repeat(40),
            group_id: 2195,
            run_id: "batch-run",
        },
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: recordQuery((q: string) =>
            Promise.resolve(
                q.includes('startsWith("package-')
                    ? [{ workItem: "tg-2195", targets: [] }]
                    : q.includes('name == "owner"')
                    ? [{ runId: "batch-run" }]
                    : [],
            )
        ),
        writeResource: () => Promise.resolve({ name: "unused" }),
    };
    let observations = 0;
    const evidence = await batchEvidence(WEBSITE_PROJECT, previous, ctx, [{
        project_id: 20,
        id: 2195,
        status: "running",
        pr_url: url,
        watched_pr_url: url,
        maintenance: { mode: "package", targets: [], run_id: "batch-run" },
    }], () => {
        observations++;
        return Promise.reject(new Error("private diagnostic must not escape"));
    });
    assertEquals(observations, 1);
    assertEquals(evidence.pr.status, "unknown");
    assertEquals(evidence.pr.error_code, "batch_pr_lookup_failed");
    assertEquals(evidence.pr.value, previous.value);
    assertEquals(evidence.pr.last_success_at, previous.last_success_at);
});

Deno.test("cancellation kills a tool's descendants and closes inherited output pipes", async () => {
    const start = Date.now();
    const result = await command(Deno.cwd(), "python3", [
        "-c",
        "import subprocess,time; subprocess.Popen(['sleep','30']); time.sleep(30)",
    ], AbortSignal.timeout(100));
    assert(result.code !== 0);
    assert(
        Date.now() - start < 1500,
        "descendant stdout must not hold the scan open",
    );
});

Deno.test("captured maintenance publishes exact PR and target links without retired factory records", async () => {
    const url = "https://github.com/nckrtl/website/pull/18";
    const target = {
        path: ".",
        manager: "composer",
        package: "acme/lib",
        version: "2.0.0",
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: () => {
            throw new Error("retired factory read");
        },
        writeResource: () => Promise.resolve({ name: "unused" }),
    };
    const evidence = await batchEvidence(
        WEBSITE_PROJECT,
        null,
        ctx,
        [{
            project_id: 20,
            id: 2195,
            status: "waiting_for_review",
            pr_url: url,
            watched_pr_url: url,
            maintenance: {
                mode: "package",
                targets: [target],
                run_id: "maintenance-native-root",
            },
        }],
        () =>
            Promise.resolve({
                url,
                number: 18,
                state: "OPEN",
                headRefOid: "a".repeat(40),
            }),
    );
    assertEquals(evidence.pr.status, "open");
    assertEquals(evidence.pr.value?.run_id, "maintenance-native-root");
    assertEquals(evidence.links, [{
        group_id: 2195,
        run_id: "maintenance-native-root",
        targets: [target],
    }]);
});
