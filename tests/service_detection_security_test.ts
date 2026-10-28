import { dirname } from "node:path";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { serviceWorkspace } from "../extensions/behaviors/_lib/service_workspace.ts";
import { command } from "../extensions/behaviors/_lib/maintenance/io.ts";
import {
  toolOptions,
  withServiceTools,
} from "../extensions/behaviors/_lib/maintenance/tool_policy.ts";
import {
  batchEvidence,
  githubPr,
} from "../extensions/behaviors/_lib/service_detection.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";

async function fixture(files: Record<string, unknown>) {
  const root = await Deno.makeTempDir({ prefix: "hostile-config-" });
  for (const [name, data] of Object.entries(files)) {
    await Deno.mkdir(dirname(`${root}/${name}`), { recursive: true });
    await Deno.writeTextFile(
      `${root}/${name}`,
      typeof data === "string" ? data : JSON.stringify(data),
    );
  }
  await command(root, "git", ["init", "--quiet"]);
  await command(root, "git", ["add", "."]);
  return { root, close: () => Deno.remove(root, { recursive: true }) };
}
Deno.test("Services scratch excludes repo config/code and overrides npm env and home config", async () => {
  const f = await fixture({
    ".npmrc": "registry=http://10.44.0.1\ngit=./tools/g\n",
    "bunfig.toml": '[install]\nregistry="http://127.0.0.1"',
    "tools/g": "touch /tmp/should-not-run",
    "package.json": {
      name: "app",
      scripts: { prepare: "./tools/g" },
      devEngines: { runtime: { onFail: "error" } },
      dependencies: { foo: "1.0.0" },
    },
    "package-lock.json": { lockfileVersion: 3, packages: {} },
  });
  try {
    const tree = await serviceWorkspace(f.root);
    try {
      for (const name of [".npmrc", "bunfig.toml", "tools"]) {
        await assertRejects(
          () => Deno.stat(`${tree.root}/${name}`),
          Deno.errors.NotFound,
        );
      }
      const pkg = JSON.parse(
        await Deno.readTextFile(`${tree.root}/package.json`),
      );
      assertEquals(pkg.scripts, undefined);
      assertEquals(pkg.devEngines, undefined);
      await withServiceTools(tree.policy, async () => {
        const opts = toolOptions("npm", [], {
          PATH: Deno.env.get("PATH")!,
          npm_config_git: "./tools/g",
          npm_config_registry: "http://10.44.0.1",
          HOME: f.root,
        });
        for (const binary of ["npm", "bun", "composer"]) {
          const env = toolOptions(binary, [], {
            GH_TOKEN: "ephemeral_sentinel",
            ORBIT_GIT_TOKEN: "ephemeral_sentinel",
            GIT_ASKPASS: "helper",
          }).env;
          assert(!JSON.stringify(env).includes("ephemeral_sentinel"));
          assert(!("GIT_ASKPASS" in env));
        }
        assertEquals(opts.env.npm_config_git, "git");
        assertEquals(
          opts.env.npm_config_registry,
          "https://registry.npmjs.org/",
        );
        assertEquals(opts.env.HOME, tree.policy.home);
        const result = await command(tree.root, "npm", [
          "config",
          "get",
          "registry",
        ]);
        assertEquals(result.code, 0);
        assertEquals(result.stdout.trim(), "https://registry.npmjs.org/");
        const git = await command(tree.root, "npm", ["config", "get", "git"]);
        assertEquals(git.stdout.trim(), "git");
        assertEquals(toolOptions("bun", ["outdated"], {}).args, [
          "--config",
          tree.policy.bunfig,
          "outdated",
        ]);
      });
    } finally {
      await tree.close();
    }
  } finally {
    await f.close();
  }
});
for (
  const repositories of [[{ type: "vcs", url: "http://10.44.0.1/repo" }], [{
    type: "path",
    url: "/home/swamp",
  }], [{ type: "composer", url: "https://127.0.0.1" }]]
) {
  Deno.test(`Services refuses hostile Composer repositories ${JSON.stringify(repositories)}`, async () => {
    const f = await fixture({ "composer.json": { repositories } });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  });
}
for (
  const dep of [
    "git+https://github.com/org/pkg.git",
    "file:../../etc",
    "http://10.44.0.1/pkg.tgz",
    "https://localhost/pkg.tgz",
    "git@github.com:org/pkg.git",
    "a/b/../../../../../../../../../../../../tmp/os49-probe/outside/x.tgz",
    "x.tgz",
    "x.tar.gz",
    "npm:pkg@x.tgz",
    "npm:pkg@file:../../etc",
    "file:..%2f..%2fetc",
    "link:../../etc",
    "workspace:../../etc",
    "unrecognized/protocol/target",
  ]
) {
  Deno.test(`Services refuses unsafe JS resolution ${dep}`, async () => {
    const f = await fixture({ "package.json": { dependencies: { pkg: dep } } });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  });
}
Deno.test("Services rejects private endpoints in registry lock metadata", async () => {
  const f = await fixture({
    "package.json": {},
    "package-lock.json": {
      packages: {
        "node_modules/a": {
          version: "1.0.0",
          resolved: "https://10.44.0.1/a.tgz",
        },
      },
    },
  });
  try {
    await assertRejects(
      () => serviceWorkspace(f.root),
      Error,
      "unsupported_dependency_endpoint",
    );
  } finally {
    await f.close();
  }
});

for (const field of ["resolved", "version"]) {
  for (const value of ["a/../../../../home/x.tgz", "x.tgz"]) {
    Deno.test(`npm lock ${field} refuses unprefixed host path ${value}`, async () => {
      const f = await fixture({
        "package.json": {},
        "package-lock.json": {
          lockfileVersion: 3,
          packages: { "node_modules/x": { [field]: value } },
        },
      });
      try {
        await assertRejects(() => serviceWorkspace(f.root));
      } finally {
        await f.close();
      }
    });
  }
}
Deno.test("Services accepts only registry selectors and normalized internal package paths", async () => {
  const f = await fixture({
    "package.json": {
      dependencies: {
        one: "^1.2.3 || >=2.0.0-beta.1 <3",
        two: "latest",
        three: "npm:@scope/pkg@^1.0.0",
        four: "npm:pkg@next",
        five: "https://registry.npmjs.org/five/-/five-1.0.0.tgz",
        local: "file:./packages/unused/../local",
        linked: "link:packages/local",
        relative: "./packages/local",
        workspace: "workspace:*",
      },
    },
    "packages/local/package.json": { name: "local", version: "1.0.0" },
    "package-lock.json": {
      lockfileVersion: 3,
      packages: {
        "node_modules/five": {
          version: "1.0.0",
          resolved: "https://registry.npmjs.org/five/-/five-1.0.0.tgz",
        },
        "node_modules/local": { resolved: "packages/local", link: true },
      },
    },
  });
  try {
    const tree = await serviceWorkspace(f.root);
    await tree.close();
  } finally {
    await f.close();
  }
});

Deno.test("Bun git-prefixed package names and workspace descriptors are not URLs", async () => {
  const f = await fixture({
    "package.json": { name: "app", workspaces: ["packages/*"] },
    "packages/github-app/package.json": {
      name: "github-app",
      version: "1.0.0",
    },
    "bun.lock": {
      lockfileVersion: 1,
      workspaces: { "packages/github-app": { name: "github-app" } },
      packages: {
        "github-slugger": ["github-slugger@2.0.0", "", {}, "sha"],
        "git-url-parse": ["git-url-parse@16.1.0", "", {}, "sha"],
        gitmoji: ["gitmoji@3.15.0", "", {}, "sha"],
        "github-app": ["github-app@workspace:packages/github-app"],
      },
    },
  });
  try {
    const tree = await serviceWorkspace(f.root);
    await tree.close();
  } finally {
    await f.close();
  }
});
for (const url of ["https://10.44.0.1/x.tgz", "a/../../../../home/x.tgz"]) {
  Deno.test(`Bun separate tarball location is validated ${url}`, async () => {
    const f = await fixture({
      "package.json": {},
      "bun.lock": {
        lockfileVersion: 1,
        packages: { one: ["one@1.0.0", url, {}, "sha"] },
      },
    });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  });
}

const tokenFixture = {
  project_id: 20,
  repository: "github.com/nckrtl/website",
  clone_url: "https://github.com/nckrtl/website.git",
  token: "ephemeral_sentinel",
  expires_at: new Date(Date.now() + 3600000).toISOString(),
};
Deno.test("gh uses only a scan token outside tool HOME; missing tokens retain last good PR", async () => {
  const root = await Deno.makeTempDir({ prefix: "gh-account-" });
  const saved = Object.fromEntries(
    [
      "PATH",
      "HOME",
      "XDG_CONFIG_HOME",
      "GH_TOKEN",
      "GITHUB_TOKEN",
      "GH_CONFIG_DIR",
    ].map((
      key,
    ) => [key, Deno.env.get(key)]),
  );
  const tree = await fixture({ "package.json": {} });
  const scratch = await serviceWorkspace(tree.root);
  try {
    const config = `${root}/.config/gh`;
    await Deno.mkdir(config, { recursive: true });
    // Synthetic credential/config marker. No real secret is read or logged.
    await Deno.writeTextFile(`${config}/hosts.yml`, "fixture credential");
    await Deno.writeTextFile(
      `${root}/gh`,
      `#!/usr/bin/env python3
import json,os,pathlib,sys
assert os.environ['GH_CONFIG_DIR'] == os.environ['HOME']
assert os.environ['GH_PROMPT_DISABLED'] == '1'
assert os.environ['GH_TOKEN'] == 'ephemeral_sentinel'
assert 'GITHUB_TOKEN' not in os.environ
assert os.environ['HOME'] != ${JSON.stringify(scratch.policy.home)}
assert not (pathlib.Path(os.environ['GH_CONFIG_DIR']) / 'hosts.yml').exists()
print(json.dumps({'url':sys.argv[3],'number':17,'headRefOid':'a'*40,'state':'OPEN'}))
`,
    );
    await Deno.chmod(`${root}/gh`, 0o755);
    Deno.env.set("PATH", `${root}:${saved.PATH}`);
    Deno.env.set("HOME", root);
    Deno.env.delete("XDG_CONFIG_HOME");
    Deno.env.set("GH_TOKEN", "must-not-be-forwarded");
    Deno.env.set("GITHUB_TOKEN", "must-not-be-forwarded");
    Deno.env.set("GH_CONFIG_DIR", scratch.policy.home);
    const url = "https://github.com/nckrtl/website/pull/17";
    const ctx = {
      definition: { name: "orbit-gateway" },
      queryData: (q: string) =>
        Promise.resolve(
          q.includes('startsWith("package-')
            ? [{ workItem: "tg-2195", targets: [] }]
            : q.includes('name == "owner"')
            ? [{ runId: "batch-run" }]
            : [],
        ),
      writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
      runModel: () => Promise.resolve({ ok: true }),
    };
    const http = () =>
      Promise.resolve({
        status: 200,
        body: {
          data: [{
            project_id: 20,
            id: 2195,
            status: "running",
            pr_url: url,
            watched_pr_url: url,
          }],
        },
      });
    const good = await batchEvidence(
      WEBSITE_PROJECT,
      null,
      ctx,
      http,
      (url) => githubPr(url, tokenFixture),
    );
    assertEquals(good.pr.status, "open");
    await Deno.remove(`${config}/hosts.yml`);
    await assertRejects(
      () => githubPr(url, { ...tokenFixture, token: "" }),
      Error,
      "token_missing",
    );
    const missing = await batchEvidence(
      WEBSITE_PROJECT,
      good.pr,
      ctx,
      http,
      (url) => githubPr(url, { ...tokenFixture, token: "" }),
    );
    assertEquals(missing.pr.status, "unknown");
    assertEquals(missing.pr.error_code, "token_missing");
    assertEquals(missing.pr.last_success_at, good.pr.last_success_at);
    assertEquals(missing.pr.value, good.pr.value);
    // Exercise installed gh too: an empty config cannot authenticate even if
    // the parent holds tokens. gh refuses this before making a network request.
    Deno.env.set("PATH", saved.PATH!);
    await assertRejects(
      () => githubPr(url, { ...tokenFixture, token: "" }),
      Error,
      "token_missing",
    );
    const realMissing = await batchEvidence(
      WEBSITE_PROJECT,
      good.pr,
      ctx,
      http,
      (url) => githubPr(url, { ...tokenFixture, token: "" }),
    );
    assertEquals(realMissing.pr.status, "unknown");
    assertEquals(realMissing.pr.last_success_at, good.pr.last_success_at);
    assertEquals(realMissing.pr.value, good.pr.value);
  } finally {
    for (const [key, value] of Object.entries(saved)) {
      if (value === undefined) Deno.env.delete(key);
      else Deno.env.set(key, value);
    }
    await scratch.close();
    await tree.close();
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("Services preserves internal manifest-only local package relationships", async () => {
  const f = await fixture({
    "app/package.json": { dependencies: { local: "file:../local" } },
    "local/package.json": { name: "local", scripts: { prepare: "./bad" } },
    "local/bad": "exit 1",
  });
  try {
    const t = await serviceWorkspace(f.root);
    try {
      assertEquals(
        JSON.parse(await Deno.readTextFile(`${t.root}/app/package.json`))
          .dependencies.local,
        "file:../local",
      );
      await assertRejects(() => Deno.stat(`${t.root}/local/bad`));
    } finally {
      await t.close();
    }
  } finally {
    await f.close();
  }
});

// These decoys make path.resolve pass: npm's file URL/home rules must still refuse them.
const localDecoys = {
  "C:/x/package.json": { name: "x", version: "1.0.0" },
  "C|/x/package.json": { name: "x", version: "1.0.0" },
  "~/x/package.json": { name: "x", version: "1.0.0" },
  "~/package.json": { name: "x", version: "1.0.0" },
};
for (
  const spec of [
    "file:C:/x",
    "file:a/../C:/x",
    "file:C|/x",
    "file:a/../C|/x",
    "file:~/x",
    "./~/x",
    "file:~",
    "file:a/../~/x",
  ]
) {
  Deno.test(`file URL rules refuse drive/home dependency ${spec} despite in-tree decoy`, async () => {
    const f = await fixture({
      ...localDecoys,
      "package.json": { dependencies: { x: spec } },
    });
    try {
      await assertRejects(
        () => serviceWorkspace(f.root),
        Error,
        "local_dependency_escape",
      );
    } finally {
      await f.close();
    }
  });
}
Deno.test("nested manifest cannot expand a parent tilde segment into tool HOME", async () => {
  const f = await fixture({
    ...localDecoys,
    "nested/package.json": { dependencies: { x: "../~/x" } },
  });
  try {
    await assertRejects(
      () => serviceWorkspace(f.root),
      Error,
      "local_dependency_escape",
    );
  } finally {
    await f.close();
  }
});
for (
  const entry of [
    { resolved: "file:C:/x" },
    { resolved: "C:/x", link: true },
    { resolved: "file:a/../C:/x" },
    { resolved: "file:~/x" },
    { resolved: "file:~" },
    { resolved: "~/x", link: true },
  ]
) {
  Deno.test(`npm package entry cannot reinterpret local resolution ${JSON.stringify(entry)}`, async () => {
    const f = await fixture({
      ...localDecoys,
      "package.json": {},
      "package-lock.json": {
        lockfileVersion: 3,
        packages: { "node_modules/x": entry },
      },
    });
    try {
      await assertRejects(
        () => serviceWorkspace(f.root),
        Error,
        "local_dependency_escape",
      );
    } finally {
      await f.close();
    }
  });
}
for (const spec of ["file:C:/x", "file:a/../C:/x", "file:~/x"]) {
  Deno.test(`Bun descriptor cannot reinterpret local resolution ${spec}`, async () => {
    const f = await fixture({
      ...localDecoys,
      "package.json": {},
      "bun.lock": { lockfileVersion: 2, packages: { x: [`x@${spec}`, {}] } },
    });
    try {
      await assertRejects(
        () => serviceWorkspace(f.root),
        Error,
        "local_dependency_escape",
      );
    } finally {
      await f.close();
    }
  });
}
Deno.test("real Bun 1.4 folder and remote-tarball tuples preserve object metadata", async () => {
  const dir = new URL("./fixtures/service-workspace-bun/", import.meta.url);
  const f = await fixture({
    "package.json": await Deno.readTextFile(new URL("package.json", dir)),
    "bun.lock": await Deno.readTextFile(new URL("bun.lock", dir)),
    "l/package.json": await Deno.readTextFile(
      new URL("local-package.json", dir),
    ),
  });
  try {
    const tree = await serviceWorkspace(f.root);
    try {
      assertEquals(
        await Deno.readTextFile(`${tree.root}/bun.lock`),
        await Deno.readTextFile(new URL("bun.lock", dir)),
      );
    } finally {
      await tree.close();
    }
  } finally {
    await f.close();
  }
});
Deno.test("accepted local file URL resolves to the same directory through real npm 11", async () => {
  const f = await fixture({
    "package.json": {
      name: "app",
      private: true,
      dependencies: { l: "file:a/../l" },
      overrides: { l: "$l" },
    },
    "l/package.json": { name: "l", version: "1.0.0" },
  });
  try {
    const tree = await serviceWorkspace(f.root);
    try {
      const out = await withServiceTools(
        tree.policy,
        () =>
          command(tree.root, "npm", [
            "install",
            "--package-lock-only",
            "--ignore-scripts",
            "--offline",
            "--no-audit",
            "--no-fund",
          ], AbortSignal.timeout(10000)),
      );
      assertEquals(out.code, 0, out.stderr);
      const lock = JSON.parse(
        await Deno.readTextFile(`${tree.root}/package-lock.json`),
      );
      assertEquals(lock.packages["node_modules/l"].resolved, "l");
      assertEquals(lock.packages["node_modules/l"].link, true);
    } finally {
      await tree.close();
    }
  } finally {
    await f.close();
  }
});
Deno.test("npm's actual file-spec parser resolves direct drive and tilde forms outside the manifest tree", async () => {
  const f = await fixture({ ...localDecoys, "package.json": {} });
  try {
    const modules = await command(
      f.root,
      "npm",
      ["root", "--global"],
      AbortSignal.timeout(10000),
    );
    assertEquals(modules.code, 0);
    const cases = [
      ["file:C:/x", f.root],
      ["file:~/x", f.root],
      ["./~/x", f.root],
      ["file:~", f.root],
      ["file:a/../~/x", f.root],
      ["../~/x", `${f.root}/nested`],
    ];
    const out = await command(f.root, "node", [
      "-e",
      "const npa=require(process.argv[1]+'/npm/node_modules/npm-package-arg');console.log(JSON.stringify(JSON.parse(process.argv[2]).map(([spec,dir])=>npa.resolve('x',spec,dir).fetchSpec)))",
      modules.stdout.trim(),
      JSON.stringify(cases),
    ], AbortSignal.timeout(10000));
    assertEquals(out.code, 0, out.stderr);
    const paths: string[] = JSON.parse(out.stdout);
    assertEquals(paths.length, cases.length);
    for (const path of paths) assert(!path.startsWith(`${f.root}/`));
  } finally {
    await f.close();
  }
});
Deno.test("empty selectors, Bun catalogs and contextual npm metadata are valid", async () => {
  const f = await fixture({
    "package.json": {
      dependencies: { one: "", two: "catalog:", three: "catalog:testing" },
      peerDependencies: { peer: "^1.0.0" },
      workspaces: {
        packages: [],
        catalog: { two: "^1.0.0" },
        catalogs: { testing: { three: "^2.0.0" } },
      },
      overrides: { one: "$one", other: { three: "$three", peer: "$peer" } },
    },
    "package-lock.json": {
      lockfileVersion: 3,
      packages: {
        "node_modules/one": {
          version: "1.0.0",
          bin: { version: "bin/cli.js", resolved: "bin/resolved.js" },
        },
      },
    },
  });
  try {
    const tree = await serviceWorkspace(f.root);
    await tree.close();
  } finally {
    await f.close();
  }
});
for (const section of ["dependencies", "resolutions", "catalog"]) {
  Deno.test(`override references are refused in ${section}`, async () => {
    const f = await fixture({ "package.json": { [section]: { one: "$one" } } });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  });
}
Deno.test("catalog definitions cannot hide a home path or private registry", async () => {
  for (const spec of ["file:~/x", "http://10.44.0.1/x.tgz"]) {
    const f = await fixture({
      ...localDecoys,
      "package.json": { workspaces: { catalog: { one: spec } } },
    });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  }
});
Deno.test("Bun lock catalogs cannot hide a local escape behind safe package descriptors", async () => {
  const f = await fixture({
    ...localDecoys,
    "package.json": {},
    "bun.lock": {
      lockfileVersion: 2,
      catalog: { x: "file:C:/x" },
      packages: { x: ["x@1.0.0", "", {}, "sha"] },
    },
  });
  try {
    await assertRejects(
      () => serviceWorkspace(f.root),
      Error,
      "local_dependency_escape",
    );
  } finally {
    await f.close();
  }
});
Deno.test("hung gh is bounded and cancellation aborts before spawning", async () => {
  const root = await Deno.makeTempDir({ prefix: "hung-gh-" }),
    old = Deno.env.get("PATH")!;
  try {
    await Deno.writeTextFile(
      `${root}/gh`,
      "#!/usr/bin/env python3\nimport signal,time\nsignal.signal(signal.SIGTERM,signal.SIG_IGN)\ntime.sleep(30)\n",
    );
    await Deno.chmod(`${root}/gh`, 0o755);
    Deno.env.set("PATH", `${root}:${old}`);
    const start = Date.now();
    await assertRejects(() =>
      githubPr(
        "https://github.com/nckrtl/website/pull/1",
        tokenFixture,
        undefined,
        50,
      )
    );
    assert(Date.now() - start < 2000);
    await assertRejects(() =>
      githubPr(
        "https://github.com/nckrtl/website/pull/1",
        tokenFixture,
        AbortSignal.abort(),
      )
    );
  } finally {
    Deno.env.set("PATH", old);
    await Deno.remove(root, { recursive: true });
  }
});

for (
  const raw of [
    "one@git+https://10.44.0.1/repo.git#" + "a".repeat(40),
    "one@https://10.44.0.1/one.tgz#" + "a".repeat(40),
    "one@file:../../etc",
  ]
) {
  Deno.test(`Bun descriptor cannot hide a private/local endpoint ${raw}`, async () => {
    const f = await fixture({
      "package.json": { name: "app" },
      "bun.lock": {
        lockfileVersion: 1,
        packages: { one: [raw, "", {}, "sha"] },
      },
    });
    try {
      await assertRejects(() => serviceWorkspace(f.root));
    } finally {
      await f.close();
    }
  });
}
Deno.test("transitive npm constraints cannot hide a Git fetch even without a resolved field", async () => {
  const f = await fixture({
    "package.json": {},
    "package-lock.json": {
      lockfileVersion: 3,
      packages: {
        "node_modules/a": {
          version: "1.0.0",
          dependencies: { hidden: "git+https://10.44.0.1/repo.git" },
        },
      },
    },
  });
  try {
    await assertRejects(() => serviceWorkspace(f.root));
  } finally {
    await f.close();
  }
});

Deno.test("cancellation kills a tool's descendants and closes inherited output pipes", async () => {
  const start = Date.now();
  const result = await command(Deno.cwd(), "python3", [
    "-c",
    "import subprocess,time; subprocess.Popen(['sleep','30']); time.sleep(30)",
  ], AbortSignal.timeout(100));
  assert(result.code !== 0);
  assert(
    Date.now() - start < 1500,
    "descendant stdout must not hold the scan open",
  );
});
