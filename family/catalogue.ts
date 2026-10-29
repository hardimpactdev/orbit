import { z } from "npm:zod@4.4.3";

const Command = z.strictObject({
  name: z.string().min(1),
  mutates: z.boolean(),
});
export const Catalogue = z.strictObject({
  schema: z.literal("orbit.family-catalogue/v1"),
  agent_contract_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  commands: z.strictObject({
    "orbit-agent": z.array(Command),
    gateway: z.array(Command),
  }),
  projections: z.array(z.string().min(1)),
  invariants: z.array(z.string().min(1)),
  native_methods: z.array(z.strictObject({
    behavior_type: z.string().min(1),
    method: z.string().min(1),
    transport: z.enum(["orbit-agent", "gateway"]),
    command_input: z.literal("command"),
    keyed: z.boolean(),
  })),
});

export async function catalogueDigest(bytes: string): Promise<string> {
  const hash = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(bytes),
  );
  return Array.from(
    new Uint8Array(hash),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
}

export async function readCatalogue(bytes: string, expected: string) {
  if (await catalogueDigest(bytes) !== expected) {
    throw new Error("Family catalogue pin mismatch");
  }
  const catalogue = Catalogue.parse(JSON.parse(bytes));
  for (
    const list of [
      catalogue.commands["orbit-agent"].map((entry) => entry.name),
      catalogue.commands.gateway.map((entry) => entry.name),
      catalogue.projections,
      catalogue.invariants,
      catalogue.native_methods.map((entry) =>
        `${entry.behavior_type}/${entry.method}`
      ),
    ]
  ) {
    if (new Set(list).size !== list.length) {
      throw new Error("Duplicate catalogue entry");
    }
  }
  return catalogue;
}
