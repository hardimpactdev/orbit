// Routing regressions for the orbit-groups factory: the real factory, driver and
// Gateway client, run by swamp in a throwaway copy of this repo against a fake
// Gateway. Every case runs as its own group, and one driver tick steps them all.
// Needs the swamp CLI; it takes a minute or two. Run: ~/.swamp/deno/deno test --allow-all tests/
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type Scenario, startFakeGateway } from "./_support/fake_gateway.ts";
import { analysisFixture } from "./_support/analysis_fixture.ts";
import { canonical, planConsentNote, sha256 } from "../extensions/behaviors/_lib/repository_analysis.ts";

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
              await swamp(dir, "model", "method", "run", "orbit-groups", "approve", "--input", `workItem=${it.workItem}`, "--input", "gateId=plan-authorized", "--input", "actor=simulated-plan-owner", "--input", `note=${planConsentNote(fixture.plan)}`);
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

Deno.test({
  name: "real factory binds plan consent to exact digest and scope, never a generic preapproval or replaced plan",
  ignore: !haveSwamp,
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fake = startFakeGateway({ 777: {}, 778: {} });
    const dir = await copyRepo(fake.url);
    const proof = await Deno.makeTempDir({ prefix: "swamp-plan-consent-evidence-" });
    let passed = false;
    try {
      let workItem = "tg-777";
      const method = (name: string, ...inputs: string[]) => swamp(dir, "model", "method", "run", "orbit-groups", name, "--input", `workItem=${workItem}`, ...inputs.flatMap((v) => ["--input", v]));
      await method("start");
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: [workItem] }));
      const tick = () => swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
      const status = async () => {
        await method("status");
        const q = lastJson(await swamp(dir, "data", "query", `modelName == "orbit-groups" && name == "status-${workItem}"`, "--select", "attributes", "--json"));
        return (q.results as Record<string, unknown>[])[0];
      };
      let s = await status();
      for (let n = 0; n < 20 && (s.stage as { id: string }).id !== "plan"; n++) { await tick(); s = await status(); }
      assertEquals((s.stage as { id: string }).id, "plan");
      const f = await analysisFixture(fake.groups.get(777)!);
      const record = (payload: unknown) => method("record_artifact", "name=plan", `payload=${JSON.stringify(payload)}`);
      const approve = (note: string) => method("approve", "gateId=plan-authorized", "actor=simulated-exact-plan-owner", `note=${note}`);
      const closed = async (label: string) => {
        await tick();
        const s = await status();
        await Deno.writeTextFile(`${proof}/${label}.json`, JSON.stringify(s, null, 2));
        assertEquals((s.stage as { id: string }).id, "plan", label);
        const g = Number(workItem.slice(3));
        assertEquals(fake.startsOf(g, "analyze"), 0, label);
        assertEquals(fake.startsOf(g, "implement"), 0, label);
        assertEquals(fake.startsOf(g, "review"), 0, label);
        const authorized = (s.transitions as { name: string; satisfied: boolean }[]).find((t) => t.name === "authorized");
        assertEquals(authorized?.satisfied, false, label);
      };
      await record(f.plan);
      await approve(planConsentNote(f.plan));
      const replacement = structuredClone(f.plan);
      replacement.manifest.revision++;
      replacement.manifest.paths.unshift({ change: "add", path: "extra-unapproved.txt" });
      replacement.plan_sha256 = await sha256(canonical(replacement.manifest));
      await record(replacement);
      await closed("v1-approved-v2-replaced");
      // Scope is part of the consent even when the manifest digest is unchanged.
      const foreignScope = { ...replacement, branch: "different-task-branch" };
      await approve(planConsentNote(foreignScope));
      await closed("wrong-scope-note");
      await approve(planConsentNote(replacement));
      for (let n = 0; n < 10 && fake.startsOf(777, "analyze") === 0; n++) await tick();
      assertEquals(fake.startsOf(777, "analyze"), 1);
      const q = lastJson(await swamp(dir, "data", "query", 'modelName == "orbit-gateway" && name == "job-tg-777.analyze.c1"', "--select", "attributes.request", "--json"));
      const request = (q.results as { body: Record<string, unknown> }[])[0];
      assertEquals(request.body.plan_sha256, replacement.plan_sha256);
      assertEquals(request.body.plan, replacement.manifest);
      assertEquals([...fake.starts].filter(([, n]) => n > 1), []);
      await Deno.writeTextFile(`${proof}/admitted.json`, JSON.stringify({ request, starts: [...fake.starts], consentNote: planConsentNote(replacement) }, null, 2));
      // A separate run proves an unspecified future plan is never authorized.
      workItem = "tg-778";
      await method("start");
      await swamp(dir, "model", "method", "run", "orbit-driver-groups", "preapprove", "--input", `workItem=${workItem}`, "--input", "gateId=plan-authorized", "--input", "stage=plan", "--input", "cycle=1", "--input", "actor=generic-before-any-plan");
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: [workItem] }));
      s = await status();
      for (let n = 0; n < 20 && (s.stage as { id: string }).id !== "plan"; n++) { await tick(); s = await status(); }
      assertEquals((s.stage as { id: string }).id, "plan");
      await closed("generic-before-plan");
      await record((await analysisFixture(fake.groups.get(778)!)).plan);
      await closed("generic-after-plan");
      console.log(`Exact plan consent proof (real factory, fake Gateway): ${proof}`);
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Failed consent fixture and method/workflow reports retained: ${dir}; proof: ${proof}`);
    }
  },
});

Deno.test({
  name: "real factory binds plan consent to the latest decision after rejection",
  ignore: !haveSwamp,
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async (t) => {
    const fake = startFakeGateway({ 780: {}, 781: {} });
    const dir = await copyRepo(fake.url);
    const proof = await Deno.makeTempDir({ prefix: "swamp-plan-decision-evidence-" });
    let passed = true;
    try {
      for (const [group, variant] of [[780, "generic-reapproval"], [781, "rejected-plan-restored"]] as const) {
        const ok = await t.step(variant, async () => {
          const workItem = `tg-${group}`;
          const method = (name: string, ...inputs: string[]) => swamp(dir, "model", "method", "run", "orbit-groups", name, "--input", `workItem=${workItem}`, ...inputs.flatMap((v) => ["--input", v]));
          const query = async (name: string) => (lastJson(await swamp(dir, "data", "query", `modelName == "orbit-groups" && name == "${name}"`, "--select", "attributes", "--json")).results as Record<string, unknown>[])[0];
          const status = async () => { await method("status"); return await query(`status-${workItem}`); };
          const record = (payload: unknown) => method("record_artifact", "name=plan", `payload=${JSON.stringify(payload)}`);
          const approve = (note: string) => method("approve", "gateId=plan-authorized", "actor=simulated-plan-owner", `note=${note}`);
          const f = await analysisFixture(fake.groups.get(group)!);
          await method("start");
          // Even an exact note from a different stage is not applicable consent.
          await approve(planConsentNote(f.plan));
          await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: [workItem] }));
          const tick = () => swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
          let s = await status();
          for (let n = 0; n < 20 && (s.stage as { id: string }).id !== "plan"; n++) { await tick(); s = await status(); }
          assertEquals((s.stage as { id: string }).id, "plan");
          const closed = async (label: string) => {
            await tick();
            const s = await status();
            await Deno.writeTextFile(`${proof}/${variant}-${label}.json`, JSON.stringify({ status: s, starts: [...fake.starts] }, null, 2));
            assertEquals((s.stage as { id: string }).id, "plan", label);
            assertEquals(fake.startsOf(group, "analyze"), 0, label);
            assertEquals(fake.startsOf(group, "implement"), 0, label);
            assertEquals(fake.startsOf(group, "review"), 0, label);
            assertEquals((s.transitions as { name: string; satisfied: boolean }[]).find((v) => v.name === "authorized")?.satisfied, false, label);
          };
          await record(f.plan);
          await closed("exact-from-other-stage");
          // Stored generic preapproval alone must not override a rejection.
          await swamp(dir, "model", "method", "run", "orbit-driver-groups", "preapprove", "--input", `workItem=${workItem}`, "--input", "gateId=plan-authorized", "--input", "stage=plan", "--input", "cycle=1", "--input", "actor=simulated-older-generic-owner");
          await record(f.plan);
          await approve(planConsentNote(f.plan));
          await method("reject", "gateId=plan-authorized", "actor=simulated-plan-owner", "note=Withdraw this exact plan consent; revise the plan before analysis.");
          await closed("rejection-only");
          await closed("rejection-still-closed");
          if (variant === "generic-reapproval") {
            await approve("General approval only; no artifact digest or scope.");
            await closed("generic-after-rejection");
          } else {
            const v2 = structuredClone(f.plan);
            v2.manifest.revision++;
            v2.manifest.paths.unshift({ change: "add", path: "extra-v2.txt" });
            v2.plan_sha256 = await sha256(canonical(v2.manifest));
            await record(v2);
            await approve(planConsentNote(v2));
            const approved = await status();
            assertEquals((approved.transitions as { name: string; satisfied: boolean }[]).find((v) => v.name === "authorized")?.satisfied, true);
            await Deno.writeTextFile(`${proof}/${variant}-v2-authorized.json`, JSON.stringify({ plan: v2, status: approved }, null, 2));
            // No driver tick between approval of v2 and restoring rejected v1.
            await record(f.plan);
            await closed("rejected-v1-restored");
          }
          await approve(planConsentNote({ ...f.plan, branch: "wrong-task-branch" }));
          await closed("wrong-scope-after-rejection");
          if (variant === "generic-reapproval") {
            // Latest means authoritative even without a new rejection: do not
            // fall back to an older exact record behind a generic decision.
            await approve(planConsentNote(f.plan));
            await approve("Superseding generic decision; exact plan not authorized.");
            await closed("generic-supersedes-fresh-exact");
          }
          await approve(planConsentNote(f.plan));
          for (let n = 0; n < 10 && fake.startsOf(group, "analyze") === 0; n++) await tick();
          assertEquals(fake.startsOf(group, "analyze"), 1);
          const q = lastJson(await swamp(dir, "data", "query", `modelName == "orbit-gateway" && name == "job-${workItem}.analyze.c1"`, "--select", "attributes.request", "--json"));
          const request = (q.results as { body: Record<string, unknown> }[])[0];
          assertEquals(request.body.plan_sha256, f.plan.plan_sha256);
          assertEquals(request.body.plan, f.plan.manifest);
          assertEquals(fake.startsOf(group, "implement"), 0);
          assertEquals(fake.startsOf(group, "review"), 0);
          assertEquals([...fake.starts].filter(([, n]) => n > 1), []);
          await Deno.writeTextFile(`${proof}/${variant}-fresh-v1-admitted.json`, JSON.stringify({ request, starts: [...fake.starts], latestDecision: await query(`approval-${workItem}-plan-authorized`) }, null, 2));
        });
        passed = ok && passed;
      }
      console.log(`Latest plan decision proof (real factory, fake Gateway): ${proof}`);
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Failed decision fixture and method/workflow reports retained: ${dir}; proof: ${proof}`);
    }
  },
});

Deno.test({
  name: "real factory consumes public immutable provision identity and failed analysis metadata",
  ignore: !haveSwamp,
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fixture = async (name: string) => JSON.parse(await Deno.readTextFile(new URL(`./fixtures/repository-analysis-public/${name}.json`, import.meta.url))).body.data.result;
    const pinned = await fixture("tasks-provision-pinned");
    const overflow = await fixture("tasks-analyze-failure-overflow");
    const missing = await fixture("tasks-analyze-failure-unavailable");
    const fake = startFakeGateway({
      790: { provisionIdentity: { ...pinned, analysis_base: { ...pinned.analysis_base, repository: "https://example.test/repo.git", branch: "task-790" } } },
      791: { provisionIdentity: await fixture("tasks-provision-unavailable") },
      792: { analysisFailure: overflow },
      793: { analysisFailure: missing },
      794: { analysisFailure: { schema: 1, error: { code: overflow.error.code, required_bytes: null } } },
    });
    const dir = await copyRepo(fake.url);
    const proof = await Deno.makeTempDir({ prefix: "swamp-public-analysis-factory-" });
    let passed = false;
    try {
      const method = (id: number, name: string, ...inputs: string[]) => swamp(dir, "model", "method", "run", "orbit-groups", name, "--input", `workItem=tg-${id}`, ...inputs.flatMap((v) => ["--input", v]));
      const query = async (model: string, name: string) => (lastJson(await swamp(dir, "data", "query", `modelName == "${model}" && name == "${name}"`, "--select", "attributes", "--json")).results as Record<string, unknown>[])[0];
      const ids = [790, 791, 792, 793, 794];
      for (const id of ids) await method(id, "start");
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: ids.map((id) => `tg-${id}`) }));
      const tick = () => swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
      const planned = new Set<number>();
      const last = new Map<number, Record<string, unknown>>();
      for (let n = 0; n < 20; n++) {
        await tick();
        for (const id of ids) {
          await method(id, "status");
          const s = await query("orbit-groups", `status-tg-${id}`);
          last.set(id, s);
          const stage = (s.stage as { id: string }).id;
          if (stage === "plan" && !planned.has(id)) {
            const f = await analysisFixture(fake.groups.get(id)!);
            // In the divergent fixture, starting_commit is aaaa; the pin is bbbb.
            if (id === 790) f.plan.base = pinned.analysis_base.base;
            await method(id, "record_artifact", "name=plan", `payload=${JSON.stringify(f.plan)}`);
            await method(id, "approve", "gateId=plan-authorized", "actor=simulated-owner", `note=${planConsentNote(f.plan)}`);
            planned.add(id);
          }
        }
        if (fake.startsOf(790, "implement") === 1 && [792, 793, 794].every((id) => (last.get(id)?.stage as { id: string }).id === "analyze" &&
          (last.get(id)?.transitions as { name: string; gates: { type: string; pass: boolean }[] }[]).some((t) => t.name === "revise" && t.gates.some((g) => g.type === "human-approval" && !g.pass)))) break;
      }
      const workspace = (await query("orbit-groups", "artifact-tg-790-workspace")).payload as Record<string, unknown>;
      assertEquals(workspace.startingCommit, pinned.starting_commit);
      assertEquals((workspace.analysisBase as { base: string }).base, pinned.analysis_base.base);
      const request = (await query("orbit-gateway", "job-tg-790.analyze.c1")).request as { body: Record<string, unknown> };
      assertEquals(request.body.base, pinned.analysis_base.base);
      assertEquals(fake.startsOf(790, "implement"), 1);
      const author = (await query("orbit-gateway", "job-tg-790.implement.c1")).request as { body: Record<string, unknown> };
      assert(author.body.analysis);
      assertEquals((last.get(791)!.stage as { id: string }).id, "provision");
      assertEquals(fake.startsOf(791, "baseline"), 0);
      assertEquals(fake.startsOf(791, "analyze"), 0);
      for (const [id, bytes] of [[792, 216325], [793, null], [794, null]] as const) {
        const job = await query("orbit-gateway", `job-tg-${id}.analyze.c1`);
        assertEquals(job.state, "failed");
        assertEquals((job.evidence as Record<string, unknown>).requiredBytes, bytes);
        assertEquals(job.artifacts, {});
        assertEquals(fake.startsOf(id, "analyze"), 1);
        assertEquals(fake.startsOf(id, "implement"), 0);
        assertEquals(fake.startsOf(id, "review"), 0);
        await Deno.writeTextFile(`${proof}/${id}-failed.json`, JSON.stringify({ job, status: last.get(id) }, null, 2));
      }
      assertEquals([...fake.starts].filter(([, n]) => n > 1), []);
      await Deno.writeTextFile(`${proof}/pins-and-turn.json`, JSON.stringify({ workspace, request, author, unavailable: last.get(791), starts: [...fake.starts] }, null, 2));
      console.log(`Public DTO real-factory proof (simulated Gateway): ${proof}`);
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Failed public DTO fixture and method/workflow reports retained: ${dir}; proof: ${proof}`);
    }
  },
});

Deno.test({
  name: "real factory parks a failed edited author until retry approval and reanalyzes its terminal candidate",
  ignore: !haveSwamp,
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const fake = startFakeGateway({ 779: { implementFailOnce: true } });
    const dir = await copyRepo(fake.url);
    const proof = await Deno.makeTempDir({ prefix: "swamp-failed-author-evidence-" });
    let passed = false;
    try {
      const method = (name: string, ...inputs: string[]) => swamp(dir, "model", "method", "run", "orbit-groups", name, "--input", "workItem=tg-779", ...inputs.flatMap((v) => ["--input", v]));
      const query = async (name: string) => {
        const q = lastJson(await swamp(dir, "data", "query", `modelName == "orbit-groups" && name == "${name}"`, "--select", "attributes", "--json"));
        return (q.results as Record<string, unknown>[])[0];
      };
      const status = async () => { await method("status"); return await query("status-tg-779"); };
      await method("start");
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: ["tg-779"] }));
      const tick = () => swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
      let s = await status();
      for (let n = 0; n < 20 && (s.stage as { id: string }).id !== "plan"; n++) { await tick(); s = await status(); }
      assertEquals((s.stage as { id: string }).id, "plan");
      const f = await analysisFixture(fake.groups.get(779)!);
      await method("record_artifact", "name=plan", `payload=${JSON.stringify(f.plan)}`);
      await method("approve", "gateId=plan-authorized", "actor=simulated-plan-owner", `note=${planConsentNote(f.plan)}`);
      let failedChange: Record<string, unknown> | undefined;
      for (let n = 0; n < 20 && !failedChange; n++) {
        await tick();
        failedChange = (await query("artifact-tg-779-change"))?.payload as Record<string, unknown> | undefined;
      }
      assertEquals(failedChange?.outcome, "blocked");
      assertEquals(failedChange?.candidate, { head: f.plan.base, tree: "c".repeat(40) });
      assertEquals(failedChange?.attempt, 1);
      await tick(); await tick(); // Unapproved ticks must not dispatch analysis or another turn.
      s = await status();
      assertEquals((s.stage as { id: string }).id, "implement");
      assertEquals(fake.startsOf(779, "analyze"), 1);
      assertEquals(fake.startsOf(779, "implement"), 1);
      assertEquals(fake.startsOf(779, "handoff"), 0);
      assertEquals(fake.startsOf(779, "review"), 0);
      await Deno.writeTextFile(`${proof}/parked.json`, JSON.stringify({ status: s, change: failedChange, evidence: await query("evidence-tg-779-implement-run") }, null, 2));
      await method("approve", "gateId=implement-retry", "actor=simulated-retry-owner");
      let change = failedChange;
      for (let n = 0; n < 20 && change?.attempt !== 2; n++) {
        await tick(); change = (await query("artifact-tg-779-change"))?.payload as Record<string, unknown> | undefined;
      }
      assertEquals(change?.attempt, 2);
      assertEquals(change?.outcome, "ready_for_review");
      assertEquals(change?.threadId, failedChange?.threadId);
      const operations = [...fake.operations.values()];
      const analyses = operations.filter((o) => o.kind === "tasks.analyze");
      assertEquals((analyses[0].input as Record<string, unknown>).tree, "b".repeat(40));
      assertEquals((analyses[1].input as Record<string, unknown>).tree, "c".repeat(40));
      assertEquals(analyses[1].status, "succeeded");
      const turns = operations.filter((o) => o.kind === "tasks.turn" && (o.input as Record<string, unknown>).role === "implementer");
      assertEquals(turns.length, 2);
      assertEquals(turns[0].status, "failed");
      assertEquals((turns[1].input as Record<string, unknown>).thread, String(failedChange?.threadId));
      assertEquals(((turns[1].input as Record<string, unknown>).analysis as Record<string, unknown>).operation, analyses[1].id);
      assertEquals([...fake.starts].filter(([, n]) => n > 1), []);
      await Deno.writeTextFile(`${proof}/recovered.json`, JSON.stringify({ change, operations, starts: [...fake.starts] }, null, 2));
      console.log(`Failed author recovery proof (real factory, fake Gateway): ${proof}`);
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else console.log(`Failed author fixture and method/workflow reports retained: ${dir}; proof: ${proof}`);
    }
  },
});
