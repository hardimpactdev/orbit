import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";

export const DirectArgs = z.object({
  operation_id: z.number().int().positive(),
  kind: z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/),
  node_id: z.number().int().positive().nullable(),
  run_id: z.string().uuid(),
});
export const DirectResult = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
const GitHubCredential = z.object({
  repository: z.string().max(140).regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
  permissions: z.object({
    contents: z.enum(["read", "write"]).optional(),
    pull_requests: z.enum(["read", "write"]).optional(),
    checks: z.literal("read").optional(),
    actions: z.literal("read").optional(),
    metadata: z.literal("read").optional(),
  }).strict().refine((value) => Object.keys(value).length > 0),
  token: z.string().max(2048).regex(/^[A-Za-z0-9_-]+$/),
  expires_at: z.iso.datetime(),
}).strict();
type GitHubGrant = Omit<z.infer<typeof GitHubCredential>, "token">;
const Outcome = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  input: DirectResult.default({}),
  result: DirectResult.nullable(),
  cancel_requested: z.boolean(),
});
export type DirectIntent = z.infer<typeof DirectArgs>;
export type Result = z.infer<typeof DirectResult>;

/** An action may cancel a read-only wait only after confirming no effect is running. */
export class DirectOperationCancelled extends Error {
  constructor(evidence: { effectRunning: false }) {
    super("Direct Operation cancelled with no effect running");
    if (evidence.effectRunning !== false) {
      throw new Error(
        "Cancellation requires confirmation that no effect is running",
      );
    }
  }
}

const Unbound = z.object({
  error: z.object({ code: z.literal("operation.run_unbound") }),
});
class RunUnbound extends Error {}

async function pauseBinding(signal?: AbortSignal): Promise<void> {
  signal?.throwIfAborted();
  await new Promise<void>((resolve, reject) => {
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, 1000);
    signal?.addEventListener("abort", abort, { once: true });
  });
}

/** Fixed endpoints. Only the first native read waits for start-response binding. */
export class GatewayOperation {
  #githubTokenUsed = false;
  constructor(
    private http: Http,
    private args: DirectIntent,
    private bindingWait = pauseBinding,
    private now = Date.now,
    private github: typeof fetch = fetch,
  ) {}
  async call(
    method: "GET" | "POST",
    suffix: string,
    body?: Record<string, unknown>,
  ): Promise<unknown> {
    const response = await this.http(
      method,
      `/api/v1/direct-operations/${this.args.operation_id}${suffix}`,
      body,
    );
    if (response.status < 200 || response.status >= 300) {
      if (response.status === 409 && Unbound.safeParse(response.body).success) {
        throw new RunUnbound("The Gateway has not bound this native run yet");
      }
      throw new Error("Direct Operation request did not succeed");
    }
    return z.object({ data: z.unknown() }).parse(response.body).data;
  }
  async read(signal?: AbortSignal): Promise<unknown> {
    const deadline = this.now() + 90_000;
    const timeout = new AbortController();
    const timer = setTimeout(
      () => timeout.abort(new Error("Native binding deadline expired")),
      90_000,
    );
    const readSignal = signal && !signal.aborted
      ? AbortSignal.any([signal, timeout.signal])
      : timeout.signal;
    try {
      while (true) {
        readSignal.throwIfAborted();
        if (this.now() >= deadline) {
          throw new Error("Native binding deadline expired");
        }
        try {
          return await this.readUntilAborted(readSignal);
        } catch (error) {
          if (!(error instanceof RunUnbound) || this.now() >= deadline) {
            throw error;
          }
          signal?.throwIfAborted();
          await this.bindingWait(readSignal);
        }
      }
    } finally {
      clearTimeout(timer);
    }
  }

  private async readUntilAborted(signal: AbortSignal): Promise<unknown> {
    signal.throwIfAborted();
    let abort = () => {};
    try {
      const response = await new Promise<unknown>((resolve, reject) => {
        abort = () => reject(signal.reason);
        signal.addEventListener("abort", abort, { once: true });
        this.call("GET", `?run_id=${this.args.run_id}`).then(resolve, reject);
      });
      signal.throwIfAborted();
      return response;
    } finally {
      signal.removeEventListener("abort", abort);
    }
  }
  project(body: Record<string, unknown>) {
    return this.call("POST", "/result", { ...body, run_id: this.args.run_id });
  }
  /** Private bytes are callback-local; native arguments/resources must stay secret-free. */
  async withPrivateMaterial(
    use: (material: Result) => Promise<void>,
  ): Promise<void> {
    try {
      const material = DirectResult.parse(
        await this.call("GET", `/private-material?run_id=${this.args.run_id}`),
      );
      await use(material);
    } catch {
      // A fetch/schema/callback error can contain private material. Never forward its text.
      throw new Error("Private material callback failed");
    }
  }
  async storeSecretOutput(outputs: Result): Promise<void> {
    try {
      await this.call("POST", "/secret-output", {
        run_id: this.args.run_id,
        outputs,
      });
    } catch {
      throw new Error("Private output write failed");
    }
  }
  /** Consume one admitted artifact in memory; never expose its bytes as native output. */
  async artifact(field: string, sha256: string): Promise<Uint8Array> {
    if (
      !/^[a-z][a-z0-9_]{0,63}$/.test(field) || !/^[a-f0-9]{64}$/.test(sha256)
    ) {
      throw new Error("Invalid artifact identity");
    }
    let bytes: Uint8Array | undefined;
    await this.withPrivateMaterial(async (material) => {
      const reference = z.object({
        path: z.string(),
        sha256: z.literal(sha256),
        bytes: z.number().int().positive().max(268435456),
        max_bytes: z.literal(268435456),
      }).strict().parse(material[field]);
      const path =
        `/api/v1/direct-operations/${this.args.operation_id}/private-material?run_id=${this.args.run_id}&artifact=${field}`;
      if (reference.path !== path) {
        throw new Error("Foreign artifact reference");
      }
      const response = await this.http("GET", path);
      if (
        response.status !== 200 || !(response.body instanceof Uint8Array) ||
        response.body.length !== reference.bytes
      ) {
        throw new Error("Invalid artifact download");
      }
      const observed = Array.from(
        new Uint8Array(
          await crypto.subtle.digest("SHA-256", new Uint8Array(response.body)),
        ),
        (byte) => byte.toString(16).padStart(2, "0"),
      ).join("");
      if (observed !== sha256) throw new Error("Artifact digest mismatch");
      bytes = response.body;
    });
    if (!bytes) throw new Error("Artifact unavailable");
    return bytes;
  }
  primitive() {
    return this.call("POST", "/primitive", { run_id: this.args.run_id });
  }
  /** Credential bytes stay inside this action; never return them as behavior data. */
  async withGitHubToken(
    action: (token: string, grant: GitHubGrant) => Promise<Result>,
  ): Promise<Result> {
    if (this.#githubTokenUsed) {
      throw new Error("operation.github_token_already_used");
    }
    this.#githubTokenUsed = true;
    let safe: Result | undefined;
    let refusal = "operation.github_token_unavailable";
    try {
      await this.withPrivateMaterial(async (material) => {
        const credential = GitHubCredential.parse(material.github);
        try {
          const expiry = Date.parse(credential.expires_at);
          const now = this.now();
          if (expiry <= now || expiry > now + 3_605_000) throw new Error();
          refusal = "operation.github_action_failed";
          const result = await action(credential.token, {
            repository: credential.repository,
            permissions: Object.freeze(credential.permissions),
            expires_at: credential.expires_at,
          });
          refusal = "operation.github_result_invalid";
          const value = DirectResult.parse(JSON.parse(JSON.stringify(result)));
          if (JSON.stringify(value).includes(credential.token)) {
            throw new Error();
          }
          safe = value;
        } finally {
          await this.revokeGitHubToken(credential);
        }
      });
    } catch {
      throw new Error(refusal);
    }
    return safe!;
  }
  private async revokeGitHubToken(
    credential: z.infer<typeof GitHubCredential>,
  ) {
    try {
      const response = await this.github(
        "https://api.github.com/installation/token",
        {
          method: "DELETE",
          redirect: "error",
          signal: AbortSignal.timeout(10_000),
          headers: {
            Authorization: `Bearer ${credential.token}`,
            Accept: "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
          },
        },
      );
      await response.body?.cancel();
      if (response.status !== 204) throw new Error();
    } catch {
      console.warn("operation.github_token_revoke_failed");
    } finally {
      credential.token = "";
    }
  }
}

/** Native steps own retry/cancellation/locking. Families supply their action and result schema. */
export async function directOperation(
  args: DirectIntent,
  gateway: Pick<GatewayOperation, "read" | "project">,
  action: (input: Result) => Promise<unknown>,
  validateResult: (result: Result) => Result,
  signal?: AbortSignal,
) {
  const current = Outcome.parse(await gateway.read(signal));
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id
  ) throw new Error("Direct Operation does not match admitted intent");
  if (["succeeded", "failed", "cancelled"].includes(current.status)) {
    if (current.status === "failed") {
      throw new Error(safeCode(current.result?.error_code));
    }
    return { status: current.status };
  }
  const started_at = new Date().toISOString();
  await gateway.project({ status: "running", started_at });
  let result: Result = {};
  let status: "succeeded" | "failed" | "cancelled";
  if (current.cancel_requested || signal?.aborted) {
    status = "cancelled";
  } else {
    try {
      result = validateResult(DirectResult.parse(await action(current.input)));
      if (result.error_code !== undefined) safeCode(result.error_code);
      status = result.error_code === undefined ? "succeeded" : "failed";
    } catch (error) {
      if (!(error instanceof DirectOperationCancelled)) throw error;
      result = {};
      status = "cancelled";
    }
  }
  await gateway.project({
    status,
    started_at,
    finished_at: new Date().toISOString(),
    result,
    error_code: result.error_code ?? null,
  });
  if (status === "failed") throw new Error(safeCode(result.error_code));
  return { status };
}

function safeCode(code: unknown): string {
  if (!z.string().regex(/^[a-z0-9_.-]{1,100}$/).safeParse(code).success) {
    throw new Error("Invalid direct Operation refusal");
  }
  return code as string;
}
