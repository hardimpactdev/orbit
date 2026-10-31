import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { inventory } from "../extensions/behaviors/_lib/dependency_inventory.ts";
import type { SourceIdentity } from "../extensions/behaviors/_lib/dependency_results_schema.ts";

async function withRepo(
  test: (
    root: string,
    json: (path: string, value: unknown) => Promise<void>,
  ) => Promise<void>,
) {
  const root = await Deno.makeTempDir();
  try {
    await test(root, async (path, value) => {
      const file = `${root}/${path}`;
      await Deno.mkdir(file.slice(0, file.lastIndexOf("/")), {
        recursive: true,
      });
      await Deno.writeTextFile(file, JSON.stringify(value));
    });
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}
const source = (
  manager: SourceIdentity["package_manager"],
): SourceIdentity => ({
  path: ".",
  ecosystem: manager === "composer" ? "composer" : "npm",
  package_manager: manager,
});

Deno.test("Composer inventory retains transitive and both-scope resolutions, path and git provenance", () =>
  withRepo(async (root, json) => {
    await json("composer.json", {
      require: { "vendor/runtime": "*" },
      "require-dev": { "vendor/test": "*" },
    });
    await json("composer.lock", {
      packages: [
        {
          name: "vendor/runtime",
          version: "1.0.0",
          require: { "vendor/shared": "*" },
          dist: { type: "zip" },
          source: { type: "git" },
        },
        { name: "vendor/shared", version: "dev-main", source: { type: "git" } },
      ],
      "packages-dev": [{
        name: "vendor/test",
        version: "dev-local",
        require: { "vendor/shared": "*" },
        dist: { type: "path", url: "/private/repo" },
      }],
    });
    const rows = await inventory(root, source("composer"));
    assertEquals(rows.find((p) => p.name === "vendor/shared")!.scopes, [
      "development",
      "regular",
    ]);
    assertEquals(rows.find((p) => p.name === "vendor/shared")!.direct, false);
    assertEquals(rows.find((p) => p.name === "vendor/shared")!.origin, "git");
    assertEquals(
      rows.find((p) => p.name === "vendor/runtime")!.origin,
      "registry",
    );
    assertEquals(rows.find((p) => p.name === "vendor/test")!.origin, "path");
    assert(!JSON.stringify(rows).includes("/private/repo"));
  }));
Deno.test("npm inventory preserves multiple resolutions, workspace links and shared scopes without installs", () =>
  withRepo(async (root, json) => {
    await json("package.json", {
      dependencies: { one: "*", local: "file:packages/local" },
      devDependencies: { tool: "*" },
    });
    await json("packages/local/package.json", {
      name: "local",
      version: "1.0.0",
      dependencies: { one: "*" },
    });
    await json("package-lock.json", {
      lockfileVersion: 3,
      packages: {
        "": {},
        "node_modules/one": { version: "1.0.0" },
        "node_modules/tool": {
          version: "2.0.0",
          dev: true,
          dependencies: { one: "*" },
        },
        "node_modules/tool/node_modules/one": { version: "0.5.0", dev: true },
        "node_modules/local": { resolved: "packages/local", link: true },
        "packages/local": { version: "1.0.0", dependencies: { one: "*" } },
      },
    });
    const rows = await inventory(root, source("npm"));
    assertEquals(
      rows.filter((p) => p.name === "one").map((p) => p.version).sort(),
      ["0.5.0", "1.0.0"],
    );
    assertEquals(rows.find((p) => p.name === "local")!.origin, "path");
    assertEquals(
      rows.find((p) => p.name === "one" && p.version === "0.5.0")!.direct,
      false,
    );
    await assertRejects(
      () => Deno.stat(root + "/node_modules"),
      Deno.errors.NotFound,
    );
  }));
Deno.test("Bun inventory follows both scope graphs and retains nested versions and local packages", () =>
  withRepo(async (root, json) => {
    await json("package.json", {
      dependencies: { one: "*", local: "workspace:packages/local" },
      devDependencies: { tool: "*" },
    });
    await json("bun.lock", {
      lockfileVersion: 1,
      workspaces: { "": {}, "packages/local": { dependencies: { one: "*" } } },
      packages: {
        one: ["one@1.0.0", "", {}, "sha"],
        tool: ["tool@2.0.0", "", { dependencies: { one: "*" } }, "sha"],
        local: ["local@workspace:packages/local"],
      },
    });
    const rows = await inventory(root, source("bun"));
    assertEquals(rows.find((p) => p.name === "one")!.scopes, [
      "development",
      "regular",
    ]);
    assertEquals(rows.find((p) => p.name === "local")!.origin, "path");
  }));
Deno.test("pnpm inventory uses exact snapshot versions; planning support is a separate contract", () =>
  withRepo(async (root, json) => {
    await json("package.json", {
      dependencies: { one: "1.0.0" },
      devDependencies: { tool: "2.0.0" },
    });
    await Deno.writeTextFile(
      root + "/pnpm-lock.yaml",
      `lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      one: {specifier: 1.0.0, version: 1.0.0}\n    devDependencies:\n      tool: {specifier: 2.0.0, version: 2.0.0}\npackages:\n  one@1.0.0: {resolution: {integrity: sha}}\n  tool@2.0.0: {resolution: {integrity: sha}}\nsnapshots:\n  one@1.0.0: {}\n  tool@2.0.0:\n    dependencies: {one: 1.0.0}\n`,
    );
    const rows = await inventory(root, source("pnpm"));
    assertEquals(rows.find((p) => p.name === "one")!.scopes, [
      "development",
      "regular",
    ]);
  }));
Deno.test("repository inventory refuses external source-file symlinks before reading them", () =>
  withRepo(async (root, json) => {
    await json("composer.json", {});
    await Deno.symlink("/etc/passwd", root + "/composer.lock");
    await assertRejects(
      () => inventory(root, source("composer")),
      Error,
      "source_path_escape",
    );
  }));

Deno.test("pnpm v9 keeps distinct peer snapshots and resolves importer peer selectors", () =>
  withRepo(async (root, json) => {
    await json("package.json", {
      dependencies: { one: "1.0.0", react: "19.0.0" },
    });
    await Deno.writeTextFile(
      root + "/pnpm-lock.yaml",
      `lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      one: {specifier: 1.0.0, version: 1.0.0(react@19.0.0)}\n      react: {specifier: 19.0.0, version: 19.0.0}\n    devDependencies:\n      other: {specifier: 1.0.0, version: 1.0.0}\npackages:\n  one@1.0.0: {}\n  react@19.0.0: {}\n  react@18.0.0: {}\n  other@1.0.0: {}\nsnapshots:\n  one@1.0.0(react@19.0.0):\n    dependencies: {react: 19.0.0}\n  one@1.0.0(react@18.0.0):\n    dependencies: {react: 18.0.0}\n  other@1.0.0:\n    dependencies:\n      one: 1.0.0(react@18.0.0)\n  react@19.0.0: {}\n  react@18.0.0: {}\n`,
    );
    const rows = await inventory(root, source("pnpm"));
    const ones = rows.filter((r) => r.name === "one");
    assertEquals(ones.length, 2);
    assert(ones[0].id !== ones[1].id);
    assertEquals(
      rows.filter((r) => r.name === "react").map((r) => r.version).sort(),
      ["18.0.0", "19.0.0"],
    );
  }));
Deno.test("unsafe absolute locked versions fail instead of exposing local paths", () =>
  withRepo(async (root, json) => {
    await json("package.json", { dependencies: { local: "*" } });
    await json("bun.lock", {
      packages: { local: ["local@file:///private/repo"] },
    });
    await assertRejects(
      () => inventory(root, source("bun")),
      Error,
      "unsafe_locked_version",
    );
  }));

Deno.test("npm workspace package without a version remains a path resolution", () =>
  withRepo(async (root, json) => {
    await json("package.json", {
      dependencies: { local: "file:packages/local" },
    });
    await json("packages/local/package.json", { name: "local" });
    await json("package-lock.json", {
      lockfileVersion: 3,
      packages: {
        "": {},
        "node_modules/local": { resolved: "packages/local", link: true },
        "packages/local": { name: "local" },
      },
    });
    const rows = await inventory(root, source("npm"));
    assertEquals(rows[0].version, "workspace");
    assertEquals(rows[0].origin, "path");
  }));
Deno.test("pnpm aliases and oversized YAML are refused before parsing", () =>
  withRepo(async (root, json) => {
    await json("package.json", {});
    for (
      const raw of [
        "lockfileVersion: '9.0'\npackages: &many {}\nsnapshots: *many\n",
        "lockfileVersion: '9.0'\npackages: &α {}\nsnapshots: *α\n",
        "#" + "x".repeat(16 * 1024 * 1024),
      ]
    ) {
      await Deno.writeTextFile(`${root}/pnpm-lock.yaml`, raw);
      await assertRejects(() => inventory(root, source("pnpm")));
    }
  }));

Deno.test("pnpm inventory accepts a realistic lockfile above the old 256 KiB cap", () =>
  withRepo(async (root, json) => {
    await json("package.json", { dependencies: { one: "^1.0.0" } });
    await Deno.writeTextFile(
      `${root}/pnpm-lock.yaml`,
      "#" + "x".repeat(512 * 1024) +
        "\nlockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      one:\n        specifier: ^1.0.0\n        version: 1.0.0\npackages:\n  one@1.0.0: {}\nsnapshots:\n  one@1.0.0: {}\n",
    );
    const result = await inventory(root, source("pnpm"));
    assertEquals(result.length, 1);
    assertEquals(result[0].name, "one");
    assertEquals(result[0].version, "1.0.0");
  }));
