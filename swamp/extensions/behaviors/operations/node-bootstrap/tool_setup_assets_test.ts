import { assertEquals } from "jsr:@std/assert@1";
import { behavior, Setup } from "./tool_setup_assets.ts";

Deno.test("manager setup accepts only admitted public pins and binds shipped files as data", async () => {
  const ctx = { extensionFile: (p: string) => new URL(p, import.meta.url).pathname, writeResource: () => Promise.resolve(null) };
  const pin = { version: "1.0.0", url: "https://example.invalid/artifact", algorithm: "sha256", digest: "a".repeat(64) };
  const values = [
    { manager: "javascript", input: { user: "orbit", tools: { node: pin, "vp-cli": pin, pnpm: pin, vp: { ...pin, lock_sha256: "b".repeat(64) } }, vp_lock: "{}" }, apt: null, packages: [] },
    { manager: "composer", input: { user: "orbit", version: "2.9.5-1" }, apt: { snapshot: "20260101T000000Z", packages: [] }, packages: [] },
    { manager: "homebrew", input: { user: "orbit", revision: "c".repeat(40), version: "7.0.0" }, apt: null, packages: [] },
  ];
  for (const value of values) {
    const setup = Setup.parse(value);
    assertEquals(Setup.safeParse({ ...value, input: { ...value.input, user: "root" } }).success, false);
    assertEquals(Setup.safeParse({ ...value, input: { ...value.input, script: "unapproved" } }).success, false);
    const assets = await behavior.methods.read.execute({ setup }, ctx);
    for (const body of [assets.manager_body, assets.apt_body].filter((s): s is string => s !== null)) {
      assertEquals(body.includes("json.load(sys.stdin)"), false);
      const child = new Deno.Command("python3", { args: ["-c", "import ast,sys;ast.parse(sys.stdin.read())"], stdin: "piped", stderr: "piped" }).spawn();
      const writer = child.stdin.getWriter(); await writer.write(new TextEncoder().encode(body)); await writer.close();
      const result = await child.output(); assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
    }
  }
});

Deno.test("composer apt artifacts carry their mirror-first candidate URLs", () => {
  const artifact = { urls: ["https://mirror.example/git_" + "a".repeat(64) + ".deb", "https://origin.example/git.deb"], algorithm: "sha256", digest: "a".repeat(64) };
  const value = (packed: unknown) => ({ manager: "composer", input: { user: "orbit", version: "2.9.5-1" }, apt: { snapshot: "20260101T000000Z", packages: [{ name: "git", version: "1:2.55.0", artifact: packed }] }, packages: [] });
  assertEquals(Setup.safeParse(value(artifact)).success, true);
  assertEquals(Setup.safeParse(value({ url: artifact.urls[1], algorithm: "sha256", digest: artifact.digest })).success, false);
  assertEquals(Setup.safeParse(value({ ...artifact, urls: [] })).success, false);
  assertEquals(Setup.safeParse(value({ ...artifact, urls: ["http://origin.example/git.deb"] })).success, false);
});

Deno.test("manager setup scripts refuse foreign launchers and recover interrupted owned scopes", async () => {
  const result = await new Deno.Command("python3", { args: [new URL("tool_setup_scripts_test.py", import.meta.url).pathname], env: { PYTHONDONTWRITEBYTECODE: "1" }, stderr: "piped" }).output();
  assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
});
