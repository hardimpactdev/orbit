// Codex argv: agent-runner's hermetic flags plus model-provider routing.
import { z } from "npm:zod@4";

export const AuthSchema = z.object({ command: z.string(), args: z.array(z.string()).default([]) });

export function codexArgs(a: {
  cwd: string;
  prompt: string;
  model: string;
  effort?: string;
  sandbox: string;
  outputPath: string;
  providerId: string;
  baseUrl: string;
  wireApi: string;
  auth: z.infer<typeof AuthSchema>;
}): string[] {
  const p = `model_providers.${a.providerId}`;
  return [
    "exec", "--ephemeral", "--ignore-user-config", "--ignore-rules", "--skip-git-repo-check",
    "--sandbox", a.sandbox, "-c", "approval_policy=never",
    "-c", `model_provider=${a.providerId}`,
    "-c", `${p}.name=${JSON.stringify(a.providerId)}`,
    "-c", `${p}.base_url=${JSON.stringify(a.baseUrl)}`,
    "-c", `${p}.wire_api=${JSON.stringify(a.wireApi)}`,
    "-c", `${p}.auth.command=${JSON.stringify(a.auth.command)}`,
    "-c", `${p}.auth.args=${JSON.stringify(a.auth.args)}`,
    ...(a.effort ? ["-c", `model_reasoning_effort=${a.effort}`] : []),
    "-C", a.cwd, "-m", a.model, "-o", a.outputPath, a.prompt,
  ];
}

