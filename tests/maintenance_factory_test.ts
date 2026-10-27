// Real official factory and driver; the remote-workflow boundary returns native fixtures.
import { behavior as groupRun } from "../extensions/behaviors/orbit_group_run.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const REPO = new URL("..", import.meta.url).pathname;
Deno.test({
  name:
    "worker routine factory publishes, completes no-change, reruns once after repair and parks a second failure",
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fake = startFakeGateway({
      71: {},
      72: { scriptNoChange: true },
      73: { scriptFailsOnce: true },
      74: { scriptFails: true },
      75: { handoffFailOnce: true },
      76: {},
      77: {},
      78: { scriptNoChange: true },
      79: { scriptNoChange: true, scriptFails: true },
      80: {},
      81: { scriptNoChange: true, scriptFailsOnce: true },
    });
    const counts = new Map<number, number>();
    const remote = Deno.serve(
      { hostname: "127.0.0.1", port: 0, onListen() {} },
      async (req) => {
        const path = new URL(req.url).pathname;
        const json = (data: unknown) => Response.json({ data });
        if (path === "/api/v1/instances/1") {
          return json({
            id: 1,
            project_id: 20,
            checkout_path: "/vm/repo",
            starting_commit: "a".repeat(40),
          });
        }
        if (path.startsWith("/fixture/maintenance/")) {
          const [, , , gid, mode] = path.split("/");
          const group = fake.groups.get(Number(gid))!;
          if (group.id === 76 || group.id === 77) {
            return json({
              lastError: group.id === 76
                ? "maintenance has no Swamp run receipt five minutes after dispatch intent"
                : "worker did not enroll within five minutes of successful startup",
            });
          }
          const run = JSON.parse(
            await Deno.readTextFile(
              `${REPO}/tests/fixtures/maintenance-worker/run.json`,
            ),
          );
          const steps = run.jobs[0].steps;
          steps[0].dataArtifacts[0].attributes = {
            head: group.head,
            tree: group.tree,
          };
          if (mode === "summary") {
            steps.find((s: { name: string }) => s.name === "summary")
              .dataArtifacts = [{
                name: "summary",
                attributes: {
                  text:
                    "Update fixture dependencies\n\n| Package | From | To |\n| --- | --- | --- |\n| fixture/pkg | 1.0 | 1.1 |",
                },
              }];
          } else {
            if (mode === "detect") {
              steps[1].dataArtifacts = [{
                name: "detection",
                attributes: { schema: 1, candidates: [] },
              }];
              steps[2].dataArtifacts = [{
                name: "detection",
                attributes: { schema: 1, candidates: [] },
              }];
            }
            const count = (counts.get(group.id) ?? 0) + 1;
            counts.set(group.id, count);
            if (!group.scenario.scriptNoChange) group.tree = "e".repeat(40);
            if (
              group.scenario.scriptFails ||
              (group.scenario.scriptFailsOnce && count === 1)
            ) {
              steps[1].dataArtifacts = [{
                name: "failure",
                attributes: { exitCode: 23, message: "fixture command failed" },
              }];
            }
          }
          if (group.id === 74) {
            steps[1].dataArtifacts.push({
              name: "exceptions",
              attributes: {
                schema: 1,
                exceptions: [{
                  path: ".",
                  manager: "composer",
                  package: "fixture/pkg",
                  recorded_on: "2026-10-27",
                  days: 7,
                }],
              },
            });
          }
          steps.at(-1).dataArtifacts[0].attributes = {
            head: group.head,
            tree: group.tree,
          };
          return json(run);
        }
        return fetch(
          fake.url + new URL(req.url).pathname + new URL(req.url).search,
          {
            method: req.method,
            headers: req.headers,
            ...(req.method === "GET" ? {} : { body: await req.text() }),
          },
        );
      },
    );
    const dir = await Deno.makeTempDir({ prefix: "worker-factory-routing-" });
    let passed = false;
    const swampOutput = (...args: string[]) =>
      new Deno.Command("swamp", {
        args,
        cwd: dir,
        stdout: "piped",
        stderr: "piped",
      }).output();
    const swamp = async (...args: string[]) => {
      const out = await swampOutput(...args);
      const text = new TextDecoder().decode(out.stdout);
      assert(
        out.success,
        text.slice(-3000) + new TextDecoder().decode(out.stderr).slice(-1000),
      );
      return text;
    };
    try {
      const files = await new Deno.Command("git", {
        args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"],
        cwd: REPO,
        stdout: "piped",
      }).output();
      for (
        const file of new TextDecoder().decode(files.stdout).split("\0").filter(
          Boolean,
        )
      ) {
        const target = `${dir}/${file}`;
        await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), {
          recursive: true,
        });
        await Deno.copyFile(`${REPO}/${file}`, target);
      }
      await Deno.mkdir(`${dir}/.swamp`);
      assert(
        (await new Deno.Command("cp", {
          args: [
            "-r",
            Deno.env.get("SWAMP_TEST_EXTENSIONS") ??
              `${REPO}/.swamp/pulled-extensions`,
            `${dir}/.swamp/`,
          ],
        }).output()).success,
      );
      const def = `${dir}/models/@nckrtl/orbit-gateway/orbit-gateway.yaml`;
      await Deno.writeTextFile(
        def,
        (await Deno.readTextFile(def))
          .replace(
            /gatewayUrl: .*/,
            `gatewayUrl: http://127.0.0.1:${remote.addr.port}`,
          )
          .replace(/caFile: .*/, "caFile: /dev/null"),
      );
      const module = `${dir}/extensions/behaviors/_lib/swamp_maintenance.ts`;
      const source = await Deno.readTextFile(module);
      const start = source.indexOf(
        "  let inputs = maintenanceInputs",
        source.indexOf("export async function runMaintenance("),
      );
      const end = source.indexOf("\n/** Swamp stores worker receipts", start);
      assert(start > 0 && end > start);
      // Replace only the transport boundary. The driver, products and factory stay real.
      await Deno.writeTextFile(
        module,
        source.slice(0, start) +
          `  const response = await http("GET", \`/fixture/maintenance/\${group}/\${request.mode}\`);
  if ((response.body!.data as Record<string, unknown>).lastError) return { state: "failed", lastError: String((response.body!.data as Record<string, unknown>).lastError) };
  await recordMaintenanceExceptions(response.body!.data as WorkflowRun, args.dependencyProject!.projectId, context);
  return maintenanceResult(response.body!.data as WorkflowRun);
}\n` + source.slice(end),
      );
      // Seed the actual Swamp datastore with the unversioned pre-retirement resource.
      const projectModule =
        `${dir}/extensions/behaviors/projects/nckrtl-com/mod.ts`;
      const projectSource = await Deno.readTextFile(projectModule);
      const legacy = JSON.parse(
        await Deno.readTextFile(
          `${REPO}/tests/fixtures/project-extensions/configuration-v1.json`,
        ),
      );
      await Deno.writeTextFile(
        projectModule,
        `
import { z } from "npm:zod@4";
import { DependencyProjectSchema } from "../../_lib/dependency_project_schema.ts";
const config = ${JSON.stringify(legacy)};
export const behavior = {
  type: "@nckrtl/project-nckrtl-com", version: "2026.10.27.1", globalArguments: z.object({}),
  resources: { configuration: { description: "Pre-retirement config", schema: DependencyProjectSchema.omit({ exceptions: true }).extend({ script: z.string() }), lifetime: "infinite", garbageCollection: 10 } },
  methods: { configuration: { description: "Write the pre-retirement fixture", arguments: z.object({}),
    execute: async (_args: unknown, context: { writeResource: (spec: string, name: string, value: unknown) => Promise<unknown> }) => ({ dataHandles: [await context.writeResource("configuration", "configuration", config)] }) } }
};
`,
      );
      await swamp(
        "model",
        "create",
        "@nckrtl/project-nckrtl-com",
        "project-nckrtl-com",
      );
      await swamp(
        "model",
        "method",
        "run",
        "project-nckrtl-com",
        "configuration",
      );
      await Deno.writeTextFile(projectModule, projectSource);
      const items = [...fake.groups.keys()].filter((id) => id < 78).map((id) =>
        `tg-${id}`
      );
      for (const item of items) {
        await swamp(
          "model",
          "method",
          "run",
          "dependency-routine",
          "start",
          "--input",
          `workItem=${item}`,
        );
      }
      await Deno.writeTextFile(
        `${dir}/tick.json`,
        JSON.stringify({ workItems: items, maxSteps: 3 }),
      );
      const expected = [
        "completed",
        "completed",
        "completed",
        "assistance",
        "completed",
        "assistance",
        "assistance",
      ];
      let stages: string[] = [];
      for (let n = 0; n < 30; n++) {
        await swamp(
          "model",
          "method",
          "run",
          "orbit-driver-routine",
          "tick",
          "--input-file",
          `${dir}/tick.json`,
        );
        const query = JSON.parse(
          await swamp(
            "data",
            "query",
            'modelName == "orbit-driver-routine" && name == "tick"',
            "--select",
            "attributes",
            "--json",
          ),
        );
        assertEquals(
          query.results[0].items.filter((item: { error?: string }) =>
            item.error && !item.error.includes("Maintenance VM limit reached")
          ),
          [],
          "native routine driver errors",
        );
        stages = query.results[0].items.map((item: { stage: string }) =>
          item.stage
        );
        if (JSON.stringify(stages) === JSON.stringify(expected)) break;
      }
      assertEquals(stages, expected);
      for (const id of [76, 77]) {
        const query = JSON.parse(
          await swamp(
            "data",
            "query",
            `modelName == "orbit-gateway" && name == "job-tg-${id}.script.c1"`,
            "--select",
            "attributes",
            "--json",
          ),
        );
        assertEquals(query.results[0].state, "failed");
        assert(String(query.results[0].lastError).includes("five minutes"));
        assertEquals(fake.groups.get(id)!.assistance_requested, true);
      }
      assertEquals([...counts], [[71, 1], [72, 1], [73, 2], [74, 2], [75, 2]]);
      assertEquals(fake.startsOf(71, "publish"), 1);
      assertEquals(fake.startsOf(72, "publish"), 0);
      for (const id of [73, 74, 75]) {
        assertEquals(fake.startsOf(id, "repair"), 1);
      }
      assertEquals(fake.startsOf(74, "publish"), 0);
      assert(
        [...fake.operations.values()].every((op) => op.kind !== "tasks.script"),
      );
      // Simulated operator cancellation frees the three parked fixture VMs before
      // the next independent scenarios. Production cancellation is never performed.
      for (const id of [74, 76, 77]) {
        const group = fake.groups.get(id)!;
        Object.assign(group, {
          status: "cancelled",
          vm_power: "destroyed",
          assistance_requested: false,
          assistance_kind: null,
          assistance_question: null,
          assistance_reason: null,
        });
        for (const task of group.tasks) {
          Object.assign(task, {
            status: "cancelled",
            assistance_requested: false,
            assistance_kind: null,
            assistance_question: null,
            assistance_reason: null,
          });
        }
      }
      // The detection factory uses the same real config/provider and stage client.
      // It has no repair or publication path, even for command failure or changed trees.
      const detections = [78, 79, 80, 81].map((id) => `tg-${id}`);
      for (const workItem of detections) {
        await swamp(
          "behavior",
          "run",
          "dependency-detect",
          "start",
          "--input",
          `workItem=${workItem}`,
        );
      }
      // Use the native per-group driver's deployed guarded-advance limit.
      const detectSteps = groupRun.globalArguments.parse({
        factory: "dependency-detect",
        preapprovalModel: "orbit-driver-detect",
      }).maxSteps;
      await Deno.writeTextFile(
        `${dir}/detect-tick.json`,
        JSON.stringify({ workItems: detections, maxSteps: detectSteps }),
      );
      let detected: string[] = [];
      for (let n = 0; n < 20; n++) {
        await swamp(
          "behavior",
          "run",
          "orbit-driver-detect",
          "tick",
          "--input-file",
          `${dir}/detect-tick.json`,
        );
        const query = JSON.parse(
          await swamp(
            "data",
            "query",
            'modelName == "orbit-driver-detect" && name == "tick"',
            "--select",
            "attributes",
            "--json",
          ),
        );
        assertEquals(
          query.results[0].items.filter((item: { error?: string }) =>
            item.error && !item.error.includes("Maintenance VM limit reached")
          ),
          [],
          "native detect driver errors",
        );
        detected = query.results[0].items.map((item: { stage: string }) =>
          item.stage
        );
        if (
          JSON.stringify(detected) ===
            JSON.stringify([
              "completed",
              "assistance",
              "assistance",
              "assistance",
            ])
        ) break;
      }
      assertEquals(detected, [
        "completed",
        "assistance",
        "assistance",
        "assistance",
      ]);
      for (const id of [78, 79, 80, 81]) {
        assertEquals(
          fake.startsOf(id, "handoff"),
          0,
          "detect needs no installed project tools",
        );
        assertEquals(fake.startsOf(id, "repair"), 0);
        assertEquals(fake.startsOf(id, "publish"), 0);
        assertEquals(counts.get(id), 1);
      }
      assertEquals(fake.groups.get(78)!.vm_power, "destroyed");

      // Recover the same failed detect group with native approvals and a fresh
      // script cycle. Never reset a factory or erase the terminal c1 receipt.
      const query = async (predicate: string) =>
        JSON.parse(
          await swamp(
            "data",
            "query",
            predicate,
            "--select",
            "attributes",
            "--json",
          ),
        ).results;
      const oldState = (await query(
        'modelName == "dependency-detect" && name == "state-tg-79"',
      ))[0];
      const oldJob = (await query(
        'modelName == "orbit-gateway" && name == "job-tg-79.script.c1"',
      ))[0];
      assertEquals(oldJob.artifacts.script.outcome, "failed");
      const retry = [
        "behavior",
        "run",
        "dependency-detect",
        "advance",
        "--input",
        "workItem=tg-79",
        "--input",
        "transition=retry",
      ];
      assert(
        !(await swampOutput(...retry)).success,
        "retry needs human consent",
      );
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "approve",
        "--input",
        "workItem=tg-79",
        "--input",
        "gateId=detect-retry",
        "--input",
        "actor=fixture-operator",
      );
      assert(
        !(await swampOutput(...retry)).success,
        "retry needs one new script cycle grant",
      );
      assertEquals(counts.get(79), 1);
      assertEquals(fake.groups.get(79)!.assistance_requested, true);
      for (const id of [79, 80, 81]) {
        for (
          const gate of id === 79
            ? ["cycle-override:script"]
            : ["detect-retry", "cycle-override:script"]
        ) {
          await swamp(
            "behavior",
            "run",
            "dependency-detect",
            "approve",
            "--input",
            `workItem=tg-${id}`,
            "--input",
            `gateId=${gate}`,
            "--input",
            "actor=fixture-operator",
          );
        }
      }
      // Advancing in one process and driving in another reproduces a restart
      // after approval, before the old assistance was reconciled.
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "advance",
        "--input",
        "workItem=tg-81",
        "--input",
        "transition=retry",
      );
      assertEquals(fake.groups.get(81)!.assistance_requested, true);
      fake.groups.get(79)!.scenario.scriptFails = false; // underlying scan cause fixed
      for (let n = 0; n < 20; n++) {
        await swamp(
          "behavior",
          "run",
          "orbit-driver-detect",
          "tick",
          "--input-file",
          `${dir}/detect-tick.json`,
        );
        const state = (await query(
          'modelName == "dependency-detect" && name == "state-tg-79"',
        ))[0];
        const restarted = (await query(
          'modelName == "dependency-detect" && name == "state-tg-81"',
        ))[0];
        if (
          state.stageId === "completed" && restarted.stageId === "completed"
        ) break;
      }
      const retried = (await query(
        'modelName == "dependency-detect" && name == "state-tg-79"',
      ))[0];
      assertEquals(retried.stageId, "completed");
      assertEquals(
        retried.startedAt,
        oldState.startedAt,
        "factory era preserved",
      );
      assertEquals(retried.cycles.script, 2);
      assertEquals(retried.cycles.provision, 1);
      assertEquals(
        (await query(
          'modelName == "orbit-gateway" && name == "job-tg-79.script.c1"',
        ))[0],
        oldJob,
        "the original failed scan receipt is unchanged",
      );
      assertEquals(
        (await query(
          'modelName == "orbit-gateway" && name == "job-tg-79.script.c2"',
        ))[0].artifacts.script.outcome,
        "passed",
      );
      assertEquals(fake.groups.get(79)!.status, "completed");
      assertEquals(fake.groups.get(79)!.vm_power, "destroyed");
      assertEquals(fake.groups.get(79)!.assistance_requested, false);
      assertEquals(counts.get(79), 2);
      assertEquals(
        (await query(
          'modelName == "dependency-detect" && name == "state-tg-81"',
        ))[0].stageId,
        "completed",
        "restart resumes without resetting or re-provisioning",
      );
      assertEquals(counts.get(81), 2);
      assertEquals(fake.groups.get(81)!.assistance_requested, false);
      assertEquals(
        (await query(
          'modelName == "dependency-detect" && name == "state-tg-80"',
        ))[0].stageId,
        "assistance",
        "changed checkout cannot retry even with approvals",
      );
      assertEquals(counts.get(80), 1);
      await swamp(
        "behavior",
        "run",
        "orbit-driver-detect",
        "tick",
        "--input-file",
        `${dir}/detect-tick.json`,
      );
      assertEquals(
        counts.get(79),
        2,
        "replayed tick cannot rescan a completed entry",
      );
      for (const id of [79, 80, 81]) {
        assertEquals(fake.startsOf(id, "provision"), 1);
        assertEquals(fake.startsOf(id, "handoff"), 0);
        assertEquals(fake.startsOf(id, "repair"), 0);
        assertEquals(fake.startsOf(id, "publish"), 0);
      }
      const config = JSON.parse(
        await swamp(
          "data",
          "query",
          'modelName == "project-nckrtl-com" && name == "configuration"',
          "--select",
          "attributes",
          "--json",
        ),
      );
      assertEquals(config.results[0].schema, 3);
      assertEquals(config.results[0].exceptions.exceptions, [{
        path: ".",
        manager: "composer",
        package: "fixture/pkg",
        recorded_on: "2026-10-27",
        days: 7,
      }]);
      assertEquals("script" in config.results[0], false);
      assertEquals(config.results[0].worker, {
        composerPaths: ["."],
        jsPaths: ["."],
        policy: { ".": { minimumReleaseAgeDays: 7 } },
      });
      // A provision failure reaches assistance before any script artifact exists.
      // Repeated status reads must remain valid and must never offer scan retry.
      const noScript = "fixture-no-script";
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "start",
        "--input",
        `workItem=${noScript}`,
      );
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "record_dispatch",
        "--input",
        `workItem=${noScript}`,
      );
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "record_evidence",
        "--input",
        `workItem=${noScript}`,
        "--input",
        "name=provision-run",
        "--input",
        `payload=${
          JSON.stringify({
            status: "failed",
            runId: "fixture-provision-failed",
          })
        }`,
      );
      await swamp(
        "behavior",
        "run",
        "dependency-detect",
        "advance",
        "--input",
        `workItem=${noScript}`,
        "--input",
        "transition=failed",
      );
      for (let n = 0; n < 2; n++) {
        await swamp(
          "behavior",
          "run",
          "dependency-detect",
          "status",
          "--input",
          `workItem=${noScript}`,
        );
        const status = (await query(
          `modelName == "dependency-detect" && name == "status-${noScript}"`,
        ))[0];
        assertEquals(status.stage.id, "assistance");
        const retry = status.transitions.find((t: { name: string }) =>
          t.name === "retry"
        );
        assertEquals(retry.satisfied, false);
        const cel = retry.gates.find((g: { type: string }) => g.type === "cel");
        assertEquals(cel.pass, false);
        assert(
          cel.reasons.length === 1 &&
            cel.reasons[0].endsWith("evaluated to false"),
          `missing artifacts must give a false gate, not a CEL error: ${
            JSON.stringify(cel.reasons)
          }`,
        );
      }
      passed = true;
    } finally {
      await remote.shutdown();
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Worker factory reports retained: ${dir}`);
    }
  },
});
