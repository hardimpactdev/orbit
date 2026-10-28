import { z } from "npm:zod@4";
import { type DependencyProject } from "./dependency_projects.ts";
import { repositoryIdentity } from "./dependency_results_schema.ts";
import { type Http } from "./gateway_stage.ts";
import { command, type CommandResult } from "./maintenance/io.ts";

export type Diagnostic = {
  stage: "settings" | "token" | "git" | "gh" | "scan";
  code: string;
  exit_status: number | null;
};
export class CredentialFailure extends Error {
  constructor(readonly diagnostic: Diagnostic) {
    super(diagnostic.code);
  }
}
const fail = (
  stage: Diagnostic["stage"],
  code: string,
  exit_status: number | null = null,
) => new CredentialFailure({ stage, code, exit_status });
const TokenSchema = z.strictObject({
  project_id: z.number().int().positive(),
  repository: z.string(),
  clone_url: z.string(),
  token: z.string().min(1).max(4096).regex(/^[A-Za-z0-9_]+$/),
  expires_at: z.string().datetime(),
});
export type Credential = z.infer<typeof TokenSchema>;
export async function dependencyCredential(
  project: DependencyProject,
  http: Http,
  signal?: AbortSignal,
): Promise<Credential> {
  try {
    signal?.throwIfAborted();
    const response = await http(
      "POST",
      `/api/v1/projects/${project.projectId}/dependency-read-token`,
      {},
    );
    signal?.throwIfAborted();
    if (response.status !== 200) {
      throw fail(
        "token",
        [403, 404, 422].includes(response.status)
          ? "token_refused"
          : "token_unavailable",
      );
    }
    if (!response.body?.data) throw fail("token", "token_missing");
    const parsed = TokenSchema.safeParse(response.body.data),
      repository = repositoryIdentity(project.repository);
    if (!parsed.success) throw fail("token", "token_invalid");
    const value = parsed.data,
      remaining = Date.parse(value.expires_at) - Date.now();
    if (
      value.project_id !== project.projectId ||
      value.repository !== repository ||
      value.clone_url !== `https://${repository}.git` || remaining <= 0 ||
      remaining > 3605000
    ) throw fail("token", "token_invalid");
    return value;
  } catch (error) {
    if (error instanceof CredentialFailure) throw error;
    throw fail(
      "token",
      signal?.aborted ? "command_aborted" : "token_unavailable",
    );
  }
}

/** Classify private stderr; never carry text, argv or the original exception across this boundary. */
export function commandFailure(
  stage: "git" | "gh",
  result: CommandResult,
  signal?: AbortSignal,
) {
  const code = signal?.aborted
    ? (signal.reason?.name === "TimeoutError"
      ? "command_timeout"
      : "command_aborted")
    : /authentication failed|bad credentials|could not read Username|HTTP 401|HTTP 403|gh auth login/i
        .test(result.stderr)
    ? `${stage}_auth_failed`
    : stage === "git" && /repository not found/i.test(result.stderr)
    ? "git_repository_unavailable"
    : stage === "git" &&
        /could not resolve|failed to connect|SSL certificate|unable to access/i
          .test(result.stderr)
    ? "git_transport_failed"
    : stage === "git"
    ? "git_failed"
    : "gh_lookup_failed";
  return fail(stage, code, result.code);
}

export async function credentialCommand(
  cwd: string,
  binary: "git" | "gh",
  args: string[],
  credential: Credential,
  signal?: AbortSignal,
) {
  if (!credential.token) throw fail(binary, "token_missing");
  const home = await Deno.makeTempDir({ prefix: "dependency-auth-" });
  try {
    if (Date.parse(credential.expires_at) <= Date.now()) {
      throw fail(binary, "token_invalid");
    }
    const env: Record<string, string> = {
      PATH: (Deno.env.get("PATH") ?? "/usr/bin:/bin").split(":").filter((p) =>
        p.startsWith("/") && p !== cwd && !p.startsWith(`${cwd}/`)
      ).join(":"),
      HOME: home,
      XDG_CONFIG_HOME: home,
      GH_CONFIG_DIR: home,
      LANG: "C",
      LC_ALL: "C",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_TERMINAL_PROMPT: "0",
    };
    if (binary === "gh") {
      env.GH_TOKEN = credential.token;
      env.GH_PROMPT_DISABLED = "1";
    } else {
      const helper = `${home}/askpass`;
      await Deno.writeTextFile(
        helper,
        `#!/bin/sh\ncase "$1" in\n"Username for '$ORBIT_GIT_ORIGIN': ") printf '%s\\n' x-access-token;;\n"Password for '$ORBIT_GIT_PASSWORD_ORIGIN': ") printf '%s\\n' "$ORBIT_GIT_TOKEN";;\n*) exit 1;;\nesac\n`,
        { mode: 0o700 },
      );
      Object.assign(env, {
        GIT_ASKPASS: helper,
        ORBIT_GIT_TOKEN: credential.token,
        ORBIT_GIT_ORIGIN: credential.clone_url,
        ORBIT_GIT_PASSWORD_ORIGIN: credential.clone_url.replace(
          "https://",
          "https://x-access-token@",
        ),
      });
      args = [
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-c",
        "credential.helper=",
        "-c",
        "credential.useHttpPath=true",
        "-c",
        "http.followRedirects=false",
        "-c",
        "protocol.allow=never",
        "-c",
        "protocol.https.allow=always",
        ...args,
      ];
    }
    const result = await command(cwd, binary, args, signal, env);
    if (result.code || signal?.aborted) {
      throw commandFailure(binary, result, signal);
    }
    return result.stdout;
  } catch (error) {
    if (error instanceof CredentialFailure) throw error;
    throw commandFailure(binary, { code: 127, stdout: "", stderr: "" }, signal);
  } finally {
    await Deno.remove(home, { recursive: true });
  }
}
