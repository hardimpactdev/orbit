// Loaded only by the isolated integration test. Never returns enrollment plaintext.
import { z } from "npm:zod@4";
import {
  type MaintenanceContext,
  workerControl,
} from "../../../extensions/behaviors/_lib/swamp_maintenance.ts";
export const behavior = {
  type: "@fixture/enrollment-probe",
  version: "2026.10.27.1",
  globalArguments: z.object({
    enrollmentVault: z.string().default("default"),
    workerUrl: z.string().default("ws://unused"),
    serveUrl: z.string().default("ws://unused"),
    serveTokenFile: z.string().default("unused"),
    caFile: z.string().default("unused"),
  }),
  resources: {
    receipt: {
      description: "Public enrollment test result",
      schema: z.object({ minted: z.boolean(), revoked: z.boolean() }),
      lifetime: "1d",
      garbageCollection: 10,
    },
  },
  methods: {
    server: {
      description:
        "Mint worker-scoped server authentication with the enrollment deadline",
      arguments: z.object({ name: z.string() }),
      execute: async (input: { name: string }, context: MaintenanceContext) => {
        const control = workerControl(
          context,
          () => Promise.reject(new Error("no Gateway request expected")),
        );
        const token = await control.token(input.name);
        if (!token) throw new Error("enrollment absent");
        await control.mintServer(input.name, token.expiresAt);
        return { dataHandles: [] };
      },
    },
    environment: {
      description: "Verify dispatch keeps serve environment out of the worker",
      arguments: z.object({}),
      execute: () => {
        if (
          Deno.env.get("MAINTENANCE_SERVE_CANARY") !== undefined ||
          Deno.env.get("MAINTENANCE_WORKER_CANARY") !== "worker"
        ) throw new Error("dispatch environment was not isolated");
        return Promise.resolve({ dataHandles: [] });
      },
    },
    verify: {
      description: "Exercise the real built-in vault-backed enrollment API",
      arguments: z.object({}),
      execute: async (_input: unknown, context: MaintenanceContext) => {
        const executable = await new Deno.Command(Deno.execPath(), {
          args: ["--version"],
          stdout: "piped",
          stderr: "piped",
        }).output();
        if (
          !executable.success ||
          !new TextDecoder().decode(executable.stdout).startsWith("swamp ")
        ) throw new Error("workflow transport executable is not Swamp");
        const control = workerControl(
          context,
          () => Promise.reject(new Error("no Gateway request expected")),
        );
        const name = "fixture-group-7";
        await control.mint(name);
        const token = await control.token(name);
        if (
          !token || token.state !== "unused" || !(await control.secret(token))
        ) {
          throw new Error("no vault-backed mint");
        }
        await control.mintServer(name, token.expiresAt);
        const server = await control.serverToken(name);
        if (
          !server || !(await control.secret(server)) ||
          Date.parse(server.expiresAt) > Date.parse(token.expiresAt) + 1000
        ) throw new Error("no paired server credential");
        await control.revoke(name);
        await control.revokeServer(name);
        if ((await control.serverToken(name))?.state !== "revoked") {
          throw new Error("no server revoke receipt");
        }
        if ((await control.token(name))?.state !== "revoked") {
          throw new Error("no revoke receipt");
        }
        return {
          dataHandles: [
            await context.writeResource("receipt", "receipt", {
              minted: true,
              revoked: true,
            }),
          ],
        };
      },
    },
  },
};
