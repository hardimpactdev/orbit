// Existing dependency planner, with controlled registry/transport faults. No
// scan of a host checkout, task VM, package-manager update, or LLM is dispatched.
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { buildInventory } from "../extensions/behaviors/_lib/inventory.ts";
import { isMajorJump, toRange } from "../extensions/behaviors/_lib/versions.ts";

const source = Deno.env.get("DEPENDENCY_PLAN_TEST_SOURCE") ??
  new URL("../extensions/behaviors/orbit_deps.ts", import.meta.url).href;
const { behavior } = await import(
  source
) as typeof import("../extensions/behaviors/orbit_deps.ts");
const commit = "a".repeat(40);
const dep = (name: string, constraint = "^1.0", locked = "1.0.0") => ({
  name,
  registryName: name,
  constraint,
  locked,
  scope: "regular" as const,
});
const inventory = (ecosystem = "composer", direct = [dep("acme/one")]) => ({
  commit,
  branch: "main",
  instanceId: 1,
  scannedAt: "2026-10-25T00:00:00Z",
  gatewayError: null,
  subProjects: [{
    path: "packages/example",
    ecosystems: [{
      ecosystem,
      manager: ecosystem === "composer" ? "composer" : "npm",
      source: "checkout",
      reason: "isolated fixture",
      observedAt: null,
      direct,
    }],
  }],
});
const packagist = (...versions: string[]) => ({
  packages: { "acme/one": versions.map((version) => ({ version })) },
});
const npm = (...versions: string[]) => ({
  name: "one",
  versions: Object.fromEntries(
    versions.map((version) => [version, { version }]),
  ),
  "dist-tags": { latest: versions.at(-1) },
});

async function run(
  inv: Record<string, unknown>,
  response: (url: string) => Response | Promise<Response>,
) {
  const saved = globalThis.fetch;
  let plan: Record<string, unknown> | undefined;
  const requests: string[] = [];
  globalThis.fetch = (input) => {
    const url = String(input);
    assert(
      /^https:\/\/(repo\.packagist\.org\/p2\/|registry\.npmjs\.org\/)/.test(
        url,
      ),
    );
    requests.push(url);
    return Promise.resolve(response(url));
  };
  let failure: unknown = null;
  const started = performance.now();
  try {
    await behavior.methods.plan.execute({}, {
      readResource: () => Promise.resolve(inv),
      writeResource: (
        _spec: string,
        _name: string,
        value: Record<string, unknown>,
      ) => {
        plan = structuredClone(value);
        return Promise.resolve({ name: "upgrade-plan" });
      },
      logger: { info() {} },
    });
  } catch (e) {
    failure = e;
  } finally {
    globalThis.fetch = saved;
  }
  assert(
    plan,
    "retain the complete diagnostic plan before refusing registry evidence",
  );
  return {
    plan,
    failure,
    requests,
    durationMs: performance.now() - started,
    mechanicalLlmCalls: 0,
  };
}
const json = (value: unknown) => new Response(JSON.stringify(value));
const counts = (p: Record<string, unknown>) =>
  p.counts as Record<string, number>;

Deno.test("real dependency planner distinguishes verified no-change from missing/malformed/partial registry evidence", async () => {
  for (const ecosystem of ["composer", "npm"]) {
    const inv = ecosystem === "composer"
      ? inventory()
      : inventory("npm", [dep("one")]);
    const good = ecosystem === "composer" ? packagist("1.0.0") : npm("1.0.0");
    const current = await run(inv, () => json(good));
    assertEquals(current.failure, null);
    assertEquals(counts(current.plan).unknown, 0);
    assertEquals(current.plan.subtasks, []);
    assertEquals(
      (current.plan.packages as { status: string }[])[0].status,
      "current",
    );
    const partial = ecosystem === "composer"
      ? [
        {},
        { packages: {} },
        { packages: { "other/name": [{ version: "1.0.0" }] } },
        packagist(),
        { packages: { "acme/one": [{}, { version: "1.1.0" }] } },
        packagist("garbage-1"),
        packagist("1.0.0-01"),
      ]
      : [
        {},
        { name: "one" },
        { ...good, name: "other" },
        npm(),
        { ...good, versions: { "1.0.0": {} } },
        { ...good, "dist-tags": { latest: "2.0.0" } },
        { ...good, versions: { "1.0.0": { version: "2.0.0" } } },
      ];
    for (const body of partial) {
      const result = await run(inv, () => json(body));
      assert(
        result.failure instanceof Error,
        `${ecosystem} must refuse ${JSON.stringify(body)}`,
      );
      assertEquals(counts(result.plan).unknown, 1);
      assertEquals(result.plan.subtasks, []);
      assertEquals(
        (result.plan.packages as { status: string }[])[0].status,
        "unknown",
      );
    }
  }
});

// R1: validate the complete npm catalog before choosing stable releases. A
// healthy sibling release must not hide an invalid key, value or latest tag.
for (
  const [name, body] of [
    ["four-part", npm("1.0.0.9")],
    ["shorthand", npm("1.0")],
    ["mixed-dev", {
      ...npm("1.0.0", "dev-main"),
      "dist-tags": { latest: "1.0.0" },
    }],
    ["invalid-key", {
      ...npm("1.0.0"),
      versions: { "1.0.0.9": { version: "1.0.0" } },
    }],
    ["invalid-value", {
      ...npm("1.0.0"),
      versions: { "1.0.0": { version: "1.0.0.9" } },
    }],
    ["invalid-latest", { ...npm("1.0.0"), "dist-tags": { latest: "1.0" } }],
    ["invalid-prerelease", {
      ...npm("1.0.0", "1.0.0-01"),
      "dist-tags": { latest: "1.0.0" },
    }],
  ] as const
) {
  Deno.test(`R1 npm ${name} retains unknown evidence and fails`, async () => {
    const result = await run(
      inventory("npm", [dep("one", "^1.0.0")]),
      () => json(body),
    );
    assert(
      result.failure instanceof Error,
      `must refuse ${JSON.stringify(body)}`,
    );
    assertEquals(counts(result.plan).unknown, 1);
    assertEquals(result.plan.subtasks, []);
    const row = (result.plan.packages as Record<string, unknown>[])[0];
    assertEquals([row.status, row.latest, row.maxInConstraint], [
      "unknown",
      null,
      null,
    ]);
    assert(typeof row.note === "string" && row.note.length > 0);
    assertEquals(result.requests, ["https://registry.npmjs.org/one"]);
  });
}

Deno.test("R1 npm stable/prerelease and Composer compatibility remain usable", async () => {
  // A valid prerelease tag is excluded from this existing stable-only policy;
  // the stable maximum remains a real release, not a coerced prerelease.
  const result = await run(
    inventory("npm", [dep("one", "^1.0.0")]),
    () => json(npm("1.0.0", "1.2.0", "2.0.0-beta.1")),
  );
  assertEquals(result.failure, null);
  assertEquals(counts(result.plan).unknown, 0);
  const row = (result.plan.packages as Record<string, unknown>[])[0];
  assertEquals([row.status, row.latest, row.maxInConstraint], [
    "bump",
    "1.2.0",
    "1.2.0",
  ]);
  for (
    const versions of [["1.0"], ["1.0.0.9"], ["1.0.0", "dev-main", "1.1.0-dev"]]
  ) {
    const compatible = await run(
      inventory(),
      () => json(packagist(...versions)),
    );
    assertEquals(compatible.failure, null);
    assertEquals(counts(compatible.plan).unknown, 0);
    assertEquals(compatible.plan.subtasks, []);
  }
});

Deno.test("registry HTTP/JSON/transport failure and partial package coverage fail the real plan method without erasing diagnostic rows", async () => {
  for (
    const response of [
      () => new Response("unavailable", { status: 503 }),
      () => new Response('{"packages":'),
      () => Promise.reject(new Error("controlled registry outage")),
      () => json(packagist("1.0.0-beta.1", "dev-main")),
    ]
  ) {
    const r = await run(inventory(), response);
    assert(r.failure instanceof Error);
    assertEquals(counts(r.plan).unknown, 1);
    assertEquals(r.plan.subtasks, []);
  }
  const mixed = await run(
    inventory("composer", [dep("acme/one"), dep("acme/two")]),
    (url) =>
      url.endsWith("one.json")
        ? json(packagist("1.0.0", "1.1.0"))
        : new Response("outage", { status: 503 }),
  );
  assert(
    mixed.failure instanceof Error,
    "one usable row cannot make incomplete registry coverage successful",
  );
  assertEquals(counts(mixed.plan).packages, 2);
  assertEquals(counts(mixed.plan).unknown, 1);
  assertEquals(counts(mixed.plan).bumps, 1);
  assertEquals(mixed.requests.length, 2);
});

Deno.test("existing package selection, aliases, ranges and caret major policy remain explicit advisory output", async () => {
  const r = await run(
    inventory(),
    () => json(packagist("1.0.0", "1.4.0", "2.0.0")),
  );
  assertEquals(r.failure, null);
  const tasks = r.plan.subtasks as {
    kind: string;
    major: boolean;
    packages: Record<string, unknown>[];
  }[];
  assertEquals(
    tasks.map((
      t,
    ) => [
      t.kind,
      t.major,
      t.packages[0].name,
      t.packages[0].to,
      t.packages[0].newConstraint,
    ]),
    [
      ["within-constraints", false, "acme/one", "1.4.0", null],
      ["constraint-change", true, "acme/one", "2.0.0", "^2.0"],
    ],
  );
  const alias = { ...dep("alias", "npm:one@^1.0.0"), registryName: "one" };
  const aliased = await run(
    inventory("npm", [alias]),
    () => json(npm("1.0.0", "1.2.0")),
  );
  assertEquals(aliased.failure, null);
  assertEquals(aliased.requests, ["https://registry.npmjs.org/one"]);
  assertEquals(
    (aliased.plan.subtasks as { packages: { name: string; to: string }[] }[])[0]
      .packages[0].name,
    "alias",
  );
  assertEquals(toRange("composer", "~1.2"), ">=1.2.0 <2.0.0");
  assertEquals(toRange("npm", "workspace:*"), null);
  assertEquals(isMajorJump("0.2.1", "0.3.0"), true);
  assertEquals(isMajorJump("1.0.0", "1.9.0"), false);
  const skipped = await run(
    inventory("npm", [dep("local", "workspace:*")]),
    () => {
      throw new Error("unsupported constraints must not query a registry");
    },
  );
  assertEquals(skipped.requests, []);
  assertEquals(skipped.failure, null);
  assertEquals(counts(skipped.plan).skipped, 1);
  assertEquals(
    (skipped.plan.packages as { status: string }[])[0].status,
    "skipped",
  );
  // No approval or execution occurs here. Output is a proposal, including majors.
});

Deno.test("real legacy inventory reads exact committed manifest/lock inputs in disposable fixtures; its commit identity is insufficient for task worktrees", async () => {
  const dir = await Deno.makeTempDir({ prefix: "swamp-dependency-input-" });
  const git = async (...args: string[]) => {
    const r = await new Deno.Command("git", {
      args: ["-C", dir, ...args],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assert(r.success, new TextDecoder().decode(r.stderr));
    return new TextDecoder().decode(r.stdout).trim();
  };
  try {
    await git("init", "-q");
    await Deno.mkdir(`${dir}/packages/example`, { recursive: true });
    const manifest = `${dir}/packages/example/composer.json`,
      lock = `${dir}/packages/example/composer.lock`;
    await Deno.writeTextFile(
      manifest,
      JSON.stringify({ require: { php: "^8.4", "acme/one": "^1.0" } }),
    );
    await Deno.writeTextFile(
      lock,
      JSON.stringify({ packages: [{ name: "acme/one", version: "1.0.0" }] }),
    );
    await git("add", ".");
    await git(
      "-c",
      "user.name=isolated-fixture",
      "-c",
      "user.email=fixture@example.invalid",
      "commit",
      "-qm",
      "inputs v1",
    );
    const first = await git("rev-parse", "HEAD");
    const v1 = await buildInventory(
      dir,
      first,
      null,
      "controlled unavailable inventory",
    );
    assertEquals(v1[0].ecosystems[0].direct, [dep("acme/one")]);
    await Deno.writeTextFile(
      manifest,
      JSON.stringify({ require: { "acme/one": "^2.0" } }),
    );
    await Deno.writeTextFile(
      lock,
      JSON.stringify({ packages: [{ name: "acme/one", version: "2.0.0" }] }),
    );
    assertEquals(
      await buildInventory(dir, first, null, "controlled"),
      v1.map((s) => ({
        ...s,
        ecosystems: s.ecosystems.map((e) => ({
          ...e,
          reason: "Gateway unavailable: controlled",
        })),
      })),
    );
    await git("add", ".");
    await git(
      "-c",
      "user.name=isolated-fixture",
      "-c",
      "user.email=fixture@example.invalid",
      "commit",
      "-qm",
      "inputs v2",
    );
    const second = await git("rev-parse", "HEAD");
    assert(first !== second);
    assertEquals(
      (await buildInventory(dir, second, null, "controlled"))[0].ecosystems[0]
        .direct,
      [dep("acme/one", "^2.0", "2.0.0")],
    );
    await Deno.writeTextFile(manifest, "{");
    await git("add", ".");
    await git(
      "-c",
      "user.name=isolated-fixture",
      "-c",
      "user.email=fixture@example.invalid",
      "commit",
      "-qm",
      "invalid input",
    );
    await assertRejects(() => buildInventory(dir, "HEAD", null, "controlled"));
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
});
