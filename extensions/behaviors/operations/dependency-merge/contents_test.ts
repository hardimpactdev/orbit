import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import {
  packagistVersion,
  type RecordValue,
  strictJson,
  validateContents,
} from "./contents.ts";
const digest = `sha512-${btoa("\0".repeat(64))}`;
const registryPackage = (
  version = "1.0.0",
  extras: RecordValue = {},
): RecordValue => ({
  name: "acme/library",
  version,
  type: "library",
  dist: {
    type: "zip",
    url: "https://api.github.com/repos/acme/library/zipball/abc",
    reference: "abc",
    shasum: "",
  },
  source: {
    type: "git",
    url: "https://github.com/acme/library",
    reference: "abc",
  },
  ...extras,
});
const registry = () => Promise.resolve(registryPackage());
const unsupportedRegistry = () =>
  Promise.reject(new Error("No registry expected"));
const composer = (packages: RecordValue[]) =>
  JSON.stringify({ packages, "packages-dev": [] });
const files = [
  "package-lock.json",
  "npm-shrinkwrap.json",
  "pnpm-lock.yaml",
  "bun.lock",
];
function jsLock(
  file: string,
  roots: Record<string, string>,
  packages: Record<string, [string, Record<string, string>]>,
): string {
  const deps = Object.fromEntries(Object.entries(roots));
  if (file === "pnpm-lock.yaml") {
    return stringify({
      lockfileVersion: "9.0",
      importers: {
        ".": {
          dependencies: Object.fromEntries(
            Object.entries(roots).map((
              [key, specifier],
            ) => [key, { specifier, version: packages[key][0] }]),
          ),
        },
      },
      packages: Object.fromEntries(
        Object.entries(packages).map((
          [key, [version]],
        ) => [`${key}@${version}`, { resolution: { integrity: digest } }]),
      ),
      snapshots: Object.fromEntries(
        Object.entries(packages).map((
          [key, [version, edges]],
        ) => [`${key}@${version}`, { dependencies: edges }]),
      ),
    });
  }
  if (file === "bun.lock") {
    return JSON.stringify({
      lockfileVersion: 2,
      workspaces: { "": { dependencies: deps } },
      packages: Object.fromEntries(
        Object.entries(packages).map((
          [key, [version, edges]],
        ) => [key, [`${key}@${version}`, "", { dependencies: edges }, digest]]),
      ),
    });
  }
  const rows = Object.fromEntries(
    Object.entries(packages).map((
      [key, [version, edges]],
    ) => [`node_modules/${key}`, {
      version,
      dependencies: edges,
      resolved: `https://registry.npmjs.org/${key}/-/${
        key.split("/").at(-1)
      }-${version}.tgz`,
      integrity: digest,
    }]),
  );
  return JSON.stringify({
    lockfileVersion: file === "npm-shrinkwrap.json" ? 2 : 3,
    packages: { "": { dependencies: deps }, ...rows },
    ...(file === "npm-shrinkwrap.json"
      ? {
        dependencies: Object.fromEntries(
          Object.entries(packages).map((
            [key, [version]],
          ) => [key, {
            version,
            resolved: `https://registry.npmjs.org/${key}/-/${
              key.split("/").at(-1)
            }-${version}.tgz`,
            integrity: digest,
          }]),
        ),
      }
      : {}),
  });
}
Deno.test("strict JSON rejects duplicate decoded keys, keeps arrays ordered and string URLs", () => {
  assertThrows(() => strictJson('{"require":{},"requ\\u0069re":{}}'));
  assertThrows(() => strictJson("[]"));
  assertEquals(
    strictJson('{// comment\n"url":"https://example.com/",}', true),
    { url: "https://example.com/" },
  );
});
Deno.test("manifest changes only existing dependency ranges", async () => {
  for (const file of ["composer.json", "package.json"]) {
    const field = file === "composer.json" ? "require" : "dependencies";
    await validateContents(
      file,
      JSON.stringify({
        [field]: { library: "^1.2" },
        scripts: { test: "fixed" },
      }),
      JSON.stringify({
        scripts: { test: "fixed" },
        [field]: { library: "^1.0" },
      }),
      null,
      unsupportedRegistry,
    );
    for (
      const extra of [{ scripts: { test: "changed" } }, {
        repositories: [{ url: "https://evil.example" }],
      }]
    ) {
      await assertRejects(() =>
        validateContents(
          file,
          JSON.stringify({ [field]: { library: "^1.2" }, ...extra }),
          JSON.stringify({
            [field]: { library: "^1.0" },
            scripts: { test: "fixed" },
          }),
          null,
          unsupportedRegistry,
        )
      );
    }
    for (const value of [{}, { library: "^1.0", new: "^1.0" }]) {
      await assertRejects(() =>
        validateContents(
          file,
          JSON.stringify({ [field]: value }),
          JSON.stringify({ [field]: { library: "^1.0" } }),
          null,
          unsupportedRegistry,
        )
      );
    }
  }
});
Deno.test("Composer lock sources and executable fields match fresh P2 evidence", async () => {
  const clean = composer([registryPackage()]),
    manifest = '{"require":{"acme/library":"^1.0"}}';
  await validateContents("composer.lock", clean, clean, manifest, registry);
  for (
    const extra of [
      { autoload: { files: ["hostile.php"] } },
      { bin: ["hostile"] },
      { extra: { "plugin-class": "Hostile" } },
      { dist: { type: "zip", url: "https://evil.example/code.zip" } },
      { source: { type: "git", url: "ssh://github.com/acme/library" } },
      { "notification-url": "https://evil.example" },
    ]
  ) {
    await assertRejects(() =>
      validateContents(
        "composer.lock",
        composer([registryPackage("1.0.0", extra)]),
        clean,
        manifest,
        registry,
      )
    );
  }
});
for (const file of files) {
  Deno.test(`${file} accepts a complete root graph and rejects orphan/new package identities`, async () => {
    const roots = { library: "^1.0" },
      base = jsLock(file, roots, { library: ["1.0.0", {}] }),
      root = JSON.stringify({ dependencies: roots });
    await validateContents(file, base, base, root, unsupportedRegistry);
    for (
      const packages of [{ library: ["1.0.0", {}], orphan: ["1.0.0", {}] }, {
        library: ["1.0.0", { injected: "1.0.0" }],
        injected: ["1.0.0", {}],
      }] as Record<string, [string, Record<string, string>]>[]
    ) {
      await assertRejects(() =>
        validateContents(
          file,
          jsLock(file, roots, packages),
          base,
          root,
          unsupportedRegistry,
        )
      );
    }
  });
  Deno.test(`${file} checks locked top-level zero-major and stable breaking boundaries`, async () => {
    for (
      const [before, after] of [["0.4.0", "0.9.0"], ["0.0.4", "0.0.9"], [
        "1.0.0",
        "2.0.0",
      ]]
    ) {
      const roots = { library: "*" };
      await assertRejects(() =>
        validateContents(
          file,
          jsLock(file, roots, { library: [after, {}] }),
          jsLock(file, roots, { library: [before, {}] }),
          JSON.stringify({ dependencies: roots }),
          unsupportedRegistry,
        )
      );
    }
  });
  Deno.test(`${file} refuses local/Git sources and aliases hidden in edges`, async () => {
    const roots = { library: "^1.0" },
      base = jsLock(file, roots, { library: ["1.0.0", {}] }),
      root = JSON.stringify({ dependencies: roots });
    for (
      const edge of [
        "file:../outside",
        "evil@1.0.0",
        "git+https://github.com/evil/repo",
      ]
    ) {
      await assertRejects(() =>
        validateContents(
          file,
          jsLock(file, roots, { library: ["1.0.0", { hidden: edge }] }),
          base,
          root,
          unsupportedRegistry,
        )
      );
    }
    await assertRejects(() =>
      validateContents(
        file,
        file === "pnpm-lock.yaml"
          ? base.replace(
            "integrity:",
            "tarball: https://evil.example/code.tgz\n      integrity:",
          )
          : file === "bun.lock"
          ? base.replace(',"",', ',"https://evil.example/code.tgz",')
          : base.replace("registry.npmjs.org", "evil.example"),
        base,
        root,
        unsupportedRegistry,
      )
    );
  });
}
Deno.test("Composer reachability follows attested virtual providers but cannot introduce or replace providers", async () => {
  const lib = registryPackage("1.0.0", { require: { "acme/virtual": "^1.0" } }),
    provider = registryPackage("1.0.0", {
      name: "acme/provider",
      provide: { "acme/virtual": "^1.0" },
    }),
    root = '{"require":{"acme/library":"^1.0"}}';
  const trusted = (key: string) =>
      Promise.resolve(key === "acme/library" ? lib : provider),
    both = composer([lib, provider]);
  await validateContents("composer.lock", both, both, root, trusted);
  await assertRejects(() =>
    validateContents(
      "composer.lock",
      both,
      composer([registryPackage()]),
      root,
      trusted,
    )
  );
  const real = registryPackage("1.0.0", { name: "acme/virtual" });
  await assertRejects(() =>
    validateContents(
      "composer.lock",
      both,
      composer([lib, provider, real]),
      root,
      trusted,
    )
  );
});
Deno.test("Composer attested transitive major is allowed while root major is unchanged", async () => {
  const lib = registryPackage("1.0.0", {
      require: { "acme/transitive": "^2.0" },
    }),
    transitive = registryPackage("2.0.0", { name: "acme/transitive" });
  await validateContents(
    "composer.lock",
    composer([lib, transitive]),
    composer([lib, { ...transitive, version: "1.0.0" }]),
    '{"require":{"acme/library":"^1.0"}}',
    (key) => Promise.resolve(key === "acme/library" ? lib : transitive),
  );
});
Deno.test("P2 inheritance and unset expansion are applied to the selected version", async () => {
  const value = await packagistVersion(
    "acme/library",
    "1.0.0",
    () =>
      Promise.resolve(
        Response.json({
          minified: "composer/2.0",
          packages: {
            "acme/library": [registryPackage("1.0.1", { bin: ["other"] }), {
              version: "1.0.0",
              bin: "__unset",
            }],
          },
        }),
      ),
  );
  assertEquals(value.bin, undefined);
  assertEquals(value.version, "1.0.0");
  await assertRejects(() =>
    packagistVersion(
      "acme/library",
      "2.0.0",
      () =>
        Promise.resolve(
          Response.json({ packages: { "acme/library": [registryPackage()] } }),
        ),
    )
  );
});
Deno.test("unsupported Yarn and binary Bun refuse the ordinary merge path", async () => {
  for (
    const [path, text] of [["yarn.lock", "library@1.0.0:"], [
      "bun.lock",
      "#!/usr/bin/env bun\0binary",
    ]]
  ) {
    await assertRejects(() =>
      validateContents(path, text, text, "{}", unsupportedRegistry)
    );
  }
});
Deno.test("null dependency maps and stale pnpm root selection are refused", async () => {
  await assertRejects(() =>
    validateContents(
      "package.json",
      '{"dependencies":null}',
      '{"dependencies":null}',
      null,
      unsupportedRegistry,
    )
  );
  const root = '{"dependencies":{"library":"*"}}';
  const before = jsLock("pnpm-lock.yaml", { library: "*" }, {
    library: ["1.0.0", {}],
  }).replace(
    "packages:\n",
    `packages:\n  library@2.0.0:\n    resolution:\n      integrity: ${digest}\n`,
  );
  const after = jsLock("pnpm-lock.yaml", { library: "*" }, {
    library: ["2.0.0", {}],
  });
  await assertRejects(() =>
    validateContents("pnpm-lock.yaml", after, before, root, unsupportedRegistry)
  );
});
Deno.test("npm v2 rejects legacy identities absent from authoritative packages", async () => {
  const base = JSON.parse(
    jsLock("npm-shrinkwrap.json", { library: "^1.0" }, {
      library: ["1.0.0", {}],
    }),
  );
  base.dependencies.orphan = {
    version: "1.0.0",
    resolved: "https://registry.npmjs.org/orphan/-/orphan-1.0.0.tgz",
    integrity: digest,
  };
  await assertRejects(() =>
    validateContents(
      "npm-shrinkwrap.json",
      JSON.stringify(base),
      JSON.stringify(base),
      '{"dependencies":{"library":"^1.0"}}',
      unsupportedRegistry,
    )
  );
});

for (const file of ["package-lock.json", "bun.lock"]) {
  Deno.test(`accepts equivalent reordered dependency maps in ${file}`, async () => {
    const manifest = JSON.stringify({ dependencies: { z: "^1", a: "^1" } });
    const lock = jsLock(file, { a: "^1", z: "^1" }, {
      a: ["1.0.0", {}],
      z: ["1.0.0", {}],
    });
    await validateContents(file, lock, lock, manifest, unsupportedRegistry);
  });
}
