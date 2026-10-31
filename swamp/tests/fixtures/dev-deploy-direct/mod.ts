import { z } from "npm:zod@4.4.3";
import { deployDirect } from "../../../extensions/behaviors/operations/instance-deploy/deploy_direct.ts";

const root = deployDirect({
  source: {
    resolveDevelopment: (_home, source) => {
      if (
        source.git_read.github_token !== Deno.env.get("ORBIT_DEPLOY_SENTINEL")
      ) {
        throw new Error("Private Git binding failed");
      }
      if (Deno.env.get("ORBIT_DEPLOY_TEST_UNCERTAIN_SOURCE") === "1") {
        throw new Error("uncertain local source mutation");
      }
      return Promise.resolve({ commit: "a".repeat(40) });
    },
    checkoutDevelopment: (_home, target) =>
      Promise.resolve({ commit: target.commit }),
    inspectDevelopment: (home) => {
      if (home.user !== "orbit") throw new Error("Wrong admitted account");
      if (Deno.env.get("ORBIT_DEPLOY_TEST_FAIL_AFTER_STEPS") === "1") {
        throw new Error(Deno.env.get("ORBIT_DEPLOY_SENTINEL"));
      }
      return Promise.resolve({ commit: "a".repeat(40) });
    },
    prepareRelease: () => {
      throw new Error("Unexpected production action");
    },
    inspectRelease: () => {
      throw new Error("Unexpected production action");
    },
    activateRelease: () => {
      throw new Error("Unexpected production action");
    },
    pruneReleases: () => {
      throw new Error("Unexpected production action");
    },
  },
  inspectRunner: (runner) => Promise.resolve({ runner, gone: true }),
  run: (step, script) => {
    if (
      step.target.variant !== "checkout" || step.target.user !== "orbit" ||
      !script.includes(Deno.env.get("ORBIT_DEPLOY_SENTINEL")!)
    ) throw new Error("Private command binding failed");
    if (Deno.env.get("ORBIT_DEPLOY_TEST_UNCERTAIN_STEP") === "1") {
      throw new Error("lost worker during synthetic step");
    }
    return Promise.resolve({
      exit_code: 0,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  },
});
export const behavior = {
  ...root,
  type: "@fixture/dev-deploy-direct",
  globalArguments: z.object({}),
  methods: {
    failure: {
      ...root.methods.failure,
      execute: (
        args: Parameters<typeof root.methods.failure.execute>[0],
        context: Parameters<typeof root.methods.failure.execute>[1],
      ) =>
        root.methods.failure.execute(args, {
          ...context,
          globalArgs: {
            gatewayUrl: Deno.env.get("ORBIT_DEPLOY_TEST_URL")!,
            caFile: Deno.env.get("ORBIT_DEPLOY_TEST_CA")!,
          },
        }),
    },
    run: {
      ...root.methods.run,
      execute: (
        args: Parameters<typeof root.methods.run.execute>[0],
        context: Parameters<typeof root.methods.run.execute>[1],
      ) =>
        root.methods.run.execute(args, {
          ...context,
          globalArgs: {
            gatewayUrl: Deno.env.get("ORBIT_DEPLOY_TEST_URL")!,
            caFile: Deno.env.get("ORBIT_DEPLOY_TEST_CA")!,
          },
        }),
    },
  },
};
