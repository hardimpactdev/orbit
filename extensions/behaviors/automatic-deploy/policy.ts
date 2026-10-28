import { z } from "npm:zod@4.4.3";

export const trigger = "orbit:auto-deploy";
export const sha = z.string().regex(/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/);
const nullable = z.string().nullable();
export const RowSchema = z.object({
  id: z.number().int().positive(),
  kind: z.enum(["deploy", "rollback"]),
  status: z.enum(["running", "succeeded", "failed"]),
  commit: sha.nullable(),
  triggered_by: nullable,
  failed_step: nullable,
  error_code: nullable,
  started_at: z.string().datetime({ offset: true }),
  finished_at: z.string().datetime({ offset: true }).nullable(),
});
export type Row = z.infer<typeof RowSchema>;
export interface Operation {
  id: number;
  kind: string;
  status: string;
  input: { commit?: string | null };
  triggered_by: string | null;
  result?: { deployment_id?: number; [key: string]: unknown } | null;
  attempts?: number;
  error_code?: string | null;
}
/** Complete policy evidence. This is an internal port, not a proposed HTTP route. */
export interface Observation {
  enabled: boolean;
  production: boolean;
  active: boolean;
  gate: string | null;
  repository: string | null;
  gateReadable: boolean;
  resumedAfter: number;
  blocked: string[];
  history: Row[];
  holder: Operation | null;
  historyVersion: string | null;
  policyOwner?: "gateway" | "swamp";
  publication?: Decision["projection"];
}
export interface Commit {
  sha: string;
  parents: string[];
  descendsFromDeployed: boolean;
}
export interface Check {
  name: string;
  head_sha: string | null;
  app: string | null;
  status: string | null;
  conclusion: string | null;
}
export interface Gateway {
  observe(instance: number): Promise<Observation>;
  history(instance: number): Promise<Row[]>;
  head(instance: number): Promise<string>;
  walk(
    instance: number,
    deployed: string,
  ): Promise<{ head: string; commits: Commit[] }>;
  checks(instance: number, commit: string, name: string): Promise<Check[]>;
  deploy(instance: number, commit: string, key: string): Promise<Operation>;
  operation(id: number): Promise<Operation>;
  resolve(instance: number, commit: string, key: string): Promise<Operation>;
  receipt(
    instance: number,
    commit: string,
    key: string,
  ): Promise<Operation | null>;
}
export class PolicyError extends Error {
  constructor(
    readonly code: string,
    message: string,
    readonly operationId: number | null = null,
  ) {
    super(message);
  }
}
export const isPolicyRefusal = (error: unknown): boolean =>
  error instanceof PolicyError && [
    "instance.auto_deploy_policy_owner",
    "instance.auto_deploy_role_inactive",
  ].includes(error.code);

export const AlertSchema = z.object({
  kind: z.enum([
    "instance_deploy_failed",
    "instance_deploy_paused",
    "instance_deploy_stalled",
  ]),
  key: z.string(),
  subject: z.object({
    target: z.string(),
    repository: z.string(),
    sha,
    release_id: nullable,
  }),
  summary: z.string(),
});
export const PolicyEventSchema = z.object({
  event: z.enum(["activity", "failed", "paused", "stalled"]),
  deployment_id: z.number().int().positive().optional(),
  commit: sha.nullable().optional(),
  since: z.string().optional(),
  result: z.string().optional(),
  operation_id: z.number().nullable().optional(),
  error_code: nullable.optional(),
  message: nullable.optional(),
});
export type PolicyEvent = z.infer<typeof PolicyEventSchema>;
export const StateSchema = z.object({
  events: z.array(z.object({
    key: z.string(),
    body: PolicyEventSchema,
    operation_id: z.number().nullable(),
    receipt: z.record(z.string(), z.unknown()).nullable(),
  })).default([]),
  event_sequence: z.number().int().nonnegative().default(0),
  result: nullable.default(null),
  commit: nullable.default(null),
  head: nullable.default(null),
  operation_id: z.number().nullable().default(null),
  error_code: nullable.default(null),
  message: nullable.default(null),
  since: nullable.default(null),
  pause: z.object({
    reason: z.enum(["rollback", "manual_deploy"]),
    deployment_id: z.number(),
    commit: nullable,
    paused_at: z.string(),
  }).nullable().default(null),
  blocked: z.array(sha).default([]),
  gate: z.object({ key: z.string(), result: z.string(), until: nullable })
    .nullable().default(null),
  stall: z.object({ sha, since: z.string(), alerted: z.boolean() }).nullable()
    .default(null),
  alerts: z.record(z.string(), z.string()).default({}),
  notifications: z.array(AlertSchema).default([]),
  attempts: z.record(z.string(), z.number().int().nonnegative()).default({}),
  effect_hold: z.object({ operation_id: z.number(), manual_after: z.number() })
    .nullable().default(null),
  intent: z.object({
    commit: sha,
    attempt: z.number().int().positive(),
    key: z.string(),
    manual_after: z.number().int().nonnegative(),
    resolve_after: nullable.default(null),
    operation_id: z.number().nullable(),
  }).nullable().default(null),
});
export type State = z.infer<typeof StateSchema>;
export type Alert = z.infer<typeof AlertSchema>;
export const DecisionSchema = z.object({
  ready: z.boolean(),
  error_code: nullable,
  gateway_gaps: z.array(z.string()),
  projection: z.object({
    enabled: z.boolean(),
    gate: nullable,
    warning: nullable,
    result: nullable,
    commit: nullable,
    head: nullable,
    error_code: nullable,
    message: nullable,
    since: nullable,
    pause: StateSchema.shape.pause,
  }).nullable(),
  alerts: z.array(AlertSchema),
  activity: z.object({
    description: z.string(),
    commit: nullable,
    operation_id: z.number().nullable(),
    error_code: nullable,
    message: nullable,
  }).nullable(),
});
export interface Decision {
  requestKey: string | null;
  state: State;
  projection: NonNullable<z.infer<typeof DecisionSchema>["projection"]>;
  alerts: Alert[];
  activity: z.infer<typeof DecisionSchema>["activity"];
}
const lastingCodes = new Set([
  "deployment.cancelled",
  "deployment.commit_not_on_branch",
  "deployment.repository_mismatch",
  "deployment.repository_rebind_required",
  "deployment_config.unavailable",
]);
export const transient = (r: Row) =>
  ["operation", "preparation", "environment"].includes(r.failed_step ?? "") &&
  !lastingCodes.has(r.error_code ?? "");
const automatic = (r: Row) => r.kind === "deploy" && r.triggered_by === trigger;
const eligibleFailure = (r: Row) =>
  automatic(r) && r.status === "failed" && r.commit !== null &&
  r.error_code !== "deployment.paused";
const milliseconds = (t: string) => Date.parse(t);

async function gateRead<T>(read: () => Promise<T>): Promise<T> {
  try {
    return await read();
  } catch (error) {
    if (!(error instanceof PolicyError)) throw error;
    throw new PolicyError(
      "instance.auto_deploy_gate_unavailable",
      error.message,
    );
  }
}

/** One bounded tick. Persist the intent BEFORE admission; deploy success remains a Gateway fact. */
export async function tick(
  instance: number,
  previous: State,
  gateway: Gateway,
  save: (state: State) => Promise<void>,
  now: string,
  onObserve?: (observation: Observation, state: State) => Promise<void>,
): Promise<Decision | null> {
  const state = StateSchema.parse(structuredClone(previous));
  // Read the receipt first. A terminal receipt must be followed by fresh history;
  // history read before completion can still describe the previous live commit.
  let receipt = state.intent?.operation_id
    ? await gateway.operation(state.intent.operation_id)
    : state.intent
    ? await gateway.receipt(instance, state.intent.commit, state.intent.key)
    : null;
  let observation = await gateway.observe(instance);
  if (!observation.enabled || !observation.production || !observation.active) {
    return null;
  }
  await onObserve?.(observation, state);
  if (state.intent && !receipt && !state.intent.operation_id) {
    if (!state.intent.resolve_after) {
      state.intent.resolve_after = new Date(milliseconds(now) + 600_000)
        .toISOString();
    } else if (milliseconds(now) >= milliseconds(state.intent.resolve_after)) {
      receipt = await gateway.resolve(
        instance,
        state.intent.commit,
        state.intent.key,
      );
      state.intent.operation_id = receipt.id;
      await save(state);
      // Resolve can find a just-completed real deployment. Read history AFTER it.
      observation = await gateway.observe(instance);
      if (
        !observation.enabled || !observation.production || !observation.active
      ) return null;
      await onObserve?.(observation, state);
    }
  }
  if (
    state.intent && receipt?.status === "cancelled" &&
    receipt.error_code === "operation.admission_fenced" &&
    receipt.attempts === 0 &&
    receipt.result?.admission_fenced === true && !receipt.result?.deployment_id
  ) {
    state.intent = null;
    await save(state);
    return null;
  }
  if (previous.result === null && observation.publication) {
    const p = observation.publication;
    Object.assign(state, {
      result: p.result,
      commit: p.commit,
      head: p.head,
      since: p.since,
      pause: p.pause,
      error_code: p.error_code,
      message: p.message,
    });
  }
  const history = observation.history.map((r) => RowSchema.parse(r)).sort((
    a,
    b,
  ) => a.id - b.id);
  const alerts: Alert[] = [];
  const alert = (
    kind: Alert["kind"],
    key: string,
    commit: string | null,
    row: Row | null,
    summary: string,
  ) => {
    if (state.alerts[kind] === key) return;
    state.alerts[kind] = key;
    if (!commit || !observation.repository) return;
    const notification = {
      kind,
      key,
      subject: {
        target: `instance:${instance}`,
        repository: observation.repository,
        sha: commit,
        release_id: row ? `deployment-${row.id}` : null,
      },
      summary,
    };
    alerts.push(notification);
    state.notifications.push(notification);
  };
  const last = history.findLast((r) =>
    automatic(r) && r.status !== "running" &&
    r.error_code !== "deployment.paused" && r.id > observation.resumedAfter
  );
  if (last && eligibleFailure(last)) {
    alert(
      "instance_deploy_failed",
      last.commit!,
      last.commit,
      last,
      `The automatic deployment ${last.id} of Instance ${instance} failed at ${
        last.failed_step ?? "unknown"
      }: ${last.error_code ?? "unknown"}. ${
        transient(last)
          ? "The commit is tried again after a backoff, 3 times at most, until a new commit or a manual deployment."
          : "The commit does not deploy automatically again until a new commit or a manual deployment."
      }`,
    );
  }
  let live: string | null = null;
  const rolledAway = [...state.blocked, ...observation.blocked];
  for (const r of history) {
    if (r.kind === "rollback" && live && live !== r.commit) {
      rolledAway.push(live);
    }
    if (r.status === "succeeded" && r.commit) live = r.commit;
  }
  state.blocked = [...new Set(rolledAway)].slice(-20);
  const manual =
    history.findLast((r) => r.kind === "deploy" && !automatic(r))?.id ?? 0;
  // Admission is known once its receipt is terminal. Missing effect evidence
  // holds the target separately, regardless of failure code or claim count.
  if (state.effect_hold && manual > state.effect_hold.manual_after) {
    state.effect_hold = null;
  }
  if (
    state.intent && receipt &&
    ["succeeded", "failed", "cancelled"].includes(receipt.status)
  ) {
    const intent = state.intent;
    const row = history.find((r) => r.id === receipt.result?.deployment_id);
    const evidence = row && row.status !== "running" &&
      row.commit === intent.commit && automatic(row);
    state.intent = null;
    if (
      (!evidence || receipt.error_code === "operation.worker_lost") &&
      manual <= intent.manual_after
    ) {
      state.effect_hold = {
        operation_id: receipt.id,
        manual_after: intent.manual_after,
      };
    }
    if (receipt.error_code === "deployment.commit_blocked") {
      state.blocked = [...new Set([...state.blocked, intent.commit])].slice(
        -20,
      );
    }
  }
  const failures = new Map<
    string,
    { attempts: number; last: string; lasting: boolean }
  >();
  for (const r of history.filter((r) => r.id > manual && eligibleFailure(r))) {
    const old = failures.get(r.commit!);
    failures.set(r.commit!, {
      attempts: (old?.attempts ?? 0) + 1,
      last: r.finished_at ?? r.started_at,
      lasting: (old?.lasting ?? false) || !transient(r),
    });
  }
  let commit: string | null = null,
    operationId: number | null = null,
    errorCode: string | null = null,
    message: string | null = null;
  let result: string;
  let requestKey: string | null = null;
  let holderKind = observation.holder?.kind;
  const pause = history.findLast((r) =>
    r.id > observation.resumedAfter && !automatic(r)
  );
  // Known receipts are always read before deciding whether a replacement write is safe.
  try {
    if (pause) {
      if (state.pause?.deployment_id !== pause.id) {
        state.pause = {
          reason: pause.kind === "rollback" ? "rollback" : "manual_deploy",
          deployment_id: pause.id,
          commit: pause.commit,
          paused_at: now,
        };
        alert(
          "instance_deploy_paused",
          String(pause.id),
          pause.commit,
          pause,
          `${
            pause.kind === "rollback" ? "Rollback" : "Manual deployment"
          } ${pause.id} of Instance ${instance} paused automatic deployment. Run \`orbit instance:update ${instance} --auto-deploy=true\` to resume.`,
        );
      }
      state.gate = null;
      commit = pause.commit;
      result = "paused";
      message = `${
        pause.kind === "rollback" ? "Rollback" : "Manual deployment"
      } ${pause.id} paused automatic deployment. Set auto_deploy again to resume.`;
    } else {
      state.pause = null;
      if (
        state.effect_hold ||
        history.some((r) =>
          r.id > manual && r.error_code === "operation.worker_lost"
        )
      ) {
        throw new PolicyError(
          "automatic.outcome_unknown",
          "Deployment effects need reconciliation. Resolve them with a manual deployment, then resume automatic deployment.",
          state.effect_hold?.operation_id ?? null,
        );
      }
      if (state.intent) {
        const intent = state.intent;
        if (!receipt) {
          throw new PolicyError(
            "automatic.outcome_unknown",
            "The admission response was lost. Resolve its request key before starting another deployment.",
          );
        }
        if (
          ["queued", "running", "waiting"].includes(receipt.status)
        ) {
          commit = intent.commit;
          operationId = receipt.id;
          result = "deploying";
          return await finish();
        }
        throw new PolicyError(
          "automatic.outcome_unknown",
          "The operation has no recognized terminal receipt. Resolve its outcome before retrying.",
          receipt.id,
        );
      }
      if (!live) {
        result = "not_deployed";
        message =
          "Deploy the Instance by hand once; automatic deployment continues from that commit.";
      } else {
        const head = sha.parse(await gateway.head(instance));
        state.head = head;
        commit = head;
        if (head === live) {
          state.gate = null;
          result = "up_to_date";
        } else {
          const blocked = [
            ...new Set([
              ...state.blocked,
              ...[...failures].filter(([, f]) => f.lasting || f.attempts >= 3)
                .map(([c]) => c),
            ]),
          ].sort();
          if (observation.gate === null) {
            result = blocked.includes(head)
              ? "commit_failed"
              : await deploy(head);
          } else {
            const key = JSON.stringify([head, live, blocked]);
            if (
              state.gate?.key === key &&
              (!state.gate.until ||
                milliseconds(now) < milliseconds(state.gate.until))
            ) {
              result = state.gate.result;
            } else {
              if (!observation.gateReadable) {
                throw new PolicyError(
                  "instance.auto_deploy_gate_unreadable",
                  "The Project has a merge_check, but the Gateway GitHub App cannot read its checks.",
                );
              }
              const walk = await gateRead(() => gateway.walk(instance, live!));
              if (walk.head !== head) {
                result = "gate_pending";
                return await finish();
              }
              const commits = new Map(walk.commits.map((c) => [c.sha, c]));
              let candidate = commits.get(walk.head),
                selected: string | null = null,
                headState: string | null = null;
              for (
                let examined = 0;
                candidate && examined < 20 && candidate.sha !== live;
                examined++
              ) {
                if (!blocked.includes(candidate.sha)) {
                  const runs = (await gateRead(() =>
                    gateway.checks(
                      instance,
                      candidate!.sha,
                      observation.gate!,
                    )
                  )).filter((r) => r.name === observation.gate);
                  const check = !runs.length
                    ? "pending"
                    : runs.some((r) =>
                        r.app !== "github-actions" ||
                        r.head_sha !== candidate!.sha
                      )
                    ? "failed"
                    : runs.some((r) => r.status !== "completed")
                    ? "pending"
                    : runs.every((r) => r.conclusion === "success")
                    ? "passed"
                    : "failed";
                  if (candidate.sha === walk.head) headState = check;
                  if (check === "passed") {
                    if (candidate.descendsFromDeployed) {
                      selected = sha.parse(candidate.sha);
                    }
                    break;
                  }
                }
                candidate = commits.get(candidate.parents[0]);
              }
              if (selected) {
                commit = selected;
                result = await deploy(selected);
              } else if (walk.head !== head) {
                result = "gate_pending";
              } else {
                result = headState === "failed"
                  ? "gate_failed"
                  : headState === "passed" || headState === null
                  ? "no_green_commit"
                  : "gate_pending";
                state.gate = result === "gate_pending" ? null : {
                  key,
                  result,
                  until: result === "gate_failed"
                    ? new Date(milliseconds(now) + 300_000).toISOString()
                    : null,
                };
              }
            }
          }
        }
      }
    }
  } catch (error) {
    if (!(error instanceof PolicyError)) throw error;
    if (isPolicyRefusal(error)) {
      await save(state);
      throw error;
    }
    result = "failed";
    errorCode = error.code;
    message = error.message;
    operationId = error.operationId;
  }
  return await finish();

  async function deploy(selected: string): Promise<string> {
    const holder = observation.holder;
    if (holder) {
      operationId = holder.id;
      return holder.kind === "instance.deploy" &&
          holder.triggered_by === trigger && holder.input.commit === selected
        ? "deploying"
        : "busy";
    }
    const failure = failures.get(selected);
    if (
      failure &&
      milliseconds(now) <
        milliseconds(failure.last) + 600_000 * 2 ** (failure.attempts - 1)
    ) return "backing_off";
    // Ordinals never reset at a manual deploy; an old idempotency key must never be reused.
    const attempt = Math.max(
      state.attempts[selected] ?? 0,
      history.filter((r) => automatic(r) && r.commit === selected).length,
    ) + 1;
    requestKey = `auto.${instance}.${selected}.${attempt}`;
    state.attempts[selected] = attempt;
    state.intent = {
      commit: selected,
      attempt,
      key: requestKey,
      manual_after: manual,
      resolve_after: new Date(milliseconds(now) + 600_000).toISOString(),
      operation_id: null,
    };
    await save(state);
    let operation: Operation;
    try {
      operation = await gateway.deploy(instance, selected, state.intent.key);
    } catch (error) {
      if (
        error instanceof PolicyError && error.code === "operation.target_busy"
      ) {
        state.intent = null;
        operationId = error.operationId;
        if (operationId !== null) {
          holderKind = (await gateway.operation(operationId)).kind;
        }
        return "busy";
      }
      // An admission error can still name the operation it created. Follow that
      // receipt; only a proven pre-admission refusal can discard the request here.
      if (
        error instanceof PolicyError &&
        !["automatic.outcome_unknown", "operation.idempotency_conflict"]
          .includes(error.code)
      ) {
        if (error.operationId !== null) {
          state.intent.operation_id = error.operationId;
        } else state.intent = null;
      }
      throw error;
    }
    state.intent.operation_id = operation.id;
    operationId = operation.id;
    return "started";
  }
  async function finish(): Promise<Decision> {
    const changed = state.result !== result || state.commit !== commit;
    Object.assign(state, {
      result,
      commit,
      operation_id: operationId,
      error_code: errorCode,
      message,
      since: changed ? now : state.since ?? now,
    });
    const waits =
      ["started", "deploying", "busy", "backing_off", "gate_pending", "failed"]
        .includes(result) &&
      commit &&
      !(result === "busy" && holderKind === "instance.rollback");
    if (!waits) state.stall = null;
    else if (state.stall?.sha !== commit) {
      state.stall = { sha: commit!, since: now, alerted: false };
    } else if (
      !state.stall.alerted &&
      milliseconds(now) - milliseconds(state.stall.since) >= 1_800_000
    ) {
      state.stall.alerted = true;
      alert(
        "instance_deploy_stalled",
        `${commit}:${state.stall.since}`,
        commit,
        null,
        `Commit ${
          commit!.slice(0, 12)
        } of Instance ${instance} has waited to deploy since ${state.stall.since}. The last tick: ${result}${
          errorCode ? ` ${errorCode}` : ""
        }.`,
      );
    }
    const activity =
      changed && !["up_to_date", "deploying", "gate_pending"].includes(result)
        ? {
          description: `auto_deploy.${result}`,
          commit,
          operation_id: operationId,
          error_code: errorCode,
          message,
        }
        : null;
    // Rebuild pending alert admissions from durable notifications too: an intent
    // save before deploy can precede this finish after a crash.
    for (const alert of state.notifications) {
      const event = alert.kind === "instance_deploy_failed"
        ? "failed"
        : alert.kind === "instance_deploy_paused"
        ? "paused"
        : "stalled";
      const digest = new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(`${alert.kind}:${alert.key}`),
        ),
      );
      const key = `policy.${instance}.${
        Array.from(digest, (b) => b.toString(16).padStart(2, "0")).join("")
      }`;
      if (state.events.some((e) => e.key === key)) continue;
      const body: PolicyEvent = { event, commit: alert.subject.sha };
      if (alert.subject.release_id) {
        body.deployment_id = Number(
          alert.subject.release_id.slice("deployment-".length),
        );
      }
      if (event === "stalled") {
        body.since = alert.key.slice(alert.subject.sha.length + 1);
        body.result = result;
      }
      state.events.push({ key, body, operation_id: null, receipt: null });
    }
    if (activity) {
      state.events.push({
        key: `policy.${instance}.activity.${++state.event_sequence}`,
        body: {
          event: "activity",
          result,
          commit,
          operation_id: operationId,
          error_code: errorCode,
          message,
        },
        operation_id: null,
        receipt: null,
      });
    }
    await save(state);
    return {
      requestKey,
      state,
      projection: {
        enabled: observation.enabled,
        gate: observation.gate,
        warning: observation.gate === null
          ? "The Project has no merge_check, so every new commit on the branch deploys without a check."
          : null,
        result,
        commit,
        head: state.head,
        error_code: errorCode,
        message,
        since: state.since,
        pause: state.pause,
      },
      alerts,
      activity,
    };
  }
}
