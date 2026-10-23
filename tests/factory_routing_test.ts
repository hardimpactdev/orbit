// Routing regressions for the orbit-groups factory: the real factory, driver and
// Gateway client, run by swamp in a throwaway copy of this repo against a fake
// Gateway. Every case runs as its own group, and one driver tick steps them all.
// Needs the swamp CLI; it takes a minute or two. Run: ~/.swamp/deno/deno test --allow-all tests/
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type Scenario, startFakeGateway } from "./_support/fake_gateway.ts";

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
  await new Deno.Command("cp", { args: ["-r", `${REPO}/.swamp/pulled-extensions`, `${dir}/.swamp/`] }).output();
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
    try {
      const items = Object.keys(SCENARIOS).map((g) => `tg-${g}`);
      for (const wi of items) await swamp(dir, "model", "method", "run", "orbit-groups", "start", "--input", `workItem=${wi}`);
      await Deno.writeTextFile(`${dir}/tick-input.json`, JSON.stringify({ maxSteps: 3, workItems: items }));

      const seen = new Map<number, Seen>();
      const approved = new Set<string>();
      const done = () => Object.entries(SCENARIOS).every(([g, sc]) => seen.has(Number(g)) && sc.end(seen.get(Number(g))!));
      for (let tick = 0; tick < 70 && !done(); tick++) {
        await swamp(dir, "workflow", "run", "groups-driver", "--input-file", `${dir}/tick-input.json`);
        const q = lastJson(await swamp(dir, "data", "query", 'modelName == "orbit-driver-groups" && name == "tick"', "--select", "attributes", "--json"));
        const row = (q.results as { items: { workItem: string; stage: string; cycle: number; waiting: string | null }[] }[])[0];
        for (const it of row.items) {
          const g = Number(it.workItem.slice(3));
          const s = { stage: it.stage, cycle: it.cycle, waiting: it.waiting ?? "" };
          seen.set(g, s);
          for (const gate of SCENARIOS[g].approve ?? []) {
            const once = `${g}:${gate}`;
            if (!approved.has(once) && s.waiting.includes(gate)) {
              approved.add(once);
              await swamp(dir, "model", "method", "run", "orbit-groups", "approve", "--input", `workItem=${it.workItem}`, "--input", `gateId=${gate}`, "--input", "actor=routing-test");
            }
          }
        }
      }

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
    } finally {
      await fake.close();
      await Deno.remove(dir, { recursive: true }).catch(() => {});
    }
  },
});
