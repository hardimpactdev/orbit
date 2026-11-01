import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { AdoptInput, adoptionResult, behavior } from "./adopt.ts";
const observation = (value: unknown) => ({ stdout: JSON.stringify(value), exitCode: 0 });
function fixture(manager = "apt") {
  const input = AdoptInput.parse({ node_id: 9, manager, package: manager === "composer" ? "vendor/package" : "hello", user: "orbit", platform: manager === "brew-cask" ? "macos" : "linux", architecture: "x86_64" });
  const facts: Record<string, unknown> = { manager_version: "3.0.0", versions: ["1.0.0"], complete: true, roots: [input.package], os_major: 15, prefix: "/prefix", home: "/home/orbit" };
  if (manager === "brew") facts.homebrew = { name: "hello", full_name: "hello", tap: "homebrew/core", disabled: false, installed: [{ version: "1.0.0", installed_on_request: true }], versions: { bottle: true }, bottle: { stable: { files: { x86_64_linux: { sha256: "a".repeat(64), url: "https://ghcr.io/v2/homebrew/core/hello/sha256:" + "a".repeat(64) } } } } };
  if (manager === "brew-cask") facts.homebrew = { token: "hello", full_token: "hello", tap: "homebrew/cask", disabled: false, url: "https://example.test/download", version: "1.0.0", sha256: "b".repeat(64), artifacts: [{ font: ["Font.ttf"] }], variations: {} };
  return { input, facts };
}
const result = (f: ReturnType<typeof fixture>) => JSON.parse(adoptionResult(f.input, observation(f.facts)).stdout);
Deno.test("all selected managers adopt readable versions and preserve installed intent", () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const f = fixture(manager);
    assertEquals(result(f), { manager_version: "3.0.0", installed_version: "1.0.0", outcome: "applied" });
    f.input.tool_status = "installed";
    assertEquals(result(f).outcome, "unchanged");
    f.input.version_constraint = "^2.0";
    assertEquals(result(f).error_code, "tool.installed_version_constraint_violated");
    f.input.version_constraint = "garbage!";
    assertEquals(result(f).error_code, "tool.constraint_invalid");
  }
});
Deno.test("absent, malformed, incomplete and ambiguous manager observations refuse", () => {
  for (const [patch, code] of [
    [{ versions: [] }, "tool.package_absent"], [{ versions: [null] }, "tool.version_probe_failed"],
    [{ versions: ["1", "2"] }, "tool.version_probe_failed"], [{ complete: false }, "tool.version_probe_failed"],
    [{ scope: "conflict" }, "tool.manager_unavailable"], [{ scope: "absent" }, "tool.manager_unavailable"],
    [{ error_code: "tool.version_probe_failed" }, "tool.version_probe_failed"], [{ versions: "invalid" }, "tool.version_probe_failed"],
  ] as const) {
    const f = fixture(); Object.assign(f.facts, patch);
    assertEquals(result(f).error_code, code);
  }
  const f = fixture("composer"); f.facts.roots = [];
  assertEquals(result(f).block, "dependency");
  assertEquals(JSON.parse(adoptionResult(f.input, { stdout: "{", exitCode: 0 }).stdout).error_code, "tool.version_probe_failed");
});
Deno.test("Homebrew eligibility preserves official roots, current bottles and safe cask artifacts", () => {
  const f = fixture("brew"), record = f.facts.homebrew as Record<string, unknown>;
  record.installed = [{ version: "1.0.0", installed_on_request: false }];
  assertEquals(result(f).block, "dependency");
  record.installed = [{ version: "1.0.0", installed_on_request: true }];
  record.disabled = true; assertEquals(result(f).block, "unsupported_artifact");
  record.disabled = false; record.bottle = { stable: { files: {} } };
  assertEquals(result(f).block, "bottle_unavailable");
  const c = fixture("brew-cask"), cask = c.facts.homebrew as Record<string, unknown>;
  for (const [artifacts, block] of [
    [[{ app: ["Hello.app"] }], "authorization_required"],
    [[{ preflight: [] }], "unsupported_artifact"],
    [[{ font: ["Font.ttf"] }, { uninstall: [{ pkgutil: "com.example" }] }], "authorization_required"],
    [[{ font: ["Font.ttf"] }, { uninstall: [{ script: "anything" }] }], "unsupported_artifact"],
  ] as const) { cask.artifacts = artifacts; assertEquals(result(c).block, block); }
  cask.artifacts = [{ font: ["Font.ttf"] }]; cask.sha256 = "no_check";
  assertEquals(result(c).block, "checksum_unavailable");
  cask.sha256 = "a".repeat(64); cask.variations = { sequoia: { artifacts: [{ app: ["Hello.app"] }] } };
  assertEquals(result(c).block, "authorization_required");
});
Deno.test("each shipped manager query compiles with public data bound as data", async () => {
  const ctx = { extensionFile: (name: string) => new URL(name, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const f = fixture(manager);
    const asset = await behavior.methods.assets.execute({ input: f.input }, ctx);
    const proc = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
    const writer = proc.stdin.getWriter(); await writer.write(new TextEncoder().encode(asset.script_body)); await writer.close();
    const output = await proc.output(); assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
  }
  assertThrows(() => behavior.methods.assert.execute({ ok: false }));
});
Deno.test("adoption graph has an official SSH query and read-only repository check, fixed209 placement and full pins", async () => {
  const source = await Deno.readTextFile(new URL("../../../../workflows/workflow-operation-tool-adopt-209.yaml", import.meta.url));
  const root = parse(source) as { jobs: { steps: { target: string; labels?: unknown; task: { behaviorType: string; globalArgs?: { transport: { hostKeys: string } }; inputs: { sudo?: boolean } } }[] }[] };
  assertEquals(source.trimEnd().split("\n").length <= 200, true);
  const steps = root.jobs[0].steps;
  assertEquals(steps.length, 5);
  for (const step of steps) { assertEquals(step.target, "orbit-node-209"); assertEquals(step.labels, undefined); }
  const ssh = steps.filter((s) => s.task.behaviorType === "@swamp/ssh");
  assertEquals(ssh.length, 2);
  assertEquals(ssh[0].task.inputs.sudo, false);
  assertEquals(ssh[0].task.globalArgs?.transport.hostKeys, "${{ inputs.ssh.target.host_keys }}");
});

Deno.test("Homebrew requires supported target variations and accepts canonical architecture aliases", () => {
  const f = fixture("brew"); f.input.architecture = "amd64";
  assertEquals(result(f).outcome, "applied");
  const c = fixture("brew-cask");
  c.facts.os_major = 10;
  assertEquals(result(c).error_code, "tool.version_probe_failed");
  c.facts.os_major = 15; c.input.architecture = "unknown";
  assertEquals(result(c).error_code, "tool.version_probe_failed");
});

Deno.test("unreadable cask inventory never proves installed ownership", () => {
  const c = fixture("brew-cask"); c.facts.versions = ["latest"];
  assertEquals(result(c).error_code, "tool.version_probe_failed");
});
