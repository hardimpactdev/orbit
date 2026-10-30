import { z } from "npm:zod@4.4.3";
import {
  directOperation,
  GatewayOperation,
} from "../operations/_lib/direct.ts";
export const behavior = {
  type: "@fixture/private-material",
  version: "2026.10.30.1",
  globalArguments: z.object({}),
  resources: {},
  methods: {
    apply: {
      description: "Native shared private transport regression fixture",
      arguments: z.object({
        failure: z.boolean(),
        attack: z.string(),
        run_id: z.string().uuid(),
      }),
      execute: async (
        args: { failure: boolean; attack: string; run_id: string },
      ) => {
        const secret = Deno.env.get("ORBIT_PRIVATE_TEST_SENTINEL");
        if (!secret) throw new Error("Fixture sentinel missing");
        const identity = {
          operation_id: 17,
          node_id: 9,
          kind: "sample.apply",
          run_id: args.run_id,
        };
        let stores = 0;
        const gateway = new GatewayOperation((method, path, raw) => {
          if (path.includes("private-material")) {
            if (method !== "GET" || !path.endsWith(args.run_id)) {
              throw new Error("Unbound private read");
            }
            return Promise.resolve({
              status: 200,
              body: { data: { credential: secret } },
            });
          }
          if (path.endsWith("secret-output")) {
            if (method !== "POST") {
              throw new Error("Wrong private write method");
            }
            const body = z.object({
              run_id: z.literal(args.run_id),
              outputs: z.object({ credential: z.literal(secret) }).strict(),
            }).strict().parse(raw);
            if (body.outputs.credential !== secret) {
              throw new Error("Wrong output");
            }
            stores++;
            return Promise.resolve({
              status: 200,
              body: {
                data: {
                  stored: {
                    credential: { $secret: "fixture.credential", id: 3 },
                  },
                },
              },
            });
          }
          return Promise.resolve({
            status: 200,
            body: {
              data: {
                id: 17,
                node_id: 9,
                kind: "sample.apply",
                status: "queued",
                input: {},
                result: null,
                cancel_requested: false,
              },
            },
          });
        }, identity);
        const result = await directOperation(identity, gateway, async () => {
          const returned = await gateway.withPrivateMaterial(
            async (material) => {
              if (material.credential !== secret || args.failure) {
                throw new Error(secret);
              }
              await gateway.storeSecretOutput({
                credential: material.credential,
              });
              if (args.attack === "direct") return material as never;
              if (args.attack === "nested") {
                return { nested: [material] } as never;
              }
              if (args.attack === "getter") {
                return {
                  get value() {
                    throw new Error(secret);
                  },
                } as never;
              }
              if (args.attack === "toJSON") {
                let count = 0;
                return {
                  toJSON() {
                    if (++count > 1) throw new Error(secret);
                    return material;
                  },
                } as never;
              }
            },
          );
          if (returned !== undefined || JSON.stringify({ returned }) !== "{}") {
            throw new Error("Private callback returned data");
          }
          return { stored: stores === 1 };
        }, z.object({ stored: z.literal(true) }).strict().parse);
        return result;
      },
    },
  },
};
