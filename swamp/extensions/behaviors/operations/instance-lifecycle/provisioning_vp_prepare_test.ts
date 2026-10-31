import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { prepareProvisioningVp } from "./provisioning_vp.ts";
import { ProvisioningRefusal } from "./provisioning_source.ts";
import type { VpLocal } from "../instance-deploy/vp_local.ts";

function fixture(installed = false) {
  const calls: string[] = [], references: string[] = [];
  const plan = {
    node_id: 9,
    platform: "linux" as const,
    architecture: "x86_64" as const,
    application: "/srv/apps/proof/default",
    user: "orbit-app-7",
    node_user: "orbit",
    vp_materialize: { user: "orbit" },
  };
  const configuration = {
    package: {
      devDependencies: { "vite-plus": "^0.3.0" },
      packageManager: "npm@11.12.0",
    },
    ".node-version": "24.22.0",
    locks: {
      "package-lock.json":
        '{"packages":{"node_modules/vite-plus":{"version":"0.3.3"}}}',
    },
    npmrc: "//registry.example/:_authToken=private-registry-token",
  };
  let inspected = 0, cancelled = false;
  const local: VpLocal = (mode, input, options) => {
    calls.push(mode);
    assertEquals(options.user, mode === "project" ? "orbit-app-7" : "orbit");
    if (mode === "project") return Promise.resolve(configuration);
    if (mode === "scope") {
      return Promise.resolve({ binary: "/opt/orbit/vite-plus/bin/vp" });
    }
    if (mode === "version") return Promise.resolve({ version: "vp v1.4.0" });
    if (mode === "install") {
      assertEquals(input.npmrc, configuration.npmrc);
      return Promise.resolve({ verified: {} });
    }
    if (mode === "inspect") {
      return Promise.resolve({ installed: [installed || inspected++ > 0] });
    }
    throw new Error("unexpected local call");
  };
  const port = {
    local,
    materialize: (_name: string, input: Record<string, unknown>) => {
      calls.push("materialize");
      assertEquals(input, plan.vp_materialize);
      return Promise.resolve({ changed: false });
    },
    current: () => Promise.resolve({ cancel_requested: cancelled }),
    reference: (_runtime: unknown, status: string) => {
      references.push(status);
      return Promise.resolve();
    },
  };
  return {
    plan,
    port,
    calls,
    references,
    configuration,
    cancel: () => {
      cancelled = true;
    },
  };
}
Deno.test("provisioning installs a missing exact VP environment with the canonical native lower", async () => {
  const f = fixture();
  const result = await prepareProvisioningVp(f.plan, f.port);
  assertEquals(f.calls, [
    "project",
    "materialize",
    "scope",
    "version",
    "inspect",
    "install",
    "inspect",
  ]);
  assertEquals(f.references, ["checking", "active"]);
  assertEquals(result?.node_version, "24.22.0");
  assertEquals(
    JSON.stringify(result).includes("private-registry-token"),
    false,
  );
});
Deno.test("provisioning accepts a newer VP CLI and preserves an installed store", async () => {
  const f = fixture(true);
  await prepareProvisioningVp(f.plan, f.port);
  assertEquals(f.calls.includes("install"), false);
  assertEquals(f.references, ["checking", "active"]);
});
Deno.test("provisioning without Vite+ performs no manager or store mutations", async () => {
  const f = fixture();
  f.configuration.package.devDependencies =
    {} as typeof f.configuration.package.devDependencies;
  assertEquals(await prepareProvisioningVp(f.plan, f.port), null);
  assertEquals(f.calls, ["project"]);
});
Deno.test("provisioning cancellation blocks manager and store effects", async () => {
  const f = fixture();
  f.cancel();
  const error = await assertRejects(
    () => prepareProvisioningVp(f.plan, f.port),
    ProvisioningRefusal,
  );
  assertEquals(error.code, "operation.cancelled");
  assertEquals(f.calls, []);
});
Deno.test("provisioning rejects moving runtime selectors before manager effects", async () => {
  const f = fixture();
  f.configuration[".node-version"] = "lts/*";
  const error = await assertRejects(
    () => prepareProvisioningVp(f.plan, f.port),
    ProvisioningRefusal,
  );
  assertEquals(error.code, "deployment.vp_requirements_invalid");
  assertEquals(f.calls, ["project"]);
});
Deno.test("provisioning never exposes registry bytes from a failed local install", async () => {
  const f = fixture(), local = f.port.local;
  f.port.local = (mode, input, options) =>
    mode === "install"
      ? Promise.reject(new Error(f.configuration.npmrc))
      : local(mode, input, options);
  const error = await assertRejects(
    () => prepareProvisioningVp(f.plan, f.port),
    ProvisioningRefusal,
  );
  assertEquals(error.code, "deployment.execution_unconfirmed");
  assertEquals(String(error).includes("private-registry-token"), false);
  assertEquals(f.references, ["checking"]);
});
