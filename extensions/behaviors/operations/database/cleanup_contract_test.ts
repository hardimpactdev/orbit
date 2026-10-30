import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { cleanupPayload } from "./cleanup_contract.ts";
const pin = "a".repeat(64);
const clone = {
  variant: "clone",
  agent_sha256: pin,
  directory: `/tmp/orbit-sqlite-clone-${"b".repeat(32)}`,
};
Deno.test("cleanup exposes one typed variant and separates the pin from action input", () => {
  assertEquals(cleanupPayload(clone), {
    agent_sha256: pin,
    input: { variant: "clone", directory: clone.directory },
  });
});
Deno.test("cleanup cannot select a generic owner command or foreign path", () => {
  for (
    const bad of [
      { ...clone, owner: "root" },
      { ...clone, command: "instance.copy-sqlite" },
      { ...clone, directory: "/tmp/orbit-sqlite-clone-../foreign" },
      { ...clone, variant: "install" },
      { ...clone, agent_sha256: "invalid" },
    ]
  ) assertThrows(() => cleanupPayload(bad));
});
Deno.test("seed cleanup binds source base target state and operation snapshot", () => {
  const operation = "c".repeat(64);
  const seed = {
    variant: "seed",
    agent_sha256: pin,
    target_instance_id: 42,
    base: "/home/app/database",
    source_path: "/home/app/database/database.sqlite",
    source_user: "app",
    transport_user: "orbit",
    operation,
    state_dir: "/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-42",
    snapshot_path: `/tmp/orbit-sqlite-${operation}.sqlite`,
  };
  assertEquals(cleanupPayload(seed).input.variant, "seed");
  for (
    const bad of [
      { ...seed, target_instance_id: 43 },
      { ...seed, source_path: "/home/foreign/db.sqlite" },
      { ...seed, state_dir: seed.state_dir + "/../foreign" },
      { ...seed, snapshot_path: "/tmp/foreign.sqlite" },
      { ...seed, mode: "prepare" },
      { ...seed, transport_user: "root;sh" },
    ]
  ) assertThrows(() => cleanupPayload(bad));
});
