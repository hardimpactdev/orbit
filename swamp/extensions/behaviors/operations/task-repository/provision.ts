import { z } from "npm:zod@4.4.3";
import {
  classifyProvisioningProfile,
  ProvisioningRefusal,
} from "../instance-lifecycle/source_profile.ts";
import { WorkspaceInput } from "../task-vm-workspace/input.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const Path = z.string().startsWith("/").min(2).refine((v) => !v.includes("\0"));
const Workspace = z.object({
  checkout_path: Path,
  repository: z.string().min(1),
  repository_identity: z.string().min(1),
  branch: z.string().min(1),
  default_branch: z.string().min(1),
  user: z.string().min(1),
  project_type: z.enum([
    "monorepo",
    "laravel-app",
    "symfony-app",
    "laravel-package",
    "node-package",
  ]),
  entry_point: z.enum(["artisan", "bin/console"]),
  skip_profile: z.boolean(),
  php_versions: z.array(z.string()).min(1),
  setup: z.array(
    z.object({
      name: z.string(),
      command: z.string(),
      timeout_seconds: Id,
      input_json: z.string(),
    }),
  ).max(32),
});
export const Intent = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
  group_id: Id,
  instance_id: Id,
  project_id: Id,
  wireguard_ip: z.ipv4(),
  ssh_identity: WorkspaceInput.shape.ssh_execution.omit({ target: true }),
  workspace: Workspace,
});
const Vm = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
  ready: z.literal(true),
  provider: z.record(z.string(), z.unknown()),
  enrollment: z.object({
    ready: z.literal(true),
    node: z.object({
      ssh_host_key_type: z.literal("ssh-ed25519"),
      ssh_host_key: z.string().min(1),
    }).passthrough(),
    process: z.record(z.string(), z.unknown()),
  }).passthrough(),
});
const data = async (ctx: Context, value: Record<string, unknown>) => ({
  dataHandles: [await ctx.writeResource("public", "output", value)],
});
type Context = {
  extensionFile(name: string): string;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function connection(raw: unknown, receipt: unknown) {
  const intent = Intent.parse(raw), vm = Vm.parse(receipt);
  if (
    vm.task_vm_id !== intent.task_vm_id ||
    vm.guest_node_id !== intent.guest_node_id
  ) throw new Error("tasks.provision_refused");
  const ssh_execution = {
    ...intent.ssh_identity,
    target: {
      name: "target",
      host: intent.wireguard_ip,
      user: intent.workspace.user,
      port: 22,
      host_keys: [
        vm.enrollment.node.ssh_host_key_type + " " +
        vm.enrollment.node.ssh_host_key,
      ],
    },
  };
  return WorkspaceInput.parse({
    task_vm_id: intent.task_vm_id,
    group_id: intent.group_id,
    instance_id: intent.instance_id,
    project_id: intent.project_id,
    checkout_path: intent.workspace.checkout_path,
    ssh_execution,
  });
}
const Source = z.object({
  branch: z.string(),
  commit: Sha,
  php_version: z.string().nullable(),
  laravel: z.boolean(),
});
const Observation = z.object({ source: Source, analysis_base: Sha.nullable() });
function nativeOutputs(steps: Record<string, unknown>, name: string) {
  const step = z.record(z.string(), z.unknown()).safeParse(steps[name]).data ??
    {};
  const outputs =
    z.record(z.string(), z.unknown()).safeParse(step.outputs).data ?? {};
  return { step, outputs };
}
export function sourceResult(raw: unknown, steps: Record<string, unknown>) {
  const intent = Intent.parse(raw);
  const profile = nativeOutputs(steps, "profile").outputs;
  if (typeof profile.error_code === "string") {
    return { error_code: profile.error_code, failed_step: "profile" };
  }
  for (
    const name of [
      "prepare",
      "directory",
      "control",
      "clone",
      "bundle",
      "prepare_remote",
      "copy_assets",
      "copy_bundle",
      "import",
      "origin",
      "branch",
      "observe",
      "profile",
      ...intent.workspace.setup.map((_, i) => `setup-${i}`),
    ]
  ) {
    if (
      (name === "clone"
        ? !["clone_private", "clone_public"].some((key) =>
          nativeOutputs(steps, key).step.status === "succeeded"
        )
        : nativeOutputs(steps, name).step.status !== "succeeded")
    ) {
      return { error_code: "tasks.provision_failed", failed_step: name };
    }
  }
  return Observation.parse(nativeOutputs(steps, "profile").outputs);
}
export function result(
  raw: unknown,
  steps: Record<string, unknown>,
): Record<string, unknown> {
  const intent = Intent.parse(raw);
  const vm = Vm.safeParse(nativeOutputs(steps, "vm").outputs.result);
  const receipt = vm.success && vm.data.task_vm_id === intent.task_vm_id &&
      vm.data.guest_node_id === intent.guest_node_id
    ? vm.data
    : {};
  for (const name of ["vm", "guest", "source"]) {
    if (
      (name === "clone"
        ? !["clone_private", "clone_public"].some((key) =>
          nativeOutputs(steps, key).step.status === "succeeded"
        )
        : nativeOutputs(steps, name).step.status !== "succeeded")
    ) {
      return {
        ...receipt,
        error_code: "tasks.provision_failed",
        failed_step: name,
      };
    }
  }
  const sourceReceipt = z.record(z.string(), z.unknown()).parse(
    nativeOutputs(steps, "source").outputs.result,
  );
  if (typeof sourceReceipt.error_code === "string") {
    return { ...receipt, ...sourceReceipt };
  }
  const { source, analysis_base: base } = Observation.parse(sourceReceipt);
  return {
    ...receipt,
    source,
    instance_id: intent.instance_id,
    node_id: intent.guest_node_id,
    starting_commit: source.commit,
    analysis_base: base === null ? null : {
      base,
      instance_id: intent.instance_id,
      repository: intent.workspace.repository,
      branch: source.branch,
    },
  };
}
export const behavior = {
  type: "@nckrtl/task-provision-data",
  version: "2026.11.01.2",
  resources: {
    public: {
      schema: z.record(z.string(), z.unknown()),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    vm_inputs: {
      description:
        "Separate Tasks Git descriptors from the canonical VM child input",
      arguments: z.object({
        execution_secrets: z.record(
          z.string(),
          z.object({ vault: z.string(), key: z.string() }),
        ),
      }),
      execute: (
        args: {
          execution_secrets: Record<string, { vault: string; key: string }>;
        },
        ctx: Context,
      ) => {
        const { git_authorization: _git, ...copies } = args.execution_secrets;
        return data(ctx, { execution_secrets: copies });
      },
    },
    prepare: {
      description:
        "Validate public workspace intent and resolve shipped actions",
      arguments: z.object({ intent: Intent }),
      execute: (args: { intent: z.infer<typeof Intent> }, ctx: Context) =>
        data(ctx, {
          directory: ctx.extensionFile("scripts/provision_profile.py")
            .replace(/\/[^/]+$/, ""),
          input_json: JSON.stringify(args.intent.workspace),
        }),
    },
    guest: {
      description:
        "Use the provision child's trusted observed pin for the admitted workspace",
      arguments: z.object({ intent: Intent, vm: z.unknown() }),
      execute: (args: { intent: unknown; vm: unknown }, ctx: Context) =>
        data(ctx, { workspace: connection(args.intent, args.vm) }),
    },
    profile: {
      description:
        "Classify source with canonical Instance profile policy and retain its initial analysis pin",
      arguments: z.object({ intent: Intent, observation: z.string() }),
      execute: (
        args: { intent: z.infer<typeof Intent>; observation: string },
        ctx: Context,
      ) => {
        const workspace = Intent.parse(args.intent).workspace;
        const observation = z.object({
          branch: z.string(),
          commit: Sha,
          analysis_base: Sha.nullable(),
          profile: z.unknown(),
        }).parse(JSON.parse(args.observation));
        if (observation.branch !== workspace.branch) {
          throw new Error("instance.source_identity_changed");
        }
        let profile;
        try {
          profile = workspace.skip_profile
            ? { php_version: null, laravel: false }
            : classifyProvisioningProfile(observation.profile, workspace);
        } catch (error) {
          if (error instanceof ProvisioningRefusal) {
            return data(ctx, {
              error_code: error.code,
              failed_step: "profile",
            });
          }
          throw error;
        }
        return data(ctx, {
          source: {
            branch: observation.branch,
            commit: observation.commit,
            ...profile,
          },
          analysis_base: observation.analysis_base,
        });
      },
    },
    source_result: {
      description: "Return the source and setup child receipt",
      arguments: z.object({
        intent: Intent,
        steps: z.record(z.string(), z.unknown()),
      }),
      execute: (
        args: { intent: unknown; steps: Record<string, unknown> },
        ctx: Context,
      ) => {
        const value = sourceResult(args.intent, args.steps);
        return data(ctx, {
          ...value,
          result_json: JSON.stringify(value),
        });
      },
    },
    result: {
      description:
        "Fold native source and setup outputs into the Tasks terminal receipt",
      arguments: z.object({
        intent: Intent,
        steps: z.record(z.string(), z.unknown()),
      }),
      execute: (
        args: { intent: unknown; steps: Record<string, unknown> },
        ctx: Context,
      ) =>
        data(ctx, {
          result_json: JSON.stringify(result(args.intent, args.steps)),
        }),
    },
  },
};
