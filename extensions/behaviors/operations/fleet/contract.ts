import { z } from "npm:zod@4.4.3";

export const PeerDnsInput = z.object({
  server: z.string(),
  dns_server: z.string(),
  public_key: z.string(),
  domains: z.array(z.string()).nonempty(),
});
export const Inspection = z.object({
  state: z.string(),
  hooks: z.string(),
  dns: z.string(),
  domains: z.string(),
  default_route: z.string(),
  uplink: z.boolean(),
});
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
});
export function matchesPolicy(
  input: z.infer<typeof PeerDnsInput>,
  observed: z.infer<typeof Inspection>,
): boolean {
  const setting = (value: string) =>
    value.includes(":") ? value.slice(value.indexOf(":") + 1).trim() : null;
  return observed.state ===
      ["orbit", input.server, ...input.domains].join("\n") &&
    setting(observed.dns) === input.server &&
    setting(observed.domains)?.split(" ").sort().join(" ") ===
      input.domains.map((d) => `~${d}`).sort().join(" ") &&
    observed.hooks ===
      `PostUp = resolvectl dns %i ${input.server}; resolvectl domain %i ${
        input.domains.map((d) => `\\~${d}`).join(" ")
      }` &&
    setting(observed.default_route) === "no";
}
