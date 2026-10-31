import { z } from "npm:zod@4.4.3";
import { deployDirect } from "../../../extensions/behaviors/operations/instance-deploy/deploy_direct.ts";

const root = deployDirect({
  invoke: (call) => {
    const secret = Deno.env.get("ORBIT_DEPLOY_SENTINEL");
    if (call.pin !== "d".repeat(64)) throw new Error("Wrong admitted pin");
    const commit = "a".repeat(40);
    if (call.name === "instance.resolve-development-target") {
      if (Deno.env.get("ORBIT_DEPLOY_TEST_UNCERTAIN_SOURCE") === "1") {
        throw new Error(secret);
      }
      if (
        !call.sudo || !call.mutates ||
        (call.input.git_read as { github_token: string }).github_token !==
          secret
      ) {
        throw new Error("Private Git binding failed");
      }
      return Promise.resolve({ commit });
    }
    if (call.name === "instance.checkout-development-default") {
      if (!call.sudo || !call.mutates) {
        throw new Error("Wrong checkout privilege");
      }
      return Promise.resolve({ commit });
    }
    if (call.name === "instance.inspect-deploy-step") {
      if (!call.sudo || call.mutates) throw new Error("Wrong probe privilege");
      return Promise.resolve({ runner: call.input.runner, gone: true });
    }
    if (call.name === "account.lookup") {
      if (call.sudo || call.mutates) throw new Error("Wrong account privilege");
      return Promise.resolve({ user: "orbit", uid: 1000, group: "developers" });
    }
    if (call.name === "instance.inspect-development-source") {
      if (Deno.env.get("ORBIT_DEPLOY_TEST_FAIL_AFTER_STEPS") === "1") {
        throw new Error(secret);
      }
      if (call.sudo || call.mutates || call.input.group !== "developers") {
        throw new Error("Wrong inspection privilege");
      }
      return Promise.resolve({
        origin: "https://example.test/proof.git",
        branch: "main",
        commit,
      });
    }
    throw new Error("Unexpected local command");
  },
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
