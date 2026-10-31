import { z } from "npm:zod@4.4.3";
import type { ValkeyClient } from "./valkey_client.ts";

export const AclUser = z.object({
  name: z.string().min(1).max(128).refine((v) => !/[\s\0]/.test(v)),
  rules: z.string().min(1).max(65536).refine((v) => !/[\0\r\n]/.test(v)),
  hashes: z.array(z.string().regex(/^[a-f0-9]{64}$/)).max(32),
}).strict();
export type User = z.infer<typeof AclUser>;
export type Client = Pick<ValkeyClient, "command" | "close">;
export function aclState(user: User) {
  const groups: Record<string, string[]> = {
    flags: [],
    keys: [],
    channels: [],
    commands: [],
  };
  for (const token of user.rules.split(/\s+/)) {
    if (token === "sanitize-payload") continue;
    const key = /^[~%]/.test(token) || ["allkeys", "resetkeys"].includes(token)
      ? "keys"
      : token.startsWith("&") ||
          ["allchannels", "resetchannels"].includes(token)
      ? "channels"
      : /^[+-]/.test(token) || ["allcommands", "nocommands"].includes(token)
      ? "commands"
      : "flags";
    groups[key].push(token);
  }
  for (const key of ["flags", "keys", "channels"]) groups[key].sort();
  return JSON.stringify([groups, [...user.hashes].sort()]);
}
export async function liveAcl(client: Client) {
  const lines = z.array(z.string().max(1024 * 1024)).max(10000).parse(
    await client.command("ACL", "LIST"),
  );
  const users = new Map<string, User>();
  for (const line of lines) {
    const [prefix, name, ...tokens] = line.trim().split(/\s+/);
    if (prefix !== "user" || !name || users.has(name)) {
      throw new Error("Invalid ACL state");
    }
    const hashes = tokens.filter((t) => t.startsWith("#")).map((t) =>
      t.slice(1)
    );
    const rules = tokens.filter((t) => !t.startsWith("#")).join(" ");
    users.set(name, AclUser.parse({ name, rules, hashes }));
  }
  return users;
}
export async function passwordHash(password: string) {
  return Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(password)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
}
export async function convergeAcl(
  client: Client,
  users: User[],
  defaultOpen: boolean,
) {
  const live = await liveAcl(client);
  const expected = new Map(users.map((user) => [user.name, user]));
  if (expected.size !== users.length) {
    throw new Error("Duplicate admitted ACL users");
  }
  for (const [name, user] of expected) {
    if (live.has(name) && aclState(live.get(name)!) === aclState(user)) {
      continue;
    }
    await client.command(
      "ACL",
      "SETUSER",
      name,
      "reset",
      ...user.rules.split(/\s+/),
      ...user.hashes.map((hash) => `#${hash}`),
    );
  }
  const stale = [...live.keys()].filter((name) =>
    !expected.has(name) && !(defaultOpen && name === "default")
  ).sort();
  if (stale.length) await client.command("ACL", "DELUSER", ...stale);
  await client.command("ACL", "SAVE");
}
