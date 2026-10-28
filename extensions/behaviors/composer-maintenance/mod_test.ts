import {
  emptyExceptions,
  type Exceptions,
} from "../_lib/maintenance/exceptions.ts";
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
const outdated =
  "composer outdated --no-plugins --no-scripts --locked --major-only --format=json";
const audit = "composer audit --locked --format=json --no-plugins --no-scripts";
const finding = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/livewire-audit.json", import.meta.url),
  ),
);
const emptyAudit = { data: { advisories: [] } };
const emptyDetect: Fixture = {
  [outdated]: [{ data: { locked: [] } }],
  [audit]: [emptyAudit],
};
const ids = ["PKSA-xhv3-nr94-h8db", "PKSA-bgw4-5zmg-2njg"];
const manifest = {
  require: { "livewire/livewire": "^4.2", "other/pkg": "^1.0" },
  "require-dev": { "dev/tool": "^1.0" },
};
const policy = { ".": { minimumReleaseAgeDays: 7 } };
const security = {
  package: "livewire/livewire",
  version: "4.9.9",
  purpose: "security",
  advisoryIds: ids,
};
const major = { package: "dev/tool", version: "2.0.0", purpose: "major" };

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
  handles: string[] = [];
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
    try {
      const returned = await execute({
        cwd: this.cwd,
        policy,
        exceptions: this.exceptions,
        ...args,
      }, context) as { dataHandles: { name: string }[] };
      this.handles = returned.dataHandles.map((handle) => handle.name);
    } finally {
      const receipt = getWrittenResources().find((r) =>
        r.name === "exceptions"
      );
      if (receipt) this.exceptions = receipt.data;
    }
    const outputs = getWrittenResources().filter((r) =>
      r.name !== "exceptions"
    );
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
  async base(
    packages: Record<string, string>,
    dev: Record<string, string> = {},
    path = ".",
  ) {
    await this.json("composer.lock", lock(packages, dev), path);
    await this.git("init", "-b", "main");
    await this.git("add", ".");
    await this.git("commit", "-m", "base");
    return await this.git("rev-parse", "HEAD");
  }
}

function test(name: string, fn: (repo: Repo) => Promise<void>) {
  Deno.test(name, async () => {
    const temp = await Deno.realPath(
      await Deno.makeTempDir({
        prefix: "composer-maintenance-test-",
      }),
    );
    const cwd = `${temp}/repo with spaces`;
    await Deno.mkdir(`${temp}/bin`);
    await Deno.mkdir(cwd);
    await Deno.symlink(php, `${temp}/bin/php`);
    await Deno.symlink(git, `${temp}/bin/git`);
    await Deno.copyFile(
      new URL("./fixtures/manager", import.meta.url),
      `${temp}/manager`,
    );
    // Keep the upstream binary fixture unchanged. Its launcher supplies only
    // fixture paths after recording the actual environment Composer receives.
    await Deno.writeTextFile(
      `${temp}/bin/composer`,
      `#!/usr/bin/env php
<?php
file_put_contents(__DIR__.'/../child-env.json', json_encode(getenv()));
putenv('DEPENDENCY_TEST_LOG='.__DIR__.'/../commands');
putenv('DEPENDENCY_TEST_FIXTURE='.__DIR__.'/../fixture.json');
require __DIR__.'/../manager';
`,
    );
    await Deno.chmod(`${temp}/bin/composer`, 0o755);
    const env = {
      PATH: `${temp}/bin`,
    };
    const previous = Object.fromEntries(
      Object.keys(env).map((k) => [k, Deno.env.get(k)]),
    );
    for (const [key, value] of Object.entries(env)) Deno.env.set(key, value);
    try {
      const repo = new Repo(temp, cwd);
      await repo.json("composer.json", manifest);
      await repo.json("vet.json", {
        "minimum-release-age": 7,
        "minimum-release-age-exclude": ["existing/pkg"],
      });
      await fn(repo);
    } finally {
      for (const [key, value] of Object.entries(previous)) {
        if (value === undefined) Deno.env.delete(key);
        else Deno.env.set(key, value);
      }
      await Deno.remove(temp, { recursive: true });
    }
  });
}

function lock(
  packages: Record<string, string>,
  dev: Record<string, string> = {},
) {
  const rows = (p: Record<string, string>) =>
    Object.entries(p).map(([name, version]) => ({
      name,
      version: `v${version}`,
    }));
  return {
    "content-hash": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    packages: rows(packages),
    "packages-dev": rows(dev),
  };
}
function summaryText(
  title: string,
  intro: string,
  rows: string[][],
  actions: string[] = [],
  fixes: string[] = [],
) {
  const parts = [title, intro];
  if (actions.length) parts.push("Action needed\n\n" + actions.join("\n"));
  parts.push(
    [
      "| Package | From | To | Change |",
      "| --- | --- | --- | --- |",
      ...rows.map((r) => `| ${r.join(" | ")} |`),
    ].join("\n"),
  );
  if (fixes.length) parts.push("Security fixes\n\n" + fixes.join("\n"));
  parts.push(
    "## What changes in the package\n\n" +
      rows.map((r) => {
        const [name, path] = r[0].split(" (");
        const version = r[2] === "—"
          ? "(removed)"
          : r[3].includes("major")
          ? r[2].split(".").slice(0, r[2].startsWith("0.") ? 2 : 1).join(".")
          : r[2];
        return `- **${name} ${version}${
          path ? ", " + path.slice(0, -1) : ""
        }.** No code changes needed.`;
      }).join("\n"),
  );
  return parts.join("\n\n") + "\n";
}
const monthlyTitle = () =>
  `Maintenance update for ${
    new Date().toLocaleString("en-US", {
      month: "long",
      year: "numeric",
      timeZone: "UTC",
    })
  }`;
const routineIntro = "Updates the dependencies listed below.";

test("routine updates configured paths sequentially", async (r) => {
  await r.json("composer.json", manifest, "server/web");
  await r.json("maintainer.json", { sides: "ignored", paths: ["missing"] });
  assertEquals(await r.execute("routine", { paths: [".", "server/web"] }), {
    schema: 1,
    paths: [".", "server/web"],
  });
  assertEquals(
    await r.log(),
    `composer|update --no-interaction|${r.cwd}\ncomposer|update --no-interaction|${r.cwd}/server/web\n`,
  );
});
for (const code of [17, 23]) {
  test(`routine stops at first failure (${code})`, async (r) => {
    await r.json("composer.json", manifest, "server");
    await r.failure("routine", { paths: [".", "server"] }, {
      "composer update --no-interaction": [{ code, raw: "update failed" }],
    }, code);
    assertEquals(await r.log(), `composer|update --no-interaction|${r.cwd}\n`);
  });
}
test("routine preflights every path before updating", async (r) => {
  await Deno.mkdir(`${r.cwd}/empty`);
  await r.failure(
    "routine",
    { paths: [".", "empty"] },
    {},
    70,
    "composer.json",
  );
  assertEquals(await r.log(), "");
});
test("detect has exact upstream Composer candidates without vendor", async (r) => {
  const result = await r.execute("detect", {}, {
    [outdated]: [{
      data: {
        locked: [{ name: "other/pkg", version: "1.0.0", latest: "2.0.0" }],
      },
    }],
    [audit]: [{ code: 1, data: finding }],
  });
  assertEquals(result, {
    schema: 1,
    candidates: [
      {
        path: ".",
        manager: "composer",
        package: "livewire/livewire",
        reasons: ["security"],
        advisories: ids.map((id, i) => ({
          id,
          url: `https://packagist.org/security-advisories/${id}`,
          title: `Livewire advisory ${i ? "two" : "one"}`,
          affected: null,
        })),
      },
      {
        path: ".",
        manager: "composer",
        package: "other/pkg",
        reasons: ["major"],
        advisories: [],
        current: "1.0.0",
        latest: "2.0.0",
      },
    ],
  });
  assertEquals(
    await r.log(),
    `composer|outdated --no-plugins --no-scripts --locked --major-only --format=json|${r.cwd}\ncomposer|audit --locked --format=json --no-plugins --no-scripts|${r.cwd}\n`,
  );
  assertEquals(await r.exists("vendor"), false);
});
test("detect merges major and security reasons and carries nested path", async (r) => {
  await r.json("composer.json", manifest, "server/web");
  const result = await r.execute("detect", {
    paths: ["server/web"],
    policy: {},
  }, {
    [outdated]: [{
      data: {
        locked: [{
          name: "livewire/livewire",
          version: "4.2.0",
          latest: "5.0.0",
        }],
      },
    }],
    [audit]: [{ code: 3, data: finding }],
  });
  assertEquals(result.candidates[0].path, "server/web");
  assertEquals(result.candidates[0].reasons, ["major", "security"]);
});
for (
  const [name, response] of Object.entries({
    malformed: { raw: "not json" },
    code4: { code: 4, data: { locked: [] } },
    error: { data: { error: "offline" } },
    missing: { data: [] },
    incomplete: { data: { locked: [{ name: "other/pkg", latest: "2.0.0" }] } },
  })
) {
  test(`detect fails closed on ${name}`, async (r) => {
    await r.failure("detect", {}, { [outdated]: [response] }, 70);
  });
}
test("audit keeps sparse numeric advisory objects", async (r) => {
  const data = {
    advisories: {
      "livewire/livewire": { "3": finding.advisories["livewire/livewire"][0] },
    },
  };
  const result = await r.execute("detect", {}, {
    ...emptyDetect,
    [audit]: [{ code: 2, data }],
  });
  assertEquals(result.candidates[0].advisories[0].id, ids[0]);
});
for (
  const data of [{}, { advisories: null }, {
    advisories: { "a/a": [{ advisoryId: "" }] },
  }, { advisories: { "a/a": "bad" } }]
) {
  test(`audit rejects unusable entries ${JSON.stringify(data)}`, async (r) => {
    await r.failure("detect", {}, { ...emptyDetect, [audit]: [{ data }] }, 70);
  });
}

test("package updates one Composer range with a targeted update", async (r) => {
  await r.execute("package", major);
  assertEquals(
    (await r.read("composer.json"))["require-dev"]["dev/tool"],
    "^2.0.0",
  );
  assertEquals((await r.read("composer.json")).require["other/pkg"], "^1.0");
  assertEquals(
    await r.log(),
    `composer|require dev/tool:^2.0.0 --no-update --no-interaction --dev|${r.cwd}\ncomposer|update dev/tool --with-dependencies --no-interaction|${r.cwd}\n`,
  );
});
test("security clears both advisories without vendor and records one exception", async (r) => {
  await r.execute("package", security, {
    [audit]: [{ code: 1, data: finding }, emptyAudit],
  });
  assertEquals(
    (await r.read("composer.json")).require["livewire/livewire"],
    "4.9.9",
  );
  assertEquals(await r.read("vet.json"), {
    "minimum-release-age": 7,
    "minimum-release-age-exclude": ["existing/pkg", "livewire/livewire"],
  });
  assertEquals(r.exceptions as Exceptions, {
    schema: 1,
    exceptions: [{
      path: ".",
      manager: "composer",
      package: "livewire/livewire",
      recorded_on: new Date().toISOString().slice(0, 10),
      days: 7,
    }],
  });
  assertEquals((await r.log()).match(/audit --locked/g)?.length, 2);
  assertEquals(await r.exists("vendor"), false);
});
test("security rejects an unobserved advisory before writes", async (r) => {
  const before = await r.read("vet.json");
  await r.failure(
    "package",
    { ...security, advisoryIds: ["PKSA-wrong"] },
    { [audit]: [{ code: 1, data: finding }] },
    64,
    "not current findings",
  );
  assertEquals(await r.read("vet.json"), before);
  assertEquals(await r.read("composer.json"), manifest);
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
  assertEquals((await r.log()).includes("require"), false);
});
test("security rejects remaining and newly reported advisories", async (r) => {
  await r.failure(
    "package",
    security,
    { [audit]: [{ code: 1, data: finding }] },
    1,
    "still has security advisories",
  );
});
test("security does not duplicate an exclusion or reset its date", async (r) => {
  const fixture = { [audit]: [{ code: 1, data: finding }, emptyAudit] };
  await r.execute("package", security, fixture);
  const document = r.exceptions as Exceptions;
  document.exceptions[0].recorded_on = "2026-10-01";
  r.exceptions = document;
  await r.execute("package", security, fixture);
  assertEquals(r.exceptions as Exceptions, document);
  assertEquals((await r.read("vet.json"))["minimum-release-age-exclude"], [
    "existing/pkg",
    "livewire/livewire",
  ]);
});
for (const absent of ["file", "age"]) {
  test(`security does not invent an age policy (${absent})`, async (r) => {
    if (absent === "file") await Deno.remove(`${r.cwd}/vet.json`);
    else await r.json("vet.json", { other: "setting" });
    await r.execute("package", { ...security, policy: {} }, {
      [audit]: [{ code: 1, data: finding }, emptyAudit],
    });
    assertEquals(await r.exists("maintainer-exceptions.json"), false);
    if (absent === "file") assertEquals(await r.exists("vet.json"), false);
    else assertEquals(await r.read("vet.json"), { other: "setting" });
  });
}
test("an already exact and fixed security target can rerun without an exclusion", async (r) => {
  await r.json("composer.json", {
    ...manifest,
    require: { ...manifest.require, "livewire/livewire": "4.9.9" },
  });
  await Deno.remove(`${r.cwd}/vet.json`);
  await r.execute("package", security);
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
});
for (const policyInput of [{}, { ".": { minimumReleaseAgeDays: 3 } }]) {
  test(`security requires input policy matching existing Vet ${JSON.stringify(policyInput)}`, async (r) => {
    await r.failure(
      "package",
      { ...security, policy: policyInput },
      { [audit]: [{ code: 1, data: finding }] },
      64,
      "must match",
    );
    assertEquals(await r.read("composer.json"), manifest);
    assertEquals((await r.log()).includes("require"), false);
  });
}
for (const phase of ["require", "update"]) {
  test(`package stops on failed ${phase}`, async (r) => {
    const key = phase === "require"
      ? "composer require dev/tool:^2.0.0 --no-update --no-interaction --dev"
      : "composer update dev/tool --with-dependencies --no-interaction";
    await r.failure(
      "package",
      major,
      { [key]: [{ code: 17, raw: "failed" }] },
      17,
    );
    if (phase === "require") {
      assertEquals((await r.log()).includes("composer|update"), false);
    }
  });
}
for (
  const target of [
    { ...major, package: "livewire/livewire other/pkg" },
    { ...major, package: "--help" },
    { ...major, package: "missing/pkg" },
    { ...major, version: "latest" },
    { ...major, advisoryIds: ["extra"] },
    { ...security, advisoryIds: [] },
    { ...major, force: true },
  ]
) {
  test(`invalid target ${JSON.stringify(target)} fails before commands`, async (r) => {
    await r.failure("package", target, {}, 64);
    assertEquals(await r.log(), "");
  });
}
test("package refuses ambiguous and aliased Composer declarations", async (r) => {
  for (
    const data of [
      { require: { "dev/tool": "^1" }, "require-dev": { "dev/tool": "^1" } },
      { require: { "dev/tool": "1.0 as 2.0" } },
      { require: { "dev/tool": "git:somewhere" } },
    ]
  ) {
    await r.json("composer.json", data);
    await r.failure("package", major, {}, 64);
  }
  assertEquals(await r.log(), "");
});
test("package selects the configured nested directory", async (r) => {
  await r.json("composer.json", manifest, "server/web");
  await r.failure(
    "package",
    { ...major, paths: ["server/web"], policy: {} },
    {},
    64,
    "Unknown path .",
  );
  await r.execute("package", {
    ...major,
    paths: ["server/web"],
    path: "server/web",
    policy: {},
  });
  assertStringIncludes(
    await r.log(),
    `composer|require dev/tool:^2.0.0 --no-update --no-interaction --dev|${r.cwd}/server/web`,
  );
});

test("detect reports a due exception and leaves policy untouched", async (r) => {
  const document = {
    schema: 1,
    exceptions: [{
      path: ".",
      manager: "composer",
      package: "existing/pkg",
      recorded_on: "2026-10-01",
      days: 7,
    }],
  };
  r.exceptions = document;
  const vet = await Deno.readTextFile(`${r.cwd}/vet.json`);
  assertEquals(await r.execute("detect"), {
    schema: 1,
    candidates: [],
    stale_exceptions: [{
      path: ".",
      manager: "composer",
      package: "existing/pkg",
      recorded_on: "2026-10-01",
      remove_on: "2026-10-08",
    }],
  });
  assertEquals(await Deno.readTextFile(`${r.cwd}/vet.json`), vet);
  assertEquals(r.exceptions as Exceptions, document);
});
for (const removed of [false, true]) {
  test(`detect omits an exception that is ${removed ? "removed" : "not due"}`, async (r) => {
    r.exceptions = {
      schema: 1,
      exceptions: [{
        path: ".",
        manager: "composer",
        package: removed ? "absent/pkg" : "existing/pkg",
        recorded_on: removed
          ? "2026-10-01"
          : new Date().toISOString().slice(0, 10),
        days: 7,
      }],
    };
    assertEquals(await r.execute("detect"), { schema: 1, candidates: [] });
  });
}
for (
  const document of [[], { schema: 2, exceptions: [] }, {
    schema: 1,
    exceptions: [{
      path: ".",
      manager: "composer",
      package: "existing/pkg",
      recorded_on: "bad",
      days: 7,
    }],
  }]
) {
  test(`detect rejects unusable exception records ${JSON.stringify(document)}`, async (r) => {
    r.exceptions = document;
    await r.failure("detect", {}, emptyDetect, 64);
  });
}
for (
  const value of [
    { "minimum-release-age": -1 },
    { "minimum-release-age": "7" },
    { "minimum-release-age": 7, "minimum-release-age-exclude": {} },
  ]
) {
  test(`security rejects malformed Vet ${JSON.stringify(value)}`, async (r) => {
    await r.json("vet.json", value);
    await r.failure("package", security, { [audit]: [{ data: finding }] }, 70);
    assertEquals(await r.read("composer.json"), manifest);
  });
}
for (const paths of [["/tmp"], ["../outside"], ["server/./web"], [".", "."]]) {
  test(`paths reject escape or duplicate ${JSON.stringify(paths)}`, async (r) => {
    await r.failure("detect", { paths, policy: {} }, {}, 70);
    assertEquals(await r.log(), "");
  });
}
test("paths reject symlinks escaping the checkout", async (r) => {
  await Deno.mkdir(`${r.temp}/outside`);
  await Deno.symlink(`${r.temp}/outside`, `${r.cwd}/linked`);
  await r.failure(
    "detect",
    { paths: ["linked"], policy: {} },
    {},
    70,
    "escapes the repository",
  );
  assertEquals(await r.log(), "");
});
test("paths reject two entries resolving to the same directory", async (r) => {
  await r.json("composer.json", manifest, "server/web");
  await Deno.symlink("server/web", `${r.cwd}/alias`);
  await r.failure(
    "detect",
    { paths: ["server/web", "alias"], policy: {} },
    {},
    70,
    "repeats path alias",
  );
});
test("missing Composer binary fails with 127", async (r) => {
  await Deno.remove(`${r.temp}/bin/composer`);
  await r.failure("routine", {}, {}, 127, "Missing executable composer");
});
test("input schemas reject unknown config and an empty path set", async (r) => {
  await r.failure("routine", { paths: [] }, {}, 64);
  await r.failure("routine", { checks: ["echo wrong"] }, {}, 64);
  await r.failure(
    "routine",
    { policy: { missing: { minimumReleaseAgeDays: 7 } } },
    {},
    64,
  );
  assertEquals(await r.log(), "");
});
test("an aborted method runs no Composer command", async (r) => {
  const controller = new AbortController();
  controller.abort();
  const { context } = createModelTestContext({ signal: controller.signal });
  await assertRejects(() =>
    behavior.methods.routine.execute({ cwd: r.cwd }, context)
  );
  assertEquals(await r.log(), "");
});

test("summary describes a routine update and omits transitive version changes", async (r) => {
  const base = await r.base({
    "livewire/livewire": "4.2.0",
    "other/pkg": "1.0.0",
    "symfony/polyfill-php80": "1.27.0",
  }, { "dev/tool": "1.0.0" });
  await r.json(
    "composer.lock",
    lock({
      "livewire/livewire": "4.2.1",
      "other/pkg": "1.0.1",
      "symfony/polyfill-php80": "1.28.0",
    }, { "dev/tool": "1.0.0" }),
  );
  const result = await r.execute("summary", {
    projectType: "laravel-package",
    base,
  });
  assertEquals(
    result.text,
    summaryText(monthlyTitle(), routineIntro, [[
      "livewire/livewire",
      "4.2.0",
      "4.2.1",
      "",
    ], ["other/pkg", "1.0.0", "1.0.1", ""]]),
  );
  assertEquals(result.title + "\n\n" + result.body, result.text);
  assertEquals((await r.log()).match(/audit --locked/g)?.length, 2);
  assertEquals(await r.exists("vendor"), false);
});
test("summary describes one major upgrade with upstream text", async (r) => {
  const base = await r.base({
    "livewire/livewire": "4.2.0",
    "other/pkg": "1.2.3",
  });
  await r.json(
    "composer.lock",
    lock({ "livewire/livewire": "4.2.0", "other/pkg": "2.0.0" }),
  );
  const result = await r.execute("summary", {
    projectType: "laravel-package",
    base,
  });
  assertEquals(
    result.text,
    summaryText(
      "Update other/pkg to 2.0.0",
      "This updates other/pkg from 1.2.3 to 2.0.0.",
      [["other/pkg", "1.2.3", "2.0.0", "major"]],
      ["other/pkg 1.2.3 → 2.0.0 is a major update. Check its upgrade notes for downstream breaking changes."],
    ),
  );
});
test("summary retains the monthly title when one of several updates is major", async (r) => {
  await r.json("composer.json", {
    require: { "laravel/framework": "^13.38", "livewire/livewire": "^4.2" },
  });
  const base = await r.base({
    "laravel/framework": "13.38.0",
    "livewire/livewire": "4.2.0",
  });
  await r.json(
    "composer.lock",
    lock({ "laravel/framework": "14.0.0", "livewire/livewire": "4.2.1" }),
  );
  assertEquals(
    (await r.execute("summary", { projectType: "laravel-package", base })).text,
    summaryText(monthlyTitle(), routineIntro, [[
      "laravel/framework",
      "13.38.0",
      "14.0.0",
      "major",
    ], ["livewire/livewire", "4.2.0", "4.2.1", ""]], [
      "laravel/framework 13.38.0 → 14.0.0 is a major update. Check its upgrade notes for downstream breaking changes.",
    ]),
  );
});
test("summary treats a newer 0.x minor as major", async (r) => {
  const base = await r.base({ "other/pkg": "0.1.4" });
  await r.json("composer.lock", lock({ "other/pkg": "0.2.0" }));
  assertEquals(
    (await r.execute("summary", { projectType: "laravel-package", base })).text,
    summaryText(
      "Update other/pkg to 0.2.0",
      "This updates other/pkg from 0.1.4 to 0.2.0.",
      [["other/pkg", "0.1.4", "0.2.0", "major"]],
      ["other/pkg 0.1.4 → 0.2.0 is a major update. Check its upgrade notes for downstream breaking changes."],
    ),
  );
});
test("summary links only cleared advisories with exact upstream text", async (r) => {
  const base = await r.base({
    "livewire/livewire": "4.2.0",
    "other/pkg": "1.0.0",
  });
  await r.json(
    "composer.lock",
    lock({ "livewire/livewire": "4.5.0", "other/pkg": "1.0.0" }),
  );
  const result = await r.execute("summary", {
    projectType: "laravel-package",
    base,
  }, {
    [audit]: [emptyAudit, { code: 1, data: finding }],
  });
  const links =
    "[PKSA-bgw4-5zmg-2njg](https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg), [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db)";
  assertEquals(
    result.text,
    summaryText(
      "Update livewire/livewire to 4.5.0 (security fix)",
      "This updates livewire/livewire from 4.2.0 to 4.5.0.",
      [["livewire/livewire", "4.2.0", "4.5.0", "security fix"]],
      [],
      [`livewire/livewire: ${links}`],
    ),
  );
  assertEquals(result.text.includes("Tested"), false);
  const baseDir = (await r.log()).trim().split("\n")[1].split("|")[2];
  await assertRejects(() => Deno.stat(baseDir), Deno.errors.NotFound);
});
test("summary does not call an open advisory a fix", async (r) => {
  const base = await r.base({ "livewire/livewire": "4.2.0" });
  await r.json("composer.lock", lock({ "livewire/livewire": "4.5.0" }));
  const result = await r.execute("summary", {
    projectType: "laravel-package",
    base,
  }, {
    [audit]: [{ code: 1, data: finding }],
  });
  assertEquals(
    result.text,
    summaryText(
      "Update livewire/livewire to 4.5.0",
      "This updates livewire/livewire from 4.2.0 to 4.5.0.",
      [["livewire/livewire", "4.2.0", "4.5.0", ""]],
    ),
  );
});
test("summary names changed paths in its table and sections", async (r) => {
  await r.json("composer.json", manifest, "mobile");
  await r.json("composer.lock", lock({ "other/pkg": "1.0.0" }), "mobile");
  const base = await r.base({ "other/pkg": "1.0.0" });
  await r.json("composer.lock", lock({ "other/pkg": "1.1.0" }));
  await r.json("composer.lock", lock({ "other/pkg": "2.0.0" }), "mobile");
  assertEquals(
    (await r.execute("summary", {
      projectType: "laravel-package",
      base,
      paths: [".", "mobile"],
    })).text,
    summaryText(monthlyTitle(), routineIntro, [[
      "other/pkg",
      "1.0.0",
      "1.1.0",
      "",
    ], ["other/pkg (mobile)", "1.0.0", "2.0.0", "major"]], [
      "other/pkg (mobile) 1.0.0 → 2.0.0 is a major update. Check its upgrade notes for downstream breaking changes.",
    ]),
  );
});
test("summary exits 1 and writes no output when lockfiles match", async (r) => {
  const base = await r.base({ "livewire/livewire": "4.2.0" });
  await r.failure(
    "summary",
    { base },
    {},
    1,
    "No dependency changes to summarize.",
  );
  assertEquals(await r.log(), "");
});
test("summary resolves origin/HEAD when base is omitted", async (r) => {
  const base = await r.base({ "livewire/livewire": "4.2.0" });
  await r.git("update-ref", "refs/remotes/origin/main", base);
  await r.git(
    "symbolic-ref",
    "refs/remotes/origin/HEAD",
    "refs/remotes/origin/main",
  );
  await r.json("composer.lock", lock({ "livewire/livewire": "4.2.2" }));
  assertEquals(
    (await r.execute("summary", { projectType: "laravel-package" })).text,
    summaryText(
      "Update livewire/livewire to 4.2.2",
      "This updates livewire/livewire from 4.2.0 to 4.2.2.",
      [["livewire/livewire", "4.2.0", "4.2.2", ""]],
    ),
  );
});
for (
  const base of [
    "",
    "--help",
    "HEAD:composer.lock",
    "HEAD..main",
    "HEAD;touch /tmp/injected",
  ]
) {
  test(`summary rejects invalid base ${base}`, async (r) => {
    await r.failure("summary", { base }, {}, 64, "Invalid base ref");
  });
}
test("summary fails on a missing base instead of producing a report", async (r) => {
  await r.base({ "other/pkg": "1.0.0" });
  await r.failure(
    "summary",
    { base: "does-not-exist" },
    {},
    70,
    "Cannot resolve base",
  );
  await r.failure("summary", {}, {}, 70, "Cannot find a merge base");
});
for (const mode of ["added", "removed"]) {
  test(`summary describes one ${mode} direct dependency`, async (r) => {
    await r.json("composer.json", {
      require: mode === "removed" ? { "other/pkg": "^1" } : {},
    });
    const base = await r.base(
      mode === "removed" ? { "other/pkg": "1.0.0" } : {},
    );
    await r.json("composer.json", {
      require: mode === "removed" ? {} : { "other/pkg": "^1" },
    });
    await r.json(
      "composer.lock",
      lock(mode === "removed" ? {} : { "other/pkg": "1.0.0" }),
    );
    assertEquals(
      (await r.execute("summary", { projectType: "laravel-package", base }))
        .text,
      mode === "removed"
        ? summaryText("Remove other/pkg", "This removes other/pkg 1.0.0.", [[
          "other/pkg",
          "1.0.0",
          "—",
          "",
        ]])
        : summaryText("Add other/pkg 1.0.0", "This adds other/pkg 1.0.0.", [[
          "other/pkg",
          "—",
          "1.0.0",
          "",
        ]]),
    );
  });
}
test("summary does not mark a removed direct declaration fixed while it stays installed", async (r) => {
  const base = await r.base({ "livewire/livewire": "4.2.0" });
  await r.json("composer.json", { require: {} });
  const result = await r.execute("summary", {
    projectType: "laravel-package",
    base,
  }, {
    [audit]: [{ code: 1, data: finding }],
  });
  assertEquals(
    result.text,
    summaryText(
      "Remove livewire/livewire",
      "This removes livewire/livewire 4.2.0.",
      [["livewire/livewire", "4.2.0", "—", ""]],
    ),
  );
});
for (const path of [".", "server"]) {
  test(`summary lists removals when Composer skips an empty lock (${path})`, async (r) => {
    await r.json("composer.json", {
      require: { "psr/log": "^3.0" },
      "require-dev": { "psr/container": "^2.0" },
    }, path);
    const base = await r.base({ "psr/log": "3.0.1" }, {
      "psr/container": "2.0.2",
    }, path);
    await r.json("composer.json", { require: [], "require-dev": [] }, path);
    await r.json("composer.lock", lock({}), path);
    const result = await r.execute("summary", {
      projectType: "laravel-package",
      base,
      paths: path === "." ? ["."] : [".", "server"],
    }, { [audit]: [{ raw: "" }, emptyAudit] });
    const suffix = path === "." ? "" : " (server)";
    assertEquals(
      result.text,
      summaryText(monthlyTitle(), routineIntro, [[
        `psr/container${suffix}`,
        "2.0.2",
        "—",
        "",
      ], [`psr/log${suffix}`, "3.0.1", "—", ""]]),
    );
  });
}
test("summary rejects empty audit output for nonempty locks", async (r) => {
  const base = await r.base({ "other/pkg": "1.0.0" });
  await r.json("composer.lock", lock({ "other/pkg": "1.0.1" }));
  await r.failure(
    "summary",
    { base },
    { [audit]: [{ raw: "" }] },
    70,
    "Invalid JSON from composer audit --locked --format=json",
  );
});
test("summary fails closed on malformed lock entries", async (r) => {
  const base = await r.base({ "other/pkg": "1.0.0" });
  await r.json("composer.lock", { packages: [{ name: "other/pkg" }] });
  await r.failure(
    "summary",
    { base },
    {},
    70,
    "composer.lock entry is incomplete",
  );
});

test("summary preserves the original Composer-only patch case", async (r) => {
  const base = await r.base({
    "livewire/livewire": "4.2.0",
    "other/pkg": "1.0.0",
    "symfony/polyfill-php80": "1.27.0",
  }, { "dev/tool": "1.0.0" });
  await r.json(
    "composer.lock",
    lock({
      "livewire/livewire": "4.2.1",
      "other/pkg": "1.0.0",
      "symfony/polyfill-php80": "1.28.0",
    }, { "dev/tool": "1.0.0" }),
  );
  assertEquals(
    (await r.execute("summary", { projectType: "laravel-package", base })).text,
    summaryText(
      "Update livewire/livewire to 4.2.1",
      "This updates livewire/livewire from 4.2.0 to 4.2.1.",
      [["livewire/livewire", "4.2.0", "4.2.1", ""]],
    ),
  );
});
test("security records the configured path in the project exception receipt", async (r) => {
  await r.json("composer.json", manifest, "server/web");
  await r.json(
    "vet.json",
    { "minimum-release-age": 7, unrelated: "preserved" },
    "server/web",
  );
  await r.execute("package", {
    ...security,
    paths: ["server/web"],
    path: "server/web",
    policy: { "server/web": { minimumReleaseAgeDays: 7 } },
  }, { [audit]: [{ code: 1, data: finding }, emptyAudit] });
  assertEquals(await r.read("server/web/vet.json"), {
    "minimum-release-age": 7,
    unrelated: "preserved",
    "minimum-release-age-exclude": ["livewire/livewire"],
  });
  assertEquals(
    (r.exceptions as Exceptions).exceptions[0].path,
    "server/web",
  );
});
test("security validates an exception input before changing Vet", async (r) => {
  const before = await r.read("vet.json");
  r.exceptions = { schema: 2, exceptions: [] };
  await r.failure(
    "package",
    security,
    { [audit]: [{ code: 1, data: finding }] },
    64,
    "exceptions",
  );
  assertEquals(await r.read("vet.json"), before);
  assertEquals(await r.read("composer.json"), manifest);
});
test("summary removes the base audit directory even when that audit fails", async (r) => {
  const base = await r.base({ "other/pkg": "1.0.0" });
  await r.json("composer.lock", lock({ "other/pkg": "1.0.1" }));
  await r.failure("summary", { base }, {
    [audit]: [emptyAudit, { code: 4, raw: "offline" }],
  }, 70);
  const baseDir = (await r.log()).trim().split("\n")[1].split("|")[2];
  await assertRejects(() => Deno.stat(baseDir), Deno.errors.NotFound);
});

test("Composer receives the shared clean environment without parent agent markers", async (r) => {
  const env = {
    CLAUDECODE: "1",
    AI_AGENT: "claude",
    COMPOSER_MAINTENANCE_UNRELATED: "drop-this",
    COMPOSER_HOME: `${r.temp}/composer-home`,
    XDG_CACHE_HOME: `${r.temp}/cache`,
  };
  const previous = Object.fromEntries(
    Object.keys(env).map((key) => [key, Deno.env.get(key)]),
  );
  for (const [key, value] of Object.entries(env)) Deno.env.set(key, value);
  try {
    await r.execute("routine");
    const child = JSON.parse(
      await Deno.readTextFile(`${r.temp}/child-env.json`),
    );
    assertEquals(child.CLAUDECODE, undefined);
    assertEquals(child.AI_AGENT, undefined);
    assertEquals(child.COMPOSER_MAINTENANCE_UNRELATED, undefined);
    assertEquals(child.COMPOSER_HOME, env.COMPOSER_HOME);
    assertEquals(child.XDG_CACHE_HOME, env.XDG_CACHE_HOME);
    assertEquals(child.PATH, `${r.temp}/bin`);
    assertEquals(child.TERM, "dumb");
  } finally {
    for (const [key, value] of Object.entries(previous)) {
      if (value === undefined) Deno.env.delete(key);
      else Deno.env.set(key, value);
    }
  }
});
for (const missing of ["cwd", "configured path"]) {
  test(`missing ${missing} returns the maintainer path failure`, async (r) => {
    const path = missing === "cwd" ? `${r.cwd}/missing` : "missing";
    await r.failure(
      "routine",
      missing === "cwd" ? { cwd: path } : { paths: [".", path] },
      {},
      70,
      `Path ${path} is missing`,
    );
    assertEquals(await r.log(), "");
  });
}
test("security preserves extra ledger and record keys when adding an exception", async (r) => {
  const record = {
    path: ".",
    manager: "js",
    package: "another-package",
    recorded_on: "2026-10-01",
    days: 7,
    advisoryIds: ["GHSA-existing"],
    note: { owner: "project" },
  };
  r.exceptions = {
    schema: 1,
    notes: { owner: "project" },
    exceptions: [record],
  };
  await r.execute("package", security, {
    [audit]: [{ code: 1, data: finding }, emptyAudit],
  });
  const result = r.exceptions as Exceptions;
  assertEquals(result.notes, { owner: "project" });
  assertEquals(result.exceptions[0] as Record<string, unknown>, record);
  assertEquals(result.exceptions.length, 2);
  assertEquals(result.exceptions[1].package, "livewire/livewire");
});
test("routine accepts stdout over 8 MiB and preserves a failed command's exit code", async (r) => {
  const raw = "x".repeat(8388609);
  const key = "composer update --no-interaction";
  await r.execute("routine", {}, { [key]: [{ raw }] });
  await r.failure("routine", {}, { [key]: [{ raw, code: 17 }] }, 17);
});
test("JSON report stdout remains limited to 8 MiB in bytes", async (r) => {
  const raw = JSON.stringify({ locked: [], padding: "é".repeat(4194304) });
  await r.failure(
    "detect",
    {},
    { [outdated]: [{ raw }] },
    70,
    "Output from composer is too large",
  );
});

test("repository exception files are ignored and never overwritten", async (r) => {
  const original = '{"schema":99,"must":"stay untouched"}';
  await Deno.writeTextFile(`${r.cwd}/maintainer-exceptions.json`, original);
  await r.execute("package", security, {
    [audit]: [{ code: 1, data: finding }, emptyAudit],
  });
  assertEquals(
    (r.exceptions as Exceptions).exceptions[0].package,
    "livewire/livewire",
  );
  assertEquals(
    await Deno.readTextFile(`${r.cwd}/maintainer-exceptions.json`),
    original,
  );
  await r.execute("detect");
  assertEquals(
    await Deno.readTextFile(`${r.cwd}/maintainer-exceptions.json`),
    original,
  );
});
test("a failed security command still returns its exception receipt", async (r) => {
  const result = await r.execute("package", {
    ...security,
    captureFailure: true,
  }, { [audit]: [{ code: 1, data: finding }] });
  assertEquals(result.exitCode, 1);
  assertEquals(r.handles, ["exceptions", "failure"]);
  assertEquals(
    (r.exceptions as Exceptions).exceptions[0].package,
    "livewire/livewire",
  );
  assertEquals(await r.exists("maintainer-exceptions.json"), false);
});

test("summary includes the checked repair effect without changing the repository", async (r) => {
  const base = await r.base({
    "livewire/livewire": "4.2.0",
    "other/pkg": "1.0.0",
  });
  await r.json(
    "composer.lock",
    lock({ "livewire/livewire": "4.2.0", "other/pkg": "2.0.0" }),
  );
  await Deno.writeTextFile(`${r.cwd}/component.ts`, "typed component");
  await r.git("add", ".");
  await r.git("commit", "-m", "repair");
  const tree = await r.git("rev-parse", "HEAD^{tree}");
  const report = JSON.stringify({
    updates: [{
      manager: "composer",
      path: ".",
      package: "other/pkg",
      codeChanged: true,
      effect: "The component now has explicit types; pages render the same.",
      files: ["component.ts"],
    }],
  });
  const result = await r.execute("summary", {
    base,
    impact: {
      report,
      inventory: [{ manager: "composer", path: ".", package: "other/pkg" }],
      scriptTree: tree,
      repairTree: tree,
      checkedTree: tree,
    },
  });
  assertStringIncludes(result.body, "What changes on the site");
  assertStringIncludes(
    result.body,
    "The component now has explicit types; pages render the same.",
  );
  assertEquals(await r.git("status", "--porcelain"), "");
});
