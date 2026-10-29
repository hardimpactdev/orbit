import { z } from "npm:zod@4.4.3";

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Receipt = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
export type Receipt = z.infer<typeof Receipt>;
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: Receipt,
  error_code: ErrorCode.nullable(),
});
export type Completion = z.infer<typeof Completion>;
export const DeployStep = z.object({
  name: z.string().regex(/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/),
  phase: z.enum(["before_activation", "after_activation"]),
  required: z.boolean().default(true),
});
export const Configuration = z.object({
  variant: z.enum(["production", "development", "rollback"]),
  php: z.boolean(),
  needs_vp: z.boolean().default(false),
  steps: z.array(DeployStep).max(32),
});
export type Configuration = z.infer<typeof Configuration>;

/** Node work remains a closed set of named invocations bound by the Gateway. */
export type Phase = {
  name: string;
  required: boolean;
  cleanup: boolean;
};
export function phases(config: Configuration): Phase[] {
  const phase = (name: string, required = true, cleanup = false): Phase => ({
    name,
    required,
    cleanup,
  });
  const userSteps = (name: "before_activation" | "after_activation") =>
    config.steps.filter((step) => step.phase === name).map((step) =>
      phase(`${name}.${step.name}`, step.required)
    );
  const vp = config.needs_vp ? [phase("vp_environment")] : [];
  if (config.variant === "development") {
    return [
      phase("source_preparation"),
      ...vp,
      ...userSteps("before_activation"),
    ];
  }
  const refresh = config.php ? [phase("php_refresh")] : [];
  if (config.variant === "rollback") {
    return [
      phase("rollback"),
      phase("activation"),
      ...refresh,
      phase("process_restart"),
    ];
  }
  return [
    phase("source_preparation"),
    phase("environment_sync"),
    ...vp,
    ...userSteps("before_activation"),
    phase("activation"),
    ...refresh,
    phase("process_restart"),
    ...userSteps("after_activation"),
    phase("cleanup", false, true),
  ];
}

export interface DeploymentPort {
  /** Re-reads the lease and cancellation before entering the next phase. */
  cancelled(): Promise<boolean>;
  activated(): Promise<boolean>;
  /** Runs only the phase's server-bound named invocations, recording intent first. */
  phase(phase: Phase): Promise<Receipt>;
  /** The Gateway-owned projection of proven receipts, including deployment identity. */
  result(): Promise<Receipt>;
}

/** A known refusal is distinct from a lost reply; lost replies must leave the workflow resumable. */
export class DeploymentRefused extends Error {
  constructor(readonly code: string) {
    super(ErrorCode.parse(code));
  }
}
