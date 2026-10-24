// Routing regressions for the orbit-groups factory: the real factory, driver and
// Gateway client, run by swamp in a throwaway copy of this repo against a fake
// Gateway. Every case runs as its own group, and one driver tick steps them all.
// Needs the swamp CLI; it takes a minute or two. Run: ~/.swamp/deno/deno test --allow-all tests/
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type Scenario, startFakeGateway } from "./_support/fake_gateway.ts";
import { analysisFixture } from "./_support/analysis_fixture.ts";

const REPO = new URL("..", import.meta.url).pathname;

const SCENARIOS: Record<number, Scenario & { approve?: string[]; end: (s: Seen) => boolean }> = {
  101: { firstReview: "changes_requested", end: (s) => s.stage === "completed" },
  102: { provisionFails: true, end: (s) => s.stage === "failed" },
  103: { baselineFailOnce: true, approve: ["baseline-retry"], end: (s) => s.stage === "completed" },
  104: { publishFailOnce: true, approve: ["publish-retry"], end: (s) => s.stage === "completed" },
  105: { handoffAlwaysFails: true, end: (s) => s.stage === "handoff" && s.waiting.includes("handoff-assistance") },
  106: { subtasks: 4, end: (s) => s.stage === "completed" },
  107: { reviewAlways: "changes_requested", end: (s) => s.stage === "review" && s.waiting.includes("review-cap") },
  108: { prClosedOnce: true, approve: ["republish"], end: (s) => s.stage === "completed" },
  109: { handoffCancelOnce: true, approve: ["handoff-retry"], end: (s) => s.stage === "completed" },
  110: { provisionCancelOnce: true, approve: ["provision-retry"], end: (s) => s.stage === "completed" },
  // A stored pre-approval resumes the run with no approval during the run.
  111: { baselineFailOnce: true, end: (s) => s.stage === "completed" },
  // Pre-approvals for another cycle, another stage, or another group are never applied.
  112: { provisionCancelOnce: true, end: (s) => s.stage === "provision" && s.waiting.includes("provision-retry") },
  // A reset mid-run starts over and still finishes (the driver ignores evidence from before it).
  113: { end: (s) => s.stage === "completed" },
};

interface Seen {
  stage: string;
  cycle: number;
  waiting: string;
}

async function swamp(cwd: string, ...args: string[]): Promise<string> {
  const out = await new Deno.Command("swamp", { args, cwd, stdin: "null", stdout: "piped", stderr: "piped" }).output();
  const text = new TextDecoder().decode(out.stdout) + new TextDecoder().decode(out.stderr);
  if (!out.success) throw new Error(`swamp ${args.join(" ")} failed:\n${text.slice(-2000)}`);
  return text;
}

function lastJson(text: string): Record<string, unknown> {
  return JSON.parse(text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1));
}

/** A throwaway repo: the tracked and new files of this checkout plus its pulled extensions. */
async function copyRepo(gatewayUrl: string): Promise<string> {
  const dir = await Deno.makeTempDir({ prefix: "orbit-groups-routing-" });
  const ls = await new Deno.Command("git", { args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd: REPO, stdout: "piped" }).output();
  for (const f of new TextDecoder().decode(ls.stdout).split("\0").filter(Boolean)) {
    try {
      const target = `${dir}/${f}`;
      await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), { recursive: true });
      await Deno.copyFile(`${REPO}/${f}`, target);
    } catch { /* deleted in the working tree */ }
  }
  await Deno.mkdir(`${dir}/.swamp`, { recursive: true });
  // Copy an already-installed official extension into this disposable test only.
  // This performs no install or write in the source/live checkout.
  const installed = Deno.env.get("SWAMP_TEST_EXTENSIONS") ?? `${REPO}/.swamp/pulled-extensions`;
  const copy = await new Deno.Command("cp", { args: ["-r", installed, `${dir}/.swamp/`] }).output();
  assert(copy.success, "provide existing official extensions via SWAMP_TEST_EXTENSIONS; tests never install packages");
  const def = `${dir}/models/@nckrtl/orbit-gateway/orbit-gateway.yaml`;
  await Deno.writeTextFile(def, (await Deno.readTextFile(def)).replace(/gatewayUrl: .*/, `gatewayUrl: ${gatewayUrl}`));
  return dir;
}

const haveSwamp = (await new Deno.Command("sh", { args: ["-c", "command -v swamp"], stdout: "null", stderr: "null" }).output()).success;

Deno.test({
  name: "orbit-groups routes every case: failures, retries, the handoff streak guard, and per-subtask caps",
  ignore: !haveSwamp,
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fake = startFakeGateway(SCENARIOS);
    const dir = await copyRepo(fake.url);
    let passed = false;
    try {
      const items = Object.keys(SCENARIOS).map((g) => `tg-${g}`);
      for (const wi of items) await swamp(dir, "model", "method", "run", "orbit-groups", "start", "--input", `workItem=${wi}`);
      const preapprove = (wi: string, gateId: string, stage: string, cycle: number) =>
        swamp(dir, "model", "method", "run", "orbit-driver-groups", "preapprove", "--input", `workItem=${wi}`, "--input", `gateId=${gateId}`,
          "--input", `stage=${stage}`, "--input", `cycle=${cycle}`, "--input", "actor=routing-test");
      await preapprove("tg-111", "baseline-retry", "baseline", 1);
      await preapprove("tg-112", "provision-retry", "provision", 2);
      await preapprove("tg-112", "provision-retry", "baseline", 1);
      await preapprove("tg-111", "provision-retry", "provision", 1);
      let refused = false;
      await preapprove("tg-999", "baseline-retry", "baseline", 1).catch(() => (refused = true));
      assert(refused, "a pre-approval for a work item without an active run is refused");
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: items }));

      const seen = new Map<number, Seen>();
      const approved = new Set<string>();
      const plans = new Set<string>();
      let resetDone = false;
      let deferredTicks = 0;
      const done = () => Object.entries(SCENARIOS).every(([g, sc]) => seen.has(Number(g)) && sc.end(seen.get(Number(g))!));
      for (let tick = 0; tick < 70 && !done(); tick++) {
        await swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
        const q = lastJson(await swamp(dir, "data", "query", 'modelName == "orbit-driver-groups" && name == "tick"', "--select", "attributes", "--json"));
        const row = (q.results as { items: { workItem: string; stage: string; cycle: number; waiting: string | null }[]; deferred?: string[] }[])[0];
        if ((row.deferred ?? []).length > 0) deferredTicks++;
        for (const it of row.items) {
          const g = Number(it.workItem.slice(3));
          const s = { stage: it.stage, cycle: it.cycle, waiting: it.waiting ?? "" };
          seen.set(g, s);
          if (s.stage === "plan") {
            const identity = `${g}:${s.cycle}:${resetDone}`;
            if (!plans.has(identity)) {
              const fixture = await analysisFixture(fake.groups.get(g)!);
              await swamp(dir, "model", "method", "run", "orbit-groups", "record_artifact", "--input", `workItem=${it.workItem}`, "--input", "name=plan", "--input", `payload=${JSON.stringify(fixture.plan)}`);
              await swamp(dir, "model", "method", "run", "orbit-groups", "approve", "--input", `workItem=${it.workItem}`, "--input", "gateId=plan-authorized", "--input", "actor=simulated-plan-owner");
              plans.add(identity);
            }
          }
          if (g === 113 && !resetDone && s.stage === "implement") {
            resetDone = true;
            await swamp(dir, "model", "method", "run", "orbit-groups", "reset", "--input", `workItem=${it.workItem}`, "--input", "confirm=reset");
          }
          for (const gate of SCENARIOS[g].approve ?? []) {
            const once = `${g}:${gate}`;
            if (!approved.has(once) && s.waiting.includes(gate)) {
              approved.add(once);
              await swamp(dir, "model", "method", "run", "orbit-groups", "approve", "--input", `workItem=${it.workItem}`, "--input", `gateId=${gate}`, "--input", "actor=routing-test");
            }
          }
        }
      }

      const retryComments = (g: number) => fake.commentsOf(g).filter((c) => !c.body.includes(" at plan, "));
      const status = Object.fromEntries([...seen].map(([g, s]) => [g, `${s.stage} c${s.cycle} ${s.waiting}`]));
      for (const [g, sc] of Object.entries(SCENARIOS)) assert(sc.end(seen.get(Number(g))!), `group ${g} ended at ${status[g]}`);

      // No Idempotency-Key ever started twice.
      assertEquals([...fake.starts].filter(([, n]) => n > 1), []);
      // A rework continues to completion.
      assertEquals([fake.startsOf(101, "implement"), fake.startsOf(101, "review")], [2, 2]);
      // A provision that ended the group ends the run; nothing else starts, and no retry is offered.
      assertEquals(fake.startsOf(102, "baseline"), 0);
      assertEquals(fake.startsOf(102, "provision"), 1);
      // A cancelled queued provision leaves the group open: it retries under a new key.
      assertEquals(fake.startsOf(110, "provision"), 2);
      assert(fake.starts.has("tg-110.provision.c2"));
      // Retries run under new cycle keys after the approval.
      assertEquals(fake.startsOf(103, "baseline"), 2);
      assertEquals(fake.startsOf(104, "publish"), 2);
      assertEquals(fake.startsOf(108, "publish"), 2);
      assertEquals(fake.startsOf(109, "handoff"), 2);
      // The streak guard parks after the 4th failing handoff in a row.
      assertEquals([fake.startsOf(105, "implement"), fake.startsOf(105, "handoff")], [4, 4]);
      // Caps count per subtask: four subtasks need four reviews.
      assertEquals(fake.startsOf(106, "review"), 4);
      assertEquals(fake.groups.get(106)!.tasks.map((t) => t.status), ["completed", "completed", "completed", "completed"]);
      // The review cap parks the 3rd change request on one subtask.
      assertEquals([fake.startsOf(107, "implement"), fake.startsOf(107, "review")], [3, 3]);
      // A stored pre-approval resumed 111 without any approval during the run, and raised nothing on the board.
      assertEquals(fake.startsOf(111, "baseline"), 2);
      assertEquals(retryComments(111), []);
      // 112's pre-approvals name another cycle, another stage, and (on tg-111) another group: none applied.
      assertEquals(fake.startsOf(112, "provision"), 1);
      // The reset ran and the run still completed.
      assert(resetDone, "113 was reset mid-run");
      // Thirteen groups need more calls than one execution allows: ticks deferred some and still finished them all.
      assert(deferredTicks > 0, "a tick deferred work items to stay within swamp's call cap");

      // Board assistance: a parked run raises it once with a plain reason; a resumed run clears it.
      // Initial plan authorization is a new explicit gate. Keep the old retry
      // assertions scoped to their own assistance entries.
      const types = (g: number) => retryComments(g).map((c) => c.type);
      assertEquals(types(105), ["assistance_requested"]);
      assertStringIncludes(fake.commentsOf(105)[0].body, "at handoff, cycle 4: the handoff check");
      assertStringIncludes(fake.commentsOf(105)[0].body, "handoff-assistance");
      assertEquals(fake.groups.get(105)!.tasks[0].assistance_requested, true);
      assertEquals(types(112), ["assistance_requested"]);
      assertStringIncludes(fake.commentsOf(112)[0].body, "provision-retry");
      assertEquals(types(103), ["assistance_requested", "resolution"]);
      assertStringIncludes(fake.commentsOf(103)[0].body, "at baseline, cycle 1: the baseline operation");
      assertEquals(fake.groups.get(103)!.tasks[0].assistance_requested, false);
      assertEquals(types(101), []);
      // Both flags, parent and child: a raise on an open subtask is mirrored on the group, and its
      // resolution clears both; a parked run still shows on both.
      const flags = (g: number) => [fake.groups.get(g)!.assistance_requested, fake.groups.get(g)!.tasks.map((x) => x.assistance_requested)];
      assertEquals(flags(103), [false, [false]]);
      assertEquals(flags(105), [true, [true]]);
      // Post-child gates use group comments, never an ended child's route.
      for (const [g, gate] of [[104, "publish-retry"], [108, "republish"]] as const) {
        assertEquals(types(g), ["assistance_requested", "resolution"]);
        assert(retryComments(g).every((c) => c.subtask === null));
        assertStringIncludes(retryComments(g)[0].body, `Gate: ${gate}; work item: tg-${g};`);
        assertEquals(flags(g), [false, [false]]);
      }
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Failed fixture and method/workflow reports retained: ${dir}`);
    }
  },
});
