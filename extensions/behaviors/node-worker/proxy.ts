import { z } from "npm:zod@4.4.3";
import { type NodeCommand, run } from "../operations/_lib/node-commands.ts";
import { nodeEnrollmentIssuer } from "../operations/node/enrollment_issuer.ts";
import {
  assertProxyHost,
  authorizeProxyKey,
  installProxyWorkers,
  knownHost,
  prepareProxyKey,
  type ProxyTarget,
} from "./proxy-install.ts";
import {
  metadata,
  owned,
  privateDirectory,
  publishRootFile,
  required,
  text,
} from "./files.ts";
import type { WorkerCredentials } from "./install.ts";
import { due, type Native, native, Token } from "./rotation.ts";
import { attestProxyRuntime, ProxyRuntime } from "./proxy-runtime.ts";
import { ProxyIdentity } from "../operations/_lib/node-proxy-config.ts";

// Orbit's recorded Ed25519 host keys, verified against their recorded fingerprints.
export const targets: readonly ProxyTarget[] = [
  {
    node_id: 1,
    host: "10.44.0.1",
    user: "orbit",
    host_key:
      "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG",
    host_fingerprint: "SHA256:TP06RDL2oMgX8CDMhbQMVPkTgBz5oH7PDMb2QcpY8CE",
  },
  {
    node_id: 18,
    host: "10.44.0.13",
    user: "orbit",
    host_key:
      "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKTqizm/h9dgo7Zab5NUC1RlLprBkRoUPrKQkF/b+DjF",
    host_fingerprint: "SHA256:teBody6WhmcYgLEXTTI2m3TqyfNnRxgEx7tCbsg7mwA",
  },
];
const quote = (v: string) => "'" + v.replaceAll("'", "'\\''") + "'";

/** Existing Gateway SSH authority installs only the new public key on each exact target. */
export async function authorize(
  node: 1 | 18,
  publicKey: string,
  invoke: NodeCommand = run,
) {
  const target = targets.find((v) => v.node_id === node)!;
  await knownHost(target);
  const identity = await required(invoke, [
    "/usr/bin/python3",
    "-c",
    `import json,subprocess
addresses=json.loads(subprocess.check_output(['/usr/sbin/ip','-j','address','show'],timeout=8))
if not any(a.get('local')=='10.44.0.2' for d in addresses for a in d.get('addr_info',[])):raise RuntimeError('Gateway bootstrap worker required')
`,
  ]);
  if (identity.length !== 0) {
    throw new Error("Unexpected bootstrap identity output");
  }
  const remote: NodeCommand = async (command, stdin, asUser, options) => {
    if (asUser !== "root") {
      throw new Error("Bootstrap authorization must run as root");
    }
    return await invoke(
      [
        "/usr/bin/ssh",
        "-F",
        "/dev/null",
        "-T",
        "-o",
        "BatchMode=yes",
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "IdentityAgent=none",
        "-o",
        "ForwardAgent=no",
        "-o",
        "UpdateHostKeys=no",
        "-o",
        "VerifyHostKeyDNS=no",
        "-o",
        "HostKeyAlgorithms=ssh-ed25519",
        "-o",
        "ClearAllForwardings=yes",
        "-o",
        "StrictHostKeyChecking=yes",
        "-o",
        "GlobalKnownHostsFile=/dev/null",
        "-o",
        "UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts",
        "-o",
        "ConnectTimeout=8",
        "-o",
        "ConnectionAttempts=1",
        "-o",
        "LogLevel=ERROR",
        "-i",
        "/home/orbit/.orbit/ssh/id_ed25519",
        `${target.user}@${target.host}`,
        [
          "/usr/bin/sudo",
          "-n",
          "--",
          "/usr/bin/timeout",
          "--signal=TERM",
          "--kill-after=5s",
          "30s",
          ...command,
        ].map(quote).join(" "),
      ],
      stdin,
      "node",
      options,
    );
  };
  return await authorizeProxyKey(target, publicKey, remote);
}

/** Existing worker credential type and canonical issuer; plaintext stays callback-local. */
export async function activate(
  expected: ProxyRuntime,
  invoke: NodeCommand = run,
  issue = nodeEnrollmentIssuer(),
  signal?: AbortSignal,
  call: Native = native,
) {
  await attestProxyRuntime(invoke, expected);
  await prepareProxyKey(invoke, expected);
  const credentials = {} as Record<1 | 18, WorkerCredentials>;
  const parent = "/etc/orbit/node-proxy/credentials";
  await privateDirectory(invoke, parent, 0, 0);
  for (const node of [1, 18] as const) {
    const dir = `${parent}/${node}`;
    await privateDirectory(invoke, dir, 0, 0);
    const paths = [dir + "/enrollment.token", dir + "/server.token"];
    const existing = await Promise.all(paths.map((p) => metadata(invoke, p)));
    let pair: WorkerCredentials | null = null;
    if (existing.every((m) => m !== null)) {
      if (!existing.every((m) => owned(m, "file", 0o600))) {
        throw new Error("Unsafe proxy credential custody");
      }
      pair = {
        enrollment_token: text(
          await required(invoke, ["/usr/bin/cat", paths[0]]),
        ),
        server_token: text(await required(invoke, ["/usr/bin/cat", paths[1]])),
      };
    } else if (existing.some((m) => m !== null)) {
      throw new Error("Partial proxy credentials require owner recovery");
    }
    if (pair !== null && !await validProxyPair(node, existing, call)) {
      pair = null;
    }
    await issue(
      {
        schema_version: 1,
        node_id: node,
        issuer_node_id: 209,
        worker_user: "orbit",
        selected: true,
      },
      pair,
      async (value) => {
        pair = value.worker_credentials;
        await publishRootFile(invoke, paths[0], pair.enrollment_token, 0o600);
        await publishRootFile(invoke, paths[1], pair.server_token, 0o600);
      },
      signal,
    );
    if (pair === null) throw new Error("Proxy issuer returned no credentials");
    credentials[node] = pair;
  }
  return await installProxyWorkers(targets, credentials, invoke, expected);
}

type Context = {
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
  signal?: AbortSignal;
};
const PublicKey = z.string().regex(/^ssh-ed25519 [A-Za-z0-9+/]+={0,2}$/);
export const behavior = {
  type: "@nckrtl/node-proxy-workers",
  version: "2026.10.31.3",
  globalArguments: z.object({}).strict(),
  resources: {
    runtime: {
      description:
        "Read-only attestation of the actual running209 worker artifact",
      schema: z.object({ runtime: ProxyRuntime }).strict(),
      lifetime: "infinite",
      garbageCollection: 5,
    },
    key: {
      description: "Dedicated proxy public key only",
      schema: z.object({ public_key: PublicKey }).strict(),
      lifetime: "infinite",
      garbageCollection: 5,
    },
    authorized: {
      description: "Exact light Node public-key authorization",
      schema: z.object({
        authorized: z.literal(true),
        node_id: z.union([z.literal(1), z.literal(18)]),
      }).strict(),
      lifetime: "infinite",
      garbageCollection: 5,
    },
    configured: {
      description: "Proxy worker configuration outcome without credentials",
      schema: z.object({
        configured: z.literal(true),
        node_ids: z.array(z.number().int()),
      }).strict(),
      lifetime: "infinite",
      garbageCollection: 5,
    },
  },
  methods: {
    preflight: {
      description:
        "Read-only running209 artifact and two-worker memory attestation before all proxy effects",
      arguments: z.object({
        expected_sha256: z.string().regex(/^(?:[a-f0-9]{64})?$/).default(""),
      }).strict(),
      execute: async (args: { expected_sha256: string }, ctx: Context) => {
        const runtime = await attestProxyRuntime();
        if (args.expected_sha256 && args.expected_sha256 !== runtime.sha256) {
          throw new Error(
            "Proxy worker runtime digest differs from expected preflight",
          );
        }
        return {
          dataHandles: [
            await ctx.writeResource("runtime", "runtime", { runtime }),
          ],
        };
      },
    },
    prepare_key: {
      description:
        "Generate or reuse the209-only private key and return its public key",
      arguments: z.object({ runtime: ProxyRuntime }).strict(),
      execute: async (args: { runtime: ProxyRuntime }, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource("key", "key", {
            public_key: await prepareProxyKey(run, args.runtime),
          }),
        ],
      }),
    },
    authorize: {
      description:
        "Authorize the dedicated public key through existing Gateway SSH custody",
      arguments: z.object({
        node_id: z.union([z.literal(1), z.literal(18)]),
        public_key: PublicKey,
      }).strict(),
      execute: async (
        args: { node_id: 1 | 18; public_key: string },
        ctx: Context,
      ) => ({
        dataHandles: [
          await ctx.writeResource(
            "authorized",
            "authorized",
            await authorize(args.node_id, args.public_key),
          ),
        ],
      }),
    },
    activate: {
      description:
        "Issue callback-local credentials and configure both proxy workers on209",
      arguments: z.object({ runtime: ProxyRuntime }).strict(),
      execute: async (args: { runtime: ProxyRuntime }, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource(
            "configured",
            "configured",
            await activate(
              args.runtime,
              run,
              nodeEnrollmentIssuer(),
              ctx.signal,
            ),
          ),
        ],
      }),
    },
  },
};

/** A stored pair may be reused only while both canonical token generations remain current. */
export async function validProxyPair(
  node: 1 | 18,
  files: readonly ({ mtime_ms?: number } | null)[],
  call: Native = native,
  now = Date.now(),
) {
  const worker = z.array(Token).parse(await call(["worker", "token", "list"]))
    .find((t) => t.name === `orbit-node-${node}`);
  const access = z.array(Token).parse(await call(["access", "token", "list"]))
    .find((t) => t.name === `orbit-node-${node}-access`);
  if (!worker || !access || due(worker, now) || due(access, now)) return false;
  return [worker, access].every((t, i) =>
    Number.isFinite(files[i]?.mtime_ms) &&
    files[i]!.mtime_ms! >= Date.parse(t.createdAt)
  );
}

/** Local managed selection exists only after the reviewed setup has installed that exact unit. */
export async function configuredProxyNodes(
  invoke: NodeCommand = run,
): Promise<(1 | 18)[]> {
  const result: (1 | 18)[] = [];
  for (const node of [1, 18] as const) {
    const path = `/etc/orbit/node-proxy/${node}.json`;
    const config = await metadata(invoke, path);
    if (config === null) continue;
    if (
      !owned(config, "file", 0o644) ||
      !owned(
        await metadata(
          invoke,
          `/etc/systemd/system/orbit-node-proxy-worker-${node}.service`,
        ),
        "file",
        0o644,
      )
    ) throw new Error("Unsafe managed proxy selection");
    const identity = ProxyIdentity.parse(
      JSON.parse(text(await required(invoke, ["/usr/bin/cat", path]))),
    );
    if (identity.node_id !== node) {
      throw new Error("Proxy configuration identity changed");
    }
    result.push(node);
  }
  return result;
}

/** Renew on209 only. Never submit a light Node to local-worker converge. */
export async function renewProxyWorker(
  node: 1 | 18,
  call: Native = native,
  signal?: AbortSignal,
  invoke: NodeCommand = run,
) {
  await assertProxyHost(invoke);
  if (!(await configuredProxyNodes(invoke)).includes(node)) {
    throw new Error("Proxy worker is not managed here");
  }
  if (
    !owned(
      await metadata(invoke, "/etc/orbit/node-proxy/id_ed25519"),
      "file",
      0o600,
    )
  ) throw new Error("Proxy key missing or unsafe");
  const dir = `/etc/orbit/node-proxy/credentials/${node}`;
  const paths = [dir + "/enrollment.token", dir + "/server.token"];
  const files = await Promise.all(paths.map((p) => metadata(invoke, p)));
  if (!files.every((m) => owned(m, "file", 0o600))) {
    throw new Error("Proxy credential custody invalid");
  }
  let pair: WorkerCredentials | null = {
    enrollment_token: text(await required(invoke, ["/usr/bin/cat", paths[0]])),
    server_token: text(await required(invoke, ["/usr/bin/cat", paths[1]])),
  };
  if (!await validProxyPair(node, files, call)) pair = null;
  await nodeEnrollmentIssuer(call)(
    {
      schema_version: 1,
      node_id: node,
      issuer_node_id: 209,
      worker_user: "orbit",
      selected: true,
    },
    pair,
    async (value) => {
      await publishRootFile(
        invoke,
        paths[0],
        value.worker_credentials.enrollment_token,
        0o600,
      );
      await publishRootFile(
        invoke,
        paths[1],
        value.worker_credentials.server_token,
        0o600,
      );
    },
    signal,
  );
  await required(invoke, [
    "/usr/bin/systemctl",
    "restart",
    "--no-block",
    `orbit-node-proxy-worker-${node}.service`,
  ]);
  return { node_id: node, user: "orbit", proxy_refreshed: true };
}
