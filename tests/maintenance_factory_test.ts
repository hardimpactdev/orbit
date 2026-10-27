// Real official factory and driver; the remote-workflow boundary returns native fixtures.
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
    const swamp = async (...args: string[]) => {
      const out = await new Deno.Command("swamp", {
        args,
        cwd: dir,
        stdout: "piped",
        stderr: "piped",
      }).output();
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
        (await Deno.readTextFile(def)).replace(
          /gatewayUrl: .*/,
          `gatewayUrl: http://127.0.0.1:${remote.addr.port}`,
        ),
      );
      const module = `${dir}/extensions/behaviors/_lib/swamp_maintenance.ts`;
      const source = await Deno.readTextFile(module);
      const start = source.indexOf(
        "  const inputs = maintenanceInputs",
        source.indexOf("export async function runMaintenance("),
      );
      const end = source.indexOf("\nfunction resource(", start);
      assert(start > 0 && end > start);
      // Replace only the transport boundary. The driver, products and factory stay real.
      await Deno.writeTextFile(
        module,
        source.slice(0, start) +
          `  const response = await http("GET", \`/fixture/maintenance/\${group}/\${request.mode}\`);
  if ((response.body!.data as Record<string, unknown>).lastError) return { state: "failed", lastError: String((response.body!.data as Record<string, unknown>).lastError) };
  return maintenanceResult(response.body!.data as WorkflowRun);
}\n` + source.slice(end),
      );
      const items = [...fake.groups.keys()].map((id) => `tg-${id}`);
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
      passed = true;
    } finally {
      await remote.shutdown();
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Worker factory reports retained: ${dir}`);
    }
  },
});
