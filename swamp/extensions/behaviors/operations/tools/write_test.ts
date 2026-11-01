import { assertEquals } from "jsr:@std/assert@1";
import { before, candidate, decision, inspect, behavior, WriteInput, writeResult } from "./write.ts";
const obs = (raw: unknown) => ({ stdout: JSON.stringify(raw), exitCode: 0 as const });
const fixture = (kind = "tool.install", manager = "apt") => {
  const input = WriteInput.parse({ kind, manager, node_id: 9, package: kind === "tool.manager_converge" ? "" : manager === "composer" ? "vendor/package" : "hello", user: "orbit", platform: manager === "brew-cask" ? "macos" : "linux", architecture: "x86_64", tool_id: kind === "tool.update" ? 1 : null });
  const facts = { manager_version: "3.0", complete: true, versions: [] as string[], roots: [input.package], scope_path: "/vp", prefix: "/brew", home: "/home/orbit" };
  return { input, facts };
};
Deno.test("first-use setup is selected only for missing Linux scopes and admitted installers", () => {
  const { input } = fixture(); input.setup = { manager: "composer", input: { user: "orbit", version: "2.9.5-1" }, apt: { snapshot: "20260101T000000Z", packages: [] }, packages: [] };
  assertEquals(inspect(input, obs({ scope: "absent" })).setup_required, true);
  assertEquals(inspect({ ...input, platform: "macos" }, obs({ scope: "absent" })).setup_required, false);
  assertEquals(inspect({ ...input, kind: "tool.update" }, obs({ scope: "absent" })).setup_required, false);
  assertEquals(inspect(input, obs({ scope: "conflict" })).setup_required, false);
});
Deno.test("silent adoption, missing updates and malformed inventories refuse before candidates", () => {
  const { input, facts } = fixture();
  assertEquals(before(input, obs({ ...facts, versions: ["1.0.0"] })).error_code, "tool.package_already_installed");
  assertEquals(before({ ...input, kind: "tool.update" }, obs(facts)).ready, false);
  for (const changes of [{ versions: [null] }, { versions: ["1", "2"] }, { complete: false }, { scope: "conflict" }]) assertEquals(before(input, obs({ ...facts, ...changes })).ready, false);
  assertEquals(before({ ...input, tool_id: 1 }, obs({ ...facts, versions: ["1.0.0"] })).package_required, false);
  assertEquals(before({ ...input, kind: "tool.manager_converge", package: "" }, obs(facts)).package_required, false);
});
Deno.test("candidate constraints gate normal choices without selecting a version", () => {
  const { input } = fixture(); input.version_constraint = "^1.0";
  assertEquals(candidate(input, obs({ version: "2.0.0" })).error_code, "tool.version_constraint_blocked");
  assertEquals(candidate({ ...input, kind: "tool.update" }, obs({ version: "2.0.0" })).allowed, false);
  assertEquals(candidate(input, obs({ version: "1.2.0" })).allowed, true);
  assertEquals(candidate(input, obs({ version: "garbage" })).error_code, "tool.candidate_version_unparseable");
});
Deno.test("reconciliation skips an effect proved completed under the original native root", () => {
  for (const kind of ["tool.install", "tool.update"]) {
    const { input, facts } = fixture(kind);
    if (kind === "tool.update") facts.versions = ["1.0.0"];
    const value = decision(input, facts, { ready: true, version: "2.0.0", allowed: true }, obs({ ...facts, versions: ["2.0.1"] }));
    assertEquals(value.ready, true); assertEquals(value.mutate, false);
  }
});
Deno.test("result refuses skipped or unproved mutation and absent post-state", () => {
  const { input, facts } = fixture();
  const steps = { "inspect-0": { status: "succeeded", outputs: { ready: true, setup_required: false } }, "before-0": { status: "succeeded", outputs: { ready: true, facts, package_required: true } }, "candidate-0": { status: "succeeded", outputs: { ready: true } }, "decision-0": { status: "succeeded", outputs: { ready: true, mutate: true, outcome: "applied" } }, "mutate-0": { status: "succeeded" }, "after-0": { status: "succeeded", outputs: { ready: true, facts: { ...facts, versions: ["2.0.0"] } } } };
  assertEquals(writeResult(input, steps).ok, true);
  assertEquals(writeResult(input, { ...steps, "mutate-0": { status: "skipped" } }).ok, false);
  assertEquals(writeResult(input, { ...steps, "after-0": { status: "succeeded", outputs: { ready: true, facts } } }).ok, false);
});
Deno.test("all manager query, candidate and mutation assets compile with public input bound as data", async () => {
  const ctx = { extensionFile: (p: string) => new URL(p, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) for (const action of ["query", "candidate", "mutate"] as const) {
    const { input, facts } = fixture("tool.install", manager);
    const { script_body } = await behavior.methods.assets.execute({ input, action, before: { ...facts, scope: "present" } }, ctx);
    const child = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
    const writer = child.stdin.getWriter(); await writer.write(new TextEncoder().encode(script_body)); await writer.close();
    const output = await child.output(); assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
  }
});

Deno.test("manager-only capture omits package and receives the canonical empty selector", () => {
  const { input } = fixture("tool.manager_converge");
  const { package: _package, ...captured } = input;
  assertEquals(WriteInput.parse(captured).package, "");
  assertEquals(WriteInput.safeParse({ ...captured, kind: "tool.install" }).success, false);
});

Deno.test("the candidate and repository intent travel as argv JSON data, never inside rewritten source", async () => {
  const ctx = { extensionFile: (p: string) => new URL(p, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  const base = fixture("tool.install", "apt");
  const repository = { name: "vendorsentinel", uris: ["https://packages.example.test/apt"], suites: ["preview"], components: ["main"], signing_key: { url: "https://packages.example.test/key.gpg", fingerprint: "A".repeat(40) } };
  const input = { ...base.input, repository, repository_packages: [base.input.package] };
  const candidate = { ready: true, version: "9.9.9-candidate" };
  for (const action of ["query", "candidate", "mutate"] as const) {
    const assets = await behavior.methods.assets.execute({ input, action, before: { ...base.facts, scope: "present" }, candidate, repository_observation: { stdout: JSON.stringify({ ready: true }), exitCode: 0 } }, ctx);
    assertEquals(assets.script_body.includes("9.9.9-candidate"), false);
    assertEquals(assets.script_body.includes("vendorsentinel"), false);
    assertEquals(JSON.parse(assets.repository_json).name, "vendorsentinel");
    assertEquals(JSON.parse(assets.candidate_json), candidate);
  }
});
