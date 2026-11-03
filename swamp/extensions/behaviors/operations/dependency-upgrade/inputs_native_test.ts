import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { type Data, git } from "../../../../tests/_support/instance_fixture.ts";
Deno.test("dependency fetch, scan and plan use the same committed inventory", async () => {
  await using repo = await nativeRepo();
  const source = `${repo.dir}/source`;
  await Deno.mkdir(source);
  git(source, "init", "-q", "-b", "main");
  await Deno.writeTextFile(`${source}/composer.json`, '{"require":{}}\n');
  git(source, "add", ".");
  git(
    source, "-c", "user.name=fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "empty inventory",
  );
  const expected = git(source, "rev-parse", "HEAD");
  await Deno.writeTextFile(`${source}/composer.json`, '{"require":{"never/lookup":"^99"}}\n');
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/_lib`, { recursive: true });
  for (const file of ["orbit_deps.ts", "_lib/inventory.ts", "_lib/versions.ts"]) {
    await Deno.copyFile(`${ROOT}/extensions/behaviors/${file}`, `${repo.dir}/extensions/behaviors/${file}`);
  }
  await Deno.copyFile(`${ROOT}/models/@nckrtl/orbit-deps/orbit-deps.yaml`, `${repo.dir}/models/orbit-deps.yaml`);
  await repo.workflow("dependency-upgrade");
  git(source, "remote", "add", "origin", source);
  await repo.cli(["workflow", "validate", "dependency-upgrade"]);
  const { history } = await repo.runWorkflow("dependency-upgrade", { checkoutDir: source, branch: "main" });
  const steps = Object.fromEntries((history as Data).jobs.flatMap((j: Data) => j.steps).map((s: Data) => [s.name, s]));
  assertEquals(steps.head.outputs.stdout.trim(), expected);
  assertEquals(steps.scan.outputs.commit, expected);
  assertEquals(steps.plan.outputs.commit, expected);
  assertEquals(steps.plan.outputs.counts.packages, 0);
  assertEquals(steps["approve-majors"].status, "skipped");
  assertStringIncludes(await Deno.readTextFile(`${source}/composer.json`), "never/lookup");
});
