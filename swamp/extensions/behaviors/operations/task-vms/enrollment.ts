import { z } from "npm:zod@4.4.3";
import { inspect } from "../../node-worker/canary.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import type { SshEnvironment } from "../_lib/pinned-ssh.ts";
import {
  installProcessLocal,
  ProcessInstallationMaterial,
} from "../processes-schedules/process-installation.ts";
import { BootstrapHostProgram } from "../node-bootstrap/bootstrap_program.ts";
import { HubPublicationInput } from "../node/wireguard_local.ts";
import { installObserver } from "../node/observer_install_system.ts";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import type {
  EnrollmentPrivateMaterial,
  EnrollmentStageActions,
  TaskVmEnrollmentIntent,
} from "./enrollment_direct.ts";
import {
  EnrollmentPeerNetwork,
  prepareTaskVmNetwork,
  publishTaskVmNetwork,
} from "./enrollment_network.ts";
import {
  configureTaskVmRuntime,
  EnrollmentRuntimeInput,
  type RuntimeProcessInstaller,
} from "./enrollment_runtime.ts";
import { incusGuestSystem, sshGuestSystem } from "./guest_system.ts";
import { piNative } from "./pi_native.ts";

const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
export const TaskVmSshIdentity = z.object({
  ssh_host_key_type: z.literal("ssh-ed25519"),
  ssh_host_key: z.string().regex(/^[A-Za-z0-9+/]{68}$/),
  ssh_host_fingerprint: z.string().regex(/^SHA256:[A-Za-z0-9+/]{43}$/),
}).strict();

const ReadPublicHostKey = String.raw`
import os,stat,sys
for path in ['/', '/etc', '/etc/ssh']:
 info=os.lstat(path)
 if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_mode & 0o022: raise ValueError('unsafe public identity')
fd=os.open('/etc/ssh/ssh_host_ed25519_key.pub',os.O_RDONLY|os.O_NOFOLLOW)
try:
 info=os.fstat(fd)
 if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_mode & 0o022 or info.st_size>4096: raise ValueError('unsafe public identity')
 value=os.read(fd,4097)
 if len(value)>4096: raise ValueError('oversize public identity')
 sys.stdout.write(value.decode('utf-8','strict'))
finally: os.close(fd)
`;
function publicHostKey(raw: string) {
  const match = /^ssh-ed25519 ([A-Za-z0-9+/]{68})(?: [!-~]+)?$/.exec(
    raw.trim(),
  );
  if (!match) throw new Error("task_vm.host_key_failed");
  const bytes = Uint8Array.from(
    atob(match[1]),
    (character) => character.charCodeAt(0),
  );
  const header = new TextEncoder().encode("ssh-ed25519");
  const view = new DataView(bytes.buffer);
  if (
    bytes.length !== 51 || view.getUint32(0) !== 11 ||
    view.getUint32(15) !== 32 ||
    header.some((byte, i) => bytes[4 + i] !== byte)
  ) throw new Error("task_vm.host_key_failed");
  return { value: match[1], bytes };
}
async function observePublicHostKey(
  system: SystemTransport,
  guard: () => Promise<void>,
  material: Material,
  upcloud: boolean,
  signal?: AbortSignal,
) {
  try {
    signal?.throwIfAborted();
    await guard();
    const result = await system({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/python3", "-c", ReadPublicHostKey],
      timeoutMs: 15000,
    }, signal);
    if (!result.success) throw new Error();
    const observed = publicHostKey(result.stdout);
    if (upcloud) {
      await material.withPrivateMaterial(async (raw) => {
        await guard();
        const first = z.object({
          kind: z.literal("ssh"),
          publicKey: z.string(),
        }).passthrough().parse(raw.first_visit);
        if (
          publicHostKey(first.publicKey).value !== observed.value
        ) throw new Error();
      });
    }
    const hash = new Uint8Array(
      await crypto.subtle.digest("SHA-256", observed.bytes),
    );
    return TaskVmSshIdentity.parse({
      ssh_host_key_type: "ssh-ed25519",
      ssh_host_key: observed.value,
      ssh_host_fingerprint: "SHA256:" +
        btoa(String.fromCharCode(...hash)).replace(/=+$/, ""),
    });
  } catch {
    throw new Error("task_vm.host_key_failed");
  }
}

export const EnrollmentInput = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
  user: z.literal("orbit"),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  agent: z.object({
    configuration: z.string().min(1).max(65536),
    certificate: z.string().min(1).max(65536),
    unit: z.string().min(1).max(65536),
    checkouts: z.array(z.string().min(1).max(4096)).max(1024),
  }).strict(),
  execution_node_id: Id,
  gateway_node_id: Id,
  issuer_node_id: Id,
  model_router_node_id: Id,
  model_origin: z.string().url(),
  _wireguard: HubPublicationInput,
  provider: z.enum(["incus", "upcloud"]),
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
  agent_artifact_url: z.string().url().nullable(),
  vm: z.object({
    project: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/),
    name: z.string(),
  }).strict().nullable(),
  bootstrap: z.record(z.string(), z.unknown()),
  node: z.object({
    node_id: Id,
    name: z.string(),
    user: z.literal("orbit"),
    wireguard_ip: z.ipv4(),
  }).passthrough(),
  network: EnrollmentPeerNetwork,
  wireguard_public_key: Key.nullable(),
  runtime: EnrollmentRuntimeInput,
  pi_url: z.string().url(),
}).strict().superRefine((input, ctx) => {
  if (
    input.name !== `tvm-${input.task_vm_id}` ||
    input.node.name !== input.name ||
    input.node.node_id !== input.guest_node_id || input.user !== "orbit" ||
    input.runtime.guest_node_id !== input.guest_node_id ||
    input.runtime.agent_sha256 !== input.agent_sha256 ||
    input.model_origin !== input.runtime.origin ||
    input._wireguard.executor_node_id !== input.gateway_node_id ||
    input._wireguard.context_node_id !== input.guest_node_id ||
    input._wireguard.excluded_node_id !== null ||
    input.network.address.split("/")[0] !== input.node.wireguard_ip ||
    new URL(input.pi_url).hostname !== input.node.wireguard_ip ||
    new URL(input.pi_url).protocol !== "http:" ||
    (input.provider === "incus"
      ? input.vm?.name !== input.name
      : input.vm !== null ||
        input.execution_node_id !== input.gateway_node_id)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Enrollment differs from the admitted VM and network",
    });
  }
});
export type EnrollmentIntent = z.infer<typeof EnrollmentInput>;
type Material =
  & EnrollmentPrivateMaterial
  & Partial<Pick<GatewayOperation, "artifact">>;
export type EnrollmentNativeDependencies = {
  guest?: SystemTransport;
  system?: SystemTransport;
  identity?: typeof inspect;
  observer?: typeof installObserver;
  prepareNetwork?: typeof prepareTaskVmNetwork;
  publishNetwork?: typeof publishTaskVmNetwork;
  runtime?: typeof configureTaskVmRuntime;
  installProcess?: RuntimeProcessInstaller;
  artifact?: () => Promise<Uint8Array>;
  fetch?: typeof fetch;
};
const refused = () => new Error("task_vm.enrollment_refused");

const nativeProcessInstaller: RuntimeProcessInstaller = async (
  input,
  material,
  transport,
  signal,
) => {
  let outcome: Awaited<ReturnType<typeof installProcessLocal>> | undefined;
  await material.withPrivateMaterial(async (raw) => {
    outcome = await installProcessLocal(
      input,
      ProcessInstallationMaterial.parse(raw.installation),
      transport,
      signal,
    );
  });
  if (outcome === undefined) throw new Error("task_vm.runtime_failed");
  return outcome;
};

/** Production lower actions composed in the caller's one placed root. No agent actions or nested workflows. */
export function nativeTaskVmEnrollmentActions(
  raw: TaskVmEnrollmentIntent,
  guard: () => Promise<void>,
  material: Material,
  environment: Partial<SshEnvironment>,
  dependencies: EnrollmentNativeDependencies = {},
): EnrollmentStageActions {
  const input = EnrollmentInput.parse(raw);
  const environmentForSsh = (): SshEnvironment => {
    if (!environment.createCelEnvironment) throw refused();
    return { createCelEnvironment: environment.createCelEnvironment };
  };
  const system: SystemTransport = async (command, signal) => {
    signal?.throwIfAborted();
    await guard();
    return await (dependencies.system ?? localSystemCommand)(
      command,
      signal,
    );
  };
  const guest: SystemTransport = dependencies.guest ??
    (input.provider === "incus"
      ? incusGuestSystem(
        {
          task_vm_id: input.task_vm_id,
          project: input.vm!.project,
          name: input.name,
        },
        guard,
        system,
      )
      : (command, signal) =>
        sshGuestSystem(
          { node_id: input.guest_node_id, name: input.name },
          guard,
          material,
          environmentForSsh(),
        )(
          command,
          signal,
        ));
  const networkInput = {
    task_vm_id: input.task_vm_id,
    guest_node_id: input.guest_node_id,
  };
  const identity = async (signal?: AbortSignal) => {
    signal?.throwIfAborted();
    await guard();
    const proof = await (dependencies.identity ?? inspect)({
      node_id: input.guest_node_id,
      user: input.user,
    });
    if (proof.hostname !== input.name) {
      throw new Error("task_vm.worker_not_ready");
    }
  };
  return {
    bootstrap: async ({ signal }) => {
      await guard();
      const output = await guest({
        command: "/usr/bin/sudo",
        args: [
          "-n",
          "--",
          "/usr/bin/python3",
          "-c",
          BootstrapHostProgram,
        ],
        stdin: JSON.stringify({
          ...input.bootstrap,
          ssh_port: 22,
          firewall: false,
        }),
        timeoutMs: 900000,
      }, signal);
      if (
        !output.success ||
        !z.object({ prepared: z.literal(true) }).strict().safeParse(
          JSON.parse(output.stdout),
        ).success
      ) throw new Error("task_vm.bootstrap_failed");
      const installed = await (dependencies.observer ?? installObserver)(
        input.agent_artifact_url,
        input.agent_sha256,
        guest,
        signal,
      );
      if ("error_code" in installed) {
        throw new Error(installed.error_code);
      }
      const node = await observePublicHostKey(
        guest,
        guard,
        material,
        input.provider === "upcloud",
        signal,
      );
      return { bootstrapped: true, node };
    },
    "prepare-network": ({ signal }) =>
      (dependencies.prepareNetwork ?? prepareTaskVmNetwork)(
        networkInput,
        guard,
        guest,
        signal,
      ),
    "publish-network": ({ signal }) => {
      if (input.wireguard_public_key === null) throw refused();
      return (dependencies.publishNetwork ?? publishTaskVmNetwork)(
        networkInput,
        input.network,
        input.wireguard_public_key,
        guard,
        guest,
        signal,
      );
    },
    "prepare-runtime": async ({ signal }) => {
      const artifact = dependencies.artifact ?? (() => {
        if (!material.artifact) throw refused();
        return material.artifact(
          "pi_artifact_file",
          input.runtime.pi_sha256,
        );
      });
      return await (dependencies.runtime ?? configureTaskVmRuntime)(
        input.runtime,
        guard,
        material,
        artifact,
        system,
        dependencies.installProcess ?? nativeProcessInstaller,
        signal,
        dependencies.identity ?? inspect,
      );
    },
    "confirm-runtime": async ({ signal }) => {
      await identity(signal);
      const current = await piNative(
        "pi.inspect-artifact",
        { user: input.user, sha256: input.runtime.pi_sha256 },
        system,
        signal,
      );
      const services = await system({
        command: "/usr/bin/sudo",
        args: [
          "-n",
          "--",
          "/usr/bin/systemctl",
          "is-active",
          "--",
          "orbit-agent.service",
          input.runtime.process.name,
        ],
        timeoutMs: 15000,
      }, signal);
      if (
        current.current !== true || !services.success ||
        services.stdout.trim() !== "active\nactive"
      ) throw new Error("task_vm.runtime_not_ready");
      await material.withPrivateMaterial(async (value) => {
        const token = z.string().regex(/^[a-f0-9]{64}$/).parse(
          value.pi_token,
        );
        signal?.throwIfAborted();
        await guard();
        let response: Response;
        try {
          response = await (dependencies.fetch ?? fetch)(
            input.pi_url + "/capabilities",
            {
              headers: { Authorization: `Bearer ${token}` },
              redirect: "error",
              signal: signal
                ? AbortSignal.any([
                  signal,
                  AbortSignal.timeout(5000),
                ])
                : AbortSignal.timeout(5000),
            },
          );
        } catch {
          throw new Error("task_vm.runtime_not_ready");
        }
        await response.body?.cancel();
        if (!response.ok) throw new Error("task_vm.runtime_not_ready");
      });
      return { ready: true };
    },
  };
}
