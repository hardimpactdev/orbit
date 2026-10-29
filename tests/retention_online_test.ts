import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { join } from "jsr:@std/path@1.1.4";
import { retentionFixture } from "./fixtures/retention.ts";
import { pruneOnline } from "../bin/retention-online.ts";

for (const publishNewReceipt of [false, true]) {
  Deno.test(`fixed budgets resume every phase and protect ${publishNewReceipt ? "newly published" : "transitive"} receipt evidence`, async () => {
    const root = await Deno.makeTempDir();
    try {
      const { catalog, run, runs } = await retentionFixture(
        root,
        publishNewReceipt,
      );
      const removed: number[] = [], phases = new Set<string>();
      let done = false;
      for (let attempt = 0; attempt < 1000; attempt++) {
        let tick = 0;
        const result = await pruneOnline(root, {
          limit: 1,
          clock: () => tick++,
          budgetMs: 20,
          deleteVersion: (r) => {
            removed.push(r.version);
            if (publishNewReceipt && removed.length === 1) {
              catalog.prepare(
                "UPDATE catalog SET is_latest=0 WHERE data_name='launch'",
              ).run();
              // Publication promotes an existing row without advancing rowid.
              catalog.prepare(
                "UPDATE catalog SET is_pending=0,is_latest=1 WHERE data_name='launch' AND version=2",
              ).run();
            }
            catalog.prepare("DELETE FROM catalog WHERE id=? AND version=?").run(
              r.id,
              r.version,
            );
            return Promise.resolve(true);
          },
        });
        phases.add(result.phase);
        if (!result.partial) {
          done = true;
          break;
        }
      }
      assert(done, "fixed budgets must make progress across every phase");
      assert(phases.has("files"));
      assert(phases.has("graph"));
      assertEquals(removed.sort(), publishNewReceipt ? [1] : [1, 3]);
      assert(
        (await Deno.stat(join(runs, `workflow-run-${run}.yaml`))).isFile,
        "receipt-pinned child survives",
      );
      catalog.close();
    } finally {
      await Deno.remove(root, { recursive: true });
    }
  });
}
