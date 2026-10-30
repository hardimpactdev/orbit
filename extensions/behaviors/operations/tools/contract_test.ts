import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  allows,
  Input,
  normalizeVersion,
  validateConstraint,
} from "./contract.ts";
import corpus from "./composer-constraints.json" with { type: "json" };

function input(constraint: string | null) {
  return Input.parse({
    node_id: 209,
    manager: "vp",
    platform: "linux",
    architecture: "x86_64",
    version_constraint: constraint,
  });
}

Deno.test("native evaluation preserves Composer constraint decisions", () => {
  for (const row of corpus) {
    for (const { version, allowed } of row.versions) {
      assertEquals(
        allows(input(row.constraint), version),
        allowed,
        `${row.constraint}: ${version}`,
      );
    }
  }
  assertEquals(allows(input("^0.1.2"), "0.2.0"), false);
  assertEquals(allows(input("^0.0.2"), "0.0.3"), false);
  assertEquals(allows(input("1.2"), "1.2.1"), false);
});

Deno.test("constraint validation refuses malformed ranges and preserves manager normalization", () => {
  for (const raw of ["", "not a range", "^1.0 ||", "\n^1.0", "1".repeat(256)]) {
    assertThrows(() => validateConstraint(input(raw)));
  }
  assertEquals(normalizeVersion("1:1.25.0-2ubuntu4", "apt"), "1.25.0");
  assertEquals(normalizeVersion("25.8.1_1", "brew"), null);
  assertEquals(normalizeVersion("3.003", "brew-cask"), null);
  assertEquals(normalizeVersion("v1.2", "vp"), "1.2.0");
  assertEquals(allows(input(null), "unparseable"), true);
});
