import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { CleanupInput } from "./cleanup_contract.ts";
const clone = {
  variant: "clone",
  directory: `/tmp/orbit-sqlite-clone-${"b".repeat(32)}`,
} as const;
Deno.test("cleanup exposes only the admitted typed descriptor", () => {
  assertEquals(CleanupInput.parse(clone), clone);
});
Deno.test("cleanup cannot select a generic owner command or foreign path", () => {
  for (
    const bad of [
      { ...clone, owner: "root" },
      { ...clone, command: "instance.copy-sqlite" },
      { ...clone, directory: "/tmp/orbit-sqlite-clone-../foreign" },
      { ...clone, variant: "install" },
      { ...clone, program: "arbitrary caller source" },
    ]
  ) assertThrows(() => CleanupInput.parse(bad));
});
Deno.test("seed cleanup binds source base target state and operation snapshot", () => {
  const operation = "c".repeat(64);
  const seed = {
    variant: "seed",
    target_instance_id: 42,
    base: "/home/app/database",
    source_path: "/home/app/database/database.sqlite",
    source_user: "app",
    transport_user: "orbit",
    operation,
    state_dir: "/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-42",
    snapshot_path: `/tmp/orbit-sqlite-${operation}.sqlite`,
  };
  assertEquals(CleanupInput.parse(seed).variant, "seed");
  for (
    const bad of [
      { ...seed, target_instance_id: 43 },
      { ...seed, source_path: "/home/foreign/db.sqlite" },
      { ...seed, state_dir: seed.state_dir + "/../foreign" },
      { ...seed, snapshot_path: "/tmp/foreign.sqlite" },
      { ...seed, mode: "prepare" },
      { ...seed, transport_user: "root;sh" },
    ]
  ) assertThrows(() => CleanupInput.parse(bad));
});
