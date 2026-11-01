import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Candidate,
  eligible,
  planBatch,
  type UpdateTarget,
} from "../extensions/behaviors/_lib/maintenance/batch.ts";
const old = "2020-01-01T00:00:00Z";
const candidate = (name: string, security = false): Candidate => ({
  path: ".",
  manager: "composer",
  package: `fixture/${name}`,
  current: "1.0.0",
  latest: "2.0.0",
  reasons: security ? ["major", "security"] : ["major"],
  advisories: security
    ? [{ id: "PKSA-test", url: "https://example.test/advisory" }]
    : [],
});
function nativeSolver(check: (targets: UpdateTarget[]) => string | null) {
  return (choices: UpdateTarget[][]) => {
    let error = "No solution";
    let found: UpdateTarget[] | null = null;
    const visit = (i: number, selected: UpdateTarget[]) => {
      if (found) return;
      if (i === choices.length) {
        const e = check(selected);
        if (e) error = e;
        else found = selected;
        return;
      }
      for (const t of choices[i]) visit(i + 1, [...selected, t]);
    };
    visit(0, []);
    return Promise.resolve(
      found
        ? {
          targets: (found as UpdateTarget[]).filter((t) =>
            t.version !== "1.0.0"
          ),
        }
        : { targets: [], error },
    );
  };
}
Deno.test("a project plan includes every compatible major and security target once, at exact versions", async () => {
  const cs = [candidate("one"), candidate("two", true), candidate("three")];
  const calls: UpdateTarget[][] = [];
  const p = await planBatch(
    cs,
    { ".": { minimumReleaseAgeDays: 7 } },
    () => Promise.resolve([{ version: "2.0.0", publishedAt: old }]),
    nativeSolver((t) => {
      calls.push(t);
      return null;
    }),
  );
  assertEquals(p.targets.length, 3);
  assertEquals(p.targets[0].purpose, "security");
  assertEquals(p.blocked, []);
  assertEquals(calls.length, 1);
  assertEquals(calls[0].length, 3);
});
Deno.test("too-young, prerelease, missing-date and unsolved targets stay recorded; security cannot bypass policy", async () => {
  const cs = [
    candidate("young", true),
    candidate("unknown"),
    candidate("incompatible"),
    candidate("safe"),
  ];
  const p = await planBatch(
    cs,
    { ".": { minimumReleaseAgeDays: 7 } },
    (c) =>
      Promise.resolve(
        c.package.endsWith("young")
          ? [{ version: "2.0.0", publishedAt: new Date().toISOString() }]
          : c.package.endsWith("unknown")
          ? [{ version: "2.0.0-beta", publishedAt: old }, {
            version: "2.0.0",
            publishedAt: "unknown",
          }]
          : [{ version: "2.0.0", publishedAt: old }],
      ),
    nativeSolver((t) =>
      t.some((t) => t.package.endsWith("incompatible") && t.version !== "1.0.0")
        ? "Project constraints cannot resolve target"
        : null
    ),
  );
  assertEquals(p.targets.map((t) => t.package), ["fixture/safe"]);
  assertEquals(p.blocked.length, 3);
  assert(p.blocked.some((b) => b.reason.includes("release-age")));
  assert(p.blocked.some((b) => b.reason.includes("constraints")));
  assertEquals(
    eligible(
      [{ version: "2.0.0", publishedAt: "2026-10-20T03:37:00Z" }],
      "1.0.0",
      7,
      Date.parse("2026-10-27T03:36:59Z"),
    ),
    [],
  );
});
Deno.test("older eligible targets are tried when a latest stable target conflicts", async () => {
  const p = await planBatch(
    [candidate("one")],
    {},
    () =>
      Promise.resolve([{ version: "3.0.0", publishedAt: old }, {
        version: "2.0.0",
        publishedAt: old,
      }]),
    nativeSolver((ts) =>
      ts[0].version === "3.0.0" ? "unsupported platform" : null
    ),
  );
  assertEquals(p.targets[0].version, "2.0.0");
});
Deno.test("joint solver can choose an older A for B and retain a coupled pair beside an unsatisfiable finding", async () => {
  const cs = [candidate("a"), candidate("b"), candidate("bad")];
  const p = await planBatch(
    cs,
    {},
    (c) =>
      Promise.resolve(
        (c.package.endsWith("a") ? ["3.0.0", "2.0.0"] : ["2.0.0"]).map(
          (version) => ({ version, publishedAt: old }),
        ),
      ),
    nativeSolver((ts) => {
      const version = (name: string) =>
        ts.find((t) => t.package === `fixture/${name}`)?.version ?? "1.0.0";
      if (version("bad") !== "1.0.0") return "Bad target cannot resolve";
      if (
        (version("a") === "2.0.0") !== (version("b") === "2.0.0")
      ) return "A2 and B2 must upgrade together";
      if (
        version("a") === "3.0.0" && version("b") === "2.0.0"
      ) return "B2 requires A2";
      return null;
    }),
  );
  assertEquals(p.targets.map((t) => [t.package, t.version]), [[
    "fixture/a",
    "2.0.0",
  ], ["fixture/b", "2.0.0"]]);
  assertEquals(p.blocked.map((b) => b.package), ["fixture/bad"]);
});
