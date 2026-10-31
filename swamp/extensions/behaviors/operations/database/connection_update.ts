import { z } from "npm:zod@4.4.3";
import { nativeEffect } from "./native_effect.ts";
import { ValkeyClient } from "./valkey_client.ts";
import type { PrivateMaterialUse } from "./private_material.ts";

const registration = { type: "@nckrtl/database-connection-update" } as const;

const Input = z.discriminatedUnion("validate_index", [
  z.object({ validate_index: z.literal(false) }).strict(),
  z.object({
    validate_index: z.literal(true),
    host: z.ipv4(),
    port: z.number().int().min(1).max(65535),
    index: z.string().regex(/^[0-9]+$/).max(255),
  }).strict(),
]);
const Material = z.object({ password: z.string().min(1).max(16384) }).strict();
export const UpdateResult = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  z.object({
    error_code: z.enum([
      "database.index_invalid",
      "database.server_command_failed",
    ]),
  }).strict(),
]);
type Client = Pick<ValkeyClient, "command" | "close">;

/** Read configured limits on the server Node; no credentials or replies leave this callback. */
export async function updateConnection(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  connect: (host: string, port: number) => Promise<Client> =
    ValkeyClient.connect,
): Promise<z.infer<typeof UpdateResult>> {
  try {
    const input = Input.parse(raw);
    signal?.throwIfAborted();
    if (!input.validate_index) return { backend: "active" };
    let result: z.infer<typeof UpdateResult> | undefined;
    await use(async (rawMaterial) => {
      const { password } = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      const client = await connect(input.host, input.port);
      try {
        await client.command("AUTH", "default", password);
        signal?.throwIfAborted();
        const reply = z.tuple([
          z.literal("databases"),
          z.string().regex(/^[0-9]+$/).max(20),
        ]).parse(await client.command("CONFIG", "GET", "databases"));
        const count = Number(reply[1]);
        if (!Number.isSafeInteger(count) || count < 1) {
          throw new Error("Invalid database count");
        }
        const index = Number(input.index);
        result = Number.isSafeInteger(index) && index < count
          ? { backend: "active" }
          : { error_code: "database.index_invalid" };
      } finally {
        client.close();
      }
    });
    if (!result) throw new Error("No update validation");
    return result;
  } catch {
    return { error_code: "database.server_command_failed" };
  }
}

export function connectionUpdate(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  connect: (host: string, port: number) => Promise<Client> =
    ValkeyClient.connect,
) {
  return nativeEffect(
    "database.update",
    registration.type,
    UpdateResult,
    (input, gateway, signal) =>
      updateConnection(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        connect,
      ),
    makeHttp,
  );
}
export const behavior = {
  ...connectionUpdate(),
  type: "@nckrtl/database-connection-update",
};
