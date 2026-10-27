import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { detect, gitCommand, manifest, stableTags } from "./_lib/detect.ts";
import {
  actions,
  ConfigKeys,
  diffConfig,
  diffText,
  routes,
} from "./_lib/surface.ts";
import { baselinePlan, taskPlan } from "./_lib/tasks.ts";
import { project } from "./config.ts";

async function fixture(run: (cwd: string, remote: string) => Promise<void>) {
  const root = await Deno.makeTempDir({ prefix: "horizon-fixture-" });
  const cwd = `${root}/project`, remote = `${root}/Horizon with spaces`;
  await Deno.mkdir(cwd);
  await Deno.mkdir(remote);
  await git(remote, "init");
  try {
    await run(cwd, remote);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}
async function git(cwd: string, ...args: string[]) {
  const r = await gitCommand()(["-C", cwd, ...args]);
  assertEquals(r.code, 0, r.error);
  return r.text;
}
async function snapshot(remote: string, tag: string, source: string) {
  for await (const entry of Deno.readDir(remote)) {
    if (entry.name !== ".git") {
      await Deno.remove(`${remote}/${entry.name}`, { recursive: true });
    }
  }
  const copy = async (from: string, to: string) => {
    await Deno.mkdir(to, { recursive: true });
    for await (const entry of Deno.readDir(from)) {
      if (entry.isDirectory) {
        await copy(`${from}/${entry.name}`, `${to}/${entry.name}`);
      } else {await Deno.copyFile(
          `${from}/${entry.name}`,
          `${to}/${entry.name.replace(/\.snapshot$/, "")}`,
        );}
    }
  };
  await copy(new URL(`./fixtures/${source}`, import.meta.url).pathname, remote);
  await git(remote, "add", "-A");
  await git(
    remote,
    "-c",
    "user.name=Fixture",
    "-c",
    "user.email=fixture@example.test",
    "-c",
    "commit.gpgsign=false",
    "commit",
    "--allow-empty",
    "-m",
    tag,
  );
  await git(remote, "tag", tag);
}
const ledger = (cwd: string, tag: string) =>
  Deno.writeTextFile(
    `${cwd}/${project.ledger}`,
    JSON.stringify({ synced_tag: tag }),
  );
const feature = (
  tag: string,
  surface: "route" | "controller" | "screen" | "config",
  change: "add" | "modify" | "delete",
  id: string,
) => ({
  kind: "feature" as const,
  tag,
  surface,
  change,
  id,
});
const release = (tag: string) => ({
  kind: "release" as const,
  tag,
  surface: null,
  change: null,
  id: tag,
});
const pairs = [
  {
    name: "silenced",
    from: "v5.11.0",
    to: "v5.12.0",
    rows: [
      feature("v5.12.0", "route", "add", "GET horizon.silenced-jobs.index"),
      feature("v5.12.0", "controller", "add", "SilencedJobsController@index"),
      feature(
        "v5.12.0",
        "screen",
        "modify",
        "resources/js/screens/recentJobs/index.vue",
      ),
      feature(
        "v5.12.0",
        "screen",
        "modify",
        "resources/js/screens/recentJobs/job-row.vue",
      ),
      feature(
        "v5.12.0",
        "screen",
        "modify",
        "resources/js/screens/recentJobs/job.vue",
      ),
      feature("v5.12.0", "config", "add", "silenced"),
    ],
  },
  {
    name: "watch",
    from: "v5.42.0",
    to: "v5.43.0",
    rows: [feature("v5.43.0", "config", "add", "watch")],
  },
  {
    name: "screens",
    from: "v5.49.0",
    to: "v5.50.0",
    rows: [
      feature(
        "v5.50.0",
        "screen",
        "modify",
        "resources/js/screens/recentJobs/job-row.vue",
      ),
      feature(
        "v5.50.0",
        "screen",
        "modify",
        "resources/js/screens/recentJobs/job.vue",
      ),
    ],
  },
  {
    name: "release",
    from: "v5.50.0",
    to: "v5.50.1",
    rows: [release("v5.50.1")],
  },
];
for (const pair of pairs) {
  Deno.test(`exact script output: ${pair.from} → ${pair.to}`, () =>
    fixture(async (cwd, remote) => {
      await snapshot(remote, pair.from, `${pair.name}/${pair.from}`);
      await snapshot(remote, pair.to, `${pair.name}/${pair.to}`);
      await ledger(cwd, pair.from);
      const before = await Deno.readTextFile(`${cwd}/${project.ledger}`);
      const result = await detect(cwd, remote);
      assertEquals(result, {
        schema: 1,
        synced_tag: pair.from,
        tag: pair.to,
        candidates: pair.rows,
      });
      assertEquals(await Deno.readTextFile(`${cwd}/${project.ledger}`), before);
      const plan = taskPlan(result, project);
      assertEquals(plan.tasks.length, pair.rows.length);
      assertEquals(
        new Set(plan.tasks.map((t) => t.key)).size,
        plan.tasks.length,
      );
      plan.tasks.forEach((task, i) => {
        assertEquals(
          task.brief.includes(JSON.stringify(result.candidates[i])),
          true,
        );
        assertEquals(task.brief.includes("Never merge."), true);
        assertEquals(
          task.brief.includes("then set synced_tag"),
          i === plan.tasks.length - 1,
        );
        assertEquals(task.deliverables.map((d) => d.command), [
          "composer test:compatibility",
          "composer quality",
        ]);
      });
      await ledger(cwd, pair.to);
      assertEquals(await detect(cwd, remote), {
        schema: 1,
        synced_tag: pair.to,
        tag: null,
        candidates: [],
      });
    }));
}
Deno.test("detects only the next stable tag, ignores prereleases and deduplicates annotated tags", () =>
  fixture(async (cwd, remote) => {
    await snapshot(remote, "v5.48.3", "screens/v5.49.0");
    await snapshot(remote, "v5.49.0", "screens/v5.49.0");
    await snapshot(remote, "v5.50.0", "screens/v5.50.0");
    await git(remote, "tag", "v5.48.4-beta.1");
    await ledger(cwd, "v5.48.3");
    assertEquals((await detect(cwd, remote)).candidates, [release("v5.49.0")]);
    assertEquals(
      stableTags(
        "a\trefs/tags/v5.10.0\na\trefs/tags/v5.9.0\na\trefs/tags/v5.9.0^{}\na\trefs/tags/v05.9.0\na\trefs/tags/v5.9.1-beta.1",
      ),
      ["v5.9.0", "v5.10.0"],
    );
  }));
Deno.test("missing, invalid and unknown baselines fail before diffing", () =>
  fixture(async (cwd, remote) => {
    await assertRejects(
      () => detect(cwd, remote),
      Error,
      "Missing horizon-sync.json",
    );
    await Deno.writeTextFile(`${cwd}/${project.ledger}`, "{");
    await assertRejects(
      () => detect(cwd, remote),
      Error,
      "Cannot read horizon-sync.json",
    );
    assertThrows(
      () => manifest('{"synced_tag":"v5.50.1","surface":[]}'),
      Error,
      "not surface",
    );
    for (
      const value of [
        "[]",
        "null",
        "{}",
        '{"synced_tag":"v05.50.1"}',
        '{"synced_tag":"v5.50.1","covered":[{}]}',
      ]
    ) assertThrows(() => manifest(value));
    assertEquals(
      manifest(
        '{"synced_tag":"v5.50.1","covered":[{"kind":"screen","upstream":"x","covered_by":"y"}]}',
      ).covered?.length,
      1,
    );
    await ledger(cwd, "v5.50.1");
    await assertRejects(
      () => detect(cwd, remote),
      Error,
      "is not a Horizon tag",
    );
  }));
Deno.test("upstream failures never become a clean empty detection", () =>
  fixture(async (cwd) => {
    await ledger(cwd, "v5.50.0");
    await assertRejects(
      () =>
        detect(
          cwd,
          "/missing",
          () => Promise.resolve({ code: 1, text: "", error: "offline" }),
        ),
      Error,
      "Could not list Horizon tags",
    );
    await assertRejects(
      () =>
        detect(cwd, "/missing", (args) =>
          Promise.resolve(
            args[0] === "ls-remote"
              ? {
                code: 0,
                text: "a\trefs/tags/v5.50.0\na\trefs/tags/v5.50.1",
                error: "",
              }
              : { code: 1, text: "", error: "fetch failed" },
          )),
      Error,
      "Could not prepare Horizon checkout",
    );
  }));
Deno.test("route and action text changes retain the script boundaries", () => {
  const before = "Route::get('/x', 'X@index')->name('horizon.x');";
  const after = "Route::get('/y', 'X@index')->name('horizon.x');";
  assertEquals(diffText(routes(before), routes(after)), [{
    change: "modify",
    id: "GET horizon.x",
  }]);
  assertThrows(() => routes("Route::get('/x', 'X@index');"), Error, "no name");
  const controller =
    "public function __construct() {} public function index() { return 1; } public function show() { return 2; }";
  const updated = "public function index() { return 3; }";
  assertEquals(
    diffText(actions(controller, "X.php"), actions(updated, "X.php")),
    [{ change: "modify", id: "X@index" }, { change: "delete", id: "X@show" }],
  );
  assertEquals(diffText({}, actions(updated, "X.php")), [{
    change: "add",
    id: "X@index",
  }]);
});
Deno.test("nested config changes collapse only parent changes, including add and delete", () => {
  const before = new ConfigKeys(
    "<?php return ['a' => ['b' => 1, 'c' => 2], 'numeric' => ['1' => 3]];",
  ).all();
  const after = new ConfigKeys(
    "<?php /* comment */ return ['a' => ['b' => env('X', 2), 'c' => 2], 'numeric' => ['1' => 3]];",
  ).all();
  assertEquals(diffConfig(before, after), [{ change: "modify", id: "a.b" }]);
  assertEquals(diffConfig({}, before), [{ change: "add", id: "a" }, {
    change: "add",
    id: "numeric",
  }]);
  assertEquals(diffConfig(before, {}), [{ change: "delete", id: "a" }, {
    change: "delete",
    id: "numeric",
  }]);
  assertThrows(
    () => new ConfigKeys("<?php return null;").all(),
    Error,
    "no return array",
  );
});
Deno.test("baseline plan is one task and an up-to-date ledger produces none", () => {
  const plan = baselinePlan(project);
  assertEquals(plan.baseline, true);
  assertEquals(plan.tasks.length, 1);
  assertEquals(plan.tasks[0].key, "horizon-sync:baseline");
  assertEquals(
    taskPlan(
      { schema: 1, synced_tag: "v5.50.1", tag: null, candidates: [] },
      project,
    ).tasks,
    [],
  );
});

Deno.test("non-object ledgers report an invalid synced_tag without reading upstream", () =>
  fixture(async (cwd, remote) => {
    for (const value of ["[]", "null", "42", "true", '"ledger"']) {
      await Deno.writeTextFile(`${cwd}/${project.ledger}`, value);
      const error = await assertRejects(
        () =>
          detect(cwd, remote, () => {
            throw new Error("must not read upstream");
          }),
        Error,
      );
      assertEquals(
        error.message,
        "horizon-sync.json synced_tag must be a stable vX.Y.Z tag",
      );
    }
  }));
