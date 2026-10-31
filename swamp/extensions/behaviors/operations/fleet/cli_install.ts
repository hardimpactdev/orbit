import { z } from "npm:zod@4.4.3";
import { type NodeCommand, run } from "../_lib/node-commands.ts";
import { CliInstallProgram } from "./cli_install_program.ts";

export const CliInstallInput = z.object({
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) =>
    v !== "root"
  ),
  release: z.object({
    version: z.string().regex(/^0\.[1-9][0-9]{0,9}\.0$/),
    url: z.url().refine((v) => {
      const url = new URL(v);
      return url.protocol === "https:" && !url.username && !url.password;
    }),
    sha256: z.string().regex(/^[a-f0-9]{64}$/),
  }).strict().nullable(),
  profile: z.string().max(1024 * 1024),
}).strict();
export const CliInstallResult = z.union([
  z.object({ installed: z.string().nullable(), configured: z.boolean() })
    .strict(),
  z.object({
    error_code: z.enum([
      "cli.foreign_binary",
      "cli.release_needed",
      "cli.update_busy",
      "cli.download_failed",
      "cli.checksum_mismatch",
      "cli.candidate_invalid",
      "cli.install_failed",
    ]),
  }).strict(),
]);

/** Shared lower for one placed Node step. Policy and publication stay here. */
export async function installCli(
  raw: z.infer<typeof CliInstallInput>,
  { command = run, signal }: { command?: NodeCommand; signal?: AbortSignal } =
    {},
): Promise<z.infer<typeof CliInstallResult>> {
  const input = CliInstallInput.parse(raw);
  signal?.throwIfAborted();
  // Once publication starts, finish the bounded atomic effect and return its result.
  const reply = await command(
    ["/usr/bin/python3", "-I", "-c", CliInstallProgram],
    new TextEncoder().encode(JSON.stringify(input)),
    "root",
    { deadlineMs: 720000, maxOutputBytes: 4096 },
  );
  if (!reply.success) {
    throw new Error("CLI installation has no bounded outcome");
  }
  const result = CliInstallResult.parse(
    JSON.parse(new TextDecoder().decode(reply.stdout)),
  );
  if (
    "installed" in result && result.installed !== null &&
    result.installed !== input.release?.version
  ) throw new Error("The CLI installer returned a different installed version");
  return result;
}
