// Pure helpers behind the stage workflows: ~/.swamp/deno/deno test tests/
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { cleanEnv, lockChanges } from "../extensions/behaviors/_lib/composer_lock.ts";
import { codexArgs } from "../extensions/behaviors/_lib/codex_args.ts";
import {
  cycleOf,
  type Envelope,
  firstJson,
  implementContext,
  implementOutcome,
  nextStreak,
  toFactoryFindings,
} from "../extensions/behaviors/_lib/stage_logic.ts";

const env = (payload: Record<string, unknown>, recordedAt: string): Envelope => ({ payload, recordedAt });

Deno.test("lockChanges lists moved, added and removed packages", () => {
  const before = { packages: [{ name: "a/a", version: "1.0.0" }, { name: "b/b", version: "2.0.0" }], "packages-dev": [{ name: "gone/x", version: "1.0.0" }] };
  const after = { packages: [{ name: "a/a", version: "1.1.0" }, { name: "b/b", version: "2.0.0" }, { name: "new/n", version: "0.1.0" }] };
  assertEquals(lockChanges(before, after), [
    { name: "a/a", from: "1.0.0", to: "1.1.0" },
    { name: "gone/x", from: "1.0.0", to: null },
    { name: "new/n", from: null, to: "0.1.0" },
  ]);
});

Deno.test("cleanEnv keeps the allowlist and drops agent markers", () => {
  const source: Record<string, string> = { PATH: "/bin", HOME: "/h", CLAUDECODE: "1", AI_AGENT: "claude" };
  assertEquals(cleanEnv((k) => source[k]), { PATH: "/bin", HOME: "/h", TERM: "dumb" });
});

Deno.test("cycleOf reads the cycle from the key", () => {
  assertEquals(cycleOf("bump-apps-gateway.handoff.c12"), 12);
});

Deno.test("nextStreak counts consecutive failures and a pass resets it", () => {
  assertEquals(nextStreak(null, true), 1);
  assertEquals(nextStreak(env({ consecutiveFailures: 3 }, "t"), true), 4);
  assertEquals(nextStreak(env({ consecutiveFailures: 3 }, "t"), false), 0);
});

Deno.test("implementContext gives the implementer only the newest reason", () => {
  const failedHandoff = env({ status: "failed", exitCode: 1 }, "2026-10-22T10:02");
  const review = env({ findings: [{ id: "REV-1" }] }, "2026-10-22T10:01");
  assertEquals(implementContext(failedHandoff, review, null).feedback, failedHandoff.payload);
  assertEquals(implementContext(failedHandoff, review, null).findings, null);
  const laterReview = env({ findings: [] }, "2026-10-22T10:03");
  assertEquals(implementContext(failedHandoff, laterReview, null).feedback, null);
  const passedHandoff = env({ status: "succeeded" }, "2026-10-22T10:04");
  assertEquals(implementContext(passedHandoff, null, null).feedback, null);
});

Deno.test("implementOutcome reads the DONE / BLOCKED contract", () => {
  assertEquals(implementOutcome("work\nDONE: fixed it"), { outcome: "done", text: "fixed it" });
  assertEquals(implementOutcome("hmm\nBLOCKED: which version?"), { outcome: "blocked", text: "which version?" });
  assertEquals(implementOutcome("").outcome, "error");
});

Deno.test("toFactoryFindings gives stable ids and keeps the location", () => {
  assertEquals(toFactoryFindings([{ severity: "high", file: "a.php", line: 3, description: "bad" }]), [
    { id: "REV-1", severity: "high", category: "review", description: "a.php:3: bad" },
  ]);
});

Deno.test("firstJson tolerates a code fence", () => {
  assertEquals(firstJson('```json\n{"answer": "x", "needsPerson": false}\n```'), { answer: "x", needsPerson: false });
  assertEquals(firstJson("no json"), null);
});

Deno.test("codexArgs keeps agent-runner's hermetic flags and adds only routing", () => {
  const argv = codexArgs({
    cwd: "/wt", prompt: "p", model: "gpt-6.1-sol", sandbox: "workspace-write", outputPath: "/o",
    providerId: "cliproxyapi", baseUrl: "http://proxy/v1", wireApi: "responses", auth: { command: "helper", args: ["x"] },
  });
  for (const flag of ["--ephemeral", "--ignore-user-config", "--ignore-rules"]) assertEquals(argv.includes(flag), true);
  assertEquals(argv.includes('model_providers.cliproxyapi.base_url="http://proxy/v1"'), true);
  assertEquals(argv.includes('model_providers.cliproxyapi.auth.args=["x"]'), true);
  assertEquals(argv.at(-1), "p");
});
