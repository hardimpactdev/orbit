import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import {
  createIncusVm,
  IncusCreate,
  IncusLocation,
  observeIncusVm,
} from "./provider_incus.ts";
import { type CloudRequest, cloudRequest } from "./upcloud_sweep.ts";
import type { SweepGateway } from "./sweep.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import {
  generateSshHostKey,
  SshHostKey,
  type SshHostKeyPair,
} from "./ssh_host_key.ts";

const Id = z.number().int().positive();
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Uuid = z.string().uuid();
const PublicKey = z.string().regex(
  /^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}(?: [!-~][ -~]*)?$/,
);
export const ProviderCreateInput = z.object({
  task_vm_id: Id,
  node_id: Id,
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
  provider: z.enum(["incus", "upcloud"]),
  agent_sha256: Digest,
  deadline_at: z.iso.datetime({ offset: true }),
  vm: IncusLocation.nullable(),
  create: IncusCreate.nullable(),
  upcloud: z.object({
    zone: z.string().regex(/^[a-z]{2}-[a-z]{3}[1-9]$/),
    plan: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/),
    disk_gb: z.number().int().min(10).max(200),
    max_vms: z.number().int().positive(),
    source_template: Uuid,
    gateway_public_key: PublicKey,
  }).strict().nullable(),
  provider_id: Uuid.nullable(),
}).strict().refine((input) =>
  input.name === `tvm-${input.task_vm_id}` &&
  (input.provider === "incus"
    ? input.vm?.name === input.name && input.create !== null &&
      input.upcloud === null && input.provider_id === null
    : input.vm === null && input.create === null && input.upcloud !== null)
);
export type ProviderCreateIntent = z.infer<typeof ProviderCreateInput>;
export type ProviderCreated = {
  provider_id: string | null;
  address: string;
  ssh_host_key: string | null;
  image_fingerprint: string;
  running: true;
  settled: true;
};
type ProviderMaterial =
  & SweepGateway
  & Pick<GatewayOperation, "storeSecretOutput">;
type Options = {
  hostKey?: (name: string) => Promise<SshHostKeyPair>;
  invoke?: SystemTransport;
  request?: CloudRequest;
  wait?: () => Promise<unknown>;
  now?: () => number;
};
const refused = () => new Error("task_vm.provider_create_refused");
const Listed = z.object({ uuid: Uuid, title: z.string() });
const Server = Listed.extend({
  hostname: z.string(),
  state: z.enum(["started", "stopped", "maintenance", "error"]),
  labels: z.object({
    label: z.array(z.object({ key: z.string(), value: z.string() })),
  }),
  ip_addresses: z.object({
    ip_address: z.array(z.object({
      access: z.string(),
      family: z.string(),
      address: z.string(),
    })),
  }),
});

/** Provider work is a placed parent step. Private cloud-init stays inside its protected callback. */
export async function createTaskVmProvider(
  raw: ProviderCreateIntent,
  guard: () => Promise<void>,
  privateMaterial: ProviderMaterial,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
  options: Options = {},
): Promise<ProviderCreated> {
  // Do not expose private provider response or validation values in workflow errors.
  try {
    const input = ProviderCreateInput.parse(raw);
    const now = options.now ?? Date.now;
    const wait = options.wait ??
      (() => new Promise((resolve) => setTimeout(resolve, 3000)));
    const deadline = Date.parse(input.deadline_at);
    const check = async () => {
      signal?.throwIfAborted();
      if (now() >= deadline) throw refused();
      await guard();
    };
    await check();
    if (input.provider === "incus") {
      const vm = input.vm!;
      const create = input.create!;
      const transport = options.invoke ?? invoke;
      let result = await createIncusVm(vm, create, check, transport, signal);
      for (;;) {
        if (result.running && result.settled && result.address !== null) {
          return {
            provider_id: null,
            address: result.address,
            ssh_host_key: null,
            image_fingerprint: create.expected_fingerprint,
            running: true,
            settled: true,
          };
        }
        await check();
        await wait();
        const observed = await observeIncusVm(vm, check, transport, signal);
        if (
          !observed ||
          observed.image_fingerprint !== create.expected_fingerprint
        ) throw refused();
        result = observed;
      }
    }
    const cloud = input.upcloud!;
    const rawRequest = options.request ?? cloudRequest(privateMaterial, signal);
    const request: CloudRequest = async (...args) => {
      await check();
      return await rawRequest(...args);
    };
    const owned = (raw: unknown, id: string) => {
      const server = Server.parse(raw);
      const labels = server.labels.label.filter((l) =>
        l.key === "orbit-task-vm"
      );
      if (
        server.uuid !== id || server.title !== input.name ||
        server.hostname !== input.name || labels.length !== 1 ||
        labels[0].value !== String(input.task_vm_id)
      ) throw refused();
      return server;
    };
    const detail = async (id: string) => {
      const result = await request("GET", `server/${id}`, undefined, true);
      return result === null ? null : owned(result.server, id);
    };
    const listed = async () =>
      z.object({ servers: z.object({ server: z.array(Listed).max(4096) }) })
        .parse(await request("GET", "server?label=orbit-task-vm")).servers
        .server;
    const find = async () => {
      const rows = (await listed()).filter((row) => row.title === input.name);
      if (rows.length > 1) throw refused();
      const id = input.provider_id ?? rows[0]?.uuid;
      if (
        input.provider_id && rows.some((row) => row.uuid !== input.provider_id)
      ) throw refused();
      return id ? await detail(id) : null;
    };
    let bootstrap: SshHostKeyPair | null = null;
    const readBootstrap = () =>
      privateMaterial.withPrivateMaterial((material) => {
        bootstrap = SshHostKey.nullable().parse(material.provider_bootstrap);
        return Promise.resolve();
      });
    await readBootstrap();
    let server = await find();
    if (server && bootstrap === null) throw refused();
    if (!server) {
      if (input.provider_id !== null) throw refused();
      if ((await listed()).length >= cloud.max_vms) {
        throw new Error("task_vm.upcloud_budget_full");
      }
      const plans = z.object({
        plans: z.object({
          plan: z.array(z.object({
            name: z.string(),
            storage_tier: z.string().nullish(),
          })),
        }),
      }).parse(await request("GET", "plan")).plans.plan
        .filter((plan) => plan.name === cloud.plan);
      if (plans.length !== 1) throw refused();
      const tier = plans[0].storage_tier ?? undefined;
      if (tier !== undefined && !/^[a-z]{1,16}$/.test(tier)) throw refused();
      if (bootstrap === null) {
        await check();
        const generated = SshHostKey.parse(
          await (options.hostKey ?? generateSshHostKey)(input.name),
        );
        await check();
        await privateMaterial.storeSecretOutput({
          provider_bootstrap: generated,
        });
        await readBootstrap();
        if (JSON.stringify(bootstrap) !== JSON.stringify(generated)) {
          throw refused();
        }
      }
      let id: string | undefined;
      try {
        await privateMaterial.withPrivateMaterial(async (material) => {
          const pair = SshHostKey.parse(material.provider_bootstrap);
          if (JSON.stringify(pair) !== JSON.stringify(bootstrap)) {
            throw refused();
          }
          const userData = "#cloud-config\n" + JSON.stringify({
            users: [{
              name: "orbit",
              lock_passwd: true,
              shell: "/bin/bash",
              sudo: "ALL=(ALL) NOPASSWD:ALL",
              ssh_authorized_keys: [cloud.gateway_public_key],
            }],
            disable_root: true,
            ssh_deletekeys: true,
            ssh_genkeytypes: [],
            ssh_keys: {
              ed25519_private: pair.private,
              ed25519_public: pair.public,
            },
          }) + "\n";
          id = z.object({ server: z.object({ uuid: Uuid }) }).parse(
            await request("POST", "server", {
              server: {
                zone: cloud.zone,
                title: input.name,
                hostname: input.name,
                plan: cloud.plan,
                metadata: "yes",
                firewall: "off",
                remote_access_enabled: "no",
                labels: {
                  label: [{
                    key: "orbit-task-vm",
                    value: String(input.task_vm_id),
                  }],
                },
                networking: {
                  interfaces: {
                    interface: [{
                      type: "public",
                      ip_addresses: { ip_address: [{ family: "IPv4" }] },
                    }],
                  },
                },
                storage_devices: {
                  storage_device: [{
                    action: "clone",
                    storage: cloud.source_template,
                    title: input.name + "-disk",
                    size: cloud.disk_gb,
                    ...(tier === undefined ? {} : { tier }),
                  }],
                },
                login_user: {
                  username: "root",
                  ssh_keys: { ssh_key: [cloud.gateway_public_key] },
                },
                user_data: userData,
              },
            }),
          ).server.uuid;
        });
      } catch {
        // A lost reply is observed once; never blindly send a second create.
        server = await find();
        if (!server) throw refused();
      }
      if (id) server = await detail(id);
      if (!server) throw refused();
    }
    const id = server.uuid;
    if (server.state === "stopped") {
      await request("POST", `server/${id}/start`, { server: {} });
    }
    for (;;) {
      if (server.state === "error") throw refused();
      const addresses = server.ip_addresses.ip_address.filter((ip) =>
        ip.access === "public" && ip.family === "IPv4"
      );
      if (addresses.length > 1) throw refused();
      if (server.state === "started" && addresses.length === 1) {
        const address = z.ipv4().parse(addresses[0].address);
        if (
          /^(?:0|10|127|169\.254|172\.(?:1[6-9]|2[0-9]|3[01])|192\.168|22[4-9]|23[0-9]|24[0-9]|25[0-5])\./
            .test(address)
        ) throw refused();
        return {
          provider_id: id,
          address,
          ssh_host_key: SshHostKey.parse(bootstrap).public,
          image_fingerprint: cloud.source_template,
          running: true,
          settled: true,
        };
      }
      await check();
      await wait();
      server = await detail(id);
      if (!server) throw refused();
    }
  } catch {
    throw refused();
  }
}
