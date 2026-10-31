import {
  assert,
  assertEquals,
  assertRejects,
  assertStringIncludes,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  batch as composerBatch,
  plan as composerPlan,
} from "../extensions/behaviors/composer-maintenance/_lib/batch.ts";
import {
  batch as jsBatch,
  plan as jsPlan,
} from "../extensions/behaviors/js-maintenance/_lib/batch.ts";
import {
  planBatch,
  scratch,
  targetRange,
  UnwritableTarget,
  type UpdateTarget,
  writableReleases,
} from "../extensions/behaviors/_lib/maintenance/batch.ts";
import { emptyExceptions } from "../extensions/behaviors/_lib/maintenance/exceptions.ts";
async function fixture(fn: (root: string, log: string) => Promise<void>) {
  const temp = await Deno.makeTempDir({ prefix: "batch-ecosystem-" }),
    root = `${temp}/repo`,
    bin = `${temp}/bin`,
    log = `${temp}/calls`;
  await Deno.mkdir(root);
  await Deno.mkdir(bin);
  const oldPath = Deno.env.get("PATH"), fetch = globalThis.fetch;
  try {
    const archives: Record<string, string> = {};
    await Deno.mkdir(`${temp}/sdk/package`, { recursive: true });
    for (const version of ["2.0.0", "3.0.0"]) {
      await Deno.writeTextFile(
        `${temp}/sdk/package/bundledNativeModules.json`,
        JSON.stringify({ react: version }),
      );
      archives[version] = `${temp}/expo-${version}.tgz`;
      assert(
        (await new Deno.Command("tar", {
          args: ["-czf", archives[version], "-C", `${temp}/sdk`, "package"],
          stdout: "null",
          stderr: "piped",
        }).output()).success,
      );
    }
    const source = (await Deno.readTextFile(
      new URL("./fixtures/batch/manager.php", import.meta.url),
    ))
      .replaceAll("__LOG__", JSON.stringify(log)).replaceAll(
        "__EXPO_TARS__",
        JSON.stringify(archives),
      );
    for (const name of ["composer", "npm", "bun"]) {
      await Deno.writeTextFile(`${bin}/${name}`, source);
      await Deno.chmod(`${bin}/${name}`, 0o755);
    }
    Deno.env.set("PATH", `${bin}:${oldPath}`);
    globalThis.fetch = () =>
      Promise.resolve(
        Response.json({
          packages: {
            "fixture/one": [
              { version: "1.5.0", time: "2020-01-01T00:00:00Z" },
              { version: "2.0.0", time: "2020-01-01T00:00:00Z" },
            ],
            "fixture/two": [{ version: "2.0.0", time: "2020-01-01T00:00:00Z" }],
          },
        }),
      );
    await Deno.writeTextFile(
      `${root}/composer.json`,
      JSON.stringify({
        require: { "fixture/one": "1.0.0", "fixture/two": "1.0.0" },
      }),
    );
    await Deno.writeTextFile(
      `${root}/composer.lock`,
      JSON.stringify({
        packages: [{ name: "fixture/one", version: "1.0.0" }, {
          name: "fixture/two",
          version: "1.0.0",
        }],
        "packages-dev": [],
      }),
    );
    await Deno.writeTextFile(
      `${root}/package.json`,
      JSON.stringify({
        dependencies: { one: "1.0.0", two: "1.0.0" },
        packageManager: "npm@11.19.1",
      }),
    );
    await Deno.writeTextFile(
      `${root}/package-lock.json`,
      JSON.stringify({
        lockfileVersion: 3,
        packages: {
          "": { dependencies: { one: "1.0.0", two: "1.0.0" } },
          "node_modules/one": { version: "1.0.0" },
          "node_modules/two": { version: "1.0.0" },
        },
      }),
    );
    await Deno.writeTextFile(`${root}/AGENTS.md`, "project instructions\n");
    await Deno.mkdir(`${root}/.agents/skills`, { recursive: true });
    await Deno.writeTextFile(`${root}/.agents/skills/example.md`, "skill\n");
    await Deno.mkdir(`${root}/.claude`);
    await Deno.symlink("AGENTS.md", `${root}/CLAUDE.md`);
    await Deno.symlink("../.agents/skills", `${root}/.claude/skills`);
    for (
      const args of [["init"], ["add", "."], [
        "-c",
        "user.name=Fixture",
        "-c",
        "user.email=f@e.test",
        "commit",
        "-m",
        "fixture",
      ]]
    ) {
      const out = await new Deno.Command("git", {
        cwd: root,
        args,
        stdout: "null",
        stderr: "piped",
      }).output();
      assert(out.success, new TextDecoder().decode(out.stderr));
    }
    await fn(root, log);
  } finally {
    globalThis.fetch = fetch;
    if (oldPath === undefined) Deno.env.delete("PATH");
    else Deno.env.set("PATH", oldPath);
    await Deno.remove(temp, { recursive: true });
  }
}

Deno.test("planning copy preserves file and directory symlinks without copying untracked targets", async () => {
  await fixture(async (root) => {
    await Deno.writeTextFile(`${root}/untracked`, "not part of the plan\n");
    await Deno.symlink("untracked", `${root}/local-link`);
    assert(
      (await new Deno.Command("git", {
        cwd: root,
        args: ["add", "local-link"],
      }).output()).success,
    );
    const copy = await scratch(root);
    try {
      assert((await Deno.lstat(`${copy.root}/CLAUDE.md`)).isSymlink);
      assert((await Deno.lstat(`${copy.root}/.claude/skills`)).isSymlink);
      assertEquals(await Deno.readLink(`${copy.root}/local-link`), "untracked");
      assertEquals(await Deno.readLink(`${copy.root}/CLAUDE.md`), "AGENTS.md");
      assertEquals(
        await Deno.readLink(`${copy.root}/.claude/skills`),
        "../.agents/skills",
      );
      assertEquals(
        await Deno.readTextFile(`${copy.root}/CLAUDE.md`),
        "project instructions\n",
      );
      assertEquals(
        await Deno.readTextFile(`${copy.root}/.claude/skills/example.md`),
        "skill\n",
      );
      await Deno.writeTextFile(`${copy.root}/CLAUDE.md`, "copy only\n");
      assertEquals(
        await Deno.readTextFile(`${root}/AGENTS.md`),
        "project instructions\n",
      );
      for (const excluded of [".git", "untracked", "node_modules", "vendor"]) {
        await assertRejects(
          () => Deno.lstat(`${copy.root}/${excluded}`),
          Deno.errors.NotFound,
        );
      }
    } finally {
      await copy.close();
    }
    await assertRejects(() => Deno.lstat(copy.root), Deno.errors.NotFound);
  });
});

Deno.test("planning copy relocates internal absolute symlinks and preserves dangling internal links", async () => {
  await fixture(async (root) => {
    await Deno.remove(`${root}/CLAUDE.md`);
    await Deno.symlink(`${root}/AGENTS.md`, `${root}/CLAUDE.md`);
    await Deno.remove(`${root}/.claude/skills`);
    await Deno.symlink("../missing/skills", `${root}/.claude/skills`);
    const copy = await scratch(root);
    try {
      assertEquals(
        await Deno.readLink(`${copy.root}/CLAUDE.md`),
        "./AGENTS.md",
      );
      assertEquals(
        await Deno.realPath(`${copy.root}/CLAUDE.md`),
        `${copy.root}/AGENTS.md`,
      );
      assertEquals(
        await Deno.readLink(`${copy.root}/.claude/skills`),
        "../missing/skills",
      );
    } finally {
      await copy.close();
    }
  });
});

for (
  const escape of [
    "relative",
    "absolute",
    "chain",
    "directory-parent",
    "missing-target",
  ] as const
) {
  Deno.test(`planning copy refuses ${escape} symlink escapes`, async () => {
    await fixture(async (root) => {
      const outside = `${root}-outside`;
      await Deno.mkdir(outside);
      try {
        await Deno.writeTextFile(`${outside}/AGENTS.md`, "outside\n");
        await Deno.remove(`${root}/CLAUDE.md`);
        let target = `../repo-outside/AGENTS.md`;
        if (escape === "absolute") target = `${outside}/AGENTS.md`;
        if (escape === "chain") {
          await Deno.symlink(`${root}/AGENTS.md`, `${outside}/return`);
          await Deno.symlink(`${outside}/return`, `${root}/indirect`);
          target = "indirect";
        }
        if (escape === "directory-parent") {
          await Deno.symlink(outside, `${root}/indirect`);
          target = "indirect/../repo/AGENTS.md";
        }
        if (escape === "missing-target") target = "../repo-outside/missing";
        await Deno.symlink(target, `${root}/CLAUDE.md`);
        await assertRejects(
          () => scratch(root),
          Error,
          "symlink escapes checkout",
        );
      } finally {
        await Deno.remove(outside, { recursive: true });
      }
    });
  });
}

Deno.test("planning copy still refuses tracked directories and unresolved link cycles", async () => {
  await fixture(async (root) => {
    await Deno.remove(`${root}/CLAUDE.md`);
    await Deno.symlink("CLAUDE.md", `${root}/CLAUDE.md`);
    await assertRejects(() => scratch(root), Error, "cannot resolve symlink");
    await Deno.remove(`${root}/CLAUDE.md`);
    await Deno.mkdir(`${root}/CLAUDE.md`);
    await assertRejects(
      () => scratch(root),
      Error,
      "cannot copy tracked non-file",
    );
  });
});

Deno.test("planning copy refuses an untracked internal symlink replacing a tracked parent directory", async () => {
  await fixture(async (root) => {
    await Deno.rename(`${root}/.agents`, `${root}/untracked-agents`);
    await Deno.symlink("untracked-agents", `${root}/.agents`);
    await assertRejects(
      () => scratch(root),
      Error,
      "cannot copy through symlink ancestor",
    );
  });
});

Deno.test("planning copy refuses a link that becomes an escape when an untracked target link is omitted", async () => {
  await fixture(async (root) => {
    await Deno.mkdir(`${root}/b/c`, { recursive: true });
    await Deno.writeTextFile(`${root}/b/c/file`, "tracked\n");
    await Deno.writeTextFile(`${root}/victim`, "inside\n");
    await Deno.symlink("b/c", `${root}/a`);
    await Deno.symlink("a/../../victim", `${root}/L`);
    assert(
      (await new Deno.Command("git", {
        cwd: root,
        args: ["add", "b/c/file", "victim", "L"],
      }).output()).success,
    );
    assertEquals(await Deno.realPath(`${root}/L`), `${root}/victim`);
    await assertRejects(
      () => scratch(root),
      Error,
      "symlink escapes checkout: L",
    );
  });
});
for (const manager of ["composer", "js"] as const) {
  Deno.test(`${manager}: preflight leaves checkout unchanged; batch applies two targets in one resolver operation`, async () => {
    await fixture(async (root, log) => {
      const names = manager === "composer"
        ? ["fixture/one", "fixture/two"]
        : ["one", "two"];
      const targets: UpdateTarget[] = names.map((name, i) => ({
        path: ".",
        manager,
        package: name,
        version: "2.0.0",
        purpose: i === 0 ? "security" : "major",
        advisories: i === 0 ? ["fixture-advisory"] : [],
        advisoryUrls: i === 0 ? ["https://example.test/advisory"] : [],
      }));
      const common = {
        cwd: root,
        paths: ["."],
        policy: { ".": { minimumReleaseAgeDays: 7 } },
        exceptions: emptyExceptions(),
      };
      const before = await new Deno.Command("git", {
        cwd: root,
        args: ["status", "--porcelain"],
        stdout: "piped",
      }).output();
      assertEquals(new TextDecoder().decode(before.stdout), "");
      const plan = await (manager === "composer" ? composerPlan : jsPlan)(
        common,
        targets.map((t) => ({
          ...t,
          current: "1.0.0",
          reasons: [t.purpose],
          advisories: t.advisories.map((id, i) => ({
            id,
            url: t.advisoryUrls[i],
          })),
        })),
      );
      assertEquals(plan.targets.length, 2);
      const after = await new Deno.Command("git", {
        cwd: root,
        args: ["status", "--porcelain"],
        stdout: "piped",
      }).output();
      assertEquals(new TextDecoder().decode(after.stdout), "");
      await Deno.writeTextFile(log, "");
      await (manager === "composer" ? composerBatch : jsBatch)(common, targets);
      const commands = await Deno.readTextFile(log);
      assertEquals(
        commands.split("\n").filter((s) =>
          s.startsWith(
            manager === "composer" ? "composer update" : "npm install",
          )
        ).length,
        1,
      );
      const manifest = JSON.parse(
        await Deno.readTextFile(
          `${root}/${manager === "composer" ? "composer" : "package"}.json`,
        ),
      );
      assertEquals(
        Object.values(
          manifest[manager === "composer" ? "require" : "dependencies"],
        ),
        ["2.0.0", "2.0.0"],
      );
    });
  });
}

for (const manager of ["composer", "js"] as const) {
  Deno.test(`${manager}: stale findings refuse downgrades before any mutation and exact-target retries remain safe`, async () => {
    await fixture(async (root, log) => {
      const common = {
        cwd: root,
        paths: ["."],
        policy: { ".": { minimumReleaseAgeDays: 7 } },
        exceptions: emptyExceptions(),
      };
      const t: UpdateTarget = {
        path: ".",
        manager,
        package: manager === "composer" ? "fixture/one" : "one",
        version: "2.0.0",
        purpose: "major",
        advisories: [],
        advisoryUrls: [],
      };
      const file = `${root}/${
          manager === "composer" ? "composer.lock" : "package-lock.json"
        }`,
        lock = JSON.parse(await Deno.readTextFile(file));
      if (manager === "composer") lock.packages[0].version = "3.0.0";
      else lock.packages["node_modules/one"].version = "3.0.0";
      await Deno.writeTextFile(file, JSON.stringify(lock));
      const manifest = `${root}/${
          manager === "composer" ? "composer.json" : "package.json"
        }`,
        before = await Deno.readTextFile(manifest);
      await assertRejects(
        () => (manager === "composer" ? composerBatch : jsBatch)(common, [t]),
        Error,
        "would downgrade",
      );
      assertEquals(await Deno.readTextFile(manifest), before);
      assert(
        !(await Deno.readTextFile(log)).includes(
          manager === "composer" ? "composer update" : "npm install",
        ),
      );
      if (manager === "composer") lock.packages[0].version = "2.0.0";
      else lock.packages["node_modules/one"].version = "2.0.0";
      await Deno.writeTextFile(file, JSON.stringify(lock));
      await (manager === "composer" ? composerBatch : jsBatch)(common, [t]);
    });
  });
}

Deno.test("Expo planning admits the SDK and its age-eligible React alignment as one immutable batch", async () => {
  await fixture(async (root) => {
    const manifest = JSON.parse(
      await Deno.readTextFile(`${root}/package.json`),
    );
    Object.assign(manifest.dependencies, { expo: "1.0.0", react: "1.0.0" });
    await Deno.writeTextFile(`${root}/package.json`, JSON.stringify(manifest));
    const locked = JSON.parse(
      await Deno.readTextFile(`${root}/package-lock.json`),
    );
    Object.assign(locked.packages, {
      "node_modules/expo": { version: "1.0.0" },
      "node_modules/react": { version: "1.0.0" },
    });
    await Deno.writeTextFile(
      `${root}/package-lock.json`,
      JSON.stringify(locked),
    );
    const common = {
      cwd: root,
      paths: ["."],
      policy: { ".": { minimumReleaseAgeDays: 7 } },
      exceptions: emptyExceptions(),
    };
    const plan = await jsPlan(common, [{
      path: ".",
      manager: "js",
      package: "expo",
      current: "1.0.0",
      latest: "2.0.0",
      reasons: ["major"],
      advisories: [],
    }]);
    assertEquals(plan.blocked, []);
    assertEquals(plan.targets.map((t) => t.package), ["expo", "react"]);
    await jsBatch(common, plan.targets);
    const updated = JSON.parse(await Deno.readTextFile(`${root}/package.json`));
    assertEquals(updated.dependencies.expo, "2.0.0");
    assertEquals(updated.dependencies.react, "2.0.0");
  });
});

for (const manager of ["composer", "js"] as const) {
  Deno.test(`${manager}: stale advisory IDs are refused before changing the checkout`, async () => {
    await fixture(async (root) => {
      const common = {
        cwd: root,
        paths: ["."],
        policy: { ".": { minimumReleaseAgeDays: 7 } },
        exceptions: emptyExceptions(),
      };
      const target: UpdateTarget = {
        path: ".",
        manager,
        package: manager === "composer" ? "fixture/one" : "one",
        version: "2.0.0",
        purpose: "security",
        advisories: ["not-a-current-advisory"],
        advisoryUrls: ["https://example.test/advisory"],
      };
      const file = `${root}/${
          manager === "composer" ? "composer.json" : "package.json"
        }`,
        before = await Deno.readTextFile(file);
      await assertRejects(
        () =>
          (manager === "composer" ? composerBatch : jsBatch)(common, [target]),
        Error,
        "Stale advisory evidence",
      );
      assertEquals(await Deno.readTextFile(file), before);
    });
  });
}

Deno.test("batch declarations retain range styles and security floors", () => {
  for (
    const [before, version, purpose, after] of [
      ["^25.3.0", "25.15.0", "security", "^25.15.0"],
      ["^1.0", "1.5.0", "security", "^1.5"],
      ["^1", "2.0.0", "major", "^2"],
      ["~1.0.0", "1.0.4", "security", "~1.0.4"],
      ["1.0.0", "1.0.4", "security", "1.0.4"],
      ["^5.9.3", "7.0.2", "major", "^7.0.2"],
      ["~1.0.0", "2.0.0", "major", "~2.0.0"],
      ["v1.0.0", "2.0.0", "major", "v2.0.0"],
      ["=1.0.0", "2.0.0", "major", "=2.0.0"],
      ["1.x", "2.0.0", "major", "2.x"],
      ["1.2.*", "2.4.0", "major", "2.4.*"],
    ] as const
  ) {
    assertEquals(
      targetRange(before, { version, purpose, manager: "js" }),
      after,
    );
  }
});

for (const manager of ["composer", "js"] as const) {
  for (const style of ["^", "~", ""] as const) {
    Deno.test(`${manager}: ${style || "pin"} batches preserve style and raise an in-range security floor`, async () => {
      await fixture(async (root) => {
        const file = `${root}/${
          manager === "composer" ? "composer" : "package"
        }.json`;
        const section = manager === "composer" ? "require" : "dependencies";
        const name = manager === "composer" ? "fixture/one" : "one";
        const manifest = JSON.parse(await Deno.readTextFile(file));
        manifest[section][name] = `${style}1.0.0`;
        await Deno.writeTextFile(file, JSON.stringify(manifest));
        const target: UpdateTarget = {
          path: ".",
          manager,
          package: name,
          version: "1.5.0",
          purpose: "security",
          advisories: ["fixture-advisory"],
          advisoryUrls: ["https://example.test/advisory"],
        };
        const common = {
          cwd: root,
          paths: ["."],
          policy: {},
          exceptions: emptyExceptions(),
        };
        const batch = manager === "composer" ? composerBatch : jsBatch;
        await batch(common, [target]);
        const expected = {
          ...manifest,
          [section]: { ...manifest[section], [name]: `${style}1.5.0` },
        };
        assertEquals(JSON.parse(await Deno.readTextFile(file)), expected);
        await batch(common, [target]);
        assertEquals(JSON.parse(await Deno.readTextFile(file)), expected);
        const locked = JSON.parse(
          await Deno.readTextFile(
            `${root}/${
              manager === "composer" ? "composer.lock" : "package-lock.json"
            }`,
          ),
        );
        if (manager === "js") {
          assertEquals(locked.packages[""].dependencies, expected.dependencies);
          assertEquals(locked.packages["node_modules/one"].version, "1.5.0");
        } else {assertEquals(
            locked.packages.find((p: { name: string }) => p.name === name)
              .version,
            "1.5.0",
          );}
      });
    });
  }
  Deno.test(`${manager}: unsupported security ranges block before mutation and leave other candidates eligible`, async () => {
    await fixture(async (root) => {
      const file = `${root}/${
        manager === "composer" ? "composer" : "package"
      }.json`;
      const section = manager === "composer" ? "require" : "dependencies";
      const name = manager === "composer" ? "fixture/one" : "one";
      const manifest = JSON.parse(await Deno.readTextFile(file));
      const common = {
        cwd: root,
        paths: ["."],
        policy: {},
        exceptions: emptyExceptions(),
      };
      for (
        const range of ["1.*", "^1.0.0 || ^2.0.0", ">=1.0.0 <3.0.0", "V1.0.0"]
      ) {
        manifest[section][name] = range;
        await Deno.writeTextFile(file, JSON.stringify(manifest));
        const before = await Deno.readTextFile(file);
        const target: UpdateTarget = {
          path: ".",
          manager,
          package: name,
          version: "2.0.0",
          purpose: "security",
          advisories: ["fixture-advisory"],
          advisoryUrls: ["https://example.test/advisory"],
        };
        await assertRejects(
          () =>
            (manager === "composer" ? composerBatch : jsBatch)(common, [
              target,
            ]),
          Error,
          "Cannot preserve range style",
        );
        assertEquals(await Deno.readTextFile(file), before);
        const plan = await (manager === "composer" ? composerPlan : jsPlan)(
          common,
          [{
            ...target,
            reasons: ["security"],
            advisories: [{
              id: "fixture-advisory",
              url: "https://example.test/advisory",
            }],
          }, {
            path: ".",
            manager,
            package: manager === "composer" ? "fixture/two" : "two",
            reasons: ["major"],
            advisories: [],
          }],
        );
        assert(
          plan.blocked.some((b) =>
            b.package === name &&
            b.reason.includes("Cannot preserve range style")
          ),
        );
        assertEquals(
          plan.targets.map((t) => t.package),
          [manager === "composer" ? "fixture/two" : "two"],
        );
      }
    });
  });
}

Deno.test("npm refuses version movement while restoring range metadata", async () => {
  await fixture(async (root) => {
    const manifest = JSON.parse(
      await Deno.readTextFile(`${root}/package.json`),
    );
    manifest.dependencies.one = "^1.0.0";
    await Deno.writeTextFile(`${root}/package.json`, JSON.stringify(manifest));
    await Deno.writeTextFile(`${root}/move-version`, "");
    await assertRejects(
      () =>
        jsBatch({
          cwd: root,
          paths: ["."],
          policy: {},
          exceptions: emptyExceptions(),
        }, [{
          path: ".",
          manager: "js",
          package: "one",
          version: "2.0.0",
          purpose: "major",
          advisories: [],
          advisoryUrls: [],
        }]),
      Error,
      "Lockfile synchronization changed resolved versions",
    );
  });
});

for (const manager of ["composer", "js"] as const) {
  Deno.test(`${manager}: a mixed major batch retains caret and tilde styles`, async () => {
    await fixture(async (root) => {
      const file = `${root}/${
        manager === "composer" ? "composer" : "package"
      }.json`;
      const section = manager === "composer" ? "require" : "dependencies";
      const names = manager === "composer"
        ? ["fixture/one", "fixture/two"]
        : ["one", "two"];
      const manifest = JSON.parse(await Deno.readTextFile(file));
      manifest[section] = { [names[0]]: "^1.0.0", [names[1]]: "~1.0.0" };
      await Deno.writeTextFile(file, JSON.stringify(manifest));
      await (manager === "composer" ? composerBatch : jsBatch)(
        { cwd: root, paths: ["."], policy: {}, exceptions: emptyExceptions() },
        names.map((name) => ({
          path: ".",
          manager,
          package: name,
          version: "2.0.0",
          purpose: "major",
          advisories: [],
          advisoryUrls: [],
        })),
      );
      assertEquals(JSON.parse(await Deno.readTextFile(file))[section], {
        [names[0]]: "^2.0.0",
        [names[1]]: "~2.0.0",
      });
    });
  });
}

Deno.test("partial numeric pins must admit the exact target under each manager's semantics", () => {
  assertThrows(
    () =>
      targetRange("1.2", {
        version: "2.3.4",
        purpose: "major",
        manager: "composer",
      }),
    Error,
    "Cannot preserve range style",
  );
  assertEquals(
    targetRange("1.2", { version: "2.3.4", purpose: "major", manager: "js" }),
    "2.3",
  );
  assertEquals(
    targetRange("1.2", {
      version: "2.3.0",
      purpose: "major",
      manager: "composer",
    }),
    "2.3",
  );
});

Deno.test("major ranges retain admitting declarations and preserve operator precision", () => {
  for (const manager of ["js", "composer"] as const) {
    for (
      const [range, version, expected] of [
        ["^1.0.0 || ^2.0.0", "2.1.0", "^1.0.0 || ^2.0.0"],
        [">=1.0.0", "2.1.0", ">=1.0.0"],
        ["*", "2.1.0", "*"],
        ["x", "2.1.0", "x"],
        ["~7.4", "8.0.0", "~8.0"],
        ["~1", "2.0.0", "~2"],
        ["^1.2", "2.0.0", "^2.0"],
        ["^1", "2.0.0", "^2"],
        ["1.2", "2.0.0", "2.0"],
        ["1.x.x", "2.0.0", "2.x.x"],
        ["1.2", "1.2.0", "1.2"],
        ["^0", "0.5.0", "^0"],
        ["^0.0", "0.0.5", "^0.0"],
      ]
    ) {
      assertEquals(
        targetRange(range, { version, purpose: "major", manager }),
        expected,
      );
    }
    for (
      const range of [
        "1.0.0-beta",
        "^1.0.0+build",
        "V1.0.0",
        "^1.0.0 || ^2.0.0",
      ]
    ) {
      assertThrows(
        () =>
          targetRange(range, { version: "3.0.0", purpose: "major", manager }),
        Error,
        "Cannot preserve range style",
      );
    }
    assertEquals(
      targetRange(">=1.0.0", {
        version: "1.5.3",
        purpose: "security",
        manager,
      }),
      ">=1.5.3",
    );
    assertThrows(
      () =>
        targetRange("^1.2", { version: "1.2.3", purpose: "security", manager }),
      Error,
      "Cannot preserve range style",
    );
  }
  assertEquals(
    targetRange("~7.4", {
      version: "7.9.0",
      purpose: "major",
      manager: "composer",
    }),
    "~7.4",
  );
  assertEquals(
    targetRange("~7.4", { version: "7.9.0", purpose: "major", manager: "js" }),
    "~7.9",
  );
});

const mixedTargets = (manager: "js" | "composer"): UpdateTarget[] => [
  {
    path: ".",
    manager,
    package: manager === "js" ? "one" : "fixture/one",
    version: "1.5.0",
    purpose: "security",
    advisories: ["fixture-advisory"],
    advisoryUrls: ["https://example.test/advisory"],
  },
  {
    path: ".",
    manager,
    package: manager === "js" ? "two" : "fixture/two",
    version: "2.0.0",
    purpose: "major",
    advisories: [],
    advisoryUrls: [],
  },
];
const batchArgs = (root: string) => ({
  cwd: root,
  paths: ["."],
  policy: {},
  exceptions: emptyExceptions(),
});

for (const workspaces of [false, true]) {
  for (const moved of [false, true]) {
    Deno.test(`Bun ${workspaces ? "workspaces" : "root"}: mixed security/dev batch ${moved ? "refuses sync movement" : "restores ranges and lock metadata"}`, async () => {
      await fixture(async (root, log) => {
        const manifest = {
          packageManager: "bun@1.4.0",
          dependencies: { one: "^1.0.0" },
          devDependencies: { two: "~1.0.0" },
          ...(workspaces ? { workspaces: ["packages/*"] } : {}),
        };
        await Deno.writeTextFile(
          `${root}/package.json`,
          JSON.stringify(manifest),
        );
        await Deno.remove(`${root}/package-lock.json`);
        const packages = {
          one: ["one@1.0.0", "", {}],
          two: ["two@1.0.0", "", {}],
          ...(workspaces
            ? {
              local: ["local@workspace:packages/local"],
              "local/one": ["one@0.9.0"],
            }
            : {}),
        };
        const members = {
          "": {
            dependencies: manifest.dependencies,
            devDependencies: manifest.devDependencies,
          },
          ...(workspaces
            ? {
              "packages/local": {
                name: "local",
                dependencies: { one: "0.9.0" },
              },
            }
            : {}),
        };
        await Deno.writeTextFile(
          `${root}/bun.lock`,
          JSON.stringify({ lockfileVersion: 1, workspaces: members, packages }),
        );
        if (workspaces) {
          await Deno.mkdir(`${root}/packages/local`, { recursive: true });
          await Deno.writeTextFile(
            `${root}/packages/local/package.json`,
            JSON.stringify(members["packages/local"]),
          );
        }
        if (moved) {
          await Deno.writeTextFile(
            `${root}/move-version`,
            workspaces ? "nested" : "",
          );
          await assertRejects(
            () => jsBatch(batchArgs(root), mixedTargets("js")),
            Error,
            "Lockfile synchronization changed resolved versions",
          );
        } else {
          await jsBatch(batchArgs(root), mixedTargets("js"));
          const updated = JSON.parse(
            await Deno.readTextFile(`${root}/package.json`),
          );
          assertEquals(updated.dependencies.one, "^1.5.0");
          assertEquals(updated.devDependencies.two, "~2.0.0");
          const lock = JSON.parse(await Deno.readTextFile(`${root}/bun.lock`));
          assertEquals(lock.packages.one[0], "one@1.5.0");
          assertEquals(
            lock.workspaces[""].devDependencies,
            updated.devDependencies,
          );
          if (workspaces) {
            assertEquals(lock.packages.local, packages.local);
            assertEquals(lock.packages["local/one"], packages["local/one"]);
            assertEquals(
              lock.workspaces["packages/local"],
              members["packages/local"],
            );
          }
        }
        assertStringIncludes(
          await Deno.readTextFile(log),
          "bun install --lockfile-only --ignore-scripts",
        );
      });
    });
  }
}

Deno.test("Vite+ mixed security/dev batch uses npm for lock synchronization and retains the Vite+ audit", async () => {
  await fixture(async (root, log) => {
    await Deno.writeTextFile(
      `${root}/package.json`,
      JSON.stringify({
        dependencies: { one: "^1.0.0" },
        devDependencies: { two: "~1.0.0", "vite-plus": "1.4.0" },
        packageManager: "npm@11.12.0",
      }),
    );
    const lock = JSON.parse(
      await Deno.readTextFile(`${root}/package-lock.json`),
    );
    lock.packages["node_modules/vite-plus"] = { version: "1.4.0" };
    await Deno.writeTextFile(`${root}/package-lock.json`, JSON.stringify(lock));
    await Deno.mkdir(`${root}/node_modules/.bin`, { recursive: true });
    await Deno.copyFile(`${root}/../bin/npm`, `${root}/node_modules/.bin/vp`);
    await Deno.chmod(`${root}/node_modules/.bin/vp`, 0o755);
    await jsBatch(batchArgs(root), mixedTargets("js"));
    const updated = JSON.parse(await Deno.readTextFile(`${root}/package.json`));
    assertEquals(updated.devDependencies, {
      two: "~2.0.0",
      "vite-plus": "1.4.0",
    });
    assertEquals(updated.dependencies.one, "^1.5.0");
    assertStringIncludes(
      await Deno.readTextFile(log),
      "npm install --package-lock-only --ignore-scripts",
    );
    assertStringIncludes(await Deno.readTextFile(log), "vp pm audit --json");
  });
});

Deno.test("Composer mixed security/require-dev batch preserves sections and floors", async () => {
  await fixture(async (root) => {
    await Deno.writeTextFile(
      `${root}/composer.json`,
      JSON.stringify({
        require: { "fixture/one": "^1.0" },
        "require-dev": { "fixture/two": "~1.0" },
      }),
    );
    const lock = JSON.parse(await Deno.readTextFile(`${root}/composer.lock`));
    lock["packages-dev"].push(lock.packages.pop());
    await Deno.writeTextFile(`${root}/composer.lock`, JSON.stringify(lock));
    await composerBatch(batchArgs(root), mixedTargets("composer"));
    const updated = JSON.parse(
      await Deno.readTextFile(`${root}/composer.json`),
    );
    assertEquals(updated, {
      require: { "fixture/one": "^1.5" },
      "require-dev": { "fixture/two": "~2.0" },
    });
    const after = JSON.parse(await Deno.readTextFile(`${root}/composer.lock`));
    assertEquals(after["packages-dev"][0].version, "2.0.0");
    assertEquals(after.packages[0].version, "1.5.0");
  });
});

for (const unrelated of [false, true]) {
  Deno.test(`Expo alignment blocks only its SDK candidate${unrelated ? "; unrelated security stays eligible" : ""}`, async () => {
    await fixture(async (root, log) => {
      const manifest = {
        packageManager: "npm@11.12.0",
        dependencies: {
          expo: "1.0.0",
          react: "^1.0.0 || ^1.5.0",
          one: "^1.0.0",
        },
      };
      await Deno.writeTextFile(
        `${root}/package.json`,
        JSON.stringify(manifest),
      );
      const locked = JSON.parse(
        await Deno.readTextFile(`${root}/package-lock.json`),
      );
      Object.assign(locked.packages, {
        "node_modules/expo": { version: "1.0.0" },
        "node_modules/react": { version: "1.0.0" },
      });
      await Deno.writeTextFile(
        `${root}/package-lock.json`,
        JSON.stringify(locked),
      );
      const plan = await jsPlan(batchArgs(root), [
        {
          path: ".",
          manager: "js",
          package: "expo",
          current: "1.0.0",
          reasons: ["major"],
          advisories: [],
        },
        ...(unrelated
          ? [{
            path: ".",
            manager: "js" as const,
            package: "one",
            reasons: ["security"],
            advisories: [{
              id: "fixture-advisory",
              url: "https://example.test/advisory",
            }],
          }]
          : []),
      ]);
      assertEquals(
        plan.targets.map((t) => t.package),
        unrelated ? ["one"] : [],
      );
      assertEquals(plan.blocked.map((t) => t.package), ["expo"]);
      assert(
        plan.blocked.some((b) =>
          b.reason.includes("Cannot preserve range style")
        ),
      );
      assertEquals(
        JSON.parse(await Deno.readTextFile(`${root}/package.json`)),
        manifest,
      );
      if (unrelated) {
        await jsBatch(batchArgs(root), plan.targets);
        const updated = JSON.parse(
          await Deno.readTextFile(`${root}/package.json`),
        );
        assertEquals(updated.dependencies, {
          ...manifest.dependencies,
          one: "^2.0.0",
        });
      } else {assertEquals(
          (await Deno.readTextFile(log)).includes("npm install"),
          false,
        );}
    });
  });
}

import { registry } from "./fixtures/batch/registry.ts";
Deno.test("native npm batch restores a caret and passes npm ci consistency without scripts", async () => {
  const temp = await Deno.makeTempDir({ prefix: "native-batch-range-" });
  const server = await registry(temp);
  const root = `${temp}/checkout`;
  const npm = async (args: string[]) => {
    const result = await new Deno.Command("npm", {
      cwd: root,
      args,
      stdout: "piped",
      stderr: "piped",
      env: { npm_config_cache: `${temp}/cache` },
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
  };
  try {
    await Deno.mkdir(root);
    await Deno.writeTextFile(
      `${root}/.npmrc`,
      `registry=http://127.0.0.1:${server.addr.port}/\naudit=false\nfund=false\n`,
    );
    await Deno.writeTextFile(
      `${root}/package.json`,
      JSON.stringify({
        name: "native-batch",
        private: true,
        packageManager: "npm@11.12.0",
        dependencies: { "batch-range-fixture": "^1.0.0" },
      }),
    );
    await npm(["install", "--ignore-scripts"]);
    await jsBatch(batchArgs(root), [{
      path: ".",
      manager: "js",
      package: "batch-range-fixture",
      version: "2.0.0",
      purpose: "major",
      advisories: [],
      advisoryUrls: [],
    }]);
    assertEquals(
      JSON.parse(await Deno.readTextFile(`${root}/package.json`))
        .dependencies["batch-range-fixture"],
      "^2.0.0",
    );
    assertEquals(
      JSON.parse(await Deno.readTextFile(`${root}/package-lock.json`))
        .packages["node_modules/batch-range-fixture"].version,
      "2.0.0",
    );
    await npm([
      "ci",
      "--dry-run",
      "--ignore-scripts",
      "--no-audit",
      "--no-fund",
    ]);
  } finally {
    await server.shutdown();
    await Deno.remove(temp, { recursive: true });
  }
});

for (const manager of ["composer", "js"] as const) {
  Deno.test(`${manager}: the solver only sees security versions with a writable floor`, async () => {
    await fixture(async (root, log) => {
      const name = manager === "composer" ? "fixture/one" : "one";
      const file = `${root}/${
        manager === "composer" ? "composer.json" : "package.json"
      }`;
      const manifest = JSON.parse(await Deno.readTextFile(file));
      manifest[manager === "composer" ? "require" : "dependencies"][name] =
        "^1";
      await Deno.writeTextFile(file, JSON.stringify(manifest));
      await Deno.writeTextFile(`${root}/prefer-version`, "1.5.0");
      assert(
        (await new Deno.Command("git", {
          cwd: root,
          args: ["add", "prefer-version"],
        }).output()).success,
      );
      const plan = await (manager === "composer" ? composerPlan : jsPlan)(
        batchArgs(root),
        [{
          path: ".",
          manager,
          package: name,
          reasons: ["security"],
          advisories: [{
            id: "fixture-advisory",
            url: "https://example.test/advisory",
          }],
        }],
      );
      assertEquals(plan.blocked, []);
      assertEquals(plan.targets.map((t) => t.version), ["2.0.0"]);
      assertEquals(
        (await Deno.readTextFile(log)).includes("2.0.0 || 1.5.0"),
        false,
      );
      await (manager === "composer" ? composerBatch : jsBatch)(
        batchArgs(root),
        plan.targets,
      );
      const updated = JSON.parse(await Deno.readTextFile(file));
      assertEquals(
        updated[manager === "composer" ? "require" : "dependencies"][name],
        "^2",
      );
    });
  });
}

Deno.test("range validation runs once for each age-eligible major option, excluding minor-only releases", async () => {
  const checked: string[] = [];
  const rows = await writableReleases(
    {
      path: ".",
      manager: "js",
      package: "one",
      current: "1.0.0",
      reasons: ["major"],
      advisories: [],
    },
    { ".": { minimumReleaseAgeDays: 7 } },
    [
      { version: "1.5.0", publishedAt: "2020-01-01T00:00:00Z" },
      { version: "2.0.0", publishedAt: new Date().toISOString() },
      { version: "3.0.0", publishedAt: "2020-01-01T00:00:00Z" },
    ],
    (version) => {
      checked.push(version);
      if (version !== "3.0.0") throw new Error("ineligible target was checked");
    },
  );
  assertEquals(checked, ["3.0.0"]);
  assertEquals(rows.map((r) => r.version), ["3.0.0"]);
});

import { MaintenanceFailure } from "../extensions/behaviors/_lib/maintenance/io.ts";
const expoCandidate = {
  path: ".",
  manager: "js" as const,
  package: "expo",
  reasons: ["major"],
  advisories: [],
};
async function expoProject(
  root: string,
  range = "^1.0.0",
  reactVersions = ["2.0.0", "3.0.0"],
) {
  const manifest = {
    packageManager: "npm@11.19.1",
    dependencies: { one: "1.0.0", expo: "1.0.0", react: range },
  };
  await Deno.writeTextFile(`${root}/package.json`, JSON.stringify(manifest));
  await Deno.writeTextFile(
    `${root}/package-lock.json`,
    JSON.stringify({
      lockfileVersion: 3,
      packages: {
        "": { dependencies: manifest.dependencies },
        "node_modules/one": { version: "1.0.0" },
        "node_modules/expo": { version: "1.0.0" },
        "node_modules/react": { version: "1.0.0" },
      },
    }),
  );
  const rows = (versions: string[]) => ({
    versions,
    time: Object.fromEntries(versions.map((v) => [v, "2020-01-01T00:00:00Z"])),
  });
  await Deno.writeTextFile(
    `${root}/registry-fixture.json`,
    JSON.stringify({
      expo: rows(["2.0.0", "3.0.0"]),
      react: rows(reactVersions),
    }),
  );
  assert(
    (await new Deno.Command("git", { cwd: root, args: ["add", "."] }).output())
      .success,
  );
  return manifest;
}
for (const command of ["pack", "view"]) {
  Deno.test(`Expo ${command} infrastructure failure aborts planning without choosing an older SDK`, async () => {
    await fixture(async (root, log) => {
      const manifest = await expoProject(root);
      await Deno.writeTextFile(
        `${root}/fail-${command}`,
        command === "pack" ? "3.0.0" : "react",
      );
      const error = await assertRejects(
        () => jsPlan(batchArgs(root), [expoCandidate]),
        MaintenanceFailure,
        command === "pack"
          ? "Cannot read the Expo SDK"
          : "Cannot read registry target metadata",
      );
      assertEquals(error.exitCode, 70);
      const calls = await Deno.readTextFile(log);
      assertEquals(calls.includes("npm pack expo@2.0.0"), false);
      assertEquals(calls.includes("npm install"), false);
      assertEquals(
        JSON.parse(await Deno.readTextFile(`${root}/package.json`)),
        manifest,
      );
    });
  });
}
for (const reason of ["range", "no eligible alignment"]) {
  Deno.test(`Expo falls back after ${reason}, with one metadata read per path/package/SDK per plan`, async () => {
    await fixture(async (root, log) => {
      const manifest = await expoProject(
        root,
        reason === "range" ? "^1.0.0 || ^2.0.0" : "^1.0.0",
        reason === "range" ? ["2.0.0", "3.0.0"] : ["2.0.0"],
      );
      for (let run = 1; run <= 2; run++) {
        const plan = await jsPlan(batchArgs(root), [expoCandidate]);
        assertEquals(plan.blocked, []);
        assertEquals(plan.targets.map((t) => [t.package, t.version]), [[
          "expo",
          "2.0.0",
        ], ["react", "2.0.0"]]);
        const calls = (await Deno.readTextFile(log)).split("\n");
        for (
          const prefix of [
            "npm view expo ",
            "npm view react ",
            "npm pack expo@3.0.0 ",
            "npm pack expo@2.0.0 ",
          ]
        ) {
          assertEquals(
            calls.filter((line) => line.startsWith(prefix)).length,
            run,
            prefix,
          );
        }
        assertEquals(
          JSON.parse(await Deno.readTextFile(`${root}/package.json`)),
          manifest,
        );
      }
    });
  });
}
Deno.test("writable selection propagates infrastructure, unknown errors and aborts without checking older versions", async () => {
  for (
    const error of [
      new MaintenanceFailure("registry unavailable"),
      new Error("unexpected failure"),
      new DOMException("cancelled", "AbortError"),
    ]
  ) {
    const checked: string[] = [];
    const caught = await assertRejects(() =>
      writableReleases(expoCandidate, {}, [
        { version: "3.0.0", publishedAt: "2020-01-01T00:00:00Z" },
        { version: "2.0.0", publishedAt: "2020-01-01T00:00:00Z" },
      ], (version) => {
        checked.push(version);
        throw error;
      })
    );
    assertEquals(caught, error);
    assertEquals(checked, ["3.0.0"]);
  }
});
Deno.test("blocked writable selection retains distinct reasons from the newest options", async () => {
  const error = await assertRejects(() =>
    writableReleases(expoCandidate, {}, [
      { version: "3.0.0", publishedAt: "2020-01-01T00:00:00Z" },
      { version: "2.0.0", publishedAt: "2020-01-01T00:00:00Z" },
    ], (version) => {
      throw new UnwritableTarget(
        version === "3.0.0"
          ? "Cannot preserve React range"
          : "No eligible React release",
      );
    }), UnwritableTarget);
  assertStringIncludes(
    error.message,
    "expo 3.0.0: Cannot preserve React range",
  );
  assertStringIncludes(error.message, "expo 2.0.0: No eligible React release");
});

Deno.test("legacy capitalized npm names stay eligible beside another package and preserve their spelling", async () => {
  await fixture(async (root) => {
    const path = `${root}/package.json`;
    const manifest = JSON.parse(await Deno.readTextFile(path));
    manifest.dependencies.JSONStream = "^1.0.0";
    delete manifest.dependencies.two;
    await Deno.writeTextFile(path, JSON.stringify(manifest));
    const lock = JSON.parse(
      await Deno.readTextFile(`${root}/package-lock.json`),
    );
    lock.packages[""].dependencies = manifest.dependencies;
    lock.packages["node_modules/JSONStream"] = { version: "1.0.0" };
    delete lock.packages["node_modules/two"];
    await Deno.writeTextFile(`${root}/package-lock.json`, JSON.stringify(lock));
    const plan = await jsPlan(
      batchArgs(root),
      ["one", "JSONStream"].map((packageName) => ({
        path: ".",
        manager: "js",
        package: packageName,
        reasons: ["major"],
        advisories: [],
      })),
    );
    assertEquals(plan.blocked, []);
    assertEquals(plan.targets.map((t) => t.package).sort(), [
      "JSONStream",
      "one",
    ]);
    await jsBatch(batchArgs(root), plan.targets);
    const updated = JSON.parse(await Deno.readTextFile(path));
    assertEquals(updated.dependencies.JSONStream, "^2.0.0");
  });
});

Deno.test("invalid target data blocks only its candidate with the schema issue", async () => {
  const plan = await planBatch(
    ["bad/name", "one"].map((packageName) => ({
      path: ".",
      manager: "js",
      package: packageName,
      current: "1.0.0",
      reasons: ["major"],
      advisories: [],
    })),
    {},
    () =>
      Promise.resolve([{
        version: "2.0.0",
        publishedAt: "2020-01-01T00:00:00Z",
      }]),
    (choices) => Promise.resolve({ targets: choices.map((c) => c[0]) }),
  );
  assertEquals(plan.targets.map((t) => t.package), ["one"]);
  assertEquals(plan.blocked.length, 1);
  assertEquals(plan.blocked[0].package, "bad/name");
  assertStringIncludes(plan.blocked[0].reason, "invalid JS package");
});

for (
  const [label, response] of [
    ["E404", { error: { code: "E404", summary: "Not Found" } }],
    ["detail", { error: { detail: "'two' is not in this registry." } }],
    ["stderr", { _stderr: "npm ERR! code E404\nnpm ERR! package not found" }],
  ] as const
) {
  Deno.test(`npm registry miss ${label} blocks only that package beside a healthy candidate`, async () => {
    await fixture(async (root) => {
      await Deno.writeTextFile(
        `${root}/registry-fixture.json`,
        JSON.stringify({ two: response }),
      );
      const plan = await jsPlan(
        batchArgs(root),
        ["two", "one"].map((packageName) => ({
          path: ".",
          manager: "js",
          package: packageName,
          reasons: ["major"],
          advisories: [],
        })),
      );
      assertEquals(plan.targets.map((t) => t.package), ["one"]);
      assertEquals(plan.blocked.length, 1);
      assertEquals(plan.blocked[0].package, "two");
      assertStringIncludes(plan.blocked[0].reason, "not found in registry");
    });
  });
}
for (const code of ["E500", "E502", "ETIMEDOUT", "ECONNRESET"]) {
  Deno.test(`npm ${code} still aborts planning instead of blocking one candidate`, async () => {
    await fixture(async (root) => {
      await Deno.writeTextFile(
        `${root}/registry-fixture.json`,
        JSON.stringify({ two: { error: { code } } }),
      );
      const error = await assertRejects(
        () =>
          jsPlan(
            batchArgs(root),
            ["two", "one"].map((packageName) => ({
              path: ".",
              manager: "js",
              package: packageName,
              reasons: ["major"],
              advisories: [],
            })),
          ),
        MaintenanceFailure,
        "Cannot read registry target metadata",
      );
      assertEquals(error.exitCode, 70);
    });
  });
}
