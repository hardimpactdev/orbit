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

for (const publication of ["new", "replacement"]) {
  Deno.test(`busy directory finishes its listing and protects a ${publication} run before pruning`, async () => {
    const root = await Deno.makeTempDir();
    const { catalog, id, runs } = await retentionFixture(root, false);
    const { DatabaseSync } = await import("node:sqlite");
    try {
      // More than one invocation's directory budget; new logs used to rewind it.
      for (let i = 120; i < 2200; i++) {
        await Deno.writeTextFile(
          join(runs, `workflow-run-${i}.yaml`),
          JSON.stringify({
            id: crypto.randomUUID(),
            status: "succeeded",
            completedAt: "2020-01-01T00:00:00Z",
          }),
        );
      }
      const removed: number[] = [];
      let published = false, finishedListing = false, done = false;
      for (let attempt = 0; attempt < 1000; attempt++) {
        let tick = 0;
        const result = await pruneOnline(root, {
          limit: 100,
          clock: () => tick++,
          budgetMs: 12,
          deleteVersion: (r) => {
            removed.push(r.version);
            catalog.prepare("DELETE FROM catalog WHERE id=? AND version=?").run(
              r.id,
              r.version,
            );
            return Promise.resolve(true);
          },
        });
        await Deno.writeTextFile(
          join(runs, `new-${attempt}.log`),
          "log output",
        );
        if (result.phase === "directories" && !finishedListing) {
          if (!published) {
            const db = new DatabaseSync(
              join(root, ".swamp/retention-planner.db"),
              { readOnly: true },
            );
            const cursor = db.prepare("SELECT cursor FROM dirs WHERE path=?")
              .get(runs)?.cursor;
            db.close();
            if (cursor) {
              // This name sorts before the cursor; a simple no-reset cursor misses it.
              const path = join(
                runs,
                publication === "new" ? "000-new.yaml" : "workflow-run-0.yaml",
              );
              const temporary = join(runs, "publish.tmp");
              await Deno.writeTextFile(
                temporary,
                JSON.stringify({
                  id: crypto.randomUUID(),
                  status: "running",
                  dataArtifacts: [{ dataId: id, name: "tick", version: 3 }],
                }),
              );
              await Deno.rename(temporary, path);
              published = true;
            }
          }
        }
        if (result.phase === "files") finishedListing = true;
        if (["history", "cleanup", "done"].includes(result.phase)) {
          done = true;
          break;
        }
      }
      assert(published);
      assert(
        finishedListing,
        "directory writes must not rewind an in-flight listing",
      );
      assert(
        done,
        "fresh invocations must reach pruning after refreshing changed records",
      );
      assertEquals(
        removed,
        [1],
        "both finished-child and newly active references survive",
      );
    } finally {
      catalog.close();
      await Deno.remove(root, { recursive: true });
    }
  });
}

for (const mode of ["pending", "relocated", "mixed"]) {
  const moveRows = mode === "relocated", mixed = mode === "mixed";
  Deno.test(`paged locators retain a promoted carrier (${mode})`, async () => {
    const { DatabaseSync } = await import("node:sqlite");
    const root = await Deno.makeTempDir();
    const { catalog, id, model, run } = await retentionFixture(root, false);
    try {
      await Deno.writeTextFile(
        join(root, ".swamp/data/nckrtl/orbit-group-run", model, "launch/1/raw"),
        JSON.stringify({
          runId: run,
          dataArtifacts: [{ name: "carrier", version: 1 }],
        }),
      );
      let promotedId = "";
      for (let i = 0; i < (mixed ? 2 : 102); i++) {
        const suffix = String(i).padStart(12, "0");
        const dataId = `70000000-0000-4000-8000-${suffix}`;
        const producer = `80000000-0000-4000-8000-${suffix}`;
        for (const version of [1, 2]) {
          catalog.prepare(
            "INSERT INTO catalog VALUES ('',?,'nckrtl/orbit-group-run',?,'carrier',?,?, '2020-01-01T00:00:00Z','tick','application/json',?,'active','{}')",
          )
            .run(
              dataId,
              producer,
              version,
              Number(version === 2),
              Number(version === 1 && (!mixed || i === 0)),
            );
          const dir = join(
            root,
            ".swamp/data/nckrtl/orbit-group-run",
            producer,
            "carrier",
            String(version),
          );
          await Deno.mkdir(dir, { recursive: true });
          await Deno.writeTextFile(
            join(dir, "raw"),
            JSON.stringify(
              version === 1 && i === (mixed ? 0 : 101)
                ? { dataArtifacts: [{ dataId: id, name: "tick", version: 3 }] }
                : {},
            ),
          );
        }
        if (!mixed || i === 0) promotedId = dataId;
      }
      const removed: number[] = [];
      let promoted = false, done = false;
      for (let attempt = 0; attempt < 1000; attempt++) {
        let tick = 0;
        const result = await pruneOnline(root, {
          limit: 100,
          budgetMs: promoted ? 20 : 2,
          clock: () => tick++,
          deleteVersion: (r) => {
            removed.push(r.version);
            catalog.prepare("DELETE FROM catalog WHERE id=? AND version=?").run(
              r.id,
              r.version,
            );
            return Promise.resolve(true);
          },
        });
        const db = new DatabaseSync(join(root, ".swamp/retention-planner.db"), {
          readOnly: true,
        });
        const carrier = db.prepare(
          mixed
            ? "SELECT ref FROM pins WHERE ref='carrier/1' AND cursor='' AND done=0"
            : "SELECT ref FROM pins WHERE ref='carrier/1' AND (cursor!='' OR done=1)",
        ).get();
        db.close();
        if (carrier && !promoted) {
          if (moveRows) catalog.exec("UPDATE catalog SET rowid=rowid+1000");
          catalog.prepare(
            "UPDATE catalog SET is_pending=0 WHERE id=? AND version=1",
          ).run(promotedId);
          promoted = true;
        }
        if (!result.partial) {
          done = true;
          break;
        }
      }
      assert(promoted);
      assert(done);
      assertEquals(
        removed,
        [1],
        "a later published carrier's transitive version must survive filtered-page pagination",
      );
    } finally {
      catalog.close();
      await Deno.remove(root, { recursive: true });
    }
  });
}

Deno.test("locator backfill reuses parsed history from an existing checkpoint", async () => {
  const { DatabaseSync } = await import("node:sqlite");
  const root = await Deno.makeTempDir();
  const { catalog, run, runs } = await retentionFixture(root, false);
  const originalRead = Deno.readTextFile;
  try {
    for (let attempt = 0; attempt < 1000; attempt++) {
      let tick = 0;
      const p = await pruneOnline(root, {
        limit: 1,
        budgetMs: 4,
        clock: () => tick++,
        deleteVersion: () => {
          throw Error("no deletion before graph");
        },
      });
      if (p.phase === "graph") break;
      assert(attempt < 999);
    }
    const checkpoint = new DatabaseSync(
      join(root, ".swamp/retention-planner.db"),
    );
    // Represent the deployed checkpoint that predates the locator table.
    checkpoint.exec(
      "DROP TABLE catalog_references;DELETE FROM state WHERE key='catalog-reference-schema'",
    );
    checkpoint.close();
    let historyReads = 0;
    Deno.readTextFile = (...args) => {
      if (String(args[0]).startsWith(runs + "/")) historyReads++;
      return originalRead(...args);
    };
    const removed: number[] = [];
    let done = false;
    for (let attempt = 0; attempt < 1000; attempt++) {
      let tick = 0;
      const p = await pruneOnline(root, {
        limit: 100,
        budgetMs: 20,
        clock: () => tick++,
        deleteVersion: (r) => {
          removed.push(r.version);
          catalog.prepare("DELETE FROM catalog WHERE id=? AND version=?").run(
            r.id,
            r.version,
          );
          return Promise.resolve(true);
        },
      });
      if (["history", "cleanup", "done"].includes(p.phase)) {
        done = true;
        break;
      }
    }
    assert(done);
    assertEquals(removed.sort(), [1, 3]);
    assertEquals(historyReads, 0);
    assert((await Deno.stat(join(runs, `workflow-run-${run}.yaml`))).isFile);
    assertEquals(
      catalog.prepare("PRAGMA index_list(catalog)").all(),
      [],
      "native catalog schema stays unchanged",
    );
  } finally {
    Deno.readTextFile = originalRead;
    catalog.close();
    await Deno.remove(root, { recursive: true });
  }
});
