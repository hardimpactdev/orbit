import { z } from "npm:zod@4.4.3";
import {
  image,
  labels,
  metricsRuntimeSpec,
  name,
  services,
  volume,
} from "./metrics_runtime_spec.ts";
const Args = z.object({
  assignment_id: z.number().int().positive(),
  address: z.ipv4(),
  files: z.array(z.object({ path: z.string(), content: z.string() })),
}).strict();
const Service = z.object({
  name: z.string(),
  image: z.string(),
  unit: z.string(),
  volume_args: z.array(z.string()),
});
const Plan = z.object({
  services: z.array(Service),
  files: z.array(z.object({ path: z.string(), content: z.string() })),
});
/** Public data only. Native SSH and official cfgmgmt own all effects. */
export const behavior = {
  type: "@nckrtl/metrics-role-plan",
  version: "2026.11.01.1",
  resources: {
    plan: { schema: Plan, lifetime: "infinite", garbageCollection: 10 },
  },
  methods: {
    plan: {
      description:
        "Render the canonical shared public container specification as systemd units",
      arguments: Args,
      execute: async (
        raw: z.infer<typeof Args>,
        ctx: {
          writeResource: (
            s: string,
            n: string,
            v: unknown,
          ) => Promise<unknown>;
        },
      ) => {
        const a = Args.parse(raw),
          configuration = Object.fromEntries(
            a.files.map((f) => [f.path, f.content]),
          );
        if (
          Object.keys(configuration).some((p) => p.endsWith("/admin-password"))
        ) throw new Error("metrics.public_plan_private_field");
        const units = await Promise.all(services.map(async (s) => {
          const spec = await metricsRuntimeSpec(
            s,
            a.assignment_id,
            a.address,
            configuration,
          );
          const quoted = spec.args.map((v) =>
            JSON.stringify(v.replaceAll("%", "%%")).replaceAll(
              "$",
              "$$",
            )
          ).join(" ");
          return {
            name: name(s),
            image: image(s),
            volume_args: [
              "/usr/bin/docker",
              "volume",
              "create",
              ...Object.entries(labels(s, true)).sort().flatMap((
                [k, v],
              ) => ["--label", `${k}=${v}`]),
              volume(s),
            ],
            unit:
              `[Unit]\nDescription=Orbit Metrics ${s}\nAfter=docker.service network-online.target\nRequires=docker.service\n[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/docker ${quoted}\n`,
          };
        }));
        const result = Plan.parse({
          services: units,
          files: [
            {
              path: "/etc/orbit/metrics/.orbit-owner",
              content: "metrics\n",
            },
            ...units.map((s) => ({
              path: "/etc/systemd/system/" + s.name + ".service",
              content: s.unit,
            })),
          ],
        });
        await ctx.writeResource("plan", "plan", result);
        return {};
      },
    },
  },
};
