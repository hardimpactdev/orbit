import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { vpRequirements } from "./vp_requirements.ts";

Deno.test("native deploy resolves locked pnpm catalog, alias, workspace and prerelease pins", () => {
  for (
    const [spec, locked, expected] of [
      ["catalog:", "0.3.3", "0.3.3"],
      ["catalog:build", "0.3.3(peer@1.0.0)", "0.3.3"],
      ["workspace:^", "workspace:0.3.3", "0.3.3"],
      ["workspace:*", "link:packages/vite-plus", "0.3.3"],
      ["npm:vite-plus@^0.3.0", "vite-plus@0.3.3", "0.3.3"],
      ["^0.4.0-beta.1", "0.4.0-beta.2", "0.4.0-beta.2"],
    ]
  ) {
    const result = vpRequirements({
      package: {
        devDependencies: { "vite-plus": spec },
        packageManager: "pnpm@10.17.0",
        engines: { node: ">=22" },
      },
      ".node-version": "24.22.0",
      locks: {
        "pnpm-lock.yaml":
          `lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus:\n        specifier: '${spec}'\n        version: '${locked}'\n`,
      },
      workspaces: { "link:packages/vite-plus": "0.3.3" },
    }, "/opt/orbit/vite-plus");
    assertEquals(result?.vp_version, expected);
    assertEquals(result?.node_version, "24.22.0");
  }
});

Deno.test("native deploy resolves npm alias and exact locked manager metadata", () => {
  assertEquals(
    vpRequirements({
      package: {
        devDependencies: { "build-tools": "npm:vite-plus@^0.3.0" },
        engines: { node: "24.22.0" },
      },
      locks: {
        "package-lock.json": JSON.stringify({
          npmVersion: "11.12.0",
          packages: {
            "node_modules/build-tools": {
              version: "0.3.3",
              engines: { node: ">=24" },
            },
          },
        }),
      },
    }, "/opt/orbit/vite-plus"),
    {
      store: "/opt/orbit/vite-plus",
      vp_version: "0.3.3",
      node_version: "24.22.0",
      package_manager: "npm",
      package_manager_version: "11.12.0",
      package_manager_hash: null,
    },
  );
});

Deno.test("native deploy follows npm workspace links only inside admitted manifest data", () => {
  const value = vpRequirements({
    package: {
      devDependencies: { "vite-plus": "workspace:*" },
      packageManager: "npm@11.12.0",
      engines: { node: ">=24" },
    },
    ".nvmrc": "v24.22.0",
    locks: {
      "package-lock.json": JSON.stringify({
        packages: {
          "node_modules/vite-plus": {
            resolved: "packages/vite-plus",
            link: true,
          },
          "packages/vite-plus": { version: "0.3.3" },
        },
      }),
    },
  }, "/opt/orbit/vite-plus");
  assertEquals(value?.vp_version, "0.3.3");
});

Deno.test("native deploy refuses incompatible or moving Node and manager selectors", () => {
  for (
    const [node, manager] of [
      ["22.18.0", "npm@11.12.0"],
      ["lts/*", "npm@11.12.0"],
      ["24.22.0", "pnpm@10.17.0"],
      ["24.22.0", "npm@latest"],
    ]
  ) {
    assertThrows(
      () =>
        vpRequirements({
          package: {
            devDependencies: { "vite-plus": "0.3.3" },
            packageManager: manager,
          },
          ".node-version": node,
          locks: {
            "package-lock.json":
              '{"packages":{"node_modules/vite-plus":{"version":"0.3.3","engines":{"node":">=24"}}}}',
          },
        }, "/opt/orbit/vite-plus"),
      Error,
      "deployment.vp_requirements_invalid",
    );
  }
});

Deno.test("native deploy skips absent Vite+ and refuses missing or malformed lock evidence", () => {
  assertEquals(
    vpRequirements({ package: { dependencies: { vite: "1.0.0" } } }, "/store"),
    null,
  );
  for (
    const locks of [{}, { "package-lock.json": "private malformed sentinel" }]
  ) {
    const error = assertThrows(() =>
      vpRequirements({
        package: { dependencies: { "vite-plus": "^0.3.0" } },
        locks,
      }, "/store")
    );
    assertEquals(
      (error as Error).message,
      "deployment.vp_requirements_invalid",
    );
  }
});

Deno.test("malformed Project data refuse before runtime preparation", () => {
  for (const raw of [null, [], {}, { package: null }, { package: [] }]) {
    assertThrows(
      () => vpRequirements(raw, "/opt/orbit/vite-plus"),
      Error,
      "deployment.vp_requirements_invalid",
    );
  }
});
