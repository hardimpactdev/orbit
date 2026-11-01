import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior, RemoveInput, removalProbe, removalResult } from "./remove.ts";
const observation = (v: unknown) => ({ stdout: JSON.stringify(v), exitCode: 0 as const });
function fixture(manager = "apt") {
  const input = RemoveInput.parse({ node_id: 9, manager, package: manager === "composer" ? "vendor/package" : "hello", user: "orbit", platform: manager === "brew-cask" ? "macos" : "linux", architecture: "x86_64", intent_only: false, installed_version: "1.0" });
  const facts: Record<string, unknown> = { scope: "present", manager_version: "3.0", versions: ["1.0"], complete: true, roots: [input.package], os_major: 15, prefix: "/prefix", home: "/home/orbit", scope_path: "/opt/orbit/vite-plus", token_count: 1 };
  if (manager === "brew-cask") facts.homebrew = { token: "hello", tap: "homebrew/cask", artifacts: [{ font: ["Font.ttf"] }, { uninstall: [{ delete: "~/Library/Fonts/Font.ttf" }] }] };
  return { input, facts };
}
const ctx = { extensionFile: (name: string) => new URL(name, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
Deno.test("removal requires complete selected inventory for each manager, including delisted casks", () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { input, facts } = fixture(manager);
    assertEquals(removalProbe(input, observation(facts)).present, true);
    facts.versions = []; assertEquals(removalProbe(input, observation(facts)).present, false);
    for (const patch of [{ complete: false }, { versions: [null] }, { versions: ["1", "2"] }, { manager_version: "" }, { scope: "absent" }, { error_code: "tool.version_probe_failed" }]) {
      assertEquals(removalProbe(input, observation({ ...facts, ...patch })).ready, false);
    }
  }
  const { input, facts } = fixture("composer"); facts.roots = [];
  assertEquals(removalProbe(input, observation(facts)).ready, false);
});
Deno.test("cask removal refuses ambiguous tokens, arbitrary uninstall scripts and privileged paths", () => {
  const { input, facts } = fixture("brew-cask");
  for (const patch of [{ token_count: 2 }, { token_count: 0 }, { homebrew: { token: "hello", tap: "foreign/tap", artifacts: [] } }]) {
    assertEquals(removalProbe(input, observation({ ...facts, ...patch })).ready, false);
  }
  for (const artifacts of [[{ pkg: ["Installer.pkg"] }], [{ app: ["Hello.app"] }], [{ binary: ["hello", { target: "/usr/bin/hello" }] }]]) {
    assertEquals(removalProbe(input, observation({ ...facts, homebrew: { token: "hello", tap: "homebrew/cask", artifacts } })).ready, false);
  }
  for (const uninstall of [{ script: "anything" }, { pkgutil: "example.test" }, { delete: "/Library/Fonts/Font.ttf" }, { rmdir: "../foreign" }]) {
    assertEquals(removalProbe(input, observation({ ...facts, homebrew: { token: "hello", tap: "homebrew/cask", artifacts: [{ uninstall: [uninstall] }] } })).ready, false);
  }
});
Deno.test("result requires successful native stages and typed absence; intent-only performs no query", () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { input, facts } = fixture(manager);
    const steps = { "before-0": { status: "succeeded", outputs: removalProbe(input, observation(facts)) }, "plan-0": { status: "succeeded", outputs: { ready: true } }, "remove-0": { status: "succeeded" }, "after-0": { status: "succeeded", outputs: { ready: true, present: false } } };
    assertEquals(removalResult(input, steps).ok, true);
    for (const stage of ["before-0", "remove-0", "after-0", ...(manager === "apt" ? ["plan-0"] : [])]) {
      assertEquals(removalResult(input, { ...steps, [stage]: { status: "skipped", outputs: { ready: true, present: false } } }).ok, false);
    }
    assertEquals(removalResult(input, { ...steps, "after-0": { status: "succeeded", outputs: { ready: true, present: true } } }).ok, false);
    const absent = removalProbe(input, observation({ ...facts, versions: [] }));
    assertEquals(removalResult(input, { "before-0": { status: "succeeded", outputs: absent } }).ok, true);
    assertEquals(removalResult({ ...input, intent_only: true }, {}).ok, true);
  }
});
Deno.test("every shipped query, plan and action compiles; mutation requires observed ownership", async () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { input, facts } = fixture(manager);
    const before = removalProbe(input, observation(facts));
    for (const action of ["query", "remove", ...(manager === "apt" ? ["plan"] : [])] as ("query" | "remove" | "plan")[]) {
      const asset = await behavior.methods.assets.execute({ input, action, before }, ctx);
      const child = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
      const writer = child.stdin.getWriter(); await writer.write(new TextEncoder().encode(asset.script_body)); await writer.close();
      const output = await child.output(); assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    }
    await assertRejects(() => behavior.methods.assets.execute({ input, action: "remove" }, ctx));
  }
});
Deno.test("apt eligibility refuses empty or wider plans", async () => {
  for (const packages of [[], ["hello", "shared"], ["other"]]) {
    const result = await behavior.methods.plan.execute({ package: "hello", observation: observation({ packages }) }, ctx);
    assertEquals(result, { ready: false, error_code: "tool.removal_plan_unsafe" });
  }
  assertEquals(await behavior.methods.plan.execute({ package: "hello", observation: observation({ packages: ["hello"] }) }, ctx), { ready: true });
});
