import { installMaintenanceFixture } from "./_support/maintenance_fixture.ts";
import { configureGateway } from "./_support/configure_gateway.ts";
import { workerProjectExtension } from "./_support/worker_project_extension.ts";
import { SECOND_PROJECT } from "./_support/dependency_projects.ts";
// End-to-end routing with the unmodified official factory and loopback Gateway.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { startFakeGateway } from "./_support/fake_gateway.ts";
const REPO = new URL("..", import.meta.url).pathname;
async function swamp(cwd: string, ...args: string[]): Promise<string> {
  const out = await new Deno.Command("swamp", { args, cwd, stdin: "null", stdout: "piped", stderr: "piped" }).output();
  const text = new TextDecoder().decode(out.stdout) + new TextDecoder().decode(out.stderr);
  if (!out.success) throw new Error(text.slice(-4000));
  return text;
}
async function query(cwd: string, predicate: string, select: string) {
  return JSON.parse(await swamp(cwd, "data", "query", predicate, "--select", select, "--json")).results;
}
Deno.test({ name: "dependency-package official factory enforces sequential fresh groups, green/one repair/second red and no extra effects", sanitizeResources: false, sanitizeOps: false, fn: async () => {
  const fake = startFakeGateway({ 901: { handoffFailOnce: true, prStaysOpen: true }, 902: { provisionIdentity: { instance_id: 2, node_id: 2, starting_commit: "b".repeat(40) } }, 903: { scriptFailsOnce: true }, 904: { handoffAlwaysFails: true }, 905: { scriptFails: true }, 906: { projectId: SECOND_PROJECT.projectId } });
  fake.projects.set(SECOND_PROJECT.projectId, { id: SECOND_PROJECT.projectId, repository_url: SECOND_PROJECT.repository, type: "laravel-app", task_check: SECOND_PROJECT.check, default_branch: SECOND_PROJECT.defaultBranch });
  const dir = await Deno.makeTempDir({ prefix: "dependency-package-routing-" });
  let passed = false;
  try {
    const ls = await new Deno.Command("git", { args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd: REPO, stdout: "piped" }).output();
    for (const f of new TextDecoder().decode(ls.stdout).split("\0").filter(Boolean)) {
      const to = `${dir}/${f}`; await Deno.mkdir(to.slice(0, to.lastIndexOf("/")), { recursive: true }); await Deno.copyFile(`${REPO}/${f}`, to);
    }
    await Deno.mkdir(`${dir}/.swamp`);
    assert((await new Deno.Command("cp", { args: ["-r", Deno.env.get("SWAMP_TEST_EXTENSIONS") ?? `${REPO}/.swamp/pulled-extensions`, `${dir}/.swamp/`] }).output()).success);
    await workerProjectExtension(dir);
  await installMaintenanceFixture(dir);
    await configureGateway(dir, fake.url, [SECOND_PROJECT]);
    const input = (id: number) => ({ workItem: `tg-${id}`, targets:[{path:".",manager: id === 902 ? "js" : "composer", package: id === 902 ? "react" : "livewire/livewire", version: id === 902 ? "20.0.0" : "4.9.9", purpose: id === 902 ? "major" : "security", advisoryUrls: id === 902 ? [] : ["https://github.com/advisories/GHSA-fixture-one", "https://github.com/advisories/GHSA-fixture-two"], advisories: id === 902 ? [] : ["PKSA-xhv3-nr94-h8db", "PKSA-bgw4-5zmg-2njg"] }] });
    async function workflow(id: number) {
      await Deno.writeTextFile(`${dir}/input.json`, JSON.stringify(input(id)));
      await swamp(dir, "workflow", "run", "dependency-package", "--input-file", `${dir}/input.json`);
    }
    async function tick(id: number) {
      await swamp(dir, "model", "method", "run", "orbit-driver-package", "tick", "--input", `workItems=["tg-${id}"]`, "--input", "maxSteps=3");
      return (await query(dir, 'modelName == "orbit-driver-package" && name == "tick"', "attributes"))[0].items[0];
    }
    async function until(id: number, stage: string) {
      let last;
      for (let i = 0; i < 25; i++) { last = await tick(id); if (last.stage === stage) return; }
      throw new Error(JSON.stringify(last));
    }
    // Simulate records written under the former name, with the same factory ID.
    const renamed = ["models/@swamp/software-factory/dependency-package.yaml", "models/@nckrtl/orbit-driver/orbit-driver-package.yaml", "extensions/behaviors/orbit_gateway.ts"];
    const originals = new Map<string, string>();
    for (const path of renamed) {
      const source = await Deno.readTextFile(`${dir}/${path}`); originals.set(path, source);
      await Deno.writeTextFile(`${dir}/${path}`, source.replaceAll("dependency-package", "website-package"));
    }
    await workflow(901);
    await until(901, "merged");
    assertEquals(fake.startsOf(901, "repair"), 1); assertEquals(fake.startsOf(901, "handoff"), 2);
    let refused = false;
    try { await workflow(902); } catch (e) { refused = /previous package|unfinished maintenance/.test(String(e)); }
    assert(refused); assertEquals(fake.startsOf(902, "provision"), 0);
    // A waiting website PR does not block an independent configured project.
    await workflow(906); await until(906, "completed");
    assertEquals(fake.startsOf(906, "repair"), 0);
    assertEquals(fake.groups.get(906)!.vm_power, "destroyed");
    fake.groups.get(901)!.watched_pr_state = "merged";
    await until(901, "completed");
    for (const [path, source] of originals) await Deno.writeTextFile(`${dir}/${path}`, source);
    assertEquals(await query(dir, 'modelName == "dependency-package" && name == "state-tg-901"', "attributes"), []);
    assertEquals((await query(dir, 'modelName == "website-package" && name == "state-tg-901"', "attributes"))[0].stageId, "completed");
    // Admission must still see completed history after the rename, not block or restart it.
    await workflow(902); await until(902, "completed");
    const workspace = (await query(dir, 'modelName == "dependency-package" && name == "artifact-tg-902-workspace"', "attributes.payload"))[0];
    assertEquals(workspace.startingCommit, "b".repeat(40));
    await workflow(903); await until(903, "completed");
    await workflow(905); await until(905, "assistance");
    assertEquals(fake.startsOf(905, "repair"), 1);
    assertEquals(fake.groups.get(905)!.counts.maintenance, 2);
    assertEquals(fake.startsOf(905, "publish"), 0, "a ready repair receipt cannot hide a still-failing package/audit command");
    // Simulated explicit operator cancellation and verified cleanup, never a live write.
    const cancelled = fake.groups.get(905)!;
    Object.assign(cancelled, { status: "cancelled", vm_power: "destroyed", assistance_requested: false, assistance_kind: null, assistance_question: null, assistance_reason: null });
    Object.assign(cancelled.tasks[0], { status: "cancelled", assistance_requested: false, assistance_kind: null, assistance_question: null, assistance_reason: null });
    await swamp(dir, "model", "method", "run", "dependency-package", "approve", "--input", "workItem=tg-905", "--input", "gateId=routine-assistance", "--input", "actor=simulated-operator", "--input", "note=Explicit cancellation and cleanup verified in fixture");
    await until(905, "failed");
    await workflow(904); await until(904, "assistance");
    await tick(904); await tick(904); // Parked restarts cannot buy another turn.
    for (const id of [901, 902, 903, 904]) {
      assertEquals(fake.startsOf(id, "provision"), 1); assertEquals(fake.groups.get(id)!.counts.maintenance ?? 0, id === 902 ? 1 : 2);
      assertEquals(fake.startsOf(id, "repair"), id === 902 ? 0 : 1);
      assertEquals(fake.startsOf(id, "publish"), id === 904 ? 0 : 1, "worker summary precedes publish");
      assertEquals(fake.startsOf(id, "analyze"), 0); assertEquals(fake.startsOf(id, "review"), 0);

    }
    assertEquals(fake.groups.get(904)!.tasks[0].assistance_requested, true);
    assertEquals(fake.groups.get(901)!.vm_power, "destroyed");
    assert([...fake.operations.values()].every((op) => op.kind !== "tasks.script"));
    passed = true;
  } finally {
    await fake.close();
    if (passed) await Deno.remove(dir, { recursive: true });
    else console.error(`Retained package routing fixture: ${dir}`);
  }
} });
