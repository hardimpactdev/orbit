import { readProjectConfiguration } from "../extensions/behaviors/_lib/project_configuration.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type DependencyProject } from "../extensions/behaviors/_lib/dependency_projects.ts";
import {
  type Http,
  type Job,
  runStage,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  batchReceipt,
  ensureProjectGroup,
  projectFindings,
  type ScheduleReceipt,
  scheduleReceipt,
} from "../extensions/behaviors/_lib/project_schedule.ts";
import { taskInputs, todoError } from "./_support/task_contract.ts";

const fleet: { slug: string }[] = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/fleet.json", import.meta.url),
  ),
);

// Only the create -> todo -> accepted provision boundary is faked. The real
// generator, receipt persistence and Gateway stage planner send the requests.
function gateway(project: DependencyProject) {
  const groups = new Map<number, Record<string, unknown>>();
  const receipts = new Map<string, ScheduleReceipt>();
  let provisions = 0;
  const assistance = {
    assistance_requested: false,
    assistance_kind: null,
    assistance_question: null,
    assistance_reason: null,
  };
  const http: Http = (method, path, body) => {
    const reply = (status: number, data: unknown) =>
      Promise.resolve({ status, body: { data } });
    if (method === "POST" && path === "/api/v1/task-groups") {
      if (!taskInputs.safeParse(body?.tasks).success) {
        return Promise.resolve({
          status: 422,
          body: { error: { code: "validation.failed" } },
        });
      }
      const id = groups.size + 1;
      const tasks = taskInputs.parse(body!.tasks).map((task, i) => ({
        ...task,
        ...assistance,
        id: id * 100 + i + 1,
        status: "todo",
      }));
      const group = { ...body, ...assistance, id, tasks };
      groups.set(id, group);
      return reply(201, group);
    }
    if (method === "GET" && path === `/api/v1/projects/${project.projectId}`) {
      return reply(200, {
        id: project.projectId,
        repository_url: project.repository,
        task_check: project.check,
        default_branch: project.defaultBranch,
      });
    }
    const match = /^\/api\/v1\/task-groups\/(\d+)(\/provision)?$/.exec(path);
    const group = match ? groups.get(Number(match[1])) : undefined;
    if (!group) return reply(404, null);
    if (method === "PATCH") {
      const error = body?.status === "todo" ? todoError(group.tasks) : null;
      if (error) {
        return Promise.resolve({
          status: 422,
          body: { error: { code: error } },
        });
      }
      Object.assign(group, body);
    }
    if (method === "POST" && match?.[2]) {
      assertEquals(group.status, "todo");
      return reply(202, {
        id: ++provisions,
        status: "queued",
        poll_after_seconds: 1,
      });
    }
    return reply(200, group);
  };
  return {
    groups,
    http,
    provisions: () => provisions,
    store: {
      http,
      read: (key: string) => Promise.resolve(receipts.get(key) ?? null),
      save: (receipt: ScheduleReceipt) => {
        receipts.set(receipt.key, structuredClone(receipt));
        return Promise.resolve();
      },
    },
  };
}

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
    const f = gateway(project);
    for (const input of desired) {
      const receipt = await ensureProjectGroup(input, f.store);
      const group = f.groups.get(Number(receipt.workItem!.slice(3)))!;
      const tasks = taskInputs.parse(group.tasks);
      let job: Job | null = null;
      const result = await runStage({
        workItem: receipt.workItem!,
        stage: "provision",
        key: `${receipt.workItem}.provision.c1`,
        routine: true,
      }, {
        http: f.http,
        projects: [project],
        readJob: () => Promise.resolve(job),
        writeJob: (next) => {
          job = next;
          return Promise.resolve();
        },
      });
      assertEquals(group.status, "todo");
      assertEquals(result.operationStatus, "queued");
      assertEquals(result.lastError, null);
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
    assertEquals(f.provisions(), desired.length);
  });
}

Deno.test("the former generated child fails todo before provision; one empty sibling also refuses the move", async () => {
  const project: DependencyProject = JSON.parse(
    await Deno.readTextFile(
      new URL("./fixtures/project-extensions/nckrtl-com.json", import.meta.url),
    ),
  );
  for (
    const [deliveries, sibling] of [[undefined, false], [[], false], [
      undefined,
      true,
    ], [[], true]] as const
  ) {
    const f = gateway(project);
    const request = scheduleReceipt(project, "routine").request;
    const task = (request.tasks as Record<string, unknown>[])[0];
    // Backlog accepts the old shape. Todo must not, even with a valid sibling.
    const response = await f.http("POST", "/api/v1/task-groups", {
      ...request,
      tasks: [...(sibling ? [task] : []), {
        title: "Empty",
        brief: "Old payload",
        ...(deliveries ? { deliverables: deliveries } : {}),
      }],
    });
    assertEquals(response.status, 201);
    const group = f.groups.get(1)!;
    await assertRejects(
      () =>
        runStage({
          workItem: "tg-1",
          stage: "provision",
          key: "tg-1.provision.c1",
        }, {
          http: f.http,
          readJob: () => Promise.resolve(null),
          writeJob: () => Promise.resolve(),
        }),
      Error,
      "tasks.subtask_deliverables_missing",
    );
    assertEquals(group.status, "backlog");
    assertEquals(f.provisions(), 0);
  }
});

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
