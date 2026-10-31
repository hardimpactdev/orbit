import { z } from "npm:zod@4.4.3";
import type { CloudRequest } from "./upcloud_sweep.ts";
import { HostKeys } from "./image_ssh.ts";

const uuid = z.string().uuid();
const labels = z.array(z.object({ key: z.string(), value: z.string() }));
const listed = z.object({ uuid, title: z.string() });
const serverSchema = listed.extend({
  hostname: z.string(),
  state: z.enum(["started", "stopped", "maintenance", "error"]),
  labels: z.object({ label: labels }),
  ip_addresses: z.object({
    ip_address: z.array(
      z.object({ access: z.string(), family: z.string(), address: z.string() }),
    ),
  }),
  storage_devices: z.object({
    storage_device: z.array(z.object({ storage: uuid })).length(1),
  }),
});
const templateSchema = listed.extend({
  access: z.string(),
  state: z.string().optional(),
  labels: labels.optional(),
});
export type ImageServer = z.infer<typeof serverSchema>;
export const ImageCloudIntent = z.object({
  build_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
  generation: z.number().int().positive(),
  stamp: z.string().regex(/^[0-9]{14}$/),
  name: z.string(),
  source: uuid,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  gateway_public_key: z.string(),
  ssh_identity: z.string(),
  recipe: z.preprocess(
    (raw) => typeof raw === "string" ? JSON.parse(raw) : raw,
    z.record(z.string(), z.unknown()),
  ),
  pi_artifact: z.object({ sha256: z.string().regex(/^[a-f0-9]{64}$/) }).strict()
    .nullable(),
  upcloud: z.object({
    zone: z.string().regex(/^[a-z]{2}-[a-z]{3}[1-9]$/),
    plan: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/),
    disk_gb: z.number().int().min(10).max(200),
  }).strict(),
  pinned_templates: z.array(uuid),
  progress: z.object({
    resources: z.record(z.string(), z.unknown()),
    template_uuid: uuid.nullable(),
  }),
  current: z.boolean(),
}).strict().superRefine((input, ctx) => {
  if (
    input.name !== `tvm-image-${input.stamp}` ||
    input.recipe.identity !== input.identity ||
    input.recipe.agent_sha256 !== input.agent_sha256 ||
    input.recipe.pi_sha256 !== (input.pi_artifact?.sha256 ?? null)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "The recipe differs from its admitted pins",
    });
  }
});
export type CloudImage = z.infer<typeof ImageCloudIntent>;
const refuse = () => new Error("task_vm.upcloud_invalid");

export function imageCloud(
  input: CloudImage,
  request: CloudRequest,
  wait = () => new Promise((resolve) => setTimeout(resolve, 3000)),
  now = Date.now,
) {
  const prefix = "orbit-task-base-" + input.stamp;
  const name = (slot: "builder" | "smoke") =>
    input.name + (slot === "smoke" ? "-smoke" : "");
  const find = async (
    slot: "builder" | "smoke",
  ): Promise<ImageServer | null> => {
    const raw = await request("GET", "server?label=orbit-task-image");
    const rows = z.object({
      servers: z.object({ server: z.array(listed).max(4096) }),
    }).parse(raw).servers.server
      .filter((server) => server.title === name(slot));
    if (rows.length > 1) throw refuse();
    if (!rows.length) return null;
    const detail = await request(
      "GET",
      `server/${rows[0].uuid}`,
      undefined,
      true,
    );
    if (detail === null) return null;
    const server = serverSchema.parse(detail.server);
    const owned = server.labels.label.filter((label) =>
      label.key === "orbit-task-image"
    );
    const stored = input.progress.resources[slot];
    if (
      server.uuid !== rows[0].uuid || server.title !== name(slot) ||
      server.hostname !== name(slot) ||
      owned.length !== 1 || owned[0].value !== input.stamp ||
      (stored !== undefined &&
        z.object({ uuid: z.literal(server.uuid) }).safeParse(stored).success ===
          false)
    ) throw refuse();
    return server;
  };
  const templates = async () =>
    z.object({
      storages: z.object({ storage: z.array(templateSchema).max(4096) }),
    })
      .parse(await request("GET", "storage/template")).storages.storage
      .filter((template) =>
        template.access === "private" &&
        /^orbit-task-base-[0-9]{14}(?:-unverified)?$/.test(template.title)
      );
  const ownedTemplate = async (allowMissing = false) => {
    const rows = (await templates()).filter((template) =>
      [prefix, prefix + "-unverified"].includes(template.title)
    );
    if (rows.length > 1) throw refuse();
    const known = input.progress.template_uuid;
    if (rows[0] && known !== null && rows[0].uuid !== known) throw refuse();
    if (known !== null && !rows[0] && !allowMissing) throw refuse();
    return rows[0] ?? null;
  };
  const until = async <T>(
    read: () => Promise<T | null | false>,
    seconds: number,
  ): Promise<T> => {
    const deadline = now() + seconds * 1000;
    for (;;) {
      const value = await read();
      if (value !== null && value !== false) return value;
      if (now() >= deadline) throw new Error("task_vm.upcloud_timeout");
      await wait();
    }
  };
  const stop = async (slot: "builder" | "smoke", soft: boolean) => {
    const server = await find(slot);
    if (!server || server.state === "stopped") return;
    await request("POST", `server/${server.uuid}/stop`, {
      stop_server: soft
        ? { stop_type: "soft", timeout: "150" }
        : { stop_type: "hard" },
    });
    if (soft) {
      const deadline = now() + 150000;
      while (now() < deadline) {
        const current = await find(slot);
        if (!current || current.state === "stopped") return;
        await wait();
      }
      await request("POST", `server/${server.uuid}/stop`, {
        stop_server: { stop_type: "hard" },
      });
    }
    await until(async () => {
      const current = await find(slot);
      return !current || current.state === "stopped";
    }, 180);
  };
  return {
    find,
    templates,
    ownedTemplate,
    async launch(slot: "builder" | "smoke", keys: z.infer<typeof HostKeys>) {
      const current = await find(slot);
      if (current) return current.uuid;
      const source = slot === "builder"
        ? input.source
        : (await ownedTemplate())?.uuid;
      if (!source) throw refuse();
      const plans = z.object({
        plans: z.object({
          plan: z.array(
            z.object({ name: z.string(), storage_tier: z.string().optional() }),
          ),
        }),
      })
        .parse(await request("GET", "plan")).plans.plan.filter((plan) =>
          plan.name === input.upcloud.plan
        );
      if (plans.length !== 1) throw refuse();
      const tier = plans[0].storage_tier;
      if (tier !== undefined && !/^[a-z]{1,16}$/.test(tier)) throw refuse();
      const user = {
        name: "orbit",
        lock_passwd: true,
        shell: "/bin/bash",
        sudo: "ALL=(ALL) NOPASSWD:ALL",
        ssh_authorized_keys: [input.gateway_public_key],
        ...(slot === "builder" ? { uid: 1000, no_user_group: false } : {}),
      };
      const cloud = {
        users: [user],
        disable_root: true,
        ssh_deletekeys: true,
        ssh_genkeytypes: [],
        ssh_keys: {
          ed25519_private: keys[slot].private,
          ed25519_public: keys[slot].public,
        },
      };
      const result = await request("POST", "server", {
        server: {
          zone: input.upcloud.zone,
          title: name(slot),
          hostname: name(slot),
          plan: input.upcloud.plan,
          metadata: "yes",
          firewall: "off",
          remote_access_enabled: "no",
          labels: { label: [{ key: "orbit-task-image", value: input.stamp }] },
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
              storage: source,
              title: name(slot) + "-disk",
              size: input.upcloud.disk_gb,
              ...(tier === undefined ? {} : { tier }),
            }],
          },
          login_user: {
            username: "root",
            ssh_keys: { ssh_key: [input.gateway_public_key] },
          },
          user_data: "#cloud-config\n" + JSON.stringify(cloud) + "\n",
        },
      });
      return z.object({ server: z.object({ uuid }) }).parse(result).server.uuid;
    },
    async boot(slot: "builder" | "smoke") {
      return await until(async () => {
        const server = await find(slot);
        if (!server || server.state === "error") {
          throw new Error("task_vm.upcloud_failed");
        }
        const addresses = server.ip_addresses.ip_address.filter((ip) =>
          ip.access === "public" && ip.family === "IPv4"
        );
        if (addresses.length > 1) throw refuse();
        return server.state === "started" && addresses.length === 1
          ? addresses[0].address
          : null;
      }, 1200);
    },
    stop,
    async remove(slot: "builder" | "smoke") {
      await stop(slot, false);
      const server = await find(slot);
      if (server) {
        await request(
          "DELETE",
          `server/${server.uuid}?storages=1&backups=delete`,
          undefined,
          true,
        );
      }
      await until(async () => await find(slot) === null, 120);
    },
    async templatize() {
      const existing = await ownedTemplate();
      if (existing) return existing.uuid;
      const server = await find("builder");
      if (server?.state !== "stopped") throw refuse();
      const result = await request(
        "POST",
        `storage/${
          server.storage_devices.storage_device[0].storage
        }/templatize`,
        { storage: { title: prefix + "-unverified" } },
      );
      return z.object({ storage: z.object({ uuid }) }).parse(result).storage
        .uuid;
    },
    async online(id: string) {
      await until(
        async () =>
          z.object({
            storage: z.object({ uuid: z.literal(id), state: z.string() }),
          })
            .parse(await request("GET", `storage/${uuid.parse(id)}`)).storage
            .state === "online",
        600,
      );
    },
    async promote() {
      const template = await ownedTemplate();
      if (!template) throw refuse();
      await request("PUT", `storage/${template.uuid}`, {
        storage: {
          title: prefix,
          labels: [{ key: "orbit.recipe", value: input.identity }],
        },
      });
      return template.uuid;
    },
    async prune(pinned: string[]) {
      const verified = (await templates()).filter((template) =>
        !template.title.endsWith("-unverified")
      )
        .sort((a, b) => b.title.localeCompare(a.title));
      for (const template of verified.slice(2)) {
        if (
          !pinned.includes(template.uuid) &&
          template.uuid !== input.progress.template_uuid
        ) {
          await request("DELETE", `storage/${template.uuid}`, undefined, true);
        }
      }
    },
    async discard() {
      const template = await ownedTemplate(true);
      if (template?.title === prefix + "-unverified") {
        await request("DELETE", `storage/${template.uuid}`, undefined, true);
      }
    },
  };
}
