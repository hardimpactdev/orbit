import { z } from "npm:zod@4.4.3";
import { parse as yaml } from "jsr:@std/yaml@1.0.12";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/);
const Pool = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/);
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
export const IncusLocation = z.object({
  project: Name,
  name: Name,
  cidr: z.cidrv4(),
}).strict();
export const IncusCreate = z.object({
  kind: z.literal("create"),
  image: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._/-]{0,253}$/),
  expected_fingerprint: Digest,
  base_alias: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._/-]{0,253}$/),
  gateway_public_key: z.string().regex(
    /^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}(?: [!-~][ -~]*)?$/,
  ),
  cpus: z.number().int().min(1).max(64),
  memory: z.string().regex(/^[1-9][0-9]{0,5}(?:MiB|GiB)$/),
  disk: z.string().regex(/^[1-9][0-9]{0,5}(?:MiB|GiB)$/),
  network: Name,
  pool: Pool,
  topology: z.boolean(),
}).strict();
export type IncusVmLocation = z.infer<typeof IncusLocation>;
export type IncusCreateIntent = z.infer<typeof IncusCreate>;
export type IncusObservation = {
  running: boolean;
  settled: true;
  address: string | null;
  image_fingerprint: string | null;
};
const failed = () => new Error("task_vm.incus_operation_failed");
const ipv4 = (address: string) =>
  z.ipv4().parse(address).split(".").reduce(
    (n, part) => n * 256 + Number(part),
    0,
  );
function subnet(location: IncusVmLocation) {
  const [address, rawPrefix] = location.cidr.split("/");
  const prefix = Number(rawPrefix);
  const network = ipv4(address);
  const size = 2 ** (32 - prefix);
  if (prefix < 16 || prefix > 28 || network % size !== 0) throw failed();
  return { network, size };
}
function command(
  project: string,
  guard: () => Promise<void>,
  invoke: SystemTransport,
  signal?: AbortSignal,
) {
  return async (
    args: string[],
    _mutates: boolean,
    stdin?: string,
    budgetMs = _mutates ? 900000 : 60000,
  ) => {
    signal?.throwIfAborted();
    await guard();
    return await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/incus", "--project", project, ...args],
      ...(stdin === undefined ? {} : { stdin }),
      timeoutMs: budgetMs,
    }, signal);
  };
}

/** Observe only the admitted exact VM and one usable address in its bridge subnet. */
export async function observeIncusVm(
  raw: IncusVmLocation,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<IncusObservation | null> {
  try {
    const vm = IncusLocation.parse(raw);
    const { network, size } = subnet(vm);
    const result = await command(vm.project, guard, invoke, signal)(
      ["list", "--format", "json", "--", vm.name],
      false,
    );
    if (!result.success) throw failed();
    const rows = z.array(z.object({ name: z.string() }).passthrough()).max(4096)
      .parse(JSON.parse(result.stdout)).filter((row) => row.name === vm.name);
    if (rows.length === 0) return null;
    if (rows.length !== 1) throw failed();
    const row = z.object({
      type: z.literal("virtual-machine"),
      status: z.enum(["Running", "Stopped"]),
      config: z.record(z.string(), z.unknown()).nullish(),
      state: z.object({
        network: z.record(
          z.string(),
          z.object({
            addresses: z.array(
              z.object({ family: z.string(), address: z.string() }),
            ).optional(),
          }),
        ).nullish(),
      }).nullish(),
    }).parse(rows[0]);
    const fingerprint =
      Digest.nullish().parse(row.config?.["volatile.base_image"]) ?? null;
    const addresses: string[] = [];
    for (const iface of Object.values(row.state?.network ?? {})) {
      for (const candidate of iface.addresses ?? []) {
        if (
          candidate.family !== "inet" ||
          !z.ipv4().safeParse(candidate.address).success
        ) continue;
        const address = ipv4(candidate.address);
        if (address >= network && address < network + size) {
          addresses.push(candidate.address);
        }
      }
    }
    if (addresses.length > 1) throw failed();
    if (addresses.length === 1) {
      const address = ipv4(addresses[0]);
      if (address <= network + 1 || address === network + size - 1) {
        throw failed();
      }
    }
    return {
      running: row.status === "Running",
      settled: true,
      address: addresses[0] ?? null,
      image_fingerprint: fingerprint,
    };
  } catch {
    throw failed();
  }
}
async function volumeNames(
  project: string,
  pool: string,
  guard: () => Promise<void>,
  invoke: SystemTransport,
  signal?: AbortSignal,
) {
  const result = await command(project, guard, invoke, signal)(
    ["storage", "volume", "list", "--format", "json", "--", pool],
    false,
  );
  if (!result.success) throw failed();
  return z.array(z.object({ type: z.string(), name: z.string() })).max(8192)
    .parse(JSON.parse(result.stdout)).filter((row) => row.type === "custom")
    .map((row) => row.name);
}
export async function prepareIncusMetadata(
  vm: Pick<IncusVmLocation, "project" | "name">,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<void> {
  Name.parse(vm.project);
  Name.parse(vm.name);
  const run = command(vm.project, guard, invoke, signal);
  const read = await run(["config", "metadata", "show", "--", vm.name], false);
  if (!read.success) throw failed();
  const metadata = z.object({ templates: z.record(z.string(), z.unknown()) })
    .passthrough()
    .parse(yaml(read.stdout));
  metadata.templates = Object.fromEntries(
    Object.entries(metadata.templates)
      .filter(([path]) => !path.startsWith("/var/lib/cloud/seed/")),
  );
  const write = await run(
    ["config", "metadata", "edit", "--", vm.name],
    true,
    JSON.stringify(metadata),
  );
  if (!write.success) throw failed();
}

async function boot(
  vm: IncusVmLocation,
  guard: () => Promise<void>,
  invoke: SystemTransport,
  signal?: AbortSignal,
) {
  const run = command(vm.project, guard, invoke, signal);
  await prepareIncusMetadata(vm, guard, invoke, signal);
  try {
    const result = await run(["start", "--", vm.name], true);
    if (!result.success) throw failed();
  } catch {
    if (!(await observeIncusVm(vm, guard, invoke, signal))?.running) {
      throw failed();
    }
  }
}

/** Create from the immutable image digest; cloud-init and metadata use stdin, never shell source. */
export async function createIncusVm(
  rawVm: IncusVmLocation,
  rawCreate: IncusCreateIntent,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<IncusObservation> {
  try {
    const vm = IncusLocation.parse(rawVm);
    const create = IncusCreate.parse(rawCreate);
    subnet(vm);
    let observed = await observeIncusVm(vm, guard, invoke, signal);
    const matching = (value: IncusObservation | null) => {
      if (value && value.image_fingerprint !== create.expected_fingerprint) {
        throw failed();
      }
      return value;
    };
    matching(observed);
    if (!observed) {
      const run = command(vm.project, guard, invoke, signal);
      const settings = {
        config: {
          "cloud-init.user-data": "#cloud-config\n" + JSON.stringify({
            users: [{
              name: "orbit",
              lock_passwd: true,
              shell: "/bin/bash",
              sudo: "ALL=(ALL) NOPASSWD:ALL",
              ssh_authorized_keys: [create.gateway_public_key],
            }],
          }) + "\n",
        } as Record<string, string>,
        devices: {
          "cloud-init": { type: "disk", source: "cloud-init:config" },
        } as Record<string, Record<string, string>>,
      };
      if (create.topology) {
        const { name: target } = await cloneIncusSeed(
          {
            location: { project: vm.project, pool: create.pool },
            operation: {
              kind: "clone_seed",
              vm: vm.name,
              fingerprint: create.expected_fingerprint,
            },
          },
          guard,
          invoke,
          signal,
        );
        settings.devices["topology-seed"] = {
          type: "disk",
          pool: create.pool,
          source: target,
        };
        settings.config["raw.qemu.conf"] =
          '[device "qemu_balloon"]\nfree-page-reporting = "on"\n';
      }
      try {
        const result = await run(
          [
            "init",
            "--vm",
            "--config",
            `limits.cpu=${create.cpus}`,
            "--config",
            `limits.memory=${create.memory}`,
            "--device",
            `root,size=${create.disk}`,
            "--device",
            `eth0,network=${create.network}`,
            "--device",
            "eth0,security.port_isolation=true",
            "--",
            create.expected_fingerprint,
            vm.name,
          ],
          true,
          JSON.stringify(settings),
        );
        if (!result.success) throw failed();
      } catch {
        if (!matching(await observeIncusVm(vm, guard, invoke, signal))) {
          throw failed();
        }
      }
      observed = matching(await observeIncusVm(vm, guard, invoke, signal));
      if (!observed) throw failed();
    }
    if (!observed.running) await boot(vm, guard, invoke, signal);
    const result = matching(await observeIncusVm(vm, guard, invoke, signal));
    if (!result?.running) throw failed();
    return result;
  } catch {
    throw failed();
  }
}

const CloneSeedInput = z.object({
  location: z.object({ project: Name, pool: Pool }).strict(),
  operation: z.object({
    kind: z.literal("clone_seed"),
    vm: Name,
    fingerprint: Digest,
  }).strict(),
}).strict();
export type IncusCloneSeedIntent = z.infer<typeof CloneSeedInput>;
/** Clone only the fingerprint-bound immutable seed into this admitted VM's one named volume. */
export async function cloneIncusSeed(
  raw: IncusCloneSeedIntent,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<{ name: string }> {
  try {
    const { location: { project, pool }, operation: { vm, fingerprint } } =
      CloneSeedInput.parse(raw);
    const source = `orbit-topology-seed-${fingerprint.slice(0, 12)}`,
      name = `${vm}-topology-seed`;
    const names = await volumeNames(project, pool, guard, invoke, signal);
    if (!names.includes(name)) {
      if (!names.includes(source)) throw failed();
      try {
        const result = await command(project, guard, invoke, signal)(
          [
            "storage",
            "volume",
            "copy",
            "--",
            `${pool}/${source}`,
            `${pool}/${name}`,
          ],
          true,
          undefined,
          180000,
        );
        if (!result.success) throw failed();
      } catch {
        if (
          !(await volumeNames(project, pool, guard, invoke, signal)).includes(
            name,
          )
        ) throw failed();
      }
    }
    return { name };
  } catch {
    throw failed();
  }
}

const VolumeInput = z.object({
  location: z.object({ project: Name, pool: Pool }).strict(),
  operation: z.object({
    kind: z.literal("remove"),
    name: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/),
  }).strict(),
}).strict();
export type IncusVolumeIntent = z.infer<typeof VolumeInput>;
/** Remove only the explicitly admitted custom volume; absence already satisfies removal. */
export async function incusVolumeOperation(
  raw: IncusVolumeIntent,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<{ name: string }> {
  try {
    const { location: { project, pool }, operation: { name } } = VolumeInput
      .parse(raw);
    if (
      (await volumeNames(project, pool, guard, invoke, signal)).includes(name)
    ) {
      try {
        const result = await command(project, guard, invoke, signal)(
          ["storage", "volume", "delete", "--", pool, name],
          true,
          undefined,
          180000,
        );
        if (!result.success) throw failed();
      } catch {
        if (
          (await volumeNames(project, pool, guard, invoke, signal)).includes(
            name,
          )
        ) throw failed();
      }
      if (
        (await volumeNames(project, pool, guard, invoke, signal)).includes(name)
      ) throw failed();
    }
    return { name };
  } catch {
    throw failed();
  }
}
const PowerInput = z.object({
  vm: IncusLocation,
  operation: z.discriminatedUnion("kind", [
    z.object({ kind: z.literal("start") }).strict(),
    z.object({ kind: z.literal("stop") }).strict(),
    z.object({ kind: z.literal("remove_instance") }).strict(),
    z.object({ kind: z.literal("delete"), pool: Pool }).strict(),
  ]),
}).strict();
export type IncusVmPowerIntent = z.infer<typeof PowerInput>;
/** Parent DAG power/removal steps. Unknown effects reconcile by reads without duplicate mutation. */
export async function incusVmOperation(
  raw: IncusVmPowerIntent,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<{ ready: true }> {
  try {
    const { vm, operation } = PowerInput.parse(raw);
    const run = command(vm.project, guard, invoke, signal);
    const observed = await observeIncusVm(vm, guard, invoke, signal);
    if (operation.kind === "start") {
      if (!observed) throw failed();
      if (!observed.running) await boot(vm, guard, invoke, signal);
    } else if (operation.kind === "stop") {
      if (observed?.running) {
        try {
          const result = await run(
            ["stop", "--timeout", "120", "--", vm.name],
            true,
            undefined,
            150000,
          );
          if (!result.success) throw failed();
        } catch {
          if ((await observeIncusVm(vm, guard, invoke, signal))?.running) {
            try {
              const result = await run(
                ["stop", "--force", "--", vm.name],
                true,
              );
              if (!result.success) throw failed();
            } catch {
              if ((await observeIncusVm(vm, guard, invoke, signal))?.running) {
                throw failed();
              }
            }
          }
        }
        if ((await observeIncusVm(vm, guard, invoke, signal))?.running) {
          throw failed();
        }
      }
    } else {
      if (observed) {
        try {
          const result = await run(["delete", "--force", "--", vm.name], true);
          if (!result.success) throw failed();
        } catch {
          if (await observeIncusVm(vm, guard, invoke, signal)) throw failed();
        }
        if (await observeIncusVm(vm, guard, invoke, signal)) throw failed();
      }
      if (operation.kind === "delete") {
        await incusVolumeOperation(
          {
            location: { project: vm.project, pool: operation.pool },
            operation: { kind: "remove", name: `${vm.name}-topology-seed` },
          },
          guard,
          invoke,
          signal,
        );
      }
    }
    return { ready: true };
  } catch {
    throw failed();
  }
}
