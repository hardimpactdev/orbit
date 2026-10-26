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
type Summary = z.infer<typeof behavior.resources.summary.schema>;

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
const major = { package: "shadcn", version: "5.0.0", purpose: "major" };
const security = {
  package: "shadcn",
  version: "4.22.0",
  purpose: "security",
  advisoryIds: ["1243066"],
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
  constructor(readonly temp: string, readonly cwd: string) {}
  async json(file: string, data: unknown, path = ".") {
    const dir = `${this.cwd}/${path}`;
    await Deno.mkdir(dir, { recursive: true });
    await Deno.writeTextFile(`${dir}/${file}`, JSON.stringify(data));
  }
  async read(file: string) {
    return JSON.parse(await Deno.readTextFile(`${this.cwd}/${file}`));
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
    method: "summary",
    args?: Record<string, unknown>,
    fixture?: Fixture,
  ): Promise<Summary>;
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
    const spec = behavior.resources[method === "detect" ? "detection" : method];
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
  async git(...args: string[]) {
    const r = await new Deno.Command(git, {
      cwd: this.cwd,
      args: [
        "-c",
        "user.email=test@example.com",
        "-c",
        "user.name=Test",
        "-c",
        "commit.gpgsign=false",
        "-c",
        "core.hooksPath=/dev/null",
        ...args,
      ],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(r.code, 0, new TextDecoder().decode(r.stderr));
    return new TextDecoder().decode(r.stdout).trim();
  }
  async base() {
    await this.git("init", "-b", "main");
    await this.git("add", ".");
    await this.git("commit", "-m", "base");
    return await this.git("rev-parse", "HEAD");
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

test("routine uses local vp and preserves the first failure code", async (r) => {
  await r.failure(
    "routine",
    {},
    { "vp update": [{ code: 9, raw: "failed" }] },
    9,
  );
  assertEquals(await r.log(), `vp|update|${r.cwd}\n`);
});
for (const manager of ["npm", "bun"]) {
  test(`plain ${manager} uses its own update command`, async (r) => {
    if (manager === "npm") await r.npm({ react: "^19" });
    else await r.bun({ react: "^19" }, {}, false);
    await r.execute("routine");
    assertEquals(await r.log(), `${manager}|update|${r.cwd}\n`);
  });
}
for (const executable of [false, true]) {
  test(`missing/non-executable local vp fails before updates (${executable})`, async (r) => {
    const file = `${r.cwd}/node_modules/.bin/vp`;
    if (executable) await Deno.chmod(file, 0o644);
    else await Deno.remove(file);
    await r.failure("routine", {}, emptyDetect, 127, "Missing executable");
    assertEquals(await r.log(), "");
  });
}
test("all paths are preflighted before routine updates", async (r) => {
  await r.npm({ react: "^19" }, {}, "mobile");
  await Deno.remove(`${r.temp}/bin/npm`);
  await r.failure(
    "routine",
    { paths: [".", "mobile"] },
    emptyDetect,
    127,
    "npm",
  );
  assertEquals(await r.log(), "");
});
test("routine stops before a later path after update failure", async (r) => {
  await r.npm({ react: "^19" }, {}, "mobile");
  await r.failure("routine", { paths: [".", "mobile"] }, {
    "vp update": [{ code: 17, raw: "failed" }],
  }, 17);
  assertEquals(await r.log(), `vp|update|${r.cwd}\n`);
});
for (const manager of ["npm", "bun"]) {
  test(`fresh Vite+ ${manager} bootstraps with a frozen install`, async (r) => {
    if (manager === "bun") {
      await r.bun({ react: "^19" }, { react: bunEntry("react", "19.0.0") });
    }
    await Deno.remove(`${r.cwd}/node_modules`, { recursive: true });
    const file = manager === "npm" ? "package-lock.json" : "bun.lock";
    const before = await Deno.readTextFile(`${r.cwd}/${file}`),
      manifest = await Deno.readTextFile(`${r.cwd}/package.json`);
    const result = await r.execute(
      "detect",
      {},
      manager === "bun"
        ? { [outdated]: [{ data: {} }], [audit]: [{ data: {} }] }
        : emptyDetect,
    );
    assertEquals(result, { schema: 1, candidates: [] });
    assertStringIncludes(
      await r.log(),
      manager === "npm"
        ? "npm|ci --ignore-scripts|"
        : "bun|install --frozen-lockfile --ignore-scripts|",
    );
    assert(
      (await r.log()).indexOf("--ignore-scripts|") <
        (await r.log()).indexOf("vp|outdated"),
    );
    assertEquals(await Deno.readTextFile(`${r.cwd}/${file}`), before);
    assertEquals(await Deno.readTextFile(`${r.cwd}/package.json`), manifest);
  });
}
test("frozen install failure stops before Vite+", async (r) => {
  await Deno.remove(`${r.cwd}/node_modules`, { recursive: true });
  await r.failure("detect", {}, {
    "npm ci --ignore-scripts": [{ code: 17, raw: "broken" }],
  }, 17);
  assertEquals(await r.log(), `npm|ci --ignore-scripts|${r.cwd}\n`);
});
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
    await r.failure("routine", {}, emptyDetect, 70);
    assertEquals(await r.log(), "");
  });
}
test("devEngines selects the declared manager", async (r) => {
  await r.npm({ react: "^19" }, {}, ".", {
    devEngines: { packageManager: { name: "npm", version: "11" } },
  });
  await r.execute("routine");
  assertEquals(await r.log(), `npm|update|${r.cwd}\n`);
});
test("two inferred lockfiles disagree before any command", async (r) => {
  await r.npm({ react: "^19" });
  await r.json("bun.lock", {});
  await r.failure("routine", {}, emptyDetect, 70, "disagree");
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
    await r.failure("routine", { paths }, emptyDetect, 70);
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
    "routine",
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
  assertEquals(result.candidates.map((c) => c.package), ["shadcn"]);
  assertEquals(result.candidates[0].advisories, [{
    id: "1243066",
    url: "https://github.com/advisories/GHSA-mrhx-c7xc-m3w5",
    title:
      "Zod can exhaust memory while validating oversized constrained collections",
    affected: "<4.6.6",
    severity: "high",
  }]);
});
test("one shared npm advisory remains on every controlling direct dependency", async (r) => {
  const data = structuredClone(finding);
  data.vulnerabilities.react = { via: ["zod"], isDirect: true };
  const result = await r.execute("detect", {}, {
    [outdated]: [{ data: {} }],
    [audit]: [{ data }],
  });
  assertEquals(result.candidates.map((c) => c.package), ["react", "shadcn"]);
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
test("npm package names are rejected as advisory ids before writes", async (r) => {
  const before = await r.read("package.json");
  await r.failure(
    "package",
    { ...security, advisoryIds: ["shadcn"] },
    { [audit]: [{ data: finding }] },
    64,
    "not current",
  );
  assertEquals(await r.read("package.json"), before);
  assert(!(await r.log()).includes("add"));
});
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

test("major uses a caret and preserves the JS dependency section", async (r) => {
  await r.execute("package", {
    package: "vite-plus",
    version: "1.4.0",
    purpose: "major",
  });
  assertStringIncludes(await r.log(), "vp|add vite-plus@^1.4.0 --save-dev|");
  assertEquals(
    (await r.read("package.json")).devDependencies["vite-plus"],
    "^1.4.0",
  );
});
for (
  const section of ["dependencies", "devDependencies", "optionalDependencies"]
) {
  test(`npm security exact pin retains ${section} and uses one age bypass`, async (r) => {
    await r.json("package.json", {
      [section]: { shadcn: "^4.21" },
      packageManager: "npm@11.12.0",
    });
    await Deno.writeTextFile(`${r.cwd}/.npmrc`, "min-release-age=7\n");
    const flag = section === "dependencies"
      ? "--save-prod"
      : section === "devDependencies"
      ? "--save-dev"
      : "--save-optional";
    await r.execute("package", security, {
      "npm audit --json": [{ data: finding }, emptyAudit],
    });
    assertStringIncludes(
      await r.log(),
      `npm|install shadcn@4.22.0 ${flag} --save-exact --min-release-age=0|`,
    );
    assertEquals((await r.read("package.json"))[section].shadcn, "4.22.0");
    assertEquals((await r.read("package.json")).packageManager, "npm@11.12.0");
    assertEquals(
      await Deno.readTextFile(`${r.cwd}/.npmrc`),
      "min-release-age=7\n",
    );
    assertEquals(await r.exists("maintainer-exceptions.json"), false);
  });
}
test("Vite+ npm security age bypass is after the manager separator", async (r) => {
  await r.execute("package", security, {
    [audit]: [{ data: finding }, emptyAudit],
  });
  assertStringIncludes(
    await r.log(),
    "vp|add shadcn@4.22.0 --save-prod --save-exact -- --min-release-age=0|",
  );
  assertEquals((await r.read("package.json")).dependencies.shadcn, "4.22.0");
});
for (
  const target of [
    { package: "shadcn;bad" },
    { package: "missing" },
    { version: "latest" },
    { version: "^2.0" },
    { version: "2.0.0 --force" },
    { purpose: "security" },
    { purpose: "major", advisoryIds: ["123"] },
  ]
) {
  test(`invalid single-package target ${JSON.stringify(target)}`, async (r) => {
    await r.failure("package", { ...major, ...target }, emptyDetect, 64);
    assertEquals(await r.log(), "");
  });
}
for (const name of ["@.scope/shadcn", "@_scope/shadcn", "@-scope/shadcn"]) {
  test(`package rejects a scope starting with punctuation: ${name}`, async (r) => {
    await r.npm({ [name]: "^4" }, { [name]: "4.21.0" });
    await r.failure("package", { ...major, package: name }, {}, 64);
    assertEquals(await r.log(), "");
  });
}
for (const variant of ["alias", "ambiguous"]) {
  test(`reject ${variant} direct target before commands`, async (r) => {
    await r.json("package.json", {
      dependencies: { shadcn: variant === "alias" ? "npm:other@^4" : "^4" },
      ...(variant === "ambiguous" ? { devDependencies: { shadcn: "^4" } } : {}),
    });
    await r.failure("package", major, emptyDetect, 64, "one existing direct");
    assertEquals(await r.log(), "");
  });
}
test("package defaults to root and does not choose the other JS path", async (r) => {
  await r.npm({ lodash: "^4" }, {}, "mobile");
  await r.failure(
    "package",
    { ...major, paths: [".", "mobile"], package: "lodash" },
    emptyDetect,
    64,
    "one existing direct",
  );
  assertEquals(await r.log(), "");
});
test("requested advisory must be current before any policy or package write", async (r) => {
  await r.failure(
    "package",
    security,
    { [audit]: [emptyAudit] },
    64,
    "not current",
  );
  assert(!(await r.log()).includes("add"));
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
});
test("security retries accept an already fixed exact declaration", async (r) => {
  await r.json("package.json", {
    dependencies: { shadcn: "4.22.0" },
    packageManager: "npm@11.12.0",
  });
  await r.execute("package", security, { "npm audit --json": [emptyAudit] });
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
});
test("security fails if the selected package still has any advisory", async (r) => {
  await r.failure(
    "package",
    security,
    { [audit]: [{ data: finding }] },
    1,
    "still has security advisories",
  );
});
test("package stops at a failed add", async (r) => {
  await r.failure("package", security, {
    [audit]: [{ data: finding }],
    "vp add shadcn@4.22.0 --save-prod --save-exact -- --min-release-age=0": [{
      code: 23,
      raw: "failed",
    }],
  }, 23);
  assertEquals((await r.log()).split("vp|pm audit").length - 1, 1);
});
const bunFinding = {
  shadcn: [{
    id: 1243066,
    url: "https://github.com/advisories/GHSA-zod",
    title: "Zod",
    vulnerable_versions: "<4.22",
  }],
};
test("Bun security changes only the named release-age exclusion and records its date", async (r) => {
  await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    '[install]\nminimumReleaseAge = 604800\nminimumReleaseAgeExcludes = ["existing"]\n\n[test]\ncoverage = true\n',
  );
  await r.json("maintainer-exceptions.json", {
    schema: 1,
    owner: "keep",
    exceptions: [{
      path: ".",
      manager: "composer",
      package: "other/pkg",
      recorded_on: "2026-01-01",
      days: 7,
      note: "keep",
    }],
  });
  await r.execute("package", security, {
    [audit]: [{ data: bunFinding }, { data: {} }],
  });
  const text = await Deno.readTextFile(`${r.cwd}/bunfig.toml`);
  assertStringIncludes(
    text,
    'minimumReleaseAgeExcludes = ["existing","shadcn"]',
  );
  assertStringIncludes(text, "[test]\ncoverage = true");
  const ledger = await r.read("maintainer-exceptions.json");
  assertEquals(ledger.owner, "keep");
  assertEquals(ledger.exceptions[0].note, "keep");
  assertEquals(ledger.exceptions[1], {
    path: ".",
    manager: "js",
    package: "shadcn",
    recorded_on: new Date().toISOString().slice(0, 10),
    days: 7,
  });
  assertStringIncludes(
    await r.log(),
    "vp|add shadcn@4.22.0 --save-prod --save-exact|",
  );
  assert(!(await r.log()).includes("min-release-age=0"));
});
for (const existing of [false, true]) {
  test(`Bun security preserves literal dollar sequences with existing excludes ${existing}`, async (r) => {
    await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
    const before = [
      "[install] # token-like settings must stay literal",
      'registry = { token = "a$$b$&c$`d$\'e" }',
      "minimumReleaseAge = 604800 # $$ $& $` $'",
      ...(existing ? ['minimumReleaseAgeExcludes = ["existing"]'] : []),
      "",
      "[test]",
      "coverage = true",
      "",
    ].join("\n");
    await Deno.writeTextFile(`${r.cwd}/bunfig.toml`, before);
    await r.execute("package", security, {
      [audit]: [{ data: bunFinding }, { data: {} }],
    });
    const rendered = existing
      ? 'minimumReleaseAgeExcludes = ["existing","shadcn"]'
      : 'minimumReleaseAgeExcludes = ["shadcn"]';
    const expected = existing
      ? before.replace(
        'minimumReleaseAgeExcludes = ["existing"]',
        () => rendered,
      )
      : before.replace(
        /^(minimumReleaseAge = .*\n)/m,
        (line) => `${line}${rendered}\n`,
      );
    assertEquals(await Deno.readTextFile(`${r.cwd}/bunfig.toml`), expected);
  });
}
test("Bun age policy input is only an assertion", async (r) => {
  await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    "[install]\nminimumReleaseAge = 604800\n",
  );
  const before = await Deno.readTextFile(`${r.cwd}/bunfig.toml`);
  await r.failure(
    "package",
    { ...security, policy: { ".": { minimumReleaseAgeDays: 3 } } },
    { [audit]: [{ data: bunFinding }] },
    64,
    "must match",
  );
  assertEquals(await Deno.readTextFile(`${r.cwd}/bunfig.toml`), before);
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
});
for (
  const text of [
    null,
    "[install]\ncache = true\n",
    "[other]\nminimumReleaseAge = 604800\n",
  ]
) {
  test(`Bun does not invent missing release-age policy (${text})`, async (r) => {
    await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
    if (text !== null) await Deno.writeTextFile(`${r.cwd}/bunfig.toml`, text);
    await r.execute("package", security, {
      [audit]: [{ data: bunFinding }, { data: {} }],
    });
    assertEquals(await r.exists("maintainer-exceptions.json"), false);
    if (text !== null) {
      assertEquals(await Deno.readTextFile(`${r.cwd}/bunfig.toml`), text);
    }
  });
}
test("Bun exclusion and record are not duplicated on retry", async (r) => {
  await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    "[install]\nminimumReleaseAge = 604800\n",
  );
  const responses = { [audit]: [{ data: bunFinding }, { data: {} }] };
  await r.execute("package", security, responses);
  const before = await Deno.readTextFile(`${r.cwd}/bunfig.toml`);
  await r.execute("package", security, responses);
  assertEquals(await Deno.readTextFile(`${r.cwd}/bunfig.toml`), before);
  assertEquals(
    (await r.read("maintainer-exceptions.json")).exceptions.length,
    1,
  );
});
test("Bun malformed exception ledger fails before exclusion write", async (r) => {
  await r.bun({ shadcn: "^4" }, { shadcn: bunEntry("shadcn", "4.21.0") });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    "[install]\nminimumReleaseAge = 604800\n",
  );
  await r.json("maintainer-exceptions.json", { schema: 9 });
  await r.failure(
    "package",
    security,
    { [audit]: [{ data: bunFinding }] },
    70,
    "maintainer-exceptions",
  );
  assert(
    !(await Deno.readTextFile(`${r.cwd}/bunfig.toml`)).includes("Excludes"),
  );
});
test("stale Bun exceptions retain the maintainer date and manager contract", async (r) => {
  await r.bun({ shadcn: "^4" }, {});
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    '[install]\nminimumReleaseAge = 604800\nminimumReleaseAgeExcludes = ["shadcn"]\n',
  );
  await r.json("maintainer-exceptions.json", {
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
  });
  const result = await r.execute("detect", {}, bunReports(""));
  assertEquals(result.stale_exceptions, [{
    path: ".",
    manager: "js",
    package: "shadcn",
    recorded_on: "2026-01-01",
    remove_on: "2026-01-08",
  }]);
});
for (
  const section of ["dependencies", "devDependencies", "optionalDependencies"]
) {
  test(`plain Bun exact security install in ${section}`, async (r) => {
    await r.bun({}, { shadcn: bunEntry("shadcn", "4.21.0") }, false);
    await r.json("package.json", {
      packageManager: "bun@1.3.14",
      [section]: { shadcn: "^4" },
    });
    await r.execute("package", security, {
      "bun pm audit --json": [{ data: bunFinding }, { data: {} }],
    });
    assertStringIncludes(
      await r.log(),
      `bun|add shadcn@4.22.0${
        section === "devDependencies"
          ? " -d"
          : section === "optionalDependencies"
          ? " --optional"
          : ""
      } --exact|`,
    );
  });
}
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
for (
  const name of [
    "react",
    "react-dom",
    "react-native",
    "expo-camera",
    "@expo/ui",
    "react-native-screens",
    "@react-native/dev-middleware",
    "@react-native-community/cli",
  ]
) {
  test(`Expo rejects independent coupled major ${name}`, async (r) => {
    await expoRepo(r);
    await r.failure(
      "package",
      {
        path: "mobile",
        paths: ["mobile"],
        package: name,
        version: "1.2.3",
        purpose: "major",
      },
      emptyDetect,
      64,
      "coupled",
    );
    assertEquals(await r.log(), "");
  });
}
test("Expo SDK install runs alignment after the exact SDK command", async (r) => {
  await expoRepo(r);
  await r.execute("package", {
    paths: ["mobile"],
    path: "mobile",
    package: "expo",
    version: "58.0.0",
    purpose: "major",
  });
  assertEquals(
    await r.log(),
    `npx|expo install expo@58.0.0|${r.cwd}/mobile\nnpx|expo install --fix|${r.cwd}/mobile\n`,
  );
  assertEquals(
    (await r.read("mobile/package.json")).dependencies.expo,
    "58.0.0",
  );
});
test("Expo stops when SDK install fails before alignment", async (r) => {
  await expoRepo(r);
  await r.failure(
    "package",
    {
      paths: ["mobile"],
      path: "mobile",
      package: "expo",
      version: "58.0.0",
      purpose: "major",
    },
    { "npx expo install expo@58.0.0": [{ code: 12, raw: "failure" }] },
    12,
  );
  assert(!(await r.log()).includes("--fix"));
});
test("Expo security uses the exact SDK and a scoped npm age environment", async (r) => {
  await expoRepo(r);
  await r.execute("package", {
    paths: ["mobile"],
    path: "mobile",
    package: "expo",
    version: "57.0.30",
    purpose: "security",
    advisoryIds: ["777"],
  }, {
    "npm audit --json": [{
      data: {
        vulnerabilities: {
          expo: {
            via: [{
              source: 777,
              url: "https://github.com/advisories/GHSA-expo",
            }],
          },
        },
      },
    }, emptyAudit],
  });
  const log = await r.log();
  assertStringIncludes(
    log,
    `npx|expo install expo@57.0.30|${r.cwd}/mobile|min-release-age=0`,
  );
  assertStringIncludes(
    log,
    `npx|expo install --fix|${r.cwd}/mobile|min-release-age=0`,
  );
  assert(!log.includes("npm|install"));
  const child = JSON.parse(await Deno.readTextFile(`${r.temp}/child-env.json`));
  assertEquals(child.npm_config_min_release_age, undefined);
});
test("coupled Expo security updates the selected package with npm", async (r) => {
  await expoRepo(r);
  await r.execute("package", {
    paths: ["mobile"],
    path: "mobile",
    package: "react-native",
    version: "0.86.4",
    purpose: "security",
    advisoryIds: ["999"],
  }, {
    "npm audit --json": [{
      data: {
        vulnerabilities: {
          "react-native": {
            via: [{
              source: 999,
              url: "https://github.com/advisories/GHSA-rn",
            }],
          },
        },
      },
    }, emptyAudit],
  });
  assertStringIncludes(
    await r.log(),
    "npm|install react-native@0.86.4 --save-prod --save-exact --min-release-age=0|",
  );
  assert(!(await r.log()).includes("npx|"));
});

function summaryText(
  title: string,
  intro: string,
  rows: string[][],
  actions: string[] = [],
  security: string[] = [],
) {
  const parts = [intro];
  if (actions.length) parts.push(`Action needed\n\n${actions.join("\n")}`);
  if (security.length) parts.push(`Security fixes\n\n${security.join("\n")}`);
  parts.push(
    [
      "| Package | From | To | note |",
      "| --- | --- | --- | --- |",
      ...rows.map((row) => `| ${row.join(" | ")} |`),
    ].join("\n"),
  );
  return `${title}\n\n${parts.join("\n\n")}\n`;
}
const cleanNpmAudit = { "npm audit --json": [emptyAudit] };
const npmFinding = (
  name: string,
  id: number = 123,
  url = "https://github.com/advisories/GHSA-test",
) => ({
  vulnerabilities: {
    [name]: {
      via: [{
        source: id,
        name,
        url,
        title: "test",
        range: "<2.0.0",
        severity: "high",
      }],
    },
  },
});
test("summary writes exact single major PR text from working tree lock", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" });
  const base = await r.base();
  await r.json(
    "package-lock.json",
    npmLock({ react: "20.0.0", transitive: "2.0.0" }),
  );
  const result = await r.execute("summary", { base }, cleanNpmAudit);
  assertEquals(
    result.text,
    summaryText(
      "Update react to 20.0.0",
      "This updates react from 19.0.0 to 20.0.0.",
      [["react", "19.0.0", "20.0.0", "major"]],
      ["react 19.0.0 → 20.0.0 is a major update. Check its upgrade notes before you deploy."],
    ),
  );
});
test("summary of several direct updates uses the UTC maintenance title", async (r) => {
  await r.npm({ react: "^19", lodash: "^4" }, {
    react: "19.0.0",
    lodash: "4.17.20",
  });
  const base = await r.base();
  await r.json(
    "package-lock.json",
    npmLock({ react: "20.0.0", lodash: "4.17.21", transitive: "2.0.0" }),
  );
  const result = await r.execute("summary", { base }, cleanNpmAudit);
  const month = new Date().toLocaleString("en-US", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  });
  assertEquals(
    result.text,
    summaryText(
      `Maintenance update for ${month}`,
      "This is a routine maintenance update. The packages below were updated to their newest safe versions.",
      [["lodash", "4.17.20", "4.17.21", ""], [
        "react",
        "19.0.0",
        "20.0.0",
        "major",
      ]],
      ["react 19.0.0 → 20.0.0 is a major update. Check its upgrade notes before you deploy."],
    ),
  );
});
test("unchanged summary exits one without auditing", async (r) => {
  const base = await r.base();
  await r.failure("summary", { base }, emptyDetect, 1, "No dependency changes");
  assertEquals(await r.log(), "");
});
test("npm summary ignores version changes outside node_modules records", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" });
  await r.json(
    "package-lock.json",
    npmLock({ react: "19.0.0" }, {
      "": { version: "1.0.0" },
      "packages/local": { version: "1.0.0" },
    }),
  );
  const base = await r.base();
  await r.json(
    "package-lock.json",
    npmLock({ react: "19.0.0" }, {
      "": { version: "2.0.0" },
      "packages/local": { version: "2.0.0" },
    }),
  );
  await r.failure("summary", { base }, {}, 1, "No dependency changes");
  assertEquals(await r.log(), "");
});
test("summary defaults to origin HEAD merge-base", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" });
  const base = await r.base();
  await r.git("update-ref", "refs/remotes/origin/main", base);
  await r.git(
    "symbolic-ref",
    "refs/remotes/origin/HEAD",
    "refs/remotes/origin/main",
  );
  await r.json("package-lock.json", npmLock({ react: "19.0.1" }));
  const result = await r.execute("summary", {}, cleanNpmAudit);
  assertEquals(result.title, "Update react to 19.0.1");
});
for (const base of ["-bad", "HEAD..main", "HEAD;echo bad"]) {
  test(`summary rejects invalid ref ${base}`, async (r) => {
    await r.failure("summary", { base }, emptyDetect, 64, "Invalid base");
  });
}
test("Bun 0.x minor summary treats a newer minor as major", async (r) => {
  await r.bun({ "@scope/pkg": "^0.2" }, {
    "@scope/pkg": bunEntry("@scope/pkg", "0.2.0"),
  }, false);
  const base = await r.base();
  await r.json("bun.lock", {
    packages: { "@scope/pkg": bunEntry("@scope/pkg", "0.3.0") },
  });
  const result = await r.execute("summary", { base }, {
    "bun pm audit --json": [{ data: {} }],
  });
  assertEquals(result.title, "Update @scope/pkg to 0.3.0");
  assertEquals(result.changes[0].major, true);
});
test("Bun lock instance key differs from the descriptor name", async (r) => {
  await r.bun({ "@scope/pkg": "^0.2" }, {
    "@scope/pkg": bunEntry("@scope/pkg", "0.2.0"),
    "parent/@scope/pkg": bunEntry("@scope/pkg", "0.1.0"),
  }, false);
  const base = await r.base();
  await r.json("bun.lock", {
    packages: {
      "@scope/pkg": bunEntry("@scope/pkg", "0.3.0"),
      "parent/@scope/pkg": bunEntry("@scope/pkg", "0.1.1"),
    },
  });
  const result = await r.execute("summary", { base }, {
    "bun pm audit --json": [{ data: {} }],
  });
  assertEquals(result.changes.length, 1);
  assertEquals(result.changes[0].to, "0.3.0");
});
for (const mode of ["add", "remove"]) {
  test(`summary lists direct ${mode}`, async (r) => {
    await r.npm(
      mode === "remove" ? { react: "^19" } : {},
      mode === "remove" ? { react: "19.0.0" } : {},
    );
    const base = await r.base();
    await r.npm(
      mode === "add" ? { react: "^19" } : {},
      mode === "add" ? { react: "19.0.0" } : {},
    );
    const result = await r.execute("summary", { base }, cleanNpmAudit);
    assertEquals(
      result.text,
      summaryText(
        mode === "add" ? "Add react 19.0.0" : "Remove react",
        mode === "add"
          ? "This adds react 19.0.0."
          : "This removes react 19.0.0.",
        [[
          "react",
          mode === "add" ? "—" : "19.0.0",
          mode === "remove" ? "—" : "19.0.0",
          "",
        ]],
      ),
    );
  });
}
test("cleared npm via advisories appear on the controlling direct package", async (r) => {
  const base = await r.base();
  await r.json(
    "package-lock.json",
    npmLock({ shadcn: "4.22.0", react: "19.0.0", "vite-plus": "0.2.9" }),
  );
  const result = await r.execute("summary", { base }, {
    [audit]: [emptyAudit, { data: finding }],
  });
  const link = "[1243066](https://github.com/advisories/GHSA-mrhx-c7xc-m3w5)";
  assertEquals(
    result.text,
    summaryText(
      "Update shadcn to 4.22.0 (security fix)",
      "This updates shadcn from 4.21.0 to 4.22.0.",
      [["shadcn", "4.21.0", "4.22.0", `security fix ${link}`]],
      [],
      [`shadcn: ${link}`],
    ),
  );
});
test("nested npm change can clear an advisory while the direct locked version stays fixed", async (r) => {
  await r.json(
    "package-lock.json",
    npmLock({ shadcn: "4.21.0" }, {
      "node_modules/shadcn/node_modules/zod": { version: "3.25.76" },
    }),
  );
  const base = await r.base();
  await r.json(
    "package-lock.json",
    npmLock({ shadcn: "4.21.0" }, {
      "node_modules/shadcn/node_modules/zod": { version: "4.6.6" },
    }),
  );
  const result = await r.execute("summary", { base }, {
    [audit]: [emptyAudit, { data: finding }],
  });
  assertEquals(result.title, "Update shadcn to 4.21.0 (security fix)");
  assertEquals(result.changes[0].from, result.changes[0].to);
  assertStringIncludes(result.body, "This updates shadcn to 4.21.0.");
});
test("nested Bun advisory clearance is assigned to its unchanged direct package", async (r) => {
  await r.bun({ shadcn: "^4" }, {
    shadcn: bunEntry("shadcn", "4.21.0", { dependencies: { zod: "^3" } }),
    zod: bunEntry("zod", "3.25.76"),
    "shadcn/zod": bunEntry("zod", "3.25.76"),
  });
  const base = await r.base();
  await r.json("bun.lock", {
    packages: {
      shadcn: bunEntry("shadcn", "4.21.0", { dependencies: { zod: "^4" } }),
      zod: bunEntry("zod", "4.6.6"),
      "shadcn/zod": bunEntry("zod", "4.6.6"),
    },
  });
  const result = await r.execute("summary", { base }, {
    [audit]: [{ data: {} }, {
      data: {
        zod: [{ id: 123, url: "https://github.com/advisories/GHSA-zod" }],
      },
    }],
  });
  assertEquals(result.title, "Update shadcn to 4.21.0 (security fix)");
  assertEquals(result.changes[0].advisories[0].id, "123");
});
test("summary does not count a still-open advisory as fixed", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" });
  const base = await r.base();
  await r.json("package-lock.json", npmLock({ react: "19.0.1" }));
  const result = await r.execute("summary", { base }, {
    "npm audit --json": [{ data: npmFinding("react") }],
  });
  assertEquals(result.title, "Update react to 19.0.1");
  assertEquals(result.changes[0].advisories, []);
  assert(!result.text.includes("Security fixes"));
});
test("removing a direct declaration is not a fix if its advisory remains installed", async (r) => {
  await r.npm({ react: "^19", lodash: "^4" }, {
    react: "19.0.0",
    lodash: "4.17.20",
  });
  const base = await r.base();
  await r.npm({ react: "^19" }, { react: "19.0.0", lodash: "4.17.20" });
  const data = {
    advisories: {
      "123": {
        id: 123,
        module_name: "lodash",
        url: "https://github.com/advisories/GHSA-lodash",
      },
    },
  };
  const result = await r.execute("summary", { base }, {
    "npm audit --json": [{ data }],
  });
  assertEquals(result.title, "Remove lodash");
  assertEquals(result.changes[0].advisories, []);
});
for (const manager of ["npm", "bun"]) {
  test(`${manager} alias summary retains declared name and cleared real id`, async (r) => {
    const aliasEntry = (version: string) => ({ name: "lodash", version });
    if (manager === "npm") {
      await r.npm({ alias: "npm:lodash@4.17.20" });
      await r.json(
        "package-lock.json",
        npmLock({}, { "node_modules/alias": aliasEntry("4.17.20") }),
      );
    } else {await r.bun({ alias: "npm:lodash@4.17.20" }, {
        alias: bunEntry("lodash", "4.17.20"),
      }, false);}
    const base = await r.base();
    if (manager === "npm") {
      await r.json(
        "package-lock.json",
        npmLock({}, { "node_modules/alias": aliasEntry("4.17.21") }),
      );
    } else {await r.json("bun.lock", {
        packages: { alias: bunEntry("lodash", "4.17.21") },
      });}
    const previous = manager === "npm"
      ? npmFinding("lodash", 1065, "https://github.com/advisories/GHSA-lodash")
      : {
        lodash: [{
          id: 1065,
          url: "https://github.com/advisories/GHSA-lodash",
        }],
      };
    const result = await r.execute("summary", { base }, {
      [manager === "npm" ? "npm audit --json" : "bun pm audit --json"]: [{
        data: manager === "npm" ? { vulnerabilities: {} } : {},
      }, { data: previous }],
    });
    assertEquals(
      result.text,
      summaryText(
        "Update alias to 4.17.21 (security fix)",
        "This updates alias from 4.17.20 to 4.17.21.",
        [[
          "alias",
          "4.17.20",
          "4.17.21",
          "security fix [1065](https://github.com/advisories/GHSA-lodash)",
        ]],
        [],
        ["alias: [1065](https://github.com/advisories/GHSA-lodash)"],
      ),
    );
  });
}
test("an npm direct dependency becoming an alias does not mark its advisory fixed", async (r) => {
  await r.npm({ lodash: "4.17.20" }, { lodash: "4.17.20" });
  const base = await r.base();
  await r.npm({ alias: "npm:lodash@4.17.20" });
  await r.json(
    "package-lock.json",
    npmLock({}, {
      "node_modules/alias": { name: "npm:lodash@4.17.20", version: "4.17.20" },
    }),
  );
  const result = await r.execute("summary", { base }, {
    "npm audit --json": [{ data: npmFinding("lodash") }],
  });
  assertEquals(
    result.changes.map((c) => [c.package, c.from, c.to, c.advisories]),
    [["alias", "—", "4.17.20", []], ["lodash", "4.17.20", "—", []]],
  );
  assert(!result.body.includes("Security fixes"));
});
test("summary leaves non-HTTPS advisory ids as plain text", async (r) => {
  await r.npm({ react: "^19" }, { react: "19.0.0" });
  const base = await r.base();
  await r.json("package-lock.json", npmLock({ react: "19.0.1" }));
  const result = await r.execute("summary", { base }, {
    "npm audit --json": [emptyAudit, {
      data: npmFinding("react", 123, "http://example.com/123"),
    }],
  });
  assertStringIncludes(result.body, "Security fixes\n\nreact: 123");
  assertEquals(result.changes[0].advisories[0].url, null);
});
test("summary combines Composer and JS, including non-root paths", async (r) => {
  await r.json("composer.json", { require: { "other/pkg": "^1" } });
  await r.json("composer.lock", {
    packages: [{ name: "other/pkg", version: "1.0.0" }],
  });
  await r.npm({ expo: "57.0.28" }, { expo: "57.0.28" }, "mobile");
  const base = await r.base();
  await r.json("composer.lock", {
    packages: [{ name: "other/pkg", version: "2.0.0" }],
  });
  await r.json("package-lock.json", npmLock({ expo: "58.0.0" }), "mobile");
  const result = await r.execute("summary", { base, paths: [".", "mobile"] }, {
    "composer audit --locked --format=json": [{ data: { advisories: {} } }],
    ...cleanNpmAudit,
  });
  assertEquals(result.changes.map((c) => [c.path, c.package]), [[
    ".",
    "other/pkg",
  ], ["mobile", "expo"]]);
  assertStringIncludes(
    result.body,
    "| expo (mobile) | 57.0.28 | 58.0.0 | major |",
  );
  assertStringIncludes(
    result.body,
    "expo (mobile) 57.0.28 → 58.0.0 is a major update.",
  );
});
test("mixed root summary sorts Composer and JS rows together by package", async (r) => {
  await r.npm({ alpha: "^1", charlie: "^1" }, {
    alpha: "1.0.0",
    charlie: "1.0.0",
  });
  await r.json("composer.json", {
    require: { "bravo/pkg": "^1", "delta/pkg": "^1" },
  });
  await r.json("composer.lock", {
    packages: ["bravo/pkg", "delta/pkg"].map((name) => ({
      name,
      version: "1.0.0",
    })),
  });
  const base = await r.base();
  await r.json("composer.lock", {
    packages: ["bravo/pkg", "delta/pkg"].map((name) => ({
      name,
      version: "1.0.1",
    })),
  });
  await r.json(
    "package-lock.json",
    npmLock({ alpha: "1.0.1", charlie: "1.0.1" }),
  );
  const result = await r.execute("summary", { base }, {
    "composer audit --locked --format=json": [{ data: { advisories: {} } }],
    ...cleanNpmAudit,
  });
  const names = ["alpha", "bravo/pkg", "charlie", "delta/pkg"];
  assertEquals(
    result.changes.map((c) => [c.path, c.package]),
    names.map((name) => [".", name]),
  );
  const title = `Maintenance update for ${
    new Date().toLocaleString("en-US", { month: "long", timeZone: "UTC" })
  } ${new Date().getUTCFullYear()}`;
  assertEquals(
    result.text,
    summaryText(
      title,
      "This is a routine maintenance update. The packages below were updated to their newest safe versions.",
      names.map((name) => [name, "1.0.0", "1.0.1", ""]),
    ),
  );
});
test("mixed summary accepts a Composer-only path and JS-only subdirectory", async (r) => {
  await Deno.remove(`${r.cwd}/package.json`);
  await r.json("composer.json", { require: { "other/pkg": "^1" } });
  await r.json("composer.lock", {
    packages: [{ name: "other/pkg", version: "1.0.0" }],
  });
  await r.npm({ react: "^19" }, { react: "19.0.0" }, "mobile");
  const base = await r.base();
  await r.json("package-lock.json", npmLock({ react: "19.0.1" }), "mobile");
  const result = await r.execute(
    "summary",
    { base, paths: [".", "mobile"] },
    cleanNpmAudit,
  );
  assertEquals(result.title, "Update react to 19.0.1");
  assertStringIncludes(result.body, "| react (mobile) |");
});
test("summary removes temporary base checkout even when base audit fails", async (r) => {
  const base = await r.base();
  await r.json("package-lock.json", npmLock({ shadcn: "4.22.0" }));
  await r.failure("summary", { base }, {
    [audit]: [emptyAudit, { raw: "broken" }],
  }, 70);
  const dirs = (await r.log()).trim().split("\n").map((line) =>
    line.split("|")[2]
  ).filter((dir) => dir !== r.cwd);
  assertEquals(dirs.length, 1);
  await assertRejects(() => Deno.stat(dirs[0]), Deno.errors.NotFound);
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
    await r.execute("routine");
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
test("routine preserves failure exit codes even with output over 8 MiB", async (r) => {
  await r.failure("routine", {}, {
    "vp update": [{ code: 23, raw: "x".repeat(8388609) }],
  }, 23);
});

for (const cleared of [true, false]) {
  test(`npm real advisory verification with another direct owner (${cleared})`, async (r) => {
    const after = structuredClone(finding);
    if (cleared) {
      delete after.vulnerabilities.shadcn;
      delete after.vulnerabilities["@shadcn/registry"];
      after.vulnerabilities.react = { via: ["zod"], isDirect: true };
    }
    if (cleared) {
      await r.execute("package", security, {
        [audit]: [{ data: finding }, { data: after }],
      });
    } else {await r.failure(
        "package",
        security,
        { [audit]: [{ data: finding }, { data: after }] },
        1,
        "still has security",
      );}
  });
}
test("Bun package mode attaches the real transitive advisory before adding its one age exception", async (r) => {
  await r.bun({ shadcn: "^4.1.0" }, {
    shadcn: bunEntry("shadcn", "4.1.0", { dependencies: { zod: "^4" } }),
    zod: bunEntry("zod", "4.0.0"),
  });
  await Deno.writeTextFile(
    `${r.cwd}/bunfig.toml`,
    "[install]\nminimumReleaseAge = 604800\n",
  );
  await r.execute("package", { ...security, version: "4.21.6" }, {
    [audit]: [{
      data: {
        zod: [{
          id: 1243066,
          url: "https://github.com/advisories/GHSA-mrhx-c7xc-m3w5",
          severity: "high",
          vulnerable_versions: "<4.6.6",
        }],
      },
    }, { data: [] }],
  });
  assertStringIncludes(
    await r.log(),
    "vp|add shadcn@4.21.6 --save-prod --save-exact|",
  );
  assertStringIncludes(
    await Deno.readTextFile(`${r.cwd}/bunfig.toml`),
    'minimumReleaseAgeExcludes = ["shadcn"]',
  );
});
