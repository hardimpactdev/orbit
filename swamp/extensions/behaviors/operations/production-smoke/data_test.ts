import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior, classify, summarize } from "./data.ts";

Deno.test("changed paths cover both roots, deleted family files and shared execution", () => {
    for (
        const path of [
            "extensions/behaviors/operations/routes/publication.ts",
            "swamp/extensions/behaviors/operations/_lib/direct.ts",
            "swamp/extensions/behaviors/production-smoke/mod.ts",
            "swamp/workflows/workflow-operation-instance-deploy-direct.yaml",
            "apps/gateway/app/Domain/Certificates/CertificateProjection.php",
            "apps/gateway/app/Console/Commands/GatewayRelease.php",
            "swamp/workflows/workflow-automatic-production-deploy.yaml",
            "swamp/bin/native-host-start.py",
            "bin/web-deploy",
            ".github/workflows/orbit-cli-release.yml",
            "apps/gateway/app/Infrastructure/Caddy/RemovedFile.php",
            "apps/gateway/app/Domain/Schedules/ScheduleRuntime.php",
            "apps/gateway/app/Domain/Processes/ProcessIntent.php",
            "apps/gateway/app/Domain/DatabaseServers/DatabaseDriver.php",
            "apps/gateway/database/migrations/2026_change.php",
            "swamp/config/services-workers.json",
        ]
    ) assertEquals(classify([path]), { required: true, matched_paths: [path] });
    assertEquals(
        classify(["docs/reference/apps.md", "swamp/docs/swamp-shape.md"]),
        {
            required: false,
            matched_paths: [],
        },
    );
});
Deno.test("a failing site does not hide passed or unknown site results", () => {
    const sites = [
        { instance_id: 1, stdout: '{"instance_id":1,"state":"passed"}' },
        { instance_id: 2, stdout: '{"instance_id":2,"state":"failed"}' },
        { instance_id: 3, stdout: "invalid or missing evidence" },
    ];
    assertEquals(
        summarize({ required: true, sites }).sites.map((site) => site.state),
        [
            "passed",
            "failed",
            "unknown",
        ],
    );
    assertEquals(summarize({ required: true, sites }).state, "failed");
    assertEquals(summarize({ required: false, sites }).state, "skipped");
    assertEquals(summarize({ required: true, sites: [] }).state, "unknown");
    assertEquals(
        summarize({
            required: true,
            sites: [{
                instance_id: 1,
                stdout: '{"instance_id":2,"state":"failed"}',
            }],
        }).state,
        "unknown",
    );
});
Deno.test("typed outputs are persisted for native downstream expressions", async () => {
    const writes: unknown[] = [];
    const ctx = {
        writeResource: (...args: unknown[]) => {
            writes.push(args);
            return Promise.resolve();
        },
    };
    await behavior.methods.classify.execute(
        { paths: ["swamp/config/a.json"] },
        ctx,
    );
    const result = await behavior.methods.summarize.execute({
        required: true,
        sites: [],
    }, ctx);
    assertEquals(writes.length, 2);
    assertEquals(JSON.parse(result.json).state, "unknown");
});
