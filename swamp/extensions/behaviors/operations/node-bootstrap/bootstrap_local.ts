import { localFilesystem } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

import { downloadAgent } from "./artifact.ts";
import {
  BootstrapHostProgram,
  BootstrapInstallProgram,
} from "./bootstrap_program.ts";
import {
  type BootstrapEnvironment,
  bootstrapHostKey,
  type BootstrapSsh,
  bootstrapSsh,
  bootstrapSystem,
  BootstrapTarget,
} from "./bootstrap_ssh.ts";

const MacObserverInstall = String.raw`set -eu
candidate=$1
expected=$2
test -f "$candidate" && test ! -L "$candidate"
actual=$(/usr/bin/shasum -a 256 "$candidate" | /usr/bin/cut -d " " -f 1)
test "$actual" = "$expected"
test -d "$HOME" && test ! -L "$HOME"
for path in "$HOME/.orbit" "$HOME/.orbit/bin"; do
  test ! -L "$path"
  if test ! -e "$path"; then /bin/mkdir -m 700 "$path"; fi
  test -d "$path"
done
target="$HOME/.orbit/bin/orbit-agent"
test ! -L "$target"
changed=true
if test -f "$target"; then
 actual=$(/usr/bin/shasum -a 256 "$target" | /usr/bin/cut -d " " -f 1)
 if test "$actual" = "$expected"; then changed=false; fi
fi
if test "$changed" = true; then
 temporary=$(/usr/bin/mktemp "$HOME/.orbit/bin/.observer-XXXXXXXXXXXX")
 trap '/bin/rm -f "$temporary"' EXIT HUP INT TERM
 /bin/cp "$candidate" "$temporary"
 /bin/chmod 755 "$temporary"
 actual=$(/usr/bin/shasum -a 256 "$temporary" | /usr/bin/cut -d " " -f 1)
 test "$actual" = "$expected"
 /bin/mv -f "$temporary" "$target"
fi
/usr/bin/printf '{"replaced":%s,"sha256":"%s"}\n' "$changed" "$expected"
`;

const Sha = z.string().regex(/^[a-f0-9]{64}$/);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Code = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z0-9_.-]{1,90}$/);
const Architecture = z.enum(["x86_64", "arm64"]);
const Artifact = z.object({
  sha256: Sha,
  url: z.url().refine((text) => {
    const url = new URL(text);
    return url.protocol === "https:" && !url.username && !url.password;
  }),
}).strict();
export const BootstrapInput = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  platform: z.enum(["linux", "macos"]),
  target: BootstrapTarget,
  managed_user: User,
  expected_architecture: Architecture.nullable(),
  address: z.ipv4(),
  artifacts: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.object({
      "linux-x86_64": Artifact.optional(),
      "macos-x86_64": Artifact.optional(),
      "macos-aarch64": Artifact.optional(),
    }).strict(),
  ),
  command: z.object({
    user: User,
    public_key: z.string().max(16384),
    releases: z.array(z.string().regex(/^[a-z]+$/)).max(8),
    packages: z.array(z.string().regex(/^[a-z0-9][a-z0-9.+:-]*$/)).max(128),
    dns_policy: z.enum(["managed", "preserve"]),
  }).strict().nullable(),
  firewall: z.record(z.string(), z.unknown()).nullable(),
}).strict().superRefine((input, ctx) => {
  if (input.node_id !== input.target.node_id) {
    ctx.addIssue({
      code: "custom",
      message: "Bootstrap target differs from Node",
    });
  }
  if (
    (input.platform === "linux" &&
      input.command?.user !== input.managed_user) ||
    (input.platform === "macos" &&
      (input.managed_user !== input.target.user || input.command !== null ||
        input.firewall !== null))
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Invalid bootstrap platform boundary",
    });
  }
});
export type BootstrapInput = z.infer<typeof BootstrapInput>;
export const BootstrapObservation = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  platform: z.enum(["linux", "macos"]),
  architecture: Architecture,
  agent_sha256: Sha,
  installed: z.boolean(),
  host_key_type: z.enum([
    "ssh-ed25519",
    "ssh-rsa",
    "ecdsa-sha2-nistp256",
    "ecdsa-sha2-nistp384",
    "ecdsa-sha2-nistp521",
  ]),
  host_public_key: z.string().regex(/^[A-Za-z0-9+/]+={0,2}$/).max(16384),
}).strict();
export const BootstrapProbeObservation = BootstrapObservation.omit({
  agent_sha256: true,
  installed: true,
}).extend({ probed: z.literal(true) });
type Refusal = { bootstrapped: false; error_code: string };
export const BootstrapResult = z.discriminatedUnion("bootstrapped", [
  BootstrapObservation.extend({ bootstrapped: z.literal(true) }).strict(),
  z.object({
    bootstrapped: z.literal(false),
    error_code: Code,
  }).strict(),
]);

export type BootstrapDependencies = {
  ssh: BootstrapSsh;
  download: typeof downloadAgent;
  hostKey?: typeof bootstrapHostKey;
};
const defaults: BootstrapDependencies = {
  ssh: bootstrapSsh,
  download: downloadAgent,
};

/** No Gateway executor: the exact Gateway worker calls this inside its private callback. */
export function nodeBootstrapActions(
  dependencies: BootstrapDependencies = defaults,
) {
  const ssh = dependencies.ssh;
  const probe = async (
    raw: unknown,
    identity: string,
    environment: BootstrapEnvironment,
    signal?: AbortSignal,
  ): Promise<z.infer<typeof BootstrapProbeObservation> | Refusal> => {
    const input = BootstrapInput.parse(raw);
    signal?.throwIfAborted();
    const hostKey = await (dependencies.hostKey ?? bootstrapHostKey)(
      input.target,
      undefined,
      signal,
    );
    const [host_key_type, host_public_key] = hostKey.split(" ");
    const platform = (await ssh(
      input.target,
      identity,
      environment,
      ["/usr/bin/uname", "-s"],
      undefined,
      15,
      undefined,
      signal,
    )).trim();
    if (platform !== (input.platform === "linux" ? "Linux" : "Darwin")) {
      return {
        bootstrapped: false as const,
        error_code: "node.platform_mismatch",
      };
    }
    const observed = (await ssh(
      input.target,
      identity,
      environment,
      ["/usr/bin/uname", "-m"],
      undefined,
      15,
      undefined,
      signal,
    )).trim();
    const arch = observed === "amd64"
      ? "x86_64"
      : observed === "aarch64"
      ? "arm64"
      : observed;
    if (
      !Architecture.safeParse(arch).success ||
      (input.platform === "linux" && arch !== "x86_64")
    ) {
      return {
        bootstrapped: false as const,
        error_code: "node.architecture_unsupported",
      };
    }
    if (
      input.expected_architecture !== null &&
      input.expected_architecture !== arch
    ) {
      return {
        bootstrapped: false as const,
        error_code: "node.architecture_mismatch",
      };
    }
    const architecture = Architecture.parse(arch);
    return BootstrapProbeObservation.parse({
      schema_version: 1 as const,
      node_id: input.node_id,
      platform: input.platform,
      architecture,
      host_key_type,
      host_public_key,
      probed: true as const,
    });
  };
  const check = async (
    raw: unknown,
    rawObservation: unknown,
    identity: string,
    environment: BootstrapEnvironment,
    signal?: AbortSignal,
    prepare = false,
  ) => {
    const input = BootstrapInput.parse(raw);
    const observation = BootstrapObservation.parse(rawObservation);
    signal?.throwIfAborted();
    if (
      observation.node_id !== input.node_id ||
      observation.platform !== input.platform
    ) {
      throw new Error("node.bootstrap_observation_mismatch");
    }
    const artifactKey =
      input.platform === "macos" && observation.architecture === "arm64"
        ? "macos-aarch64"
        : `${input.platform}-x86_64` as const;
    if (input.artifacts[artifactKey]?.sha256 !== observation.agent_sha256) {
      throw new Error("node.bootstrap_observation_mismatch");
    }
    const system = bootstrapSystem(
      input.target,
      identity,
      environment,
      ssh,
      input.platform,
    );
    if (input.platform === "linux" && prepare) {
      const result = JSON.parse(
        (await system(
          ["/usr/bin/python3", "-c", BootstrapHostProgram],
          JSON.stringify({
            ...input.command,
            ssh_port: input.target.port,
            firewall: input.firewall !== null,
          }) + "\n",
        )).stdout,
      );
      if (result.error_code) {
        return BootstrapResult.parse({
          bootstrapped: false,
          error_code: Code.parse(result.error_code),
        });
      }
      z.object({ prepared: z.literal(true) }).parse(result);
    }
    const managedTarget = { ...input.target, user: input.managed_user };
    if (!prepare) {
      const digest = (await ssh(
        managedTarget,
        identity,
        environment,
        input.platform === "linux"
          ? ["/usr/bin/sha256sum", "/usr/local/bin/orbit-agent"]
          : [
            "/bin/sh",
            "-c",
            'set -eu; test ! -L "$HOME/.orbit/bin/orbit-agent"; /usr/bin/shasum -a 256 "$HOME/.orbit/bin/orbit-agent"',
          ],
        undefined,
        15,
        undefined,
        signal,
      )).trim().split(/\s+/)[0];
      if (digest !== observation.agent_sha256) {
        throw new Error("node.bootstrap_observer_mismatch");
      }
    }
    const user = (await ssh(
      managedTarget,
      identity,
      environment,
      ["/usr/bin/id", "-un"],
      undefined,
      15,
      undefined,
      signal,
    )).trim();
    if (user !== input.managed_user) {
      throw new Error("node.bootstrap_account_mismatch");
    }
    if (input.platform === "linux") {
      const root = (await ssh(
        managedTarget,
        identity,
        environment,
        ["/usr/bin/sudo", "-n", "--", "/usr/bin/id", "-u"],
        undefined,
        15,
        undefined,
        signal,
      )).trim();
      if (root !== "0") throw new Error("node.bootstrap_sudo_refused");
    } else {
      const text = await ssh(
        managedTarget,
        identity,
        environment,
        ["/sbin/ifconfig"],
        undefined,
        15,
        undefined,
        signal,
      );
      const addresses = Array.from(
        text.matchAll(/(?:^|\n)\s*inet\s+([0-9.]+)\s/g),
        (m) => m[1],
      );
      if (!addresses.includes(input.address)) {
        return BootstrapResult.parse({
          bootstrapped: false,
          error_code: "node.macos_wireguard_required",
        });
      }
    }
    return BootstrapResult.parse({ ...observation, bootstrapped: true });
  };

  return {
    probe,
    async install(
      raw: unknown,
      identity: string,
      environment: BootstrapEnvironment,
      signal?: AbortSignal,
    ): Promise<z.infer<typeof BootstrapObservation> | Refusal> {
      const input = BootstrapInput.parse(raw);
      const observed = await probe(input, identity, environment, signal);
      if ("error_code" in observed) return observed;
      const { architecture, host_key_type, host_public_key } = observed;
      const key = input.platform === "macos" && architecture === "arm64"
        ? "macos-aarch64"
        : `${input.platform}-x86_64` as const;
      const artifact = input.artifacts[key];
      if (!artifact) {
        return {
          bootstrapped: false as const,
          error_code: "node.agent_delivery_missing",
        };
      }
      const bytes = await dependencies.download(
        artifact.url,
        artifact.sha256,
        signal,
      );
      const local = await localFilesystem().makeTempDir({
        prefix: "orbit-bootstrap-agent-",
      });
      let remote: string | undefined;
      let changed: boolean;
      try {
        const candidate = local + "/agent";
        await localFilesystem().writeFile(candidate, bytes, {
          createNew: true,
          mode: 0o700,
        });
        signal?.throwIfAborted();
        remote = (await ssh(
          input.target,
          identity,
          environment,
          [
            "/usr/bin/mktemp",
            "-d",
            "/tmp/orbit-bootstrap-agent-XXXXXXXXXXXX",
          ],
          undefined,
          15,
          undefined,
          signal,
        )).trim();
        if (!/^\/tmp\/orbit-bootstrap-agent-[A-Za-z0-9]{12}$/.test(remote)) {
          throw new Error("node.bootstrap_channel_uncertain");
        }
        const remoteAgent = remote + "/agent";
        await ssh(input.target, identity, environment, [], undefined, 180, {
          src: candidate,
          dst: remoteAgent,
        }, signal);
        const system = bootstrapSystem(
          input.target,
          identity,
          environment,
          ssh,
          input.platform,
        );
        const reply = input.platform === "linux"
          ? await system(
            ["/usr/bin/python3", "-c", BootstrapInstallProgram],
            JSON.stringify({
              candidate: remoteAgent,
              sha256: artifact.sha256,
            }) + "\n",
          )
          : await system([
            "/bin/sh",
            "-c",
            MacObserverInstall,
            "orbit-bootstrap",
            remoteAgent,
            artifact.sha256,
          ]);
        const installed = JSON.parse(reply.stdout);
        if (installed.error_code) {
          throw new Error("node.bootstrap_install_uncertain");
        }
        const result = z.object({
          replaced: z.boolean(),
          sha256: z.literal(artifact.sha256),
        }).parse(installed);
        changed = result.replaced;
      } finally {
        await localFilesystem().remove(local, { recursive: true });
        if (
          remote &&
          /^\/tmp\/orbit-bootstrap-agent-[A-Za-z0-9]{12}$/.test(remote)
        ) {
          await ssh(
            input.target,
            identity,
            environment,
            ["/bin/rm", "-rf", "--", remote],
            undefined,
            15,
          ).catch(() => {});
        }
      }
      return BootstrapObservation.parse({
        schema_version: 1,
        node_id: input.node_id,
        platform: input.platform,
        architecture,
        agent_sha256: artifact.sha256,
        installed: changed,
        host_key_type,
        host_public_key,
      });
    },
    host: (
      raw: unknown,
      observation: unknown,
      identity: string,
      environment: BootstrapEnvironment,
      signal?: AbortSignal,
    ) => check(raw, observation, identity, environment, signal, true),
    verify: (
      raw: unknown,
      observation: unknown,
      identity: string,
      environment: BootstrapEnvironment,
      signal?: AbortSignal,
    ) => check(raw, observation, identity, environment, signal),
  };
}
