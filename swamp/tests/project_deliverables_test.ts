import { readProjectConfiguration } from "../extensions/behaviors/_lib/project_configuration.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type DependencyProject } from "../extensions/behaviors/_lib/dependency_projects.ts";

import {
  batchReceipt,
  projectFindings,
  scheduleReceipt,
} from "../extensions/behaviors/_lib/project_schedule.ts";
import { taskInputs, todoError } from "./_support/task_contract.ts";

const fleet: { slug: string }[] = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/fleet.json", import.meta.url),
  ),
);

for (const slug of ["nckrtl-com", ...fleet.map((p) => p.slug)]) {
  Deno.test(`${slug}: routine, detect, major and advisory groups pass Orbit's todo deliverable contract`, async () => {
    const { behavior } = await import(
      new URL(
        `../extensions/behaviors/projects/${slug}/mod.ts`,
        import.meta.url,
      ).href
    );
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.configuration.execute({}, context);
    const project: DependencyProject = readProjectConfiguration(
      getWrittenResources()[0].data,
    );
    const reports = (["composer", "js"] as const).flatMap((manager) => {
      const paths = manager === "composer"
        ? project.worker!.composerPaths
        : project.worker!.jsPaths;
      return paths.length
        ? [{
          schema: 1,
          candidates: paths.map((path) => ({
            path,
            manager,
            package: manager === "composer" ? "fixture/package" : "fixture-ui",
            current: "1.0.0",
            latest: "2.0.0",
            reasons: ["major", "security"],
            advisories: [{
              id: "GHSA-fixture",
              url: "https://example.test/advisory",
              title: "Fixture advisory",
              affected: "<1.0.1",
            }],
          })),
        }]
        : [];
    });
    const desired = [
      scheduleReceipt(project, "routine"),
      scheduleReceipt(project, "detect"),
      await batchReceipt(
        project,
        await projectFindings(
          project,
          "fixture",
          reports,
          reports.map((r) => ({
            schema: 1,
            targets: r.candidates.map((c) => ({
              path: c.path,
              manager: c.manager,
              package: c.package,
              version: c.latest,
              purpose: "security",
              advisories: c.advisories.map((a) => a.id),
              advisoryUrls: c.advisories.map((a) => a.url),
            })),
            blocked: [],
          })),
        ),
      ),
    ];
    for (const input of desired) {
      const tasks = taskInputs.parse(input.request.tasks);
      assertEquals(todoError(tasks), null);
      for (const task of tasks) {
        assertEquals(
          task.deliverables?.find((d) => d.type === "command"),
          input.mode === "detect" ? undefined : {
            id: "project-check",
            type: "command",
            description:
              "Pass the configured project check in the task checkout.",
            command: project.check,
            directory: ".",
          },
        );
        assert(task.deliverables!.some((d) => d.type === "review"));
        const files = task.deliverables!.filter((d) => d.type === "file");
        if (input.mode !== "package") {
          assertEquals(files, []); // No-change routine and read-only detect remain valid.
        } else {
          assertEquals(files.length, 1);
          assert(
            input.targets!.every((t) =>
              files[0].path!.includes(
                t.manager === "composer" ? "composer.lock" : "bun.lock",
              )
            ),
          );
          assertEquals(files[0].change, "modified");
        }
      }
    }
  });
}

Deno.test("the contract fixture rejects malformed typed deliverables at creation", () => {
  const valid = {
    id: "check",
    type: "command",
    description: "Check",
    command: "composer check",
    directory: ".",
  };
  for (
    const deliverables of [
      [{ ...valid, command: undefined }],
      [{ ...valid, directory: "../other" }],
      [{ ...valid, type: "unknown" }],
      [{ ...valid, id: "Invalid_ID" }],
      [{ ...valid, path: "composer.lock" }],
      [valid, valid],
      Array.from({ length: 6 }, (_, i) => ({ ...valid, id: `check-${i}` })),
    ]
  ) {
    assertEquals(
      taskInputs.safeParse([{ title: "Task", brief: "Brief", deliverables }])
        .success,
      false,
    );
  }
});
