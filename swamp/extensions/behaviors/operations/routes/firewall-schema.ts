import { z } from "npm:zod@4.4.3";

const Shape = z.object({
  comment: z.string().min(1).max(256),
  action: z.enum(["allow", "deny"]),
  direction: z.enum(["in", "out", "fwd"]),
  source: z.string(),
  destination: z.string(),
  port: z.string(),
  protocol: z.enum(["any", "tcp", "udp"]),
  in_interface: z.string().nullable().default(null),
  out_interface: z.string().nullable().default(null),
  family: z.enum(["v4", "v6"]).nullable().default(null),
}).strict();
export const FirewallRules = z.object({
  desired: z.array(
    z.object({ shape: Shape, replace_port: z.boolean() }).strict(),
  ).max(4096),
  remove: z.array(Shape).max(4096),
  enable: z.boolean(),
}).strict();
