import { recordQuery } from "./fixtures/worker_query.ts";
import { dirname } from "node:path";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { serviceWorkspace } from "../extensions/behaviors/_lib/service_workspace.ts";
import { command } from "../extensions/behaviors/_lib/maintenance/io.ts";
import {
    toolOptions,
    withServiceTools,
} from "../extensions/behaviors/_lib/maintenance/tool_policy.ts";
import { batchEvidence } from "../extensions/behaviors/_lib/service_detection.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";

// Parser/environment fixtures invoke npm directly. Production package routing
// and refusal are covered by package_sandbox_test and the agent native suite.
async function npmFixture(
    cwd: string,
    _binary: string,
    args: string[],
    signal?: AbortSignal,
) {
    const options = toolOptions("npm", args, {
        PATH: Deno.env.get("PATH") ?? "/usr/bin:/bin",
    });
    const result = await new Deno.Command("npm", {
        args: options.args,
        cwd,
        clearEnv: true,
        env: options.env,
        stdin: "null",
        stdout: "piped",
        stderr: "piped",
        signal,
    }).output();
    return {
        code: result.code,
        stdout: new TextDecoder().decode(result.stdout),
        stderr: new TextDecoder().decode(result.stderr),
    };
}

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
                const result = await npmFixture(tree.root, "npm", [
                    "config",
                    "get",
                    "registry",
                ]);
                assertEquals(result.code, 0);
                assertEquals(
                    result.stdout.trim(),
                    "https://registry.npmjs.org/",
                );
                const git = await npmFixture(tree.root, "npm", [
                    "config",
                    "get",
                    "git",
                ]);
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
        const f = await fixture({
            "package.json": { dependencies: { pkg: dep } },
        });
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
                    resolved:
                        "https://registry.npmjs.org/five/-/five-1.0.0.tgz",
                },
                "node_modules/local": {
                    resolved: "packages/local",
                    link: true,
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

Deno.test("unavailable native PR observations retain the last good publication", async () => {
    const url = "https://github.com/nckrtl/website/pull/17",
        at = new Date().toISOString();
    const previous = {
        status: "open" as const,
        checked_at: at,
        last_success_at: at,
        error_code: null,
        value: {
            repository: "github.com/nckrtl/website",
            number: 17,
            url,
            head_commit: "a".repeat(40),
            group_id: 2195,
            run_id: "batch-run",
        },
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: recordQuery((q: string) =>
            Promise.resolve(
                q.includes('startsWith("package-')
                    ? [{ workItem: "tg-2195", targets: [] }]
                    : q.includes('name == "owner"')
                    ? [{ runId: "batch-run" }]
                    : [],
            )
        ),
        writeResource: () => Promise.resolve({ name: "unused" }),
    };
    let observations = 0;
    const evidence = await batchEvidence(WEBSITE_PROJECT, previous, ctx, [{
        project_id: 20,
        id: 2195,
        status: "running",
        pr_url: url,
        watched_pr_url: url,
        maintenance: { mode: "package", targets: [], run_id: "batch-run" },
    }], () => {
        observations++;
        return Promise.reject(new Error("private diagnostic must not escape"));
    });
    assertEquals(observations, 1);
    assertEquals(evidence.pr.status, "unknown");
    assertEquals(evidence.pr.error_code, "batch_pr_lookup_failed");
    assertEquals(evidence.pr.value, previous.value);
    assertEquals(evidence.pr.last_success_at, previous.last_success_at);
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
                JSON.parse(
                    await Deno.readTextFile(`${t.root}/app/package.json`),
                )
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
            "bun.lock": {
                lockfileVersion: 2,
                packages: { x: [`x@${spec}`, {}] },
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
                    npmFixture(tree.root, "npm", [
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
            dependencies: {
                one: "",
                two: "catalog:",
                three: "catalog:testing",
            },
            peerDependencies: { peer: "^1.0.0" },
            workspaces: {
                packages: [],
                catalog: { two: "^1.0.0" },
                catalogs: { testing: { three: "^2.0.0" } },
            },
            overrides: {
                one: "$one",
                other: { three: "$three", peer: "$peer" },
            },
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
        const f = await fixture({
            "package.json": { [section]: { one: "$one" } },
        });
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

Deno.test("Composer funding metadata is excluded while private download URLs still fail", async () => {
    const lock = {
        packages: [{
            name: "fixture/library",
            version: "1.0.0",
            funding: [{ type: "custom", url: "https://symfony.com/sponsor" }],
            dist: {
                type: "zip",
                url: "https://api.github.com/repos/fixture/library/zipball/abc",
            },
        }],
        "packages-dev": [],
    };
    const f = await fixture({
        "composer.json": { require: { "fixture/library": "^1.0" } },
        "composer.lock": lock,
    });
    try {
        const tree = await serviceWorkspace(f.root);
        try {
            const sanitized = JSON.parse(
                await Deno.readTextFile(`${tree.root}/composer.lock`),
            );
            assertEquals(sanitized.packages[0].funding, undefined);
            assertEquals(sanitized.packages[0].dist, lock.packages[0].dist);
        } finally {
            await tree.close();
        }
        lock.packages[0].dist.url = "https://10.44.0.1/private.zip";
        await Deno.writeTextFile(
            `${f.root}/composer.lock`,
            JSON.stringify(lock),
        );
        await command(f.root, "git", ["add", "composer.lock"]);
        await assertRejects(
            () => serviceWorkspace(f.root),
            Error,
            "unsupported_dependency_endpoint",
        );
    } finally {
        await f.close();
    }
});

Deno.test("npm lock accepts registry peer alternatives but refuses an endpoint alternative", async () => {
    const lock = {
        packages: {
            "node_modules/@tailwindcss/typography": {
                version: "0.5.19",
                peerDependencies: {
                    tailwindcss: ">=3.0.0 || >=4.0.0 || insiders",
                },
            },
        },
    };
    const f = await fixture({ "package.json": {}, "package-lock.json": lock });
    try {
        const tree = await serviceWorkspace(f.root);
        await tree.close();
        for (
            const endpoint of [
                "https://10.44.0.1/pkg",
                "git+https://github.com/org/pkg",
                "file:../../etc",
                "x.tgz",
            ]
        ) {
            lock.packages["node_modules/@tailwindcss/typography"]
                .peerDependencies
                .tailwindcss = `>=3.0.0 || ${endpoint}`;
            await Deno.writeTextFile(
                `${f.root}/package-lock.json`,
                JSON.stringify(lock),
            );
            await command(f.root, "git", ["add", "package-lock.json"]);
            await assertRejects(
                () => serviceWorkspace(f.root),
                Error,
                "unsupported_dependency_endpoint",
            );
        }
    } finally {
        await f.close();
    }
});

Deno.test("captured maintenance publishes exact PR and target links without retired factory records", async () => {
    const url = "https://github.com/nckrtl/website/pull/18";
    const target = {
        path: ".",
        manager: "composer",
        package: "acme/lib",
        version: "2.0.0",
    };
    const ctx = {
        definition: { name: "orbit-gateway" },
        queryData: () => {
            throw new Error("retired factory read");
        },
        writeResource: () => Promise.resolve({ name: "unused" }),
    };
    const evidence = await batchEvidence(
        WEBSITE_PROJECT,
        null,
        ctx,
        [{
            project_id: 20,
            id: 2195,
            status: "waiting_for_review",
            pr_url: url,
            watched_pr_url: url,
            maintenance: {
                mode: "package",
                targets: [target],
                run_id: "maintenance-native-root",
            },
        }],
        () =>
            Promise.resolve({
                url,
                number: 18,
                state: "OPEN",
                headRefOid: "a".repeat(40),
            }),
    );
    assertEquals(evidence.pr.status, "open");
    assertEquals(evidence.pr.value?.run_id, "maintenance-native-root");
    assertEquals(evidence.links, [{
        group_id: 2195,
        run_id: "maintenance-native-root",
        targets: [target],
    }]);
});
