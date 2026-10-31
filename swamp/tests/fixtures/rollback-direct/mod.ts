import { z } from "npm:zod@4.4.3";
import { deployDirect } from "../../../extensions/behaviors/operations/instance-deploy/deploy_direct.ts";
import { processAction } from "../../../extensions/behaviors/operations/processes-schedules/process-control.ts";

const home = "/home/orbit-app-102";
const release = { name: "retained", commit: "a".repeat(40) };
const previous = { name: "previous", commit: "b".repeat(40) };
let selected = Deno.env.get("ORBIT_ROLLBACK_CURRENT") === "true"
  ? release
  : previous;
const assert = (condition: boolean) => {
  if (!condition) throw new Error("Fixture boundary mismatch");
};
const event = async (name: string) => {
  const response = await fetch(
    Deno.env.get("ORBIT_ROLLBACK_TEST_URL")! + "/fixture/" + name,
    { method: "POST" },
  );
  await response.text();
  assert(response.ok);
};
const forbidden = () => {
  throw new Error("Rollback attempted a deployment-only effect");
};
const action = processAction((name, input) => {
  assert(input.process_id === 14 || name === "process.check-release");
  if (name === "process.check-release") {
    assert(input.directory === home + "/current");
    return Promise.resolve({ available: true });
  }
  if (name === "process.control") {
    assert(input.operation === "restart");
    assert(input.name === "orbit-process-14-worker.service");
    return Promise.resolve({ state: "active" });
  }
  if (name === "process.status") return Promise.resolve({ state: "active" });
  return forbidden();
});
const root = deployDirect({
  source: {
    resolveDevelopment: forbidden,
    checkoutDevelopment: forbidden,
    inspectDevelopment: forbidden,
    prepareRelease: forbidden,
    inspectRelease: forbidden,
    activateRelease: forbidden,
    pruneReleases: forbidden,
  },
  run: forbidden,
  rollback: {
    source: {
      resolveDevelopment: forbidden,
      checkoutDevelopment: forbidden,
      inspectDevelopment: forbidden,
      prepareRelease: forbidden,
      pruneReleases: forbidden,
      inspectRelease: async (target, name) => {
        assert(target.user === "orbit-app-102" && target.home === home);
        assert(target.instance === 101);
        assert(name === undefined || name === release.name);
        await event(
          name === undefined ? "inspect-current" : "inspect-retained",
        );
        return { release: name === undefined ? selected : release };
      },
      activateRelease: async (target, retained) => {
        assert(target.user === "orbit-app-102" && target.home === home);
        assert(
          retained.name === release.name && retained.commit === release.commit,
        );
        selected = retained;
        await event("activated");
        return retained;
      },
    },
    php: async (input, _deadline, port) => {
      assert(input.project_id === 102 && input.php === null);
      assert((await port.current()).cancel_requested === false);
      await event("php-refresh");
      return {
        confirmed: true,
        changed: false,
        deferred_count: 0,
        stale_master_count: 0,
      };
    },
    restart: async (kind, input, signal) => {
      assert(kind === "process.restart" && input.vite === null);
      assert(input.directory === home + "/current");
      await event("process-restart");
      return await action(kind, input, signal);
    },
  },
});
export const behavior = {
  ...root,
  type: "@fixture/rollback-direct",
  globalArguments: z.object({}),
  methods: {
    rollback: {
      ...root.methods.rollback,
      execute: (
        args: Parameters<typeof root.methods.rollback.execute>[0],
        context: Parameters<typeof root.methods.rollback.execute>[1],
      ) =>
        root.methods.rollback.execute(args, {
          ...context,
          globalArgs: {
            gatewayUrl: Deno.env.get("ORBIT_ROLLBACK_TEST_URL")!,
            caFile: Deno.env.get("ORBIT_ROLLBACK_TEST_CA")!,
          },
        }),
    },
  },
};
