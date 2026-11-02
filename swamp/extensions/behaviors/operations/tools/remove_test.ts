import { assertEquals } from "jsr:@std/assert@1";
import { behavior, RemoveInput, removalDecision } from "./remove.ts";
const probe = (input: ReturnType<typeof fixture>["input"], facts: unknown, plan: unknown = { packages: ["hello"] }) => removalDecision(input, { facts, plan });
function fixture(manager = "apt") {
  const input = RemoveInput.parse({ node_id: 9, manager, package: manager === "composer" ? "vendor/package" : "hello", user: "orbit", platform: manager === "brew-cask" ? "macos" : "linux", architecture: "x86_64", installed_version: "1.0" });
  const facts: Record<string, unknown> = { scope: "present", manager_version: "3.0", versions: ["1.0"], complete: true, roots: [input.package], os_major: 15, prefix: "/prefix", home: "/home/orbit", scope_path: "/opt/orbit/vite-plus", token_count: 1 };
  if (manager === "brew-cask") facts.homebrew = { token: "hello", tap: "homebrew/cask", artifacts: [{ font: ["Font.ttf"] }, { uninstall: [{ delete: "~/Library/Fonts/Font.ttf" }] }] };
  return { input, facts };
}
const ctx = { extensionFile: (name: string) => new URL(name, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
Deno.test("removal requires complete selected inventory for each manager, including delisted casks", () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { input, facts } = fixture(manager);
    assertEquals(probe(input, facts).remove, true);
    facts.versions = []; assertEquals(probe(input, facts, null), { ready: true, remove: false, manager_version: "3.0" });
    for (const patch of [{ complete: false }, { versions: [null] }, { versions: ["1", "2"] }, { manager_version: "" }, { scope: "absent" }, { error_code: "tool.version_probe_failed" }]) {
      assertEquals(probe(input, { ...facts, ...patch }).ready, false);
    }
  }
  const { input, facts } = fixture("composer"); facts.roots = [];
  assertEquals(probe(input, facts).error_code, "tool.remove_failed");
  assertEquals(removalDecision(input, null).error_code, "tool.version_probe_failed");
});
Deno.test("cask removal refuses ambiguous tokens, arbitrary uninstall scripts and privileged paths", () => {
  const { input, facts } = fixture("brew-cask");
  for (const patch of [{ token_count: 2 }, { token_count: 0 }, { homebrew: { token: "hello", tap: "foreign/tap", artifacts: [] } }]) {
    assertEquals(probe(input, { ...facts, ...patch }).ready, false);
  }
  for (const artifacts of [[{ pkg: ["Installer.pkg"] }], [{ app: ["Hello.app"] }], [{ binary: ["hello", { target: "/usr/bin/hello" }] }]]) {
    assertEquals(probe(input, { ...facts, homebrew: { token: "hello", tap: "homebrew/cask", artifacts } }).ready, false);
  }
  for (const uninstall of [{ script: "anything" }, { pkgutil: "example.test" }, { delete: "/Library/Fonts/Font.ttf" }, { rmdir: "../foreign" }]) {
    assertEquals(probe(input, { ...facts, homebrew: { token: "hello", tap: "homebrew/cask", artifacts: [{ uninstall: [uninstall] }] } }).ready, false);
  }
});
Deno.test("apt eligibility refuses empty, unread or wider plans", () => {
  const { input, facts } = fixture("apt");
  for (const packages of [[], ["hello", "shared"], ["other"]]) assertEquals(probe(input, facts, { packages }).error_code, "tool.removal_plan_unsafe");
  assertEquals(probe(input, facts, null).error_code, "tool.remove_failed");
});
Deno.test("brew refuses while another installed formula depends on it", () => {
  const { input, facts } = fixture("brew");
  assertEquals(probe(input, facts, { packages: ["hello", "other"] }).error_code, "tool.removal_plan_unsafe");
  assertEquals(probe(input, facts, { packages: ["hello"] }).remove, true);
});
Deno.test("removals use one manager argv with no shipped code", async () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const value = await behavior.methods.assets.execute({ input: fixture(manager).input }, ctx);
    assertEquals(value.remove.includes("-c"), false);
    assertEquals(value.plan.includes("-c"), false);
  }
});
