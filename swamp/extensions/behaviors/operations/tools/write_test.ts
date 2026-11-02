import { assertEquals } from "jsr:@std/assert@1";
import { behavior, decide, ManagerInput, WriteInput, writeResult } from "./write.ts";

const facts = { scope: "present", manager_version: "apt 3.0.0", versions: [] as (string | null)[], complete: true, roots: ["vendor/dependency"] };
const base = { kind: "tool.install", manager: "apt", node_id: 9, package: "hello", user: "orbit", platform: "linux", architecture: "x86_64", tool_id: null };
const input = (changes: Record<string, unknown> = {}) => WriteInput.parse({ ...base, ...changes });

const ran = (value: unknown, exitCode = 0) => ({ status: "succeeded", outputs: { stdout: JSON.stringify(value), exitCode } });
const none = { setup: null, source: null, check: null, decision: null, write: null };
Deno.test("a failed shared manager setup records no manager version, so the Gateway records no Tool", () => {
  const failed = JSON.parse(writeResult(input(), { ...none, setup: { status: "failed" } }).stdout);
  assertEquals([failed.error_code, failed.manager_version], ["tool.manager_provision_failed", null]);
  assertEquals(JSON.parse(writeResult(input(), { ...none, setup: ran({ facts: null }, 1) }).stdout).error_code, "tool.manager_provision_failed");
  const conflict = JSON.parse(writeResult(input({ manager: "vp" }), { ...none, setup: ran({ facts: { scope: "conflict" } }, 1) }).stdout);
  assertEquals([conflict.error_code, conflict.native_error_code], ["tool.manager_unavailable", "tool.vp_scope_conflict"]);
  assertEquals(writeResult(input(), { ...none, setup: ran({ facts }), check: ran({ facts, candidate: { version: "2.0" } }) }).ok, false);
  const scope = decide(input({ manager: "vp", kind: "tool.update", tool_id: 1 }), { facts: { scope: "absent" } });
  assertEquals([scope.error_code, scope.native_error_code], ["tool.manager_unavailable", "tool.vp_scope_absent"]);
});
Deno.test("a refused apt source stops before the candidate with its own code", () => {
  const value = JSON.parse(writeResult(input(), { ...none, source: ran({ ready: false, error_code: "tool.repository_key_mismatch" }, 1) }).stdout);
  assertEquals(value.error_code, "tool.repository_key_mismatch");
});
Deno.test("refusals happen before any write: silent adoption, missing updates and malformed inventories", () => {
  assertEquals(decide(input(), { facts: { ...facts, versions: ["1.0.0"] } }).error_code, "tool.package_already_installed");
  assertEquals(decide(input({ kind: "tool.update", tool_id: 1 }), { facts }).error_code, "tool.version_probe_failed");
  for (const changes of [{ versions: [null] }, { versions: ["1", "2"] }, { complete: false }]) {
    const refused = decide(input(), { facts: { ...facts, ...changes } });
    assertEquals([refused.ready, refused.manager_version], [false, "apt 3.0.0"]);
  }
  assertEquals(decide(input(), null).error_code, "tool.version_probe_failed");
  const retry = decide(input({ tool_id: 1 }), { facts: { ...facts, versions: ["1.0.0"] } });
  assertEquals([retry.ready, retry.mutate, retry.installed_version], [true, false, "1.0.0"]);
});

Deno.test("the constraint blocks an install, an update stays unchanged on the same version, and no candidate fails", () => {
  const pinned = { version_constraint: "^1.0" };
  assertEquals(decide(input(pinned), { facts, candidate: { version: "2.0.0" } }).error_code, "tool.version_constraint_blocked");
  assertEquals(decide(input(pinned), { facts, candidate: { version: "garbage" } }).error_code, "tool.candidate_version_unparseable");
  const update = input({ ...pinned, kind: "tool.update", tool_id: 1 });
  const blocked = decide(update, { facts: { ...facts, versions: ["1.0.0"] }, candidate: { version: "2.0.0" } });
  assertEquals([blocked.ready, blocked.mutate, blocked.outcome], [true, false, "blocked_by_constraint"]);
  const same = decide(update, { facts: { ...facts, versions: ["1.2.0"] }, candidate: { version: "1.2.0" } });
  assertEquals([same.mutate, same.outcome], [false, "unchanged"]);
  assertEquals(decide(input(), { facts, candidate: null }).error_code, "tool.candidate_version_probe_failed");
  const newer = decide(update, { facts: { ...facts, versions: ["1.2.0"] }, candidate: { version: "1.3.0" } });
  assertEquals([newer.mutate, newer.candidate_json], [true, '{"version":"1.3.0"}']);
});

Deno.test("the result needs the write and a readable installed version inside the constraint", () => {
  const value = input();
  const check = ran({ facts, candidate: { version: "2.0.0" } });
  const decision = { status: "succeeded", outputs: decide(value, { facts, candidate: { version: "2.0.0" } }) };
  const after = { ...facts, versions: ["2.0.0"] };
  const result = (write: unknown) => JSON.parse(writeResult(value, { ...none, check, decision, write: write as never }).stdout);
  assertEquals(result(ran({ written: true, facts: after })), { manager_version: "apt 3.0.0", installed_version: "2.0.0", outcome: "applied" });
  assertEquals(result(ran({ written: false, facts }, 1)).error_code, "tool.install_failed");
  assertEquals(result(null).error_code, "tool.install_failed");
  assertEquals(result(ran({ written: true, facts })).error_code, "tool.version_probe_failed");
});

Deno.test("a Composer dependency already in the inventory is still an installable root", () => {
  const value = input({ manager: "composer", package: "vendor/dependency", kind: "tool.update", tool_id: 41 });
  const decision = decide(value, { facts: { ...facts, versions: ["1.0.0"], roots: [] }, candidate: { version: "1.1.0" } });
  assertEquals([decision.ready, decision.mutate], [true, true]);
});

Deno.test("the shared manager setup reads only the manager, with no package", async () => {
  const ctx = { extensionFile: (p: string) => new URL(p, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  const deploy = { node_id: 9, user: "orbit", manager: "vp", platform: "linux", architecture: "x86_64", setup: null };
  const bundle = JSON.parse((await behavior.methods.manager_assets.execute({ input: ManagerInput.parse(deploy) }, ctx)).bundle);
  assertEquals([bundle.input.manager, bundle.input.package, bundle.repository], ["vp", "", null]);
  assertEquals(WriteInput.safeParse({ ...base, kind: "tool.manager_converge" }).success, false);
});
Deno.test("every manager script compiles and public intent travels as argv JSON, never in rewritten source", async () => {
  const ctx = { extensionFile: (p: string) => new URL(p, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    const value = input({ manager, package: "vendorsentinel", platform: manager === "brew-cask" ? "macos" : "linux" });
    const { bundle, driver } = await behavior.methods.assets.execute({ input: value }, ctx);
    const parsed = JSON.parse(bundle);
    assertEquals(parsed.input.package, "vendorsentinel");
    assertEquals(parsed.root, manager === "apt");
    for (const source of [driver, parsed.query, parsed.candidate, parsed.write]) {
      assertEquals(source.includes("vendorsentinel"), false);
      const child = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
      const writer = child.stdin.getWriter(); await writer.write(new TextEncoder().encode(source)); await writer.close();
      const output = await child.output(); assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    }
  }
});
