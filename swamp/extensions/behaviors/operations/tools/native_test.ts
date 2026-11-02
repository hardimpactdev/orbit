import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { WriteInput } from "./write.ts";
import { after, candidate, commands, inventory, removalPlan } from "./native.ts";
const input = (manager = "apt") => WriteInput.parse({ kind: "tool.install", node_id: 9, manager, package: manager === "composer" ? "vendor/tool" : "hello", user: "orbit", platform: "linux", architecture: "x86_64" });
const step = (stdout: string, exitCode = 0, stderr = "") => ({ status: "succeeded", outputs: { stdout, exitCode, stderr } });
Deno.test("apt observations distinguish absence, malformed state, and the normal candidate", () => {
  const value = input(), version = step("apt 3.0\n");
  assertEquals(inventory(value, version, step("hello\tinstall ok installed\t2.0\n")).versions, ["2.0"]);
  assertEquals(inventory(value, version, step("", 1, "dpkg-query: no packages found matching hello\n")).versions, []);
  assertThrows(() => inventory(value, version, step("", 1, "permission denied")));
  assertThrows(() => inventory(value, version, step("other\tinstall ok installed\t2.0\n")));
  assertEquals(candidate(value, step("hello:\n  Candidate: 2.0-1\n"), {}), { version: "2.0-1" });
  assertEquals(candidate(value, step("  Candidate: (none)\n"), {}), { version: null });
  assertThrows(() => candidate(value, step("unreadable"), {}));
});
Deno.test("removal plans and official checks prove exact package state", () => {
  assertEquals(removalPlan(input(), step("Remv hello [1.0]\nRemv other [1.0]\n")), { packages: ["hello", "other"] });
  assertEquals(after(input(), { status: "compliant", packages: [{ name: "hello", installed: false, version: null }] }).versions, []);
  assertThrows(() => after(input(), { status: "failed", packages: null }));
});
Deno.test("Composer and Vite+ observations are parsed on the worker", () => {
  const composer = input("composer"), vp = input("vp"), version = step("manager 1.0");
  assertEquals(inventory(composer, version, step('{"installed":[{"name":"vendor/tool","version":"v1.2.0"}]}')).versions, ["v1.2.0"]);
  assertEquals(candidate(composer, step("", 0, "  - Upgrading vendor/tool (v1.0.0 => v1.2.0)"), {}), { version: "v1.2.0" });
  assertEquals(inventory(vp, version, step('[{"name":"hello","version":"1.2.0"}]')).versions, ["1.2.0"]);
  assertEquals(candidate(vp, step('"1.3.0"'), {}), { version: "1.3.0" });
  for (const manager of ["apt", "composer", "vp", "brew"]) {
    const c = commands(input(manager));
    assertEquals([...c.inventory, ...c.candidate, ...c.write, ...c.remove].includes("-c"), false);
  }
});

Deno.test("Homebrew end observations decode the installed version without another OS read", () => {
  const value = WriteInput.parse({ ...input("brew"), platform: "macos", architecture: "arm64" });
  assertEquals(after(value, { exitCode: 0, stdout: '{"formulae":[{"name":"hello","installed":[{"version":"2.0"}]}]}' }).versions, ["2.0"]);
  const command = commands(value).write;
  assertEquals(command.includes("--force-bottle"), true);
  assertEquals(command.includes("HOMEBREW_NO_AUTOREMOVE=1"), true);
  assertEquals(command.includes("/opt/homebrew/bin/brew"), true);
});
