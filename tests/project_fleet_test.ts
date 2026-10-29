import { readProjectConfiguration } from "../extensions/behaviors/_lib/project_configuration.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.11";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type DependencyProject } from "../extensions/behaviors/_lib/dependency_project_schema.ts";

interface Source {
  slug: string;
  project: {
    id: number;
    repository_url: string;
    task_check: string;
    default_branch: string;
  };
  source: { repository: string; commit: string };
  manifestPaths: string[];
  setup: Record<string, string | Record<string, unknown>>;
}
const sources: Source[] = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/fleet.json", import.meta.url),
  ),
);
const repo = new URL("../", import.meta.url);
const yaml = async (file: string) =>
  parse(await Deno.readTextFile(new URL(file, repo))) as Record<
    string,
    unknown
  >;
for (const source of sources) {
  Deno.test(`${source.slug} preserves its current Project settings, configured paths and age policy`, async () => {
    const { behavior } = await import(
      new URL(`extensions/behaviors/projects/${source.slug}/mod.ts`, repo).href
    );
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.configuration.execute({}, context);
    const config: DependencyProject = readProjectConfiguration(
      getWrittenResources()[0].data,
    );
    assertEquals(config.dependencyAutoMerge, false);
    assertEquals(behavior.type, `@nckrtl/project-${source.slug}`);
    assertEquals("script" in config, false);
    assertEquals(config.projectId, source.project.id);
    assertEquals(config.repository, source.project.repository_url);
    assertEquals(config.check, source.project.task_check);
    assertEquals(config.defaultBranch, source.project.default_branch);
    const paths =
      (source.setup["maintainer.json"] as { paths: string[] } | undefined)
        ?.paths ?? ["."];
    const manifest = (path: string, name: string) =>
      `${path === "." ? "" : path + "/"}${name}`;
    assertEquals(
      config.worker!.composerPaths,
      paths.filter((p) =>
        source.manifestPaths.includes(manifest(p, "composer.json"))
      ),
    );
    assertEquals(
      config.worker!.jsPaths,
      paths.filter((p) =>
        source.manifestPaths.includes(manifest(p, "package.json"))
      ),
    );
    const expected: Record<string, { minimumReleaseAgeDays: number }> = {};
    for (const path of paths) {
      const vet = source.setup[manifest(path, "vet.json")] as
        | Record<string, number>
        | undefined;
      const npm = source.setup[manifest(path, ".npmrc")] as string | undefined;
      const bun = source.setup[manifest(path, "bunfig.toml")] as
        | string
        | undefined;
      const ages = [
        vet?.["minimum-release-age"],
        npm?.match(/^min-release-age=(\d+)/m)?.[1]
          ? Number(npm.match(/^min-release-age=(\d+)/m)![1])
          : undefined,
        bun?.match(/^minimumReleaseAge\s*=\s*(\d+)/m)?.[1]
          ? Number(bun.match(/^minimumReleaseAge\s*=\s*(\d+)/m)![1]) / 86400
          : undefined,
      ].filter((age) => age !== undefined);
      assert(new Set(ages).size <= 1, "source ecosystem policies disagree");
      if (ages.length) expected[path] = { minimumReleaseAgeDays: ages[0]! };
    }
    assertEquals(config.worker!.policy, expected);
    if (source.slug === "operator") {
      assertEquals(config.worker!.composerPaths, ["server/web"]);
      assertEquals(config.worker!.jsPaths, ["server/web"]);
    }
    if (["hauzer", "mealou"].includes(source.slug)) {
      assertEquals(config.worker!.jsPaths, [".", "mobile"]);
    }
    if (
      [
        "launch-starter-kit",
        "treadmill-website",
        "orbit-website",
        "ai-cli-proxy",
      ].includes(source.slug)
    ) assertEquals(config.worker!.policy, {});
    assert(/^[0-9a-f]{40}$/.test(source.source.commit));
  });
}

Deno.test("the fleet registers all 18 projects once and owns private, correctly routed schedules", async () => {
  assertEquals(sources.length, 18);
  assertEquals(new Set(sources.map((s) => s.project.id)).size, 18);
  assert(!sources.some((s) => s.slug === "horizon-new-dawn"));
  const gateway = await yaml(
    "models/@nckrtl/orbit-gateway/orbit-gateway.yaml",
  ) as {
    globalArguments: {
      projectExtensions: { projectId: number; type: string }[];
      dependencyProjects: unknown[];
    };
  };
  assertEquals(gateway.globalArguments.dependencyProjects, []);
  assertEquals(gateway.globalArguments.projectExtensions.length, 19);
  const names = new Set<string>(), ids = new Set<string>();
  const schedules = new Set<string>();
  for (const source of sources) {
    const type = `@nckrtl/project-${source.slug}`;
    assertEquals(
      gateway.globalArguments.projectExtensions.filter((p) =>
        p.projectId === source.project.id
      ),
      [{ projectId: source.project.id, type }],
    );
    const manifest = await yaml(
      `extensions/behaviors/projects/${source.slug}/manifest.yaml`,
    );
    assertEquals(manifest.name, type);
    assertEquals(manifest.visibility, "private");
    assertEquals(manifest.behaviors, [`projects/${source.slug}/mod.ts`]);
    assertEquals(manifest.dependencies, [
      "@nckrtl/composer-maintenance",
      "@nckrtl/js-maintenance",
    ]);
    assertEquals(
      manifest.workflows,
      ["routine", "detect"].map((mode) =>
        `workflow-project-${source.slug}-${mode}.yaml`
      ),
    );
    for (const mode of ["routine", "detect"]) {
      const workflow = await yaml(
        `workflows/workflow-project-${source.slug}-${mode}.yaml`,
      ) as {
        id: string;
        name: string;
        trigger: { schedule: string };
        jobs: {
          steps: {
            task: {
              type: string;
              modelIdOrName?: string;
              modelType?: string;
              modelName?: string;
              methodName: string;
              inputs: unknown;
            };
          }[];
        }[];
      };
      assertEquals(workflow.name, `${type}/${mode}`);
      assert(!names.has(workflow.name));
      names.add(workflow.name);
      assert(!ids.has(workflow.id));
      ids.add(workflow.id);
      const cron = workflow.trigger.schedule.split(" ");
      assertEquals(cron.length, 5);
      assertEquals(
        cron.slice(2),
        mode === "routine" ? ["*", "*", "1"] : ["*", "*", "*"],
      );
      assert(!schedules.has(`${mode}:${workflow.trigger.schedule}`));
      schedules.add(`${mode}:${workflow.trigger.schedule}`);
      assertEquals(workflow.jobs[0].steps[0].task, {
        type: "model_method",
        ...(mode === "detect"
          ? {
            modelType: "@nckrtl/dependency-scanner",
            modelName: "dependency-scanner",
            methodName: "scan",
          }
          : { modelIdOrName: "orbit-gateway", methodName: "schedule_project" }),
        inputs: {
          projectId: source.project.id,
          ...(mode === "routine" ? { mode } : {}),
          ...(mode === "detect" ? { runId: "${{ run.id }}" } : {}),
        },
      });
    }
  }
});

Deno.test("private project config preserves every exception ledger found in the 20-repository inventory", async () => {
  const inventory = JSON.parse(
    await Deno.readTextFile(
      new URL(
        "./fixtures/project-extensions/exception-inventory.json",
        import.meta.url,
      ),
    ),
  ) as {
    name: string;
    nameWithOwner: string;
    fileSha?: string;
    exceptions: unknown;
  }[];
  assertEquals(inventory.length, 20);
  const withLedger = inventory.filter((row) => row.exceptions !== null);
  assertEquals(withLedger.map((row) => row.nameWithOwner), [
    "nckrtl/recall",
    "hardimpactdev/laravel-toolbar-site",
    "nckrtl/operator-website",
    "nckrtl/operator",
  ]);
  for (const row of withLedger) {
    assert(/^[a-f0-9]{40}$/.test(row.fileSha!));
    const { behavior } = await import(
      new URL(`extensions/behaviors/projects/${row.name}/mod.ts`, repo).href
    );
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.configuration.execute({}, context);
    const config = readProjectConfiguration(getWrittenResources()[0].data);
    assertEquals(config.exceptions, row.exceptions);
  }
});
