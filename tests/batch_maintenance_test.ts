import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  batch as composerBatch,
  plan as composerPlan,
} from "../extensions/behaviors/composer-maintenance/_lib/batch.ts";
import {
  batch as jsBatch,
  plan as jsPlan,
} from "../extensions/behaviors/js-maintenance/_lib/batch.ts";
import {
  scratch,
  type UpdateTarget,
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
    await Deno.mkdir(`${temp}/sdk/package`, { recursive: true });
    await Deno.writeTextFile(
      `${temp}/sdk/package/bundledNativeModules.json`,
      JSON.stringify({ react: "2.0.0" }),
    );
    assert(
      (await new Deno.Command("tar", {
        args: ["-czf", `${temp}/expo.tgz`, "-C", `${temp}/sdk`, "package"],
        stdout: "null",
        stderr: "piped",
      }).output()).success,
    );
    const source = `#!/usr/bin/env php
<?php
file_put_contents(${
      JSON.stringify(log)
    },basename($argv[0]).' '.implode(' ',array_slice($argv,1))."\\n",FILE_APPEND);
$name=basename($argv[0]);
if($name==='npm' && $argv[1]==='pack'){$i=array_search('--pack-destination',$argv);copy(${
      JSON.stringify(temp + "/expo.tgz")
    },$argv[$i+1].'/expo-2.0.0.tgz');echo '[{"filename":"expo-2.0.0.tgz"}]';exit;}
if($name==='npm' && $argv[1]==='view'){echo json_encode(['versions'=>['2.0.0'],'time'=>['2.0.0'=>'2020-01-01T00:00:00Z']]);exit;}
if($name==='composer' && $argv[1]==='update'){$m=json_decode(file_get_contents('composer.json'),true);$rows=[];foreach($m['require'] as $n=>$v)$rows[]=['name'=>$n,'version'=>$v];file_put_contents('composer.lock',json_encode(['packages'=>$rows,'packages-dev'=>[]]));exit;}
if($name==='npm' && $argv[1]==='install'){$m=json_decode(file_get_contents('package.json'),true);$rows=[''=>['dependencies'=>$m['dependencies']]];foreach($m['dependencies'] as $n=>$v)$rows['node_modules/'.$n]=['version'=>$v];file_put_contents('package-lock.json',json_encode(['lockfileVersion'=>3,'packages'=>$rows]));exit;}
if($argv[1]==='audit'){
if($name==='composer'){$lock=json_decode(file_get_contents('composer.lock'),true);$v=$lock['packages'][0]['version'];echo json_encode(['advisories'=>$v==='1.0.0'?['fixture/one'=>[['advisoryId'=>'fixture-advisory','link'=>'https://example.test/advisory','title'=>'fixture','affectedVersions'=>'<2']]]:[]]);}
else{$lock=json_decode(file_get_contents('package-lock.json'),true);$v=$lock['packages']['node_modules/one']['version'];echo json_encode(['vulnerabilities'=>$v==='1.0.0'?['one'=>['via'=>[['source'=>'fixture-advisory','url'=>'https://example.test/advisory','title'=>'fixture','range'=>'<2']]] ]:(object)[]]);}exit;}

fwrite(STDERR,'unexpected command');exit(64);
`;
    for (const name of ["composer", "npm"]) {
      await Deno.writeTextFile(`${bin}/${name}`, source);
      await Deno.chmod(`${bin}/${name}`, 0o755);
    }
    Deno.env.set("PATH", `${bin}:${oldPath}`);
    globalThis.fetch = () =>
      Promise.resolve(
        Response.json({
          packages: {
            "fixture/one": [{ version: "2.0.0", time: "2020-01-01T00:00:00Z" }],
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
