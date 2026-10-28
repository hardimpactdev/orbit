import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { RepairFixture } from "./fixtures/production-smoke/repair.ts";

Deno.test("repair handoff uses the unchanged official task factory and reconciles a lost start reply", async () => {
  const source = new URL("..", import.meta.url).pathname;
  const dir = await Deno.makeTempDir({ prefix: "smoke-repair-factory-" });
  const env: Record<string, string> = {
    ...Deno.env.toObject(),
    SWAMP_NO_UPDATE_CHECK: "1",
    SWAMP_NO_TELEMETRY: "1",
  };
  for (
    const key of [
      "SWAMP_SERVER_TOKEN",
      "SWAMP_SERVER_TOKEN_FILE",
      "SWAMP_SERVE_URL",
      "SWAMP_SERVER_URL",
    ]
  ) delete env[key];
  const command = async (...args: string[]) => {
    const out = await new Deno.Command("swamp", {
      args: [...args, "--json"],
      cwd: dir,
      env,
      clearEnv: true,
      stdin: "null",
      stdout: "piped",
      stderr: "piped",
    }).output();
    const stdout = new TextDecoder().decode(out.stdout);
    assert(out.success, new TextDecoder().decode(out.stderr).slice(-2000));
    return JSON.parse(
      stdout.slice(stdout.indexOf("{"), stdout.lastIndexOf("}") + 1),
    );
  };
  try {
    const files = await new Deno.Command("git", {
      args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"],
      cwd: source,
      stdout: "piped",
    }).output();
    assert(files.success);
    for (
      const name of new TextDecoder().decode(files.stdout).split("\0").filter(
        Boolean,
      )
    ) {
      const target = `${dir}/${name}`;
      await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), {
        recursive: true,
      });
      await Deno.copyFile(`${source}/${name}`, target);
    }
    await Deno.mkdir(`${dir}/.swamp`, { recursive: true });
    const installed = Deno.env.get("SWAMP_TEST_EXTENSIONS") ??
      `${source}/.swamp/pulled-extensions`;
    const copied = await new Deno.Command("cp", {
      args: ["-r", installed, `${dir}/.swamp/`],
      stdout: "null",
      stderr: "piped",
    }).output();
    assert(
      copied.success,
      "Use already installed official packages; this test never installs or changes the source checkout.",
    );
    const f = new RepairFixture();
    await f.prepare();
    f.queryFactory = async (predicate) =>
      (await command("data", "query", predicate, "--select", "attributes"))
        .results;
    f.startFactory = async (workItem) => {
      await command(
        "model",
        "method",
        "run",
        "orbit-groups",
        "start",
        "--input",
        `workItem=${workItem}`,
      );
    };
    f.lostFactory = true;
    assertEquals((await f.repair()).status, "assistance_required");
    assertEquals(f.starts, 1);
    f.lostFactory = false;
    assertEquals((await f.repair()).status, "started");
    assertEquals(f.starts, 1);
    const rows = await f.queryFactory(
      'modelName == "orbit-groups" && name == "state-tg-91"',
    ) as { workItem: string; stageId: string; status: string }[];
    assertEquals(rows.length, 1);
    assertEquals(rows[0].workItem, "tg-91");
    assertEquals(rows[0].stageId, "provision");
    assertEquals(rows[0].status, "active");
    assertEquals(f.repairs.size, 1);
    assertEquals(
      f.requests.filter((r) =>
        r.method === "POST" && r.path.includes("/task-groups/")
      ).length,
      0,
    );
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
});
