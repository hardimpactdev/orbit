import { emptyExceptions } from "../_lib/maintenance/exceptions.ts";
import {
  assert,
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, MaintenanceFailure } from "./mod.ts";
import type { z } from "npm:zod@4";
type Detection = z.infer<typeof behavior.resources.detection.schema>;

type Response = { code?: number; raw?: string; data?: unknown };
type Fixture = Record<string, Response[]>;
type Method = keyof typeof behavior.methods;
const outdated = "vp outdated --format json";
const audit = "vp pm audit --json";
const emptyAudit = { data: { vulnerabilities: {} } };
const emptyDetect: Fixture = {
  [outdated]: [{ data: {} }],
  [audit]: [emptyAudit],
};
const manifest = {
  dependencies: { "shadcn": "^4.21.0", "react": "^19.0" },
  devDependencies: {
    "vite-plus": "^0.2.9",
    "vite": "npm:@voidzero-dev/vite-plus-core@0.2.8",
  },
  packageManager: "npm@11.12.0",
};
async function fixture(name: string) {
  return JSON.parse(
    await Deno.readTextFile(new URL(`./fixtures/${name}`, import.meta.url)),
  );
}
const finding = await fixture("npm-audit-shadcn.json");
async function binary(name: string) {
  const r = await new Deno.Command("which", { args: [name], stdout: "piped" })
    .output();
  assertEquals(r.code, 0);
  return new TextDecoder().decode(r.stdout).trim();
}
const php = await binary("php");
const git = await binary("git");

class Repo {
  exceptions: unknown = emptyExceptions();
  constructor(readonly temp: string, readonly cwd: string) {}
  async json(file: string, data: unknown, path = ".") {
    const dir = `${this.cwd}/${path}`;
    await Deno.mkdir(dir, { recursive: true });
    await Deno.writeTextFile(`${dir}/${file}`, JSON.stringify(data));
  }
  async log() {
    try {
      return await Deno.readTextFile(`${this.temp}/commands`);
    } catch (error) {
      if (error instanceof Deno.errors.NotFound) return "";
      throw error;
    }
  }
  async exists(file: string) {
    try {
      await Deno.stat(`${this.cwd}/${file}`);
      return true;
    } catch (error) {
      if (error instanceof Deno.errors.NotFound) return false;
      throw error;
    }
  }
  async execute(
    method: "detect",
    args?: Record<string, unknown>,
    fixture?: Fixture,
  ): Promise<Detection>;
  async execute(
    method: Method,
    args?: Record<string, unknown>,
    fixture?: Fixture,
  ): Promise<Record<string, unknown>>;
  async execute(
    method: Method,
    args: Record<string, unknown> = {},
    fixture: Fixture = emptyDetect,
  ) {
    if (method === "detect") {
      const bun = await this.exists("bun.lock");
      fixture = Object.fromEntries(
        Object.entries(fixture).map(([key, value]) => [
          key === outdated
            ? (bun ? "bun outdated" : "npm outdated --json")
            : key === audit
            ? (bun ? "bun pm audit --json" : "npm audit --json")
            : key,
          value,
        ]),
      );
    }
    await Deno.writeTextFile(
      `${this.temp}/fixture.json`,
      JSON.stringify(fixture),
    );
    try {
      await Deno.remove(`${this.temp}/fixture.json.counts`);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    const { context, getWrittenResources } = createModelTestContext();
    const execute = behavior.methods[method].execute as (
      a: unknown,
      c: typeof context,
    ) => Promise<unknown>;
    await execute({
      cwd: this.cwd,
      exceptions: this.exceptions,
      policy: Object.fromEntries(
        ((args.paths as string[] | undefined) ?? ["."]).map(
          (p) => [p, { minimumReleaseAgeDays: 7 }],
        ),
      ),
      ...args,
    }, context);
    const outputs = getWrittenResources();
    assertEquals(outputs.length, 1);
    const output = outputs[0];
    const spec = behavior
      .resources[
        output.name === "failure"
          ? "failure"
          : method === "detect"
          ? "detection"
          : method
      ];
    assert(spec.schema.safeParse(output.data).success);
    return output.data as Record<string, unknown>;
  }
  async failure(
    method: Method,
    args: Record<string, unknown>,
    fixture: Fixture,
    code: number,
    message?: string,
  ) {
    const error = await assertRejects(
      () => this.execute(method, args, fixture),
      MaintenanceFailure,
      message,
    );
    assertEquals(error.exitCode, code);
  }
  async vp(path = ".") {
    await Deno.mkdir(`${this.cwd}/${path}/node_modules/.bin`, {
      recursive: true,
    });
    await Deno.copyFile(
      `${this.temp}/manager`,
      `${this.cwd}/${path}/node_modules/.bin/vp`,
    );
    await Deno.chmod(`${this.cwd}/${path}/node_modules/.bin/vp`, 0o755);
  }
  async npm(
    deps: Record<string, string>,
    versions: Record<string, string> = {},
    path = ".",
    extra: Record<string, unknown> = {},
  ) {
    await this.json("package.json", { dependencies: deps, ...extra }, path);
    await this.json("package-lock.json", npmLock(versions), path);
  }
  async bun(
    deps: Record<string, string>,
    packages: Record<string, unknown> = {},
    vitePlus = true,
  ) {
    await Deno.remove(`${this.cwd}/package-lock.json`);
    await this.json("package.json", {
      dependencies: { ...deps, ...(vitePlus ? { "vite-plus": "^0.2.9" } : {}) },
      packageManager: "bun@1.3.14",
    });
    await this.json("bun.lock", { lockfileVersion: 1, packages });
  }
}
function test(name: string, fn: (repo: Repo) => Promise<void>) {
  Deno.test(name, async () => {
    const temp = await Deno.realPath(
      await Deno.makeTempDir({ prefix: "js-maintenance-test-" }),
    );
    const cwd = `${temp}/repo with spaces`;
    await Deno.mkdir(`${temp}/bin`);
    await Deno.mkdir(cwd);
    await Deno.symlink(php, `${temp}/bin/php`);
    await Deno.symlink(git, `${temp}/bin/git`);
    const source = await Deno.readTextFile(
      new URL("./fixtures/manager", import.meta.url),
    );
    const setup = `<?php
file_put_contents(${
      JSON.stringify(temp + "/child-env.json")
    }, json_encode(getenv()));
putenv(${JSON.stringify("DEPENDENCY_TEST_LOG=" + temp + "/commands")});
putenv(${JSON.stringify("DEPENDENCY_TEST_FIXTURE=" + temp + "/fixture.json")});
`;
    await Deno.writeTextFile(`${temp}/manager`, source.replace("<?php", setup));
    await Deno.chmod(`${temp}/manager`, 0o755);
    for (const name of ["npm", "bun", "npx", "vp", "composer"]) {
      await Deno.copyFile(`${temp}/manager`, `${temp}/bin/${name}`);
    }
    const previous = Deno.env.get("PATH");
    Deno.env.set("PATH", `${temp}/bin`);
    try {
      const repo = new Repo(temp, cwd);
      await repo.json("package.json", manifest);
      await repo.json(
        "package-lock.json",
        npmLock({ shadcn: "4.21.0", react: "19.0.0", "vite-plus": "0.2.9" }),
      );
      await repo.vp();
      await fn(repo);
    } finally {
      if (previous === undefined) Deno.env.delete("PATH");
      else Deno.env.set("PATH", previous);
      await Deno.remove(temp, { recursive: true });
    }
  });
}
function npmLock(
  versions: Record<string, string>,
  extra: Record<string, unknown> = {},
) {
  return {
    lockfileVersion: 3,
    packages: {
      "": {},
      ...Object.fromEntries(
        Object.entries(versions).map((
          [name, version],
        ) => [`node_modules/${name}`, { version }]),
      ),
      ...extra,
    },
  };
}
function bunEntry(
  name: string,
  version: string,
  meta: Record<string, unknown> = {},
) {
  return [`${name}@${version}`, "", meta, "sha"];
}
function npmReports(
  data: unknown = {},
  findings: unknown = { vulnerabilities: {} },
): Fixture {
  return {
    "npm outdated --json": [{ data }],
    "npm audit --json": [{ data: findings }],
  };
}
function bunReports(table: string, findings: unknown = {}): Fixture {
  return { [outdated]: [{ raw: table }], [audit]: [{ data: findings }] };
}

for (const manager of ["npm", "bun"]) {
  test(`fresh Vite+ ${manager} detects from lockfiles without a bootstrap install`, async (r) => {
    if (manager === "bun") {
      await r.bun({ react: "^19" }, { react: bunEntry("react", "19.0.0") });
    }
    await Deno.remove(`${r.cwd}/node_modules`, { recursive: true });
    const file = manager === "npm" ? "package-lock.json" : "bun.lock";
    const before = await Deno.readTextFile(`${r.cwd}/${file}`);
    const result = await r.execute(
      "detect",
      {},
      manager === "bun"
        ? { [outdated]: [{ data: {} }], [audit]: [{ data: {} }] }
        : emptyDetect,
    );
    assertEquals(result, { schema: 1, candidates: [] });
    assertStringIncludes(await r.log(), `${manager}|outdated`);
    assert(!(await r.log()).includes("install"));
    assert(!(await r.log()).includes("vp|"));
    assertEquals(await r.exists("node_modules"), false);
    assertEquals(await Deno.readTextFile(`${r.cwd}/${file}`), before);
  });
}
for (
  const [declared, locks, message] of [
    ["npm@11.12.0", ["bun.lock"], "bun.lock and no package-lock.json"],
    ["bun@1.3.14", ["package-lock.json"], "package-lock.json and no bun.lock"],
    ["npm@11.12.0", [], "no package-lock.json"],
    [
      "bun@1.3.14",
      ["package-lock.json", "bun.lock"],
      "also has package-lock.json",
    ],
  ] as [string, string[], string][]
) {
  test(`manager lock mismatch ${declared} ${locks}`, async (r) => {
    await r.json("package.json", {
      dependencies: { react: "^19" },
      packageManager: declared,
    });
    await Deno.remove(`${r.cwd}/package-lock.json`);
    for (const file of locks) await r.json(file, {});
    await r.failure("detect", {}, emptyDetect, 70, message);
    assertEquals(await r.log(), "");
  });
}
for (
  const extra of [{}, { packageManager: "pnpm@10" }, {
    packageManager: "npm@11",
    devEngines: { packageManager: { name: "bun" } },
  }]
) {
  test(`invalid manager ${JSON.stringify(extra)}`, async (r) => {
    await r.json("package.json", { dependencies: { react: "^19" }, ...extra });
    await Deno.remove(`${r.cwd}/package-lock.json`);
    await r.failure("detect", {}, emptyDetect, 70);
    assertEquals(await r.log(), "");
  });
}
test("devEngines selects the declared manager", async (r) => {
  await r.npm({ react: "^19" }, {}, ".", {
    devEngines: { packageManager: { name: "npm", version: "11" } },
  });
  await r.execute("detect");
  assertStringIncludes(await r.log(), "npm|outdated");
});
test("two inferred lockfiles disagree before any command", async (r) => {
  await r.npm({ react: "^19" });
  await r.json("bun.lock", {});
  await r.failure("detect", {}, emptyDetect, 70, "disagree");
  assertEquals(await r.log(), "");
});
for (
  const paths of [
    ["../outside"],
    ["/tmp"],
    ["mobile/../x"],
    [".", "."],
    ["missing"],
    ["C:/repo"],
    ["mobile\\app"],
  ]
) {
  test(`unsafe or missing paths ${paths}`, async (r) => {
    await r.failure("detect", { paths }, emptyDetect, 70);
    assertEquals(await r.log(), "");
  });
}
test("symlink leaving the checkout is rejected", async (r) => {
  await Deno.symlink(r.temp, `${r.cwd}/outside`);
  await r.failure("detect", { paths: ["outside"] }, emptyDetect, 70, "escapes");
  assertEquals(await r.log(), "");
});
test("two paths to the same real directory are rejected", async (r) => {
  await Deno.symlink(r.cwd, `${r.cwd}/same`);
  await r.failure(
    "detect",
    { paths: [".", "same"] },
    emptyDetect,
    70,
    "repeats",
  );
});
test("missing cwd has the shared exit 70 failure", async (r) => {
  await r.failure(
    "detect",
    { cwd: `${r.cwd}/gone` },
    emptyDetect,
    70,
    "missing",
  );
});
test("detect covers a subdirectory with no Composer package", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" }, "server/web");
  const result = await r.execute(
    "detect",
    { paths: ["server/web"] },
    npmReports({ react: { current: "19.0.0", latest: "20.0.0" } }),
  );
  assertEquals(result, {
    schema: 1,
    candidates: [{
      path: "server/web",
      manager: "js",
      package: "react",
      reasons: ["major"],
      advisories: [],
      current: "19.0.0",
      latest: "20.0.0",
    }],
  });
});
for (const project of ["hauzer", "mealou"]) {
  test(`real ${project} mobile outdated reads current from lock and folds Expo`, async (r) => {
    const data = await fixture(`${project}-mobile-outdated.json`),
      versions = await fixture(`${project}-mobile-locked.json`);
    await r.npm(
      Object.fromEntries(Object.keys(data).map((n) => [n, "*"])),
      versions,
      "mobile",
    );
    const result = await r.execute(
      "detect",
      { paths: ["mobile"] },
      npmReports(data),
    );
    assertEquals(result.candidates, [{
      path: "mobile",
      manager: "js",
      package: "expo",
      reasons: ["major"],
      advisories: [],
      current: project === "hauzer" ? "57.0.28" : "57.0.27",
      latest: "58.0.14",
      align: "expo-install-fix",
    }]);
    assert(!(await r.log()).includes("install"));
    assertEquals(await r.exists("mobile/node_modules"), false);
  });
}
test("npm missing current uses locked version rather than wanted", async (r) => {
  await r.npm({ "expo-auth-session": "*" }, { "expo-auth-session": "57.0.15" });
  const result = await r.execute(
    "detect",
    {},
    npmReports(await fixture("hauzer-mobile-outdated.json")),
  );
  assertEquals(result.candidates[0].current, "57.0.15");
  assertEquals(result.candidates[0].latest, "58.0.13");
});
test("missing current in report and lock fails", async (r) => {
  await r.npm({ lodash: "^4" });
  await r.failure(
    "detect",
    {},
    npmReports({ lodash: { wanted: "4.17.21", latest: "5.0.0" } }),
    70,
    "no current version: lodash",
  );
});
test("reported current takes precedence over lock", async (r) => {
  await r.npm({ react: "^19" }, { react: "9.9.9" });
  const result = await r.execute(
    "detect",
    {},
    npmReports({ react: { current: "19.0.0", latest: "20.0.0" } }),
  );
  assertEquals(result.candidates[0].current, "19.0.0");
});
test("Bun JSON missing current uses bun.lock", async (r) => {
  await r.bun({ "left-pad": "^1" }, {
    "left-pad": bunEntry("left-pad", "1.2.3"),
  }, false);
  const result = await r.execute("detect", {}, {
    "bun outdated": [{
      data: { "left-pad": { wanted: "1.9.0", latest: "2.0.0" } },
    }],
    "bun pm audit --json": [{ data: {} }],
  });
  assertEquals(result.candidates[0].current, "1.2.3");
});
test("Bun JSON missing current cannot use an aliased lock descriptor", async (r) => {
  await r.bun({ alias: "npm:lodash@^4" }, {
    alias: bunEntry("lodash", "4.17.20"),
  }, false);
  await r.failure(
    "detect",
    {},
    {
      "bun outdated": [{ data: { alias: { latest: "5.0.0" } } }],
    },
    70,
    "no current version: alias",
  );
  assert(!(await r.log()).includes("audit"));
});
test("real Bun table strips age markers, compares Current to Latest and skips non-direct alias target", async (r) => {
  await r.bun({
    "@laravel/passkeys": "^0.2",
    react: "^19",
    vite: "npm:@voidzero-dev/vite-plus-core@0.3.3",
  }, {});
  const result = await r.execute(
    "detect",
    {},
    bunReports(
      await Deno.readTextFile(
        new URL("./fixtures/bun-outdated.txt", import.meta.url),
      ),
    ),
  );
  assertEquals(result.candidates.map((c) => [c.package, c.current, c.latest]), [
    ["@laravel/passkeys", "0.2.0", "0.4.0"],
    ["vite-plus", "0.3.3", "1.3.0"],
  ]);
});
test("Bun lock trailing-comma parser preserves commas inside strings", async (r) => {
  await r.bun({ "left-pad": "^1" }, {}, false);
  await Deno.writeTextFile(
    `${r.cwd}/bun.lock`,
    '{"packages":{"left-pad":["left-pad@1.2.3","",{"text":", ]"},"sha",],},}',
  );
  const result = await r.execute("detect", {}, {
    "bun outdated": [{ data: { "left-pad": { latest: "2.0.0" } } }],
    "bun pm audit --json": [{ data: {} }],
  });
  assertEquals(result.candidates[0].current, "1.2.3");
});
for (
  const response of [{ raw: "broken" }, { data: { error: "failure" } }, {
    data: { react: { latest: 2 } },
  }, { code: 17, data: {} }]
) {
  test(`invalid npm detection output ${JSON.stringify(response)}`, async (r) => {
    await r.failure("detect", {}, { [outdated]: [response] }, 70);
  });
}
test("invalid Bun table fails closed", async (r) => {
  await r.bun({ react: "^19" });
  await r.failure("detect", {}, bunReports("not a table"), 70);
});
test("npm via chains preserve real IDs, URLs, title, severity and affected range", async (r) => {
  const result = await r.execute("detect", {}, {
    [outdated]: [{ data: {} }],
    [audit]: [{ code: 1, data: finding }],
  });
  assertEquals(result.candidates.map((c) => c.package), [
    "@shadcn/registry",
    "shadcn",
    "zod",
  ]);
  assertEquals(
    result.candidates.find((c) => c.package === "shadcn")!.advisories,
    [{
      id: "1243066",
      url: "https://github.com/advisories/GHSA-mrhx-c7xc-m3w5",
      title:
        "Zod can exhaust memory while validating oversized constrained collections",
      affected: "<4.6.6",
      severity: "high",
    }],
  );
});
test("one shared npm advisory remains on every controlling direct dependency", async (r) => {
  const data = structuredClone(finding);
  data.vulnerabilities.react = { via: ["zod"], isDirect: true };
  const result = await r.execute("detect", {}, {
    [outdated]: [{ data: {} }],
    [audit]: [{ data }],
  });
  assertEquals(result.candidates.map((c) => c.package), [
    "@shadcn/registry",
    "react",
    "shadcn",
    "zod",
  ]);
  assertEquals(
    result.candidates[0].advisories,
    result.candidates[1].advisories,
  );
});
for (const via of [["missing-package"], ["@shadcn/registry"], []]) {
  test(`unresolvable npm via chain ${via}`, async (r) => {
    const data = structuredClone(finding);
    data.vulnerabilities.shadcn.via = via;
    data.vulnerabilities["@shadcn/registry"].via = ["shadcn"];
    await r.failure("detect", {}, {
      [outdated]: [{ data: {} }],
      [audit]: [{ data }],
    }, 70);
  });
}
test("Bun walks dependencies and peers, stopping at another direct package", async (r) => {
  await r.bun({ "@nckrtl/launch-ui": "0.0.x", shadcn: "^4.21.0" }, {
    "@nckrtl/launch-ui": bunEntry("@nckrtl/launch-ui", "0.0.31", {
      dependencies: { "@storybook/addon-mcp": "1.0.0" },
      peerDependencies: { "vite-plus": "^0.2" },
    }),
    "@storybook/addon-mcp": bunEntry("@storybook/addon-mcp", "1.0.0", {
      peerDependencies: { storybook: "^10" },
    }),
    storybook: bunEntry("storybook", "10.5.6"),
    shadcn: bunEntry("shadcn", "4.21.0", { dependencies: { zod: "^3" } }),
    zod: bunEntry("zod", "3.25.76"),
    "vite-plus": bunEntry("vite-plus", "0.2.9"),
  });
  const data = {
    zod: [{
      id: 1243066,
      url: "https://github.com/advisories/GHSA-zod",
      severity: "high",
    }],
    storybook: [{ id: 999, url: "https://github.com/advisories/GHSA-story" }],
    "vite-plus": [{ id: 111, url: "https://github.com/advisories/GHSA-vp" }],
  };
  const result = await r.execute(
    "detect",
    {},
    bunReports(
      "| Package | Current | Update | Latest |\n| @nckrtl/launch-ui | 0.0.31 | 0.0.33 | 0.1.0 |",
      data,
    ),
  );
  assertEquals(
    result.candidates.map((c) => [
      c.package,
      c.reasons,
      c.advisories.map((a) => a.id),
    ]),
    [["@nckrtl/launch-ui", ["major", "security"], ["999"]], ["shadcn", [
      "security",
    ], ["1243066"]], ["vite-plus", ["security"], ["111"]]],
  );
});
test("real Bun advisory fixture attaches through the original lock graph and keeps advisory order", async (r) => {
  await r.bun({ shadcn: "^4.1.0" }, {
    shadcn: bunEntry("shadcn", "4.1.0", { dependencies: { zod: "^4" } }),
    zod: bunEntry("zod", "4.0.0"),
    "vite-plus": bunEntry("vite-plus", "0.3.3", {
      dependencies: { "@vitest/browser": "4.0.0" },
    }),
    "@vitest/browser": bunEntry("@vitest/browser", "4.0.0", {
      dependencies: { "@vitest/mocker": "4.0.0" },
    }),
    "@vitest/mocker": bunEntry("@vitest/mocker", "4.0.0"),
  });
  const result = await r.execute(
    "detect",
    {},
    bunReports("bun outdated v1.3.14\n", await fixture("bun-audit.json")),
  );
  assertEquals(
    result.candidates.map((c) => [c.package, c.advisories.map((a) => a.id)]),
    [["shadcn", ["1243066"]], ["vite-plus", ["1242723", "111"]]],
  );
});
test("unattachable Bun advisory fails closed", async (r) => {
  await r.bun({ shadcn: "^4" }, {});
  await r.failure(
    "detect",
    {},
    bunReports("", {
      unknown: [{ id: 123, url: "https://example.com/advisory" }],
    }),
    70,
    "Cannot attach",
  );
});

test("stale Bun exceptions retain the maintainer date and manager contract", async (r) => {
  await r.bun({ shadcn: "^4" }, {});
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    '[install]\nminimumReleaseAge = 604800\nminimumReleaseAgeExcludes = ["shadcn"]\n',
  );
  r.exceptions = {
    schema: 1,
    exceptions: [{
      path: ".",
      manager: "js",
      package: "shadcn",
      recorded_on: "2026-01-01",
      days: 7,
    }, {
      path: ".",
      manager: "composer",
      package: "other/pkg",
      recorded_on: "2026-01-01",
      days: 7,
    }],
  };
  const result = await r.execute("detect", {}, bunReports(""));
  assertEquals(result.stale_exceptions, [{
    path: ".",
    manager: "js",
    package: "shadcn",
    recorded_on: "2026-01-01",
    remove_on: "2026-01-08",
  }]);
});
async function expoRepo(r: Repo) {
  await r.npm(
    {
      expo: "57.0.28",
      react: "19.0.0",
      "react-native": "0.86.3",
      "expo-camera": "57.0.6",
      lodash: "^4.17.21",
    },
    { expo: "57.0.28", "react-native": "0.86.3" },
    "mobile",
  );
}
test("Expo SDK major is one candidate while coupled security remains separate", async (r) => {
  await expoRepo(r);
  const findings = {
    vulnerabilities: {
      "expo-camera": {
        via: [{
          source: 999,
          url: "https://github.com/advisories/GHSA-camera",
        }],
      },
      "react-native": {
        via: [{ source: 1000, url: "https://github.com/advisories/GHSA-rn" }],
      },
    },
  };
  const result = await r.execute(
    "detect",
    { paths: ["mobile"] },
    npmReports({
      expo: { current: "57.0.28", latest: "58.0.0" },
      "react-native": { current: "0.86.3", latest: "0.87.0" },
      lodash: { current: "4.17.21", latest: "5.0.0" },
    }, findings),
  );
  assertEquals(result.candidates.map((c) => [c.package, c.reasons]), [
    ["expo", ["major"]],
    ["expo-camera", ["security"]],
    ["lodash", ["major"]],
    ["react-native", ["security"]],
  ]);
  assertEquals(result.candidates[0].align, "expo-install-fix");
  assertEquals(result.candidates[3].advisories[0].id, "1000");
});
test("no Expo SDK candidate is invented for an isolated coupled bump", async (r) => {
  await expoRepo(r);
  const result = await r.execute(
    "detect",
    { paths: ["mobile"] },
    npmReports({ "react-native": { current: "0.86.3", latest: "0.87.0" } }),
  );
  assertEquals(result.candidates, []);
});
test("manager and git children never receive parent agent markers or npm overrides", async (r) => {
  const env = {
    CLAUDECODE: "1",
    CODEX_THREAD_ID: "parent",
    SWAMP_SERVER_TOKEN: "dummy-test-marker",
    npm_config_min_release_age: "77",
    BUN_CONFIG_NO_CLEAR_TERMINAL: "1",
  };
  const previous = Object.fromEntries(
    Object.keys(env).map((key) => [key, Deno.env.get(key)]),
  );
  for (const [key, value] of Object.entries(env)) Deno.env.set(key, value);
  try {
    await r.execute("detect");
    const actual = JSON.parse(
      await Deno.readTextFile(`${r.temp}/child-env.json`),
    );
    for (const key of Object.keys(env)) assertEquals(actual[key], undefined);
    assertEquals(actual.TERM, "dumb");
    assertEquals(actual.PATH, `${r.temp}/bin`);
  } finally {
    for (const [key, value] of Object.entries(previous)) {
      if (value === undefined) Deno.env.delete(key);
      else Deno.env.set(key, value);
    }
  }
});
test("JSON reports have an 8 MiB byte limit", async (r) => {
  await r.failure(
    "detect",
    {},
    { [outdated]: [{ raw: JSON.stringify({ x: "é".repeat(4194304) }) }] },
    70,
    "too large",
  );
});
test("detect ignores repository exception files and never overwrites them", async (r) => {
  await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    "[install]\nminimumReleaseAge = 604800\n",
  );
  const original = '{"schema":99,"must":"stay untouched"}';
  await Deno.writeTextFile(`${r.cwd}/maintainer-exceptions.json`, original);
  await r.execute("detect", {}, bunReports(""));
  assertEquals(
    await Deno.readTextFile(`${r.cwd}/maintainer-exceptions.json`),
    original,
  );
});
async function commit(r: Repo) {
  for (
    const args of [["init", "-b", "main"], ["add", "."], [
      "-c",
      "user.email=test@example.com",
      "-c",
      "user.name=Test",
      "-c",
      "commit.gpgsign=false",
      "commit",
      "-m",
      "base",
    ]]
  ) {
    const out = await new Deno.Command(git, {
      cwd: r.cwd,
      args,
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(out.code, 0, new TextDecoder().decode(out.stderr));
  }
}
test("plan accepts an install failure with output over 8 MiB and reports its command", async (r) => {
  await commit(r);
  const raw = "x".repeat(8388609);
  const plan = await r.execute("plan", {
    candidates: [{
      path: ".",
      manager: "js",
      package: "shadcn",
      reasons: ["major"],
      advisories: [],
      current: "4.21.0",
      latest: "5.0.0",
    }],
  }, {
    "npm view shadcn --json": [{
      data: {
        versions: ["5.0.0"],
        time: { "5.0.0": "2020-01-01T00:00:00Z" },
      },
    }],
    "npm install --package-lock-only --ignore-scripts": [{ code: 23, raw }],
  }) as { targets: unknown[]; blocked: { reason: string }[] };
  assertEquals(plan.targets, []);
  assertEquals(plan.blocked.length, 1);
  assertStringIncludes(
    plan.blocked[0].reason,
    "npm install --package-lock-only --ignore-scripts failed:",
  );
  assertStringIncludes(plan.blocked[0].reason, raw);
  assertStringIncludes(
    await r.log(),
    "npm|install --package-lock-only --ignore-scripts|",
  );
});
