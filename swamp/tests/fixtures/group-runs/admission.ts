import { z } from "npm:zod@4";
import { behavior as gateway } from "../../../extensions/behaviors/orbit_gateway.ts";
import {
  type Admission,
  reserveMaintenance,
} from "../../../extensions/behaviors/_lib/project_admission.ts";

type Context = {
  definition: { name: string };
  queryData: (predicate: string, select: string) => Promise<Admission[]>;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

// Uses the production admission rule and resource schema under Swamp's real
// behavior lock. No test mutex or in-memory admission cache serializes calls.
export const behavior = {
  ...gateway,
  type: "@fixture/maintenance-admission",
  resources: {
    admission: gateway.resources.admission,
    decision: {
      description: "Result of a concurrent admission request",
      schema: z.object({ admitted: z.boolean(), projectId: z.number() }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    seed: {
      description: "Reserve three maintenance slots",
      arguments: z.object({}),
      execute: async (_args: unknown, ctx: Context) => {
        for (const projectId of [1, 2, 3]) {
          await ctx.writeResource("admission", `admission-${projectId}`, {
            key: `k${projectId}`,
            projectId,
            mode: "detect",
            workItem: null,
            admittedAt: new Date().toISOString(),
            released: false,
          });
        }
        return { dataHandles: [] };
      },
    },
    admit: {
      description: "Try the real shared cap with room for only one request",
      arguments: z.object({ projectId: z.number() }),
      execute: async ({ projectId }: { projectId: number }, ctx: Context) => {
        const admissions = await ctx.queryData(
          `modelName == ${
            JSON.stringify(ctx.definition.name)
          } && name.startsWith("admission-")`,
          "attributes",
        );
        const result = await reserveMaintenance({
          key: `k${projectId}`,
          projectId,
          mode: "package",
          workItem: null,
        }, {
          admissions,
          existing: [],
          http: async () => {
            // Two unlocked calls would both read three, then each save a fourth.
            await new Promise((resolve) => setTimeout(resolve, 300));
            return { status: 200, body: { data: [] } };
          },
          save: async (a) => {
            await ctx.writeResource("admission", `admission-${projectId}`, a);
          },
        });
        const handle = await ctx.writeResource(
          "decision",
          `decision-${projectId}`,
          {
            admitted: result.admitted,
            projectId,
          },
        );
        return { dataHandles: [handle] };
      },
    },
  },
};
