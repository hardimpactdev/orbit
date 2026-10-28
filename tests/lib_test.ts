import { render, type Change } from "../extensions/behaviors/_lib/maintenance/summary.ts";
import type { ProjectType } from "../extensions/behaviors/_lib/maintenance/project_type.ts";
// Pure helpers behind the stage workflows: ~/.swamp/deno/deno test tests/
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
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

Deno.test("app major PR text lists versions plainly; only package Projects retain the upgrade warning", () => {
  const changes: Change[] = [{manager:"composer", path:".", package:"example/library", from:"1.0.0", to:"2.0.0", major:true, advisories:[]}];
  for (const type of ["laravel-app","symfony-app","monorepo"] as ProjectType[]) {
    const text = render(changes,type).body;
    assertEquals(text.includes("| example/library | 1.0.0 | 2.0.0 | major |"),true);
    assertEquals(text.includes("Check its upgrade notes"),false);
    assertEquals(text.includes("Action needed"),false);
  }
  assertEquals(render(changes).body.includes("Check its upgrade notes"),false);
  for (const type of ["laravel-package","node-package"] as ProjectType[]) {
    assertEquals(render(changes,type).body.includes("Check its upgrade notes for downstream breaking changes."),true);
  }
});

import { assertRejects } from "jsr:@std/assert@1.0.19";
import { impacts } from "../extensions/behaviors/_lib/maintenance/impact.ts";

Deno.test("repair impact binds explanations to changed updates, source files and the checked tree", async () => {
  const root = await Deno.makeTempDir({ prefix: "impact-report-" });
  const git = async (...args: string[]) => {
    const r = await new Deno.Command("git", { cwd: root, args, stdout: "piped", stderr: "piped" }).output();
    assertEquals(r.code, 0, new TextDecoder().decode(r.stderr));
    return new TextDecoder().decode(r.stdout).trim();
  };
  try {
    await git("init");
    await Deno.writeTextFile(`${root}/package.json`, "{}");
    await Deno.writeTextFile(`${root}/layout.ts`, "before");
    await git("add", ".");
    await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "base");
    const base = await git("rev-parse", "HEAD"), oldTree = await git("rev-parse", "HEAD^{tree}");
    const rows: Change[] = [
      { manager: "js", path: ".", package: "shaders", from: "2.0.0", to: "4.0.4", major: true, advisories: [] },
      { manager: "js", path: ".", package: "icons", from: "1.0.0", to: "2.0.0", major: true, advisories: [{ id: "GHSA-example", url: "https://github.com/advisories/GHSA-example" }] },
    ];
    const place = [{ path: ".", dir: root }];
    const unchanged = render(rows, "laravel-app", await impacts(root, place, base, rows));
    assertEquals((unchanged.body.match(/No code changes needed\./g) ?? []).length, 2);
    await Deno.writeTextFile(`${root}/layout.ts`, "WebGPU only");
    await git("add", ".");
    await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "repair");
    const tree = await git("rev-parse", "HEAD^{tree}");
    const updates = [
      { manager: "js", path: ".", package: "shaders", codeChanged: true, effect: "The light-ray effect now appears only when the browser offers WebGPU.", files: ["layout.ts"] },
      { manager: "js", path: ".", package: "icons", codeChanged: false, effect: "", files: [] },
    ];
    const context = { report: JSON.stringify({ updates }), inventory: updates.map(({ manager, path, package: name }) => ({ manager: manager as "js", path, package: name })), scriptTree: oldTree, repairTree: tree, checkedTree: tree };
    const body = render(rows, "laravel-app", await impacts(root, place, base, rows, context)).body;
    assertEquals(body.includes("- **shaders 4.** The light-ray effect now appears only when the browser offers WebGPU."), true);
    assertEquals(body.includes("- **icons 2.** No code changes needed."), true);
    assertEquals(body.includes("security fix"), true);
    assertEquals(body.includes("https://github.com/advisories/GHSA-example"), true);
    assertEquals(/Tested|Validation|Gateway|handoff|admitted targets|check its upgrade notes/i.test(body), false);
    await assertRejects(() => impacts(root, place, base, rows), Error, "require a repair impact report");
    await assertRejects(() => impacts(root, place, base, rows, { ...context, report: "not JSON" }), Error, "Invalid repair impact report");
    await assertRejects(() => impacts(root, place, base, rows, { ...context, checkedTree: oldTree }), Error, "checked candidate");
    await assertRejects(() => impacts(root, place, base, rows, { ...context, repairTree: oldTree }), Error, "Source changed after");
    for (const invalid of [
      [...updates, updates[0]],
      [{ ...updates[0], package: "unknown" }, updates[1]],
      [{ ...updates[0], manager: "composer" }, updates[1]],
      [{ ...updates[0], files: ["missing.ts"] }, updates[1]],
      [updates[0]],
      [{ ...updates[0], codeChanged: false, effect: "", files: [] }, updates[1]],
      [{ ...updates[0], effect: "" }, updates[1]],
    ]) await assertRejects(() => impacts(root, place, base, rows, { ...context, report: JSON.stringify({ updates: invalid }) }));
    // A dependency-only rerun may change the tree while the repaired source stays the same.
    await Deno.writeTextFile(`${root}/package.json`, '{"dependencies":{"shaders":"^4.0.4"}}');
    await git("add", ".");
    await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "rerun");
    assertEquals((await impacts(root, place, base, rows, { ...context, checkedTree: await git("rev-parse", "HEAD^{tree}") })).size, 2);
  } finally { await Deno.remove(root, { recursive: true }); }
});

import { assertThrows } from "jsr:@std/assert@1.0.19";
import { impactKey, MAX_REPORT_LENGTH, repairReport } from "../extensions/behaviors/_lib/maintenance/impact.ts";

for (const effect of [
  "See [notes](https://evil.example)", "![image](https://evil.example)",
  "<img src=x>", "<details>", "text > text", "cc @nckrtl", "fixes #12",
  "GH-12", "owner/repo#12", "Use `types`", "first\u2028second", "first\u2029second",
  "&#x40;nckrtl", "&commat;nckrtl", "&#x23;46", "owner/repo&#x23;46",
  "first\u200Bsecond", "first\u200Dsecond", "first\u202Esecond",
  "Fixed in 136e32a", "Fixed in 136e32a5ce33b99527b1dcb66ce786ecb81a24d4",
  "1e75288...136e32a", ":shipit:", ":octocat:", "$x^2$", "$$x$$",
  "www.example.com", "See www.", "Safe\n", "\u2028Safe",
  "See [notes][reference]", "See https://evil.example", "See www.evil.example",
]) Deno.test(`repair impact rejects active public prose: ${JSON.stringify(effect)}`, () => {
  const update = { manager: "js" as const, path: ".", package: "types", codeChanged: true, effect, files: ["types.d.ts"] };
  assertThrows(() => repairReport(JSON.stringify({ updates: [update] })), Error, "Invalid repair impact report");
  const row: Change = { manager: "js", path: ".", package: "types", from: "1.0.0", to: "2.0.0", major: true, advisories: [] };
  assertThrows(() => render([row], "laravel-app", new Map([["js\0.\0types", update]])));
  assertThrows(() => repairReport(JSON.stringify({ updates: [{ ...update, effect: "Safe text.", label: effect }] })));
});
Deno.test("repair impact ignores unchanged effect text and bounds the full receipt", () => {
  const update = { manager: "js", path: ".", package: "types", codeChanged: false, effect: "No code changes needed.", files: [] };
  assertEquals(repairReport(JSON.stringify({ updates: [update] })).updates[0].effect, "");
  assertEquals(repairReport(JSON.stringify({ updates: [{ ...update, effect: "<!-- @nckrtl #12" }] })).updates[0].effect, "");
  assertThrows(() => repairReport(" ".repeat(MAX_REPORT_LENGTH + 1)), Error, "length limit");
});

Deno.test("workspace type repairs, repair-time manifests and both rename paths need coverage", async () => {
  const root = await Deno.makeTempDir({ prefix: "workspace-impact-" });
  const git = async (...args: string[]) => {
    const result = await new Deno.Command("git", { cwd: root, args, stdout: "piped", stderr: "piped" }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
    return new TextDecoder().decode(result.stdout).trim();
  };
  const commit = async () => { await git("add", "."); await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "fixture"); return await git("rev-parse", "HEAD^{tree}"); };
  try {
    await git("init"); await git("config", "diff.renames", "true");
    await Deno.mkdir(`${root}/web`);
    await Deno.writeTextFile(`${root}/web/types.d.ts`, 'export type Page = unknown;\n'.repeat(10));
    for (const file of ["package.json", "composer.json"]) await Deno.writeTextFile(`${root}/web/${file}`, "{}");
    const scriptTree = await commit(), base = await git("rev-parse", "HEAD");
    await Deno.rename(`${root}/web/types.d.ts`, `${root}/web/pages.d.ts`);
    const repairTree = await commit();
    const row: Change = { manager: "js", path: "web", package: "types", from: "1.0.0", to: "2.0.0", major: true, advisories: [] };
    const update = { manager: "js", path: "web", package: "types", codeChanged: true, effect: "Page types move to their own module; pages render the same.", files: ["web/pages.d.ts", "web/types.d.ts"] };
    const places = [{ path: "web", dir: `${root}/web` }];
    const context = { inventory: [{manager: row.manager, path: row.path, package: row.package}], scriptTree, repairTree, checkedTree: repairTree, report: JSON.stringify({ updates: [update] }) };
    assertEquals(render([row], "laravel-app", await impacts(root, places, base, [row], context)).body.endsWith("- **types 2, web.** Page types move to their own module; pages render the same.\n"), true);
    await assertRejects(() => impacts(root, places, base, [row], { ...context, report: JSON.stringify({ updates: [{ ...update, files: ["web/pages.d.ts"] }] }) }), Error, "omits updates or changed files");
    // The old path must also appear in the post-repair drift diff, even if the new path is a dependency file.
    await git("mv", "web/pages.d.ts", "web/bun.lock");
    await assertRejects(() => impacts(root, places, base, [row], context), Error, "Source changed after");
    await git("reset", "--hard", base);
    for (const [manager, manifest, content] of [["js", "package.json", '{"type":"module","scripts":{"build":"vite build"}}'], ["composer", "composer.json", '{"autoload":{"psr-4":{"App\\\\":"src/"}}}']]) {
      await Deno.writeTextFile(`${root}/web/${manifest}`, content);
      const tree = await commit();
      const manifestRow = { ...row, manager: manager as "js" | "composer" };
      const manifestUpdate = { ...update, manager, files: [`web/${manifest}`], effect: "The package now exposes its modules to consumers." };
      const ctx = { inventory: [{manager: manifestRow.manager, path: manifestRow.path, package: manifestRow.package}], scriptTree, repairTree: tree, checkedTree: tree, report: JSON.stringify({ updates: [{ ...manifestUpdate, codeChanged: false, effect: "No code changes needed.", files: [] }] }) };
      await assertRejects(() => impacts(root, places, base, [manifestRow], ctx), Error, "omits updates or changed files");
      assertEquals((await impacts(root, places, base, [manifestRow], { ...ctx, report: JSON.stringify({ updates: [manifestUpdate] }) })).size, 1);
      await git("reset", "--hard", base);
    }
  } finally { await Deno.remove(root, { recursive: true }); }
});

Deno.test("a repair restoring a manifest to base does not require an impossible file entry", async () => {
  const root = await Deno.makeTempDir({ prefix: "restored-manifest-impact-" });
  const git = async (...args: string[]) => {
    const result = await new Deno.Command("git", { cwd: root, args }).output();
    assertEquals(result.code, 0);
    return new TextDecoder().decode(result.stdout).trim();
  };
  const commit = async () => { await git("add", "."); await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "fixture"); return await git("rev-parse", "HEAD^{tree}"); };
  try {
    await git("init");
    for (const manifest of ["package.json", "composer.json"]) await Deno.writeTextFile(`${root}/${manifest}`, "{}");
    await commit(); const base = await git("rev-parse", "HEAD");
    for (const [manager, manifest] of [["js", "package.json"], ["composer", "composer.json"]] as const) {
      await Deno.writeTextFile(`${root}/${manifest}`, '{"scripts":{"build":"changed"}}');
      const scriptTree = await commit();
      await Deno.writeTextFile(`${root}/${manifest}`, "{}");
      const tree = await commit();
      const row: Change = { manager, path: ".", package: "one", from: "1.0.0", to: "1.1.0", major: false, advisories: [] };
      const update = { manager, path: ".", package: "one", codeChanged: false, effect: "", files: [] };
      const effects = await impacts(root, [{path: ".", dir: root}], base, [row], { inventory: [{manager: row.manager, path: row.path, package: row.package}], scriptTree, repairTree: tree, checkedTree: tree, report: JSON.stringify({ updates: [update] }) });
      assertEquals(effects.size, 1);
    }
  } finally { await Deno.remove(root, {recursive: true}); }
});

Deno.test("equal effects with different labels keep each label and zero-major updates keep the minor", () => {
  const rows: Change[] = ["0.578.0", "0.579.0"].map((to, i) => ({ manager: "js", path: ".", package: `icons-${i}`, from: "0.577.0", to, major: true, advisories: [] }));
  const effects = new Map(rows.map((row, i) => [impactKey(row), { manager: row.manager, path: row.path, package: row.package, codeChanged: true, label: `Label ${i}`, effect: "The icons now match the page.", files: ["icons.ts"] }]));
  const body = render(rows, "laravel-app", effects).body;
  assertStringIncludes(body, "- **Label 0 (icons-0 0.578).** The icons now match the page.");
  assertStringIncludes(body, "- **Label 1 (icons-1 0.579).** The icons now match the page.");
});

Deno.test("PR bodies match the website and treadmill reference styles", async () => {
  for (const name of ["website", "treadmill"]) {
    const data = JSON.parse(await Deno.readTextFile(new URL(`./fixtures/pr-text/${name}.json`, import.meta.url)));
    const expected = await Deno.readTextFile(new URL(`./fixtures/pr-text/${name}.md`, import.meta.url));
    const effects = new Map<string, ReturnType<typeof repairReport>["updates"][number]>(repairReport(JSON.stringify({ updates: data.updates })).updates.map((u) => [`${u.manager}\0${u.path}\0${u.package}`, u]));
    assertEquals(render(data.changes, "laravel-app", effects).body, expected);
  }
});

for (const effect of [
  "Guards the hero shader when WebGPU is unavailable",
  "Updates Inertia page prop types for TypeScript 7",
  "Updates Next.js page props",
  "Works around it, e.g. on Safari",
  "Updates Socket.io, Vue.js and Chart.js clients",
  "See example.com",
  "Handles 10000000 rows",
  "The café menu renders in Français (desktop/mobile); it's clearer, faster - and stable.",
]) Deno.test(`repair impact accepts ordinary prose: ${effect}`, () => {
  const update = { manager: "js", path: ".", package: "types", codeChanged: true, effect, files: ["types.d.ts"] };
  assertEquals(repairReport(JSON.stringify({ updates: [update] })).updates[0].effect, effect);
});

Deno.test("a Composer repair permits dependency-only JS rows first reached by the rerun", async () => {
  const root = await Deno.makeTempDir({ prefix: "mixed-rerun-impact-" });
  const git = async (...args: string[]) => {
    const r = await new Deno.Command("git", { cwd: root, args, stdout: "piped", stderr: "piped" }).output();
    assertEquals(r.code, 0, new TextDecoder().decode(r.stderr));
    return new TextDecoder().decode(r.stdout).trim();
  };
  const commit = async () => { await git("add", "."); await git("-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-m", "fixture"); return await git("rev-parse", "HEAD^{tree}"); };
  try {
    await git("init");
    for (const f of ["composer.json", "composer.lock", "package.json", "package-lock.json"]) await Deno.writeTextFile(`${root}/${f}`, "{}");
    await Deno.writeTextFile(`${root}/App.php`, "before");
    const scriptTree = await commit(), base = await git("rev-parse", "HEAD");
    const composer: Change = { manager: "composer", path: ".", package: "vendor/pkg", from: "1.0.0", to: "2.0.0", major: true, advisories: [] };
    const js: Change = { ...composer, manager: "js", package: "vite" };
    const update = { manager: composer.manager, path: ".", package: composer.package, codeChanged: true, effect: "The page handles the updated response.", files: ["App.php"] };
    await Deno.writeTextFile(`${root}/App.php`, "repaired");
    const repairTree = await commit();
    await Deno.writeTextFile(`${root}/package-lock.json`, '{"updated":true}');
    const checkedTree = await commit();
    const context = { inventory: [{ manager: composer.manager, path: ".", package: composer.package }], scriptTree, repairTree, checkedTree, report: JSON.stringify({ updates: [update] }) };
    const effects = await impacts(root, [{path: ".", dir: root}], base, [composer, js], context);
    assertEquals(effects.get(impactKey(js))?.codeChanged, false);
    assertStringIncludes(render([composer, js], "laravel-app", effects).body, "- **vite 2.** No code changes needed.");
    await assertRejects(() => impacts(root, [{path: ".", dir: root}], base, [composer, js], { ...context, inventory: [composer, js] }), Error, "omits updates");
    await assertRejects(() => impacts(root, [{path: ".", dir: root}], base, [composer, js], { ...context, report: JSON.stringify({ updates: [{...update, codeChanged: false, files: []}] }) }), Error, "changed files");
    await Deno.writeTextFile(`${root}/app.js`, "unreported source");
    await assertRejects(() => impacts(root, [{path: ".", dir: root}], base, [composer, js], context), Error, "changed files");
  } finally { await Deno.remove(root, {recursive: true}); }
});

Deno.test("three packages sharing an advisory use commas and a final and", () => {
  const rows: Change[] = ["a", "b", "c"].map(packageName => ({ manager: "js", path: ".", package: packageName, from: "1.0.0", to: "1.0.1", major: false, advisories: [{ id: "GHSA-fixture", url: null }] }));
  assertStringIncludes(render(rows).body, "a, b and c fix GHSA-fixture.");
});
