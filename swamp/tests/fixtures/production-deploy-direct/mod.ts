import { z } from "npm:zod@4.4.3";
import { deployDirect } from "../../../extensions/behaviors/operations/instance-deploy/deploy_direct.ts";

const secret = () => Deno.env.get("ORBIT_DEPLOY_SENTINEL")!;
const home = "/home/orbit-app-102";
const release = { name: "proof-release", commit: "a".repeat(40) };
const previous = { name: "previous", commit: "b".repeat(40) };
let selected = previous;
const assert = (condition: boolean) => {
  if (!condition) throw new Error("Fixture boundary mismatch");
};
const event = async (name: string) => {
  const response = await fetch(
    Deno.env.get("ORBIT_DEPLOY_TEST_URL")! + "/fixture/" + name,
    { method: "POST" },
  );
  await response.text();
  assert(response.ok);
};
const process = (name: string, input: Record<string, unknown>) => {
  if (name === "process.check-release") {
    assert(input.directory === home + "/current");
    return Promise.resolve({ available: true });
  }
  if (name === "process.control") {
    assert(input.operation === "restart");
    return Promise.resolve({ state: "active" });
  }
  if (name === "process.status") return Promise.resolve({ state: "active" });
  throw new Error("Unexpected process effect");
};
const root = deployDirect({
  source: {
    resolveDevelopment: () => {
      throw new Error("Unexpected dev action");
    },
    checkoutDevelopment: () => {
      throw new Error("Unexpected dev action");
    },
    inspectDevelopment: () => {
      throw new Error("Unexpected dev action");
    },
    prepareRelease: (target, input) => {
      assert(target.user === "orbit-app-102" && target.home === home);
      assert(
        input.git_read.github_token === secret() && input.name === release.name,
      );
      return Promise.resolve(release);
    },
    inspectRelease: () => Promise.resolve({ release: selected }),
    activateRelease: async (target) => {
      assert(target.user === "orbit-app-102");
      selected = release;
      await event("activated");
      return release;
    },
    pruneReleases: () => Promise.resolve({ removed: [], in_use: [] }),
  },
  run: (step, script) => {
    assert(step.project_id === 102 && step.instance_id === 101);
    assert(
      step.target.variant === "release" && step.target.release === release.name,
    );
    assert(script === "printf " + secret());
    return Promise.resolve({
      exit_code: 0,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  },
  production: {
    environment: {
      access: (input) => {
        assert(input.user === "orbit-app-102" && input.base === home);
        if (input.mode === "inspect") {
          return Promise.resolve({
            preflight: { state: "ok", installed: false },
            existing: { state: "missing", installed: false },
          });
        }
        assert(
          input.mode === "write" && input.content?.includes(secret()) === true,
        );
        return Promise.resolve({ state: "changed", installed: true });
      },
    },
    vp: {
      materialize: (_name, recipe, node) => {
        assert(recipe.user === "orbit" && node.user === "orbit");
        return Promise.resolve({});
      },
      local: (mode, input, scope) => {
        if (mode === "project") {
          assert(
            input.path === home + "/releases/" + release.name &&
              scope.user === "orbit-app-102",
          );
          return Promise.resolve({
            package: {
              devDependencies: { "vite-plus": "0.1.0" },
              packageManager: "npm@10.0.0",
            },
            ".node-version": "22.0.0",
            npmrc: secret(),
            locks: {
              "package-lock.json": JSON.stringify({
                packages: { "node_modules/vite-plus": { version: "0.1.0" } },
              }),
            },
          });
        }
        assert(scope.user === "orbit");
        if (mode === "scope") {
          return Promise.resolve({
            binary: "/home/orbit/.local/share/vp/bin/vp",
          });
        }
        if (mode === "version") {
          return Promise.resolve({ version: "0.1.0" });
        }
        if (mode === "inspect") {
          return Promise.resolve({ installed: [true] });
        }
        throw new Error("Unexpected VP effect");
      },
    },
    processControl: process,
    account: (user) => {
      assert(user === "orbit-app-102");
      return Promise.resolve({
        user,
        uid: 1001,
        gid: 1001,
        group: "applications",
        home,
        shell: "/usr/sbin/nologin",
      });
    },
    schedule: (name, input) => {
      assert(name === "schedule.install" && input.group === "applications");
      assert(
        input.user === "orbit-app-102" &&
          input.command === secret() + "-schedule",
      );
      return Promise.resolve({});
    },
  },
});
export const behavior = {
  ...root,
  type: "@fixture/production-deploy-direct",
  globalArguments: z.object({}),
  methods: {
    run: {
      ...root.methods.run,
      execute: async (
        args: Parameters<typeof root.methods.run.execute>[0],
        context: Parameters<typeof root.methods.run.execute>[1],
      ) => {
        const ctx = {
          ...context,
          globalArgs: {
            gatewayUrl: Deno.env.get("ORBIT_DEPLOY_TEST_URL")!,
            caFile: Deno.env.get("ORBIT_DEPLOY_TEST_CA")!,
          },
        };
        const prepared = await root.methods.prepareProduction.execute(
          args,
          ctx,
        );
        if (!("ready" in prepared)) return prepared;
        return await root.methods.finishProduction.execute(args, ctx);
      },
    },
  },
};
