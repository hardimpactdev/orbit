import { SECOND_PROJECT } from "./_support/dependency_projects.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type Scenario, startFakeGateway } from "./_support/fake_gateway.ts";

const REPO = new URL("..", import.meta.url).pathname;

async function swamp(cwd: string, ...args: string[]): Promise<string> {
  const out = await new Deno.Command("swamp", { args, cwd, stdin: "null", stdout: "piped", stderr: "piped" }).output();
  const text = new TextDecoder().decode(out.stdout) + new TextDecoder().decode(out.stderr);
  if (!out.success) throw new Error(`swamp ${args.join(" ")} failed:\n${text.slice(-4000)}`);
  return text;
}

async function copyRepo(url: string) {
  const dir = await Deno.makeTempDir({ prefix: "publish-retry-" });
  const ls = await new Deno.Command("git", { args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd: REPO, stdout: "piped" }).output();
  assert(ls.success);
  for (const f of new TextDecoder().decode(ls.stdout).split("\0").filter(Boolean)) {
    const target = `${dir}/${f}`;
    await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), { recursive: true });
    await Deno.copyFile(`${REPO}/${f}`, target);
  }
  await Deno.mkdir(`${dir}/.swamp`);
  const installed = Deno.env.get("SWAMP_TEST_EXTENSIONS") ?? `${REPO}/.swamp/pulled-extensions`;
  assert((await new Deno.Command("cp", { args: ["-r", installed, `${dir}/.swamp/`] }).output()).success);
  const def = `${dir}/models/@nckrtl/orbit-gateway/orbit-gateway.yaml`;
  await Deno.writeTextFile(def, (await Deno.readTextFile(def)).replace(/      worker:\n(?:        .*\n)+/, "").replace(/gatewayUrl: .*/, `gatewayUrl: ${url}`).replace("methods: {}", `    - projectId: ${SECOND_PROJECT.projectId}
      repository: ${SECOND_PROJECT.repository}
      script: ${SECOND_PROJECT.script}
      check: ${SECOND_PROJECT.check}
      defaultBranch: ${SECOND_PROJECT.defaultBranch}
methods: {}`));
  return dir;
}

async function tick(dir: string, driver: string, workItem: string, maxSteps: number) {
  await swamp(dir, "model", "method", "run", driver, "tick", "--input", `workItems=["${workItem}"]`, "--input", `maxSteps=${maxSteps}`);
  const text = await swamp(dir, "data", "query", `modelName == "${driver}" && name == "tick"`, "--select", "attributes", "--json");
  const q = JSON.parse(text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1));
  return q.results[0].items.find((row: { workItem: string }) => row.workItem === workItem);
}

async function until(dir: string, driver: string, workItem: string, ready: (row: { stage: string; waiting: string | null }) => boolean) {
  let last;
  for (let i = 0; i < 40; i++) {
    last = await tick(dir, driver, workItem, 3);
    if (ready(last)) return last;
  }
  throw new Error(JSON.stringify(last));
}

function publishKeys(fake: ReturnType<typeof startFakeGateway>, id: number): string[] {
  return [...fake.starts.keys()].filter((key) => key.startsWith(`tg-${id}.publish.`)).sort();
}

Deno.test({
  name: "routine publish retry and republish reuse the saved summary",
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const scenarios: Record<number, Scenario> = { 7: { publishFailOnce: true }, 8: { prClosedOnce: true } };
    const fake = startFakeGateway(scenarios);
    const dir = await copyRepo(fake.url);
    let passed = false;
    try {
      for (const workItem of ["tg-7", "tg-8"]) {
        await swamp(dir, "model", "method", "run", "dependency-routine", "start", "--input", `workItem=${workItem}`);
      }
      await until(dir, "orbit-driver-routine", "tg-7", (row) => row.stage === "publish" && (row.waiting ?? "").includes("publish-retry"));
      assertEquals(fake.groups.get(7)!.tasks[0].status, "completed");
      await swamp(dir, "model", "method", "run", "dependency-routine", "approve", "--input", "workItem=tg-7", "--input", "gateId=publish-retry", "--input", "actor=simulated-operator", "--input", "note=Retry the publish");
      await tick(dir, "orbit-driver-routine", "tg-7", 1);
      await until(dir, "orbit-driver-routine", "tg-7", (row) => row.stage === "completed");
      assertEquals(publishKeys(fake, 7), ["tg-7.publish.c1", "tg-7.publish.c1.summary", "tg-7.publish.c2"]);
      assertEquals(fake.groups.get(7)!.watched_pr_state, "merged");

      await until(dir, "orbit-driver-routine", "tg-8", (row) => row.stage === "merged" && (row.waiting ?? "").includes("republish"));
      assertEquals(fake.groups.get(8)!.tasks[0].status, "completed");
      await swamp(dir, "model", "method", "run", "dependency-routine", "approve", "--input", "workItem=tg-8", "--input", "gateId=republish", "--input", "actor=simulated-operator", "--input", "note=Open the pull request again");
      await until(dir, "orbit-driver-routine", "tg-8", (row) => row.stage === "completed");
      assertEquals(publishKeys(fake, 8), ["tg-8.publish.c1", "tg-8.publish.c1.summary", "tg-8.publish.c2"]);
      assertEquals(fake.groups.get(8)!.watched_pr_state, "merged");
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Retained routine publish-retry fixture: ${dir}`);
    }
  },
});

Deno.test({
  name: "package publish retry and republish reuse the saved summary",
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fake = startFakeGateway({ 7: { publishFailOnce: true }, 8: { prClosedOnce: true } });
    const dir = await copyRepo(fake.url);
    let passed = false;
    const target = (id: number) => ({
      workItem: `tg-${id}`, manager: "composer", package: "livewire/livewire", version: "4.9.9", purpose: "security",
      advisoryUrls: ["https://github.com/advisories/GHSA-fixture-one"], advisories: ["PKSA-xhv3-nr94-h8db"],
    });
    async function workflow(id: number) {
      await Deno.writeTextFile(`${dir}/input.json`, JSON.stringify(target(id)));
      await swamp(dir, "workflow", "run", "dependency-package", "--input-file", `${dir}/input.json`);
    }
    try {
      await workflow(7);
      await until(dir, "orbit-driver-package", "tg-7", (row) => row.stage === "publish" && (row.waiting ?? "").includes("publish-retry"));
      assertEquals(fake.groups.get(7)!.tasks[0].status, "completed");
      await swamp(dir, "model", "method", "run", "dependency-package", "approve", "--input", "workItem=tg-7", "--input", "gateId=publish-retry", "--input", "actor=simulated-operator", "--input", "note=Retry the publish");
      await tick(dir, "orbit-driver-package", "tg-7", 1);
      await until(dir, "orbit-driver-package", "tg-7", (row) => row.stage === "completed");
      assertEquals(publishKeys(fake, 7), ["tg-7.publish.c1", "tg-7.publish.c1.summary", "tg-7.publish.c2"]);

      await workflow(8);
      await until(dir, "orbit-driver-package", "tg-8", (row) => row.stage === "merged" && (row.waiting ?? "").includes("republish"));
      assertEquals(fake.groups.get(8)!.tasks[0].status, "completed");
      await swamp(dir, "model", "method", "run", "dependency-package", "approve", "--input", "workItem=tg-8", "--input", "gateId=republish", "--input", "actor=simulated-operator", "--input", "note=Open the pull request again");
      await until(dir, "orbit-driver-package", "tg-8", (row) => row.stage === "completed");
      assertEquals(publishKeys(fake, 8), ["tg-8.publish.c1", "tg-8.publish.c1.summary", "tg-8.publish.c2"]);
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Retained package publish-retry fixture: ${dir}`);
    }
  },
});
