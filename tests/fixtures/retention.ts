import { join } from "jsr:@std/path@1.1.4";
import { DatabaseSync } from "node:sqlite";

export async function retentionFixture(
  root: string,
  publishNewReceipt: boolean,
) {
  await Deno.mkdir(join(root, ".swamp/data"), { recursive: true });
  await Deno.writeTextFile(
    join(root, ".swamp.yaml"),
    "garbageCollection:\n  automatic: false\n",
  );
  const catalog = new DatabaseSync(join(root, ".swamp/data/_catalog.db"));
  catalog.exec(
    "CREATE TABLE catalog(namespace TEXT,id TEXT,type_normalized TEXT,model_id TEXT,data_name TEXT,version INTEGER,is_latest INTEGER,created_at TEXT,spec_name TEXT,content_type TEXT,is_pending INTEGER,lifecycle TEXT,tags TEXT)",
  );
  const id = "11111111-1111-4111-8111-111111111111",
    model = "22222222-2222-4222-8222-222222222222",
    run = "33333333-3333-4333-8333-333333333333";
  const insert = catalog.prepare(
    "INSERT INTO catalog VALUES ('',?,'nckrtl/orbit-group-run',?,?,?,?,'2020-01-01T00:00:00Z',?,'application/json',0,'active','{}')",
  );
  for (let v = 1; v <= 4; v++) {
    insert.run(id, model, "tick", v, Number(v === 4), "tick");
    const dir = join(
      root,
      ".swamp/data/nckrtl/orbit-group-run",
      model,
      "tick",
      String(v),
    );
    await Deno.mkdir(dir, { recursive: true });
    await Deno.writeTextFile(join(dir, "raw"), JSON.stringify({ v }));
  }
  insert.run(
    "44444444-4444-4444-8444-444444444444",
    model,
    "launch",
    1,
    1,
    "launch",
  );
  const launch = join(
    root,
    ".swamp/data/nckrtl/orbit-group-run",
    model,
    "launch/1",
  );
  await Deno.mkdir(launch, { recursive: true });
  await Deno.writeTextFile(
    join(launch, "raw"),
    JSON.stringify({ runId: run }),
  );
  const runs = join(root, ".swamp/workflow-runs/fixture");
  await Deno.mkdir(runs, { recursive: true });
  for (let i = 0; i < 120; i++) {
    await Deno.writeTextFile(
      join(runs, `workflow-run-${i}.yaml`),
      JSON.stringify({
        id: crypto.randomUUID(),
        status: "succeeded",
        completedAt: "2020-01-01T00:00:00Z",
      }),
    );
  }
  // The receipt names a finished child; that child pins an exact old version.
  await Deno.writeTextFile(
    join(runs, `workflow-run-${run}.yaml`),
    JSON.stringify({
      id: run,
      status: "succeeded",
      completedAt: "2020-01-01T00:00:00Z",
      dataArtifacts: [{ dataId: id, name: "tick", version: 2 }],
    }),
  );
  if (publishNewReceipt) {
    insert.run(
      "44444444-4444-4444-8444-444444444444",
      model,
      "launch",
      2,
      0,
      "launch",
    );
    catalog.prepare(
      "UPDATE catalog SET is_pending=1 WHERE data_name='launch' AND version=2",
    ).run();
    const dir = join(
      root,
      ".swamp/data/nckrtl/orbit-group-run",
      model,
      "launch/2",
    );
    await Deno.mkdir(dir, { recursive: true });
    await Deno.writeTextFile(
      join(dir, "raw"),
      JSON.stringify({
        dataArtifacts: [{ dataId: id, name: "tick", version: 3 }],
      }),
    );
  }
  return { catalog, id, model, run, runs };
}
