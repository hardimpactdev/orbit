import { z } from "npm:zod@4.4.3";
import { nativeEffect } from "./native_effect.ts";
import { ValkeyClient } from "./valkey_client.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

const Input = z.object({
  host: z.ipv4(),
  port: z.number().int().min(1).max(65535),
  default_open: z.boolean(),
}).strict();
const Material = z.object({ password: z.string().min(1).max(16384) }).strict();
export const CloseResult = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  z.object({
    error_code: z.enum([
      "database.server_in_use",
      "database.server_command_failed",
    ]),
  }).strict(),
]);
type Client = Pick<ValkeyClient, "command" | "close">;

/** No anonymous retry: nopass accepts AUTH; a resumed closure uses its saved password. */
export async function closeServer(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  connect: (host: string, port: number) => Promise<Client> =
    ValkeyClient.connect,
) {
  try {
    const input = Input.parse(raw);
    let result: z.infer<typeof CloseResult> | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      let client: Client | undefined;
      try {
        client = await connect(input.host, input.port);
        await client.command("AUTH", "default", material.password);
        if (!input.default_open) {
          await client.command("PING");
          result = { backend: "active" };
          return;
        }
        const acl = z.array(z.unknown()).max(32).parse(
          await client.command("ACL", "GETUSER", "default"),
        );
        const flagsAt = acl.indexOf("flags");
        if (flagsAt < 0) throw new Error("No default flags");
        const wasOpen = z.array(z.string()).max(32).parse(acl[flagsAt + 1])
          .includes("nopass");
        const own = z.number().int().parse(
          await client.command("CLIENT", "ID"),
        );
        const clients = z.string().max(1024 * 1024).parse(
          await client.command("CLIENT", "LIST", "USER", "default"),
        );
        const foreign = clients.split(/\r?\n/).filter(Boolean).some((line) =>
          line.split(" ").find((field) => field.startsWith("id=")) !==
            `id=${own}`
        );
        if (foreign) {
          result = { error_code: "database.server_in_use" };
          return;
        }
        signal?.throwIfAborted();
        const hash = Array.from(
          new Uint8Array(
            await crypto.subtle.digest(
              "SHA-256",
              new TextEncoder().encode(material.password),
            ),
          ),
          (b) => b.toString(16).padStart(2, "0"),
        ).join("");
        await client.command(
          "ACL",
          "SETUSER",
          "default",
          "resetpass",
          `#${hash}`,
        );
        try {
          await client.command("ACL", "SAVE");
        } catch {
          if (wasOpen) {
            await client.command(
              "ACL",
              "SETUSER",
              "default",
              "resetpass",
              "nopass",
            );
          }
          throw new Error("Valkey save failed");
        }
        await client.command(
          "CLIENT",
          "KILL",
          "USER",
          "default",
          "SKIPME",
          "yes",
        );
        result = { backend: "active" };
      } catch {
        result = { error_code: "database.server_command_failed" };
      } finally {
        client?.close();
      }
    });
    if (!result) throw new Error("No completed closure");
    return result;
  } catch {
    throw new Error("database.server_close_outcome_unknown");
  }
}

export function serverClose(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  connect = ValkeyClient.connect,
) {
  return nativeEffect(
    "database_server.close",
    "@nckrtl/database-server-close",
    CloseResult,
    (input, gateway, signal) =>
      closeServer(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        connect,
      ),
    makeHttp,
  );
}
export const behavior = {
  ...serverClose(),
  type: "@nckrtl/database-server-close",
};
