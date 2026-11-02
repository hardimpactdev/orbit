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
      policy,
      exceptions: this.exceptions,
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

test("detect preflights every path before any command", async (r) => {
  await Deno.mkdir(`${r.cwd}/empty`);
  await r.failure(
    "detect",
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
  await r.failure("detect", {}, {}, 127, "Missing executable composer");
});
test("input schemas reject unknown config and an empty path set", async (r) => {
  await r.failure("detect", { paths: [] }, {}, 64);
  await r.failure("detect", { checks: ["echo wrong"] }, {}, 64);
  await r.failure(
    "detect",
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
    behavior.methods.detect.execute({ cwd: r.cwd }, context)
  );
  assertEquals(await r.log(), "");
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
    await r.execute("detect");
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
      "detect",
      missing === "cwd" ? { cwd: path } : { paths: [".", path] },
      {},
      70,
      `Path ${path} is missing`,
    );
    assertEquals(await r.log(), "");
  });
}
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

test("detect ignores repository exception files and never overwrites them", async (r) => {
  const original = '{"schema":99,"must":"stay untouched"}';
  await Deno.writeTextFile(`${r.cwd}/maintainer-exceptions.json`, original);
  await r.execute("detect");
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
test("plan accepts an update failure with output over 8 MiB and reports its command", async (r) => {
  await commit(r);
  const raw = "x".repeat(8388609);
  const fetch = globalThis.fetch;
  globalThis.fetch = () =>
    Promise.resolve(
      Response.json({
        packages: {
          "dev/tool": [{ version: "v2.0.0", time: "2020-01-01T00:00:00Z" }],
        },
      }),
    );
  const command =
    "composer update dev/tool --with dev/tool:2.0.0 --with-dependencies --no-interaction --no-install --no-scripts --no-plugins";
  try {
    const plan = await r.execute("plan", {
      candidates: [{
        path: ".",
        manager: "composer",
        package: "dev/tool",
        reasons: ["major"],
        advisories: [],
      }],
    }, { [command]: [{ code: 17, raw }] }) as {
      targets: unknown[];
      blocked: { reason: string }[];
    };
    assertEquals(plan.targets, []);
    assertEquals(plan.blocked.length, 1);
    assertStringIncludes(plan.blocked[0].reason, `${command} failed:`);
    assertStringIncludes(plan.blocked[0].reason, raw);
    assertStringIncludes(await r.log(), `composer|${command.slice(9)}|`);
  } finally {
    globalThis.fetch = fetch;
  }
});
