import { z } from "npm:zod@4.4.3";
import { localSystemCommand } from "../node/system_command.ts";

const Input = z.object({
  volume: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.-]{1,254}$/),
  image: z.string().regex(/^valkey\/valkey:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/),
  acl: z.string().min(1).max(1024 * 1024),
}).strict();
export type AclInput = z.infer<typeof Input>;
const WRITE =
  "umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl";
const hash = (token: string) => /^#[0-9a-f]{64}$/.test(token);
const printable = (token: string) => /^[!-~]+$/.test(token);
function managedRules(rules: string[]) {
  if (rules.shift() !== "on") return false;
  let patterns = 0;
  while (rules[0]?.startsWith("~")) {
    if (!printable(rules.shift()!.slice(1))) return false;
    patterns++;
  }
  if (
    !patterns || rules.shift() !== "resetchannels" || rules.shift() !== "-@all"
  ) return false;
  if (rules.splice(-4).join(" ") !== "-@admin -@dangerous -function -script") {
    return false;
  }
  return rules.every((token) =>
    token.startsWith("+") && printable(token.slice(1))
  );
}

/** The exact default and managed-user ACL frame formerly enforced in valkey_acl.rs. */
export function validValkeyAcl(acl: string) {
  if (!acl.endsWith("\n")) return false;
  return acl.slice(0, -1).split("\n").every((line, index) => {
    const tokens = line.split(" ");
    if (
      tokens.length < 3 || tokens[0] !== "user" ||
      !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tokens[1])
    ) return false;
    const flags = tokens.slice(2);
    const hashes = flags.filter(hash).length;
    const rules = flags.filter((token) =>
      !hash(token) && token !== "sanitize-payload"
    );
    if (index === 0) {
      return tokens[1] === "default" &&
        ((hashes === 1 && rules.join(" ") === "on ~* &* +@all") ||
          (hashes === 0 && rules.join(" ") === "on nopass ~* &* +@all"));
    }
    return tokens[1] !== "default" && hashes === 1 && managedRules(rules);
  });
}

export type AclDocker = (
  args: string[],
  acl: string,
  signal?: AbortSignal,
) => Promise<boolean>;
/** Fixed local sudo Docker call. ACL bytes enter stdin only; diagnostics are discarded. */
export const runAclDocker: AclDocker = async (args, acl, signal) => {
  const result = await localSystemCommand({
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/docker", ...args],
    stdin: acl,
    timeoutMs: 300_000,
  }, signal);
  return result.success;
};

export async function writeValkeyAcl(
  raw: unknown,
  signal?: AbortSignal,
  docker = runAclDocker,
): Promise<
  Record<string, never> | {
    error_code: "database.valkey_acl_invalid" | "database.valkey_acl_failed";
  }
> {
  let input: AclInput;
  try {
    input = Input.parse(raw);
    if (!validValkeyAcl(input.acl)) {
      return { error_code: "database.valkey_acl_invalid" };
    }
  } catch {
    return { error_code: "database.valkey_acl_invalid" };
  }
  try {
    const ok = await docker(
      [
        "run",
        "--rm",
        "-i",
        "--network",
        "none",
        "--mount",
        `type=volume,source=${input.volume},target=/data`,
        "--entrypoint",
        "sh",
        input.image,
        "-c",
        WRITE,
      ],
      input.acl,
      signal,
    );
    return ok ? {} : { error_code: "database.valkey_acl_failed" };
  } catch {
    return { error_code: "database.valkey_acl_failed" };
  }
}
