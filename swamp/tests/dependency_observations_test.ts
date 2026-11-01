import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { refreshMethod } from "../extensions/behaviors/dependency_scanner.ts";
import {
    indexName,
    IndexSchema,
} from "../extensions/behaviors/_lib/dependency_results_schema.ts";
import { fixture, original } from "./_support/project_scheduler.ts";

Deno.test("native refresh uses supplied observations and refuses a superseded publication", async () => {
    const f = fixture();
    try {
        const index = IndexSchema.parse(
            JSON.parse(
                await Deno.readTextFile(
                    new URL(
                        "./fixtures/dependency-read-token-v1/failed-scan-index.json",
                        import.meta.url,
                    ),
                ),
            ),
        );
        const key = "orbit-gateway/" + await indexName(20, index.source_ref);
        f.data.set(key, index);
        const now = new Date().toISOString();
        const evidence = {
            pr: {
                status: "absent" as const,
                checked_at: now,
                last_success_at: now,
                error_code: null,
                value: null,
            },
            links: [],
        };
        await refreshMethod.execute({
            rows: [{ project: original, index, evidence }],
        }, f.ctx);
        const current = IndexSchema.parse(f.data.get(key));
        assertEquals(current.batch_pr.status, "absent");
        assert(current.batch_pr.checked_at > index.batch_pr.checked_at);
        let writes = 0;
        await refreshMethod.execute({
            rows: [{
                project: original,
                index,
                evidence: {
                    ...evidence,
                    pr: {
                        ...evidence.pr,
                        status: "unknown",
                        error_code: "batch_pr_lookup_failed",
                    },
                },
            }],
        }, {
            ...f.ctx,
            writeResource: (...args) => {
                writes++;
                return f.ctx.writeResource(args[0], args[1], args[2]);
            },
        });
        assertEquals(writes, 0);
        assertEquals(f.data.get(key), current);
        assertEquals(f.posts(), 0);
        assertEquals(f.starts(), 0);
    } finally {
        await f.close();
    }
});
