import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

export const ProcessAccountHome = "/__orbit_process_account_home__";
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
  user !== "root"
);

/** Resolve only the admitted unit account on its placed Node; private bytes stay in memory. */
export async function processAccountContent(
  content: string,
  user: string,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<string> {
  User.parse(user);
  if (
    !content.includes(`\nUser=${user}\n`) ||
    !content.includes(ProcessAccountHome)
  ) {
    throw new Error("The Process account template is invalid");
  }
  signal?.throwIfAborted();
  const result = await transport({
    command: "/usr/bin/getent",
    args: ["passwd", user],
    timeoutMs: 10000,
  }, signal);
  const rows = result.stdout.trimEnd().split("\n");
  const fields = rows[0]?.split(":") ?? [];
  const home = fields[5];
  if (
    !result.success || rows.length !== 1 || fields.length !== 7 ||
    fields[0] !== user ||
    !/^[1-9][0-9]*$/.test(fields[2]) || !/^[0-9]+$/.test(fields[3]) ||
    !home?.startsWith("/") || home.length > 4096 || /[\p{Cc}]/u.test(home) ||
    home.split("/").slice(1).some((part) => ["", ".", ".."].includes(part))
  ) {
    throw new Error("The Process account is unavailable");
  }
  signal?.throwIfAborted();
  const directive = home.replace(
    /[ "'$%\\]/g,
    (char) =>
      char === "%"
        ? "%%"
        : `\\x${char.charCodeAt(0).toString(16).padStart(2, "0")}`,
  );
  const argument = home.replace(
    /[\\"$%]/g,
    (char) => char === "$" ? "$$" : char === "%" ? "%%" : `\\${char}`,
  );
  return content.split("\n").map((line) =>
    line.replaceAll(
      ProcessAccountHome,
      () => line.startsWith("ExecStart=") ? argument : directive,
    )
  ).join("\n");
}
