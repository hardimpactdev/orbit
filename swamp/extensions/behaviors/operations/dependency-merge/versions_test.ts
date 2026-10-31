import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { constraintChange, lockedChange, matches } from "./versions.ts";
for (
  const [before, after] of [
    ["^1.0", "^1.2"],
    ["~1.0.0", "~1.1.0"],
    ["latest", "next"],
    ["^0.1.1", "^0.1.2"],
    ["~0.1.1", "~0.1.2"],
  ]
) {
  Deno.test(`allows nonbreaking registry constraint ${before} -> ${after}`, () =>
    constraintChange(before, after));
}
for (
  const [before, after] of [
    ["^1.0", "^2.0"],
    ["^1.0", "v2"],
    ["^0.1", "^0.2"],
    ["^0.4", "^0.9"],
    ["^0.0.1", "^0.0.2"],
    [">=1.0 <2.0", "^2.0"],
    [">=1.0.0 <2.0.0", "^1 || ^2"],
    [">=0.1.0 <0.2.0", "^0.1 || ^0.2"],
    [">=0.0.1 <0.0.2", "0.0.1 || 0.0.2"],
    ["^1 || ^3", "^1 || ^2 || ^3"],
  ]
) {
  Deno.test(`refuses breaking registry constraint ${before} -> ${after}`, () => {
    assertThrows(() => constraintChange(before, after));
  });
}
for (
  const source of [
    "file:../x",
    "link:../x",
    "workspace:*",
    "npm:attacker@1.0.0",
    "user/repo",
    "https://registry.npmjs.org/x.tgz",
    "git+https://github.com/user/repo",
    "^1.0#deadbeef",
    "dev-main",
    "1.x-dev",
    "^1.0@dev",
    "archive.tgz",
    "archive.tar.gz",
  ]
) {
  Deno.test(`refuses execution-bearing constraint ${source}`, () => {
    assertThrows(() => constraintChange("^1.0", source));
  });
}
for (
  const [before, after] of [["0.4.0", "0.9.0"], ["0.0.4", "0.0.9"], [
    "1.0.0",
    "2.0.0",
  ]]
) {
  Deno.test(`locked breaking boundary ${before} -> ${after}`, () => {
    assertThrows(() => lockedChange(before, after));
  });
}

Deno.test("refuses Composer tilde adding a new major alternative", () => {
  assertThrows(() => constraintChange("~1.0", "^1 || ^2", true));
});

Deno.test("preserves numeric Composer exclusions and comparison whitespace", () => {
  for (
    const constraint of ["!=1.5.0", "!= 1.5", ">= 1.0", ">=1.0 !=1.5.0 !=1.6.0"]
  ) {
    constraintChange(constraint, constraint, true);
  }
  assertEquals(matches("1.5.0", "!=1.5.0", true), false);
  assertEquals(matches("1.5.0", "!= 1.5", true), false);
  assertEquals(matches("1.6.0", ">=1.0 !=1.5.0 !=1.6.0", true), false);
  assertEquals(matches("1.7.0", ">=1.0 !=1.5.0 !=1.6.0", true), true);
  assertEquals(matches("1.5.0", ">= 1.0", true), true);
});
