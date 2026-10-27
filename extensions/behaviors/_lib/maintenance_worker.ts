import { type Http } from "./gateway_stage.ts";
import { canonical } from "./repository_analysis.ts";

export const START_RECEIPT_GRACE_MS = 5 * 60 * 1000;
export class MaintenanceAttention extends Error {
  constructor(message: string, public workerName?: string) {
    super(message);
  }
}
export const workerAccessName = (name: string) => `${name}-access`;

export interface WorkerSession {
  group: number;
  generation: number;
  tokenName: string;
  operationId: number | null;
  nextReadAt?: string | null;
  startedAt?: string;
  ready: boolean;
  revoked: boolean;
}
export interface Token {
  state: string;
  expiresAt: string;
  vaultName: string;
  secretKey: string;
}
export interface WorkerControl {
  read: (group: number) => Promise<WorkerSession | null>;
  save: (session: WorkerSession) => Promise<void>;
  token: (name: string) => Promise<Token | null>;
  mint: (name: string) => Promise<void>;
  serverToken: (name: string) => Promise<Token | null>;
  mintServer: (name: string, expiresAt: string) => Promise<void>;
  revokeServer: (name: string) => Promise<void>;
  secret: (token: Token) => Promise<string>;
  revoke: (name: string) => Promise<void>;
  connected: (name: string) => Promise<boolean>;
  http: Http;
  url: string;
  now?: () => number;
}

/** Public receipt only. Enrollment plaintext is read from Swamp's vault just for delivery. */
export async function ensureWorker(
  group: number,
  deps: WorkerControl,
): Promise<WorkerSession> {
  let session = await deps.read(group);
  if (session?.revoked) throw new Error("worker group already ended");
  const expired = (token: Token | null) =>
    !token ||
    ["revoked", "expired"].includes(token.state) ||
    Date.parse(token.expiresAt) <= (deps.now ?? Date.now)();
  const rotate = async (previous: WorkerSession) => {
    if (await deps.token(previous.tokenName)) {
      await deps.revoke(previous.tokenName);
    }
    if (await deps.serverToken(previous.tokenName)) {
      await deps.revokeServer(previous.tokenName);
    }
    const fresh: WorkerSession = {
      group,
      generation: previous.generation + 1,
      tokenName: `task-group-${group}-${previous.generation + 1}`,
      operationId: null,
      ready: false,
      revoked: false,
    };
    await deps.save(fresh);
    return fresh;
  };
  if (session?.ready) {
    if (
      !expired(await deps.token(session.tokenName)) &&
      !expired(await deps.serverToken(session.tokenName)) &&
      await deps.connected(session.tokenName)
    ) return session;
    return await rotate(session);
  }
  if (!session) {
    session = {
      group,
      generation: 1,
      tokenName: `task-group-${group}-1`,
      operationId: null,
      ready: false,
      revoked: false,
    };
    await deps.save(session);
  }
  if (session.operationId !== null) {
    if (
      session.nextReadAt &&
      Date.parse(session.nextReadAt) > (deps.now ?? Date.now)()
    ) return session;
    const response = await deps.http(
      "GET",
      `/api/v1/operations/${session.operationId}`,
    );
    const op = response.body?.data as Record<string, unknown> | undefined;
    if (response.status !== 200 || !op) return session;
    if (
      op.id !== session.operationId || op.kind !== "tasks.worker" ||
      op.target_type !== "task_group" || op.target_id !== group
    ) {
      throw new Error("worker operation identity differs");
    }
    if (["failed", "cancelled"].includes(String(op.status))) {
      if (op.error_code === "tasks.worker_enrollment_expired") {
        return await rotate(session);
      }
      throw new Error(
        `worker start ${String(op.status)}; reconcile before a new start`,
      );
    }
    if (op.status !== "succeeded") {
      const delay = Number(op.poll_after_seconds);
      if (Number.isFinite(delay) && delay > 0) {
        session = {
          ...session,
          nextReadAt: new Date((deps.now ?? Date.now)() + delay * 1000)
            .toISOString(),
        };
        await deps.save(session);
      }
      return session;
    }
    if ((op.result as { started?: boolean } | undefined)?.started !== true) {
      throw new Error("worker startup has no process receipt");
    }
    if (!session.startedAt) {
      const finishedAt = typeof op.finished_at === "string"
        ? Date.parse(op.finished_at)
        : NaN;
      session = {
        ...session,
        startedAt: new Date(
          Number.isFinite(finishedAt) ? finishedAt : (deps.now ?? Date.now)(),
        ).toISOString(),
      };
      await deps.save(session);
    }
    const connected = await deps.connected(session.tokenName);
    if (
      !connected && (deps.now ?? Date.now)() - Date.parse(session.startedAt!) >=
        START_RECEIPT_GRACE_MS
    ) {
      throw new MaintenanceAttention(
        `worker ${session.tokenName} did not enroll within five minutes of successful startup; inspect the VM's Swamp URL, CA and worker logs`,
        session.tokenName,
      );
    }
    if (expired(await deps.token(session.tokenName))) {
      // Preserve the enrollment deadline across expiry while startup is unconfirmed.
      if (!connected) return session;
      return await rotate(session);
    }
    if (connected) {
      session = { ...session, ready: true };
      await deps.save(session);
    }
    return session;
  }
  let token = await deps.token(session.tokenName);
  if (!token) {
    await deps.mint(session.tokenName);
    token = await deps.token(session.tokenName);
  }
  if (!token) throw new Error("worker enrollment mint has no receipt");
  let server = await deps.serverToken(session.tokenName);
  if (!server) {
    await deps.mintServer(session.tokenName, token.expiresAt);
    server = await deps.serverToken(session.tokenName);
  }
  if (!server) {
    throw new Error("worker server authentication mint has no receipt");
  }
  // Even after expiry, replay this key to recover an unknown Gateway receipt.
  // Only a known terminal operation permits rotating to a new start.
  const enrollment = `${session.tokenName}.${await deps.secret(token)}`;
  // Do not retain a secret-bearing request, response, or exception in driver data.
  let response;
  try {
    response = await deps.http("POST", `/api/v1/task-groups/${group}/worker`, {
      worker: {
        orchestrator_url: deps.url,
        enrollment_token: enrollment,
        server_token: `${workerAccessName(session.tokenName)}.${await deps
          .secret(server)}`,
        labels: { group: String(group) },
      },
    }, `tg-${group}.worker.c${session.generation}`);
  } catch {
    throw new Error("worker start transport failed; retry the same key");
  }
  if (response.status === 202) {
    const id = (response.body?.data as { id?: unknown } | undefined)?.id;
    if (!Number.isSafeInteger(id) || Number(id) < 1) {
      throw new Error("worker start has no operation id");
    }
    const delay = Number(
      (response.body?.data as { poll_after_seconds?: unknown })
        .poll_after_seconds,
    );
    session = {
      ...session,
      operationId: Number(id),
      ...(Number.isFinite(delay) && delay > 0
        ? {
          nextReadAt: new Date((deps.now ?? Date.now)() + delay * 1000)
            .toISOString(),
        }
        : {}),
    };
    await deps.save(session);
  } else if (
    response.status >= 400 && response.status < 500 && response.status !== 409
  ) {
    throw new Error(`worker start refused (${response.status})`);
  }
  return session;
}

export async function revokeEndedWorker(
  group: number,
  deps: WorkerControl,
): Promise<void> {
  const session = await deps.read(group);
  if (!session || session.revoked) return;
  const response = await deps.http("GET", `/api/v1/task-groups/${group}`);
  const status = (response.body?.data as { status?: string } | undefined)
    ?.status;
  if (
    response.status !== 200 ||
    !["completed", "failed", "cancelled"].includes(status ?? "")
  ) return;
  if (await deps.token(session.tokenName)) await deps.revoke(session.tokenName);
  if (await deps.serverToken(session.tokenName)) {
    await deps.revokeServer(session.tokenName);
  }
  await deps.save({ ...session, revoked: true, ready: false });
}

export interface WorkflowIntent {
  key: string;
  inputs: Record<string, unknown>;
  runId: string | null;
  createdAt: string;
}
export interface WorkflowRun {
  id: string;
  status: string;
  inputs: Record<string, unknown>;
  jobs: {
    name: string;
    steps: {
      name: string;
      status: string;
      error?: string;
      outputs?: Record<string, unknown>;
      dataArtifacts?: { name: string; attributes?: Record<string, unknown> }[];
    }[];
  }[];
}
export interface WorkflowControl {
  read: (key: string) => Promise<WorkflowIntent | null>;
  save: (intent: WorkflowIntent) => Promise<void>;
  search: (key: string) => Promise<WorkflowRun[]>;
  launch: (inputs: Record<string, unknown>) => Promise<string>;
  get: (id: string) => Promise<WorkflowRun>;
  now?: () => number;
}

/** Bind once to the enrollment identity; an existing write must not rotate its worker. */
export async function ensurePlacedMaintenance(
  group: number,
  inputs: Record<string, unknown> & { key: string },
  worker: WorkerControl,
  workflow: WorkflowControl,
): Promise<WorkflowRun | null> {
  const intent = await workflow.read(inputs.key);
  if (intent) {
    inputs = { ...inputs, worker: intent.inputs.worker };
  } else {
    const session = await ensureWorker(group, worker);
    if (!session.ready) return null;
    inputs = { ...inputs, worker: session.tokenName };
  }
  return await ensureMaintenance(inputs, workflow);
}

/** An uncertain write dispatch is adopted from Swamp history, never dispatched again. */
export async function ensureMaintenance(
  inputs: Record<string, unknown> & { key: string },
  deps: WorkflowControl,
): Promise<WorkflowRun | null> {
  let intent = await deps.read(inputs.key);
  if (intent && canonical(intent.inputs) !== canonical(inputs)) {
    throw new Error("maintenance input conflict");
  }
  if (!intent) {
    intent = {
      key: inputs.key,
      inputs,
      runId: null,
      createdAt: new Date((deps.now ?? Date.now)()).toISOString(),
    };
    await deps.save(intent);
    // Swamp owns the run and worker leases. A lost start response leaves this intent.
    intent = { ...intent, runId: await deps.launch(inputs) };
    await deps.save(intent);
  }
  if (!intent.runId) {
    const runs = await deps.search(inputs.key);
    if (runs.length > 1) {
      throw new Error("multiple maintenance runs for one key");
    }
    if (!runs.length) {
      if (
        (deps.now ?? Date.now)() - Date.parse(intent.createdAt) >=
          START_RECEIPT_GRACE_MS
      ) {
        throw new MaintenanceAttention(
          `maintenance ${intent.key} has no Swamp run receipt five minutes after dispatch intent; inspect serve history before any retry; it was not dispatched again`,
          typeof intent.inputs.worker === "string"
            ? intent.inputs.worker
            : undefined,
        );
      }
      return null;
    }
    if (canonical(runs[0].inputs) !== canonical(inputs)) {
      throw new Error("maintenance history input conflict");
    }
    intent = { ...intent, runId: runs[0].id };
    await deps.save(intent);
  }
  const run = await deps.get(intent.runId!);
  if (run.id !== intent.runId || canonical(run.inputs) !== canonical(inputs)) {
    throw new Error("maintenance run identity differs");
  }
  return run;
}
