import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { invokeLocalVp } from "./provisioning_vp_local.ts";
import { prepareProvisioningVp } from "./provisioning_vp.ts";
import { Provisioning, ProvisioningRefusal } from "./provisioning_source.ts";

const user = new TextDecoder().decode(
  (await new Deno.Command("/usr/bin/id", { args: ["-un"] }).output()).stdout,
).trim();
async function fixture(run: (root: string) => Promise<void>) {
  const root = await Deno.makeTempDir({ prefix: "orbit-vp-local-" });
  try {
    await run(root);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
}
Deno.test("native VP reader reads only project data and returns no npmrc credential", async () => {
  await fixture(async (path) => {
    await Deno.writeTextFile(
      `${path}/package.json`,
      JSON.stringify({
        devDependencies: { "vite-plus": "0.1.0" },
        packageManager: "npm@11.0.0",
      }),
    );
    await Deno.writeTextFile(`${path}/package-lock.json`, "{}");
    await Deno.writeTextFile(`${path}/.node-version`, "24.0.0");
    await Deno.writeTextFile(
      `${path}/.npmrc`,
      "//registry.npmjs.org/:_authToken=private\n",
    );
    const result = await invokeLocalVp({
      name: "tool.read-vp-project",
      input: { path, user },
    });
    assertEquals(result[".node-version"], "24.0.0");
    assertEquals(result.locks, { "package-lock.json": "{}" });
    assertEquals(result.npmrc, "");
  });
});
Deno.test("native VP reader refuses symlinked manifest", async () => {
  await fixture(async (path) => {
    await Deno.writeTextFile(`${path}/outside`, "{}");
    await Deno.symlink(`${path}/outside`, `${path}/package.json`);
    assertEquals(
      await invokeLocalVp({
        name: "tool.read-vp-project",
        input: { path, user },
      }),
      { error_code: "deployment.vp_store_failed" },
    );
  });
});
Deno.test("native VP prepared-runtime read recognizes existing exact environment without installation", async () => {
  await fixture(async (store) => {
    const environment = {
      store,
      node_version: "24.0.0",
      package_manager: "npm",
      package_manager_version: "11.0.0",
      package_manager_hash: null,
    };
    const inspect = () =>
      invokeLocalVp({
        name: "tool.inspect-vp-store",
        input: { environments: [environment] },
      });
    assertEquals(await inspect(), { installed: [false] });
    const node = `${store}/js_runtime/node/24.0.0/bin/node`;
    const manager = `${store}/package_manager/npm/11.0.0/npm`;
    await Deno.mkdir(`${store}/js_runtime/node/24.0.0/bin`, {
      recursive: true,
    });
    await Deno.mkdir(`${manager}/bin`, { recursive: true });
    await Deno.writeTextFile(node, "not executed", { mode: 0o755 });
    await Deno.writeTextFile(
      `${manager}/package.json`,
      JSON.stringify({
        name: "npm",
        version: "11.0.0",
      }),
    );
    await Deno.writeTextFile(`${manager}/bin/npm`, "not executed", {
      mode: 0o755,
    });
    assertEquals(await inspect(), { installed: [true] });
    assertEquals(await Deno.readTextFile(node), "not executed");
    await Deno.writeTextFile(
      `${manager}/package.json`,
      JSON.stringify({
        name: "npm",
        version: "12.0.0",
      }),
    );
    assertEquals(await inspect(), { installed: [false] });
  });
});
Deno.test("native VP lower refuses missing runtime without an installer or agent fallback", async () => {
  const calls: string[] = [];
  const plan = {
    application: "/srv/apps/proof/default",
    user,
    agent_sha256: "a".repeat(64),
  } as Provisioning;
  const error = await assertRejects(
    () =>
      prepareProvisioningVp(plan, (call) => {
        calls.push(call.name);
        return Promise.resolve(
          call.name === "tool.read-vp-project"
            ? {
              package: {
                devDependencies: { "vite-plus": "0.1.0" },
                engines: { node: "24.0.0" },
                packageManager: "npm@11.0.0",
              },
              locks: {
                "package-lock.json": JSON.stringify({
                  packages: {
                    "node_modules/vite-plus": { version: "0.1.0" },
                  },
                }),
              },
            }
            : call.name === "tool.inspect-vp-scope"
            ? { binary: "/opt/orbit/vite-plus/bin/vp" }
            : call.name === "tool.vp-version"
            ? { version: "0.1.0" }
            : { installed: [false] },
        );
      }),
    ProvisioningRefusal,
  );
  assertEquals(error.code, "deployment.vp_store_failed");
  assertEquals(calls, [
    "tool.read-vp-project",
    "tool.inspect-vp-scope",
    "tool.vp-version",
    "tool.inspect-vp-store",
  ]);
});
