import { assertEquals } from "jsr:@std/assert@1";
import { behavior, RemoveInput, removalDecision, removalResult } from "./remove.ts";
const step = (v: unknown) => ({ status: "succeeded", outputs: { stdout: JSON.stringify(v) } });
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
Deno.test("only proven absence deletes the Tool, and dropping the last source is best-effort", () => {
  const ok = { status: "succeeded" }, failed = { status: "failed" }, skipped = { status: "skipped" };
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { input, facts } = fixture(manager);
    const decision = { status: "succeeded", outputs: probe(input, facts) };
    assertEquals(removalResult(input, null, decision, ok, ok, skipped).ok, true);
    assertEquals(JSON.parse(removalResult(input, null, decision, failed, skipped, skipped).stdout).error_code, "tool.remove_failed");
    assertEquals(removalResult(input, null, decision, ok, failed, skipped).ok, false);
    assertEquals(removalResult(input, step({ facts: { ...facts, versions: [] } }), skipped, null, null, null).ok, true);
  }
  const repository = { name: "vendor", uris: ["https://packages.example.test/apt"], suites: ["preview"], components: ["main"], signing_key: { url: "https://packages.example.test/key.gpg", fingerprint: "A".repeat(40) } };
  const input = RemoveInput.parse({ ...fixture("apt").input, repository, remove_repository: true });
  const decision = { status: "succeeded", outputs: { ready: true, remove: true, manager_version: "3.0" } };
  assertEquals(JSON.parse(removalResult(input, null, decision, ok, ok, ok).stdout), { manager_version: "3.0", installed_version: null, outcome: "applied", repository_removed: true });
  const kept = removalResult(input, null, decision, ok, ok, failed);
  assertEquals([kept.ok, JSON.parse(kept.stdout).repository_removed], [true, false]);
});
Deno.test("brew refuses while another installed formula depends on it", () => {
  const { input, facts } = fixture("brew");
  assertEquals(probe(input, facts, { packages: ["hello", "other"] }).error_code, "tool.removal_plan_unsafe");
  assertEquals(probe(input, facts, { packages: ["hello"] }).remove, true);
});
Deno.test("every shipped query, plan and removal compiles", async () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const { bundle } = await behavior.methods.assets.execute({ input: fixture(manager).input }, ctx);
    const parsed = JSON.parse(bundle);
    for (const source of [parsed.query, parsed.remove, ...(parsed.plan ? [parsed.plan] : [])]) {
      const child = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
      const writer = child.stdin.getWriter(); await writer.write(new TextEncoder().encode(source)); await writer.close();
      const output = await child.output(); assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    }
  }
});
