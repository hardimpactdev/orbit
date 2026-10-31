import { issue, report, rows } from "./doctor_contract.ts";
import type {
  FamilyReport,
  Issue,
  ProbeContext,
  Row,
} from "./doctor_contract.ts";

const object = (v: unknown): Row => {
  if (!v || typeof v !== "object" || Array.isArray(v)) {
    throw new Error("Invalid observation");
  }
  return v as Row;
};
const architecture = (v: unknown): string | null =>
  v === null || v === undefined || v === ""
    ? null
    : ["amd64", "x86_64"].includes(String(v).toLowerCase())
    ? "x86_64"
    : ["arm64", "aarch64"].includes(String(v).toLowerCase())
    ? "aarch64"
    : "other";
const platform = (v: unknown): string | null =>
  v === null || v === undefined
    ? null
    : ["linux", "darwin"].includes(String(v).toLowerCase())
    ? String(v).toLowerCase()
    : "other";
const checksum = (v: unknown) =>
  typeof v === "string" && /^[a-f0-9]{64}$/.test(v);
const component = (v: unknown): v is string =>
  typeof v === "string" && /^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/.test(v);
const list = (v: unknown): Row[] => {
  if (!Array.isArray(v)) throw new Error("Invalid inventory");
  return v.map(object);
};
const names = (v: unknown): string[] => {
  if (!Array.isArray(v) || !v.every(component)) {
    throw new Error("Invalid names");
  }
  return v;
};

function nodeIssue(
  ctx: ProbeContext,
  code: string,
  summary: string,
  expected: boolean | string | null = true,
  observed: boolean | string | null = false,
  unverifiable = false,
): Issue {
  return issue(
    "node",
    `node.${code}`,
    ctx.node,
    summary,
    expected,
    observed,
    unverifiable ? "unverifiable" : "drift",
  );
}
const setting = (v: string) =>
  v.includes(":") ? v.slice(v.indexOf(":") + 1).trim() : null;
export function dnsPolicyMatches(
  server: string,
  domains: string[],
  observed: Row,
): boolean {
  if (
    ["state", "dns", "domains", "hooks", "default_route"].some((k) =>
      typeof observed[k] !== "string"
    )
  ) return false;
  const live = (setting(String(observed.domains)) ?? "").split(" ").sort();
  const expected = domains.map((d) => `~${d}`).sort();
  return observed.state === ["orbit", server, ...domains].join("\n") &&
    setting(String(observed.dns)) === server &&
    JSON.stringify(live) === JSON.stringify(expected) &&
    observed.hooks ===
      `PostUp = resolvectl dns %i ${server}; resolvectl domain %i ${
        domains.map((d) => `\\~${d}`).join(" ")
      }` &&
    setting(String(observed.default_route)) === "no";
}
async function dnsIssues(ctx: ProbeContext, dns: Row): Promise<Issue[]> {
  try {
    if (
      dns.invalid || typeof dns.server !== "string" ||
      !Array.isArray(dns.domains) || !dns.domains.every((d) =>
        typeof d === "string"
      ) || typeof dns.public_domain !== "string"
    ) throw new Error("Invalid DNS policy");
    const privateName = "gateway.orbit", publicName = dns.public_domain;
    const isPrivate = (name: string) =>
      (dns.domains as string[]).some((suffix) =>
        name === suffix || name.endsWith(`.${suffix}`)
      );
    if (!isPrivate(privateName) || isPrivate(publicName)) {
      throw new Error("Invalid DNS probes");
    }
    const observed = await ctx.run("wireguard.inspect-peer-dns", {
      server: dns.server,
    }, true);
    if (
      ["state", "hooks", "dns", "domains", "default_route"].some((k) =>
        typeof observed[k] !== "string"
      ) || typeof observed.uplink !== "boolean"
    ) throw new Error("Invalid DNS observation");
    const answer = await ctx.run("wireguard.query-dns", {
      names: [privateName, publicName],
    }, false);
    if (
      !Array.isArray(answer.answers) || answer.answers.length !== 2 ||
      answer.answers.some((a) => a !== null && typeof a !== "string")
    ) throw new Error("Invalid DNS answers");
    const [privateAnswer, publicAnswer] = answer.answers as (string | null)[];
    const privateLink = /-- link: (\S+)/.exec(privateAnswer ?? "")?.[1];
    const publicLink = /-- link: (\S+)/.exec(publicAnswer ?? "")?.[1];
    const gateway = typeof dns.gateway_address === "string"
      ? dns.gateway_address.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
      : null;
    const validPrivate = privateLink === "orbit" && gateway !== null &&
      new RegExp(`(?<![0-9.])${gateway}(?![0-9.])`).test(privateAnswer ?? "");
    const validPublic = publicAnswer !== null && publicAnswer !== "" &&
      publicLink !== "orbit";
    const issues: Issue[] = [];
    for (
      const [valid, code, summary] of [
        [
          observed.uplink,
          "private_dns_uplink_missing",
          "No usable non-Orbit DNS server is configured.",
        ],
        [
          dnsPolicyMatches(dns.server, dns.domains as string[], observed),
          "private_dns_policy_mismatch",
          "Managed private DNS policy does not match.",
        ],
        [
          validPrivate,
          "private_dns_unreachable",
          "Private DNS does not return the expected Gateway address.",
        ],
        [
          validPublic,
          "public_dns_unreachable",
          "Public DNS does not resolve through a non-Orbit resolver.",
        ],
      ] as const
    ) if (!valid) issues.push(nodeIssue(ctx, code, summary));
    return issues;
  } catch {
    return [
      nodeIssue(
        ctx,
        "inspection_failed",
        "Managed private DNS policy could not be inspected.",
        true,
        false,
        true,
      ),
    ];
  }
}

async function taskVmIssues(ctx: ProbeContext, host: Row): Promise<Issue[]> {
  const issues: Issue[] = [];
  const fail = () =>
    nodeIssue(
      ctx,
      "task_vm_inspection_failed",
      "The task VM host inventory could not be inspected.",
      null,
      "task_vm.invalid_host_output",
      true,
    );
  const ownedBuild = (name: string) => {
    const match = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/.exec(
      name,
    );
    return match !== null && Array.isArray(host.owned_build_ids) &&
      host.owned_build_ids.includes(Number(match[1]));
  };
  try {
    const result = await ctx.run(
      "incus.instances",
      { project: host.project },
      true,
    );
    const actual = names(result.names),
      live = list(host.live),
      recorded = new Set(live.map((r) => r.name));
    for (const name of actual) {
      if (
        (/^tvm-[0-9]+$/.test(name) && !recorded.has(name)) ||
        (/^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/.test(name) &&
          !ownedBuild(name))
      ) {
        issues.push(
          nodeIssue(
            ctx,
            "task_vm_orphaned",
            "A task VM has no live task VM record.",
            "absent",
            name,
          ),
        );
      }
    }
    for (const vm of live) {
      if (
        ["ready", "parked"].includes(String(vm.state)) &&
        !actual.includes(String(vm.name))
      ) {
        issues.push(
          nodeIssue(
            ctx,
            "task_vm_missing",
            "A ready or parked task VM is missing from Incus.",
            String(vm.name),
            "absent",
          ),
        );
      }
    }
  } catch {
    return [fail()];
  }
  try {
    const inventory = await ctx.run("incus.inventory", {}, true);
    const instances = list(inventory.instances),
      volumes = list(inventory.volumes);
    if (
      instances.some((r) => !component(r.project) || !component(r.name)) ||
      volumes.some((r) =>
        !component(r.project) || !component(r.pool) || !component(r.name)
      )
    ) throw new Error("Invalid inventory");
    const images = object(
      (await ctx.run("incus.images", { project: host.project }, true)).images,
    );
    const fingerprints = Object.keys(images);
    if (fingerprints.some((f) => !/^[a-f0-9]{64}$/.test(f))) {
      throw new Error("Invalid fingerprints");
    }
    const operatorOwns = (r: Row) =>
      Array.isArray(host.operator_owned) &&
      (host.operator_owned.includes(`${r.project}/${r.name}`) ||
        host.operator_owned.includes(`${r.project}/*`));
    const ciOwned = (r: Row) =>
      host.ci_runner === true && r.project === "orbit-ci";
    for (const row of instances) {
      const orbitOwned = row.project === host.project &&
        (/^tvm-[0-9]+$/.test(String(row.name)) || ownedBuild(String(row.name)));
      if (!orbitOwned && !operatorOwns(row) && !ciOwned(row)) {
        issues.push(
          nodeIssue(
            ctx,
            "task_vm_stray_instance",
            "An Incus instance is outside Orbit and operator ownership.",
            "absent",
            `${row.project}/${row.name}`,
          ),
        );
      }
    }
    for (const row of volumes) {
      const seed = /^orbit-topology-seed-([a-f0-9]{12})(?:-new)?$/.exec(
        String(row.name),
      );
      const orbitOwned = row.project === host.project &&
        row.pool === host.pool &&
        (/^tvm-[0-9]+-topology-seed$/.test(String(row.name)) ||
          (seed !== null && fingerprints.some((f) => f.startsWith(seed[1]))));
      if (!orbitOwned && !operatorOwns(row) && !ciOwned(row)) {
        issues.push(
          nodeIssue(
            ctx,
            "task_vm_stray_volume",
            "An Incus volume is outside Orbit and operator ownership.",
            "absent",
            `${row.project}/${row.pool}/${row.name}`,
          ),
        );
      }
    }
    for (const row of list(host.stray_records)) {
      issues.push(
        nodeIssue(
          ctx,
          "task_vm_stray_record",
          "A ready or parked task VM record has no open group.",
          "destroyed",
          `${row.name} ${row.state}`,
        ),
      );
    }
  } catch {
    issues.push(fail());
  }
  return issues;
}

export async function checkNode(ctx: ProbeContext): Promise<FamilyReport> {
  const facts = rows(ctx, "node_checks")[0];
  if (!facts) return report("node", 0, []);
  const issues: Issue[] = [];
  if (facts.status !== "active") {
    issues.push(
      nodeIssue(
        ctx,
        "lifecycle_not_active",
        "Node lifecycle is not active.",
        "active",
        String(facts.status),
      ),
    );
  }
  for (
    const row of Array.isArray(facts.route_residues)
      ? facts.route_residues.map(object)
      : []
  ) {
    issues.push(
      nodeIssue(
        ctx,
        "route_residue_retained",
        `Removed Route [${row.route_id}] [${row.domain}] still has projections on the Node.`,
        "removed",
        Array.isArray(row.steps) ? row.steps.join(",") : "retained",
      ),
    );
  }
  if (facts.stray_node === true) {
    issues.push(
      nodeIssue(
        ctx,
        "task_vm_stray_node",
        "The Node is named like a task VM without a live task VM record.",
        "a live task VM record",
        "none",
      ),
    );
  }
  if (facts.managed !== true) return report("node", 1, issues);
  if (ctx.inspectionFailed) {
    return report("node", 1, [
      ...issues,
      nodeIssue(
        ctx,
        "inspection_failed",
        "Node observation failed.",
        null,
        null,
        true,
      ),
    ]);
  }
  if (ctx.observation.reachable === false) {
    return report("node", 1, [
      ...issues,
      nodeIssue(
        ctx,
        "ssh_unreachable",
        "The Node could not be reached.",
        true,
        false,
        true,
      ),
    ]);
  }
  const observation = ctx.observation;
  if (observation.observer_unavailable === true) {
    issues.push(
      nodeIssue(
        ctx,
        "agent_view_stale",
        "The Gateway has no fresh continuous observation of this Node.",
        "fresh",
        "unavailable",
        true,
      ),
    );
  }
  if (observation.node_read_unavailable) {
    if (facts.dns) issues.push(...await dnsIssues(ctx, object(facts.dns)));
    if (facts.task_host) {
      issues.push(...await taskVmIssues(ctx, object(facts.task_host)));
    }
    return report("node", 1, [
      ...issues,
      nodeIssue(
        ctx,
        "inspection_failed",
        "Node identity and filesystem state could not be inspected.",
        "fresh",
        "unavailable",
        true,
      ),
    ]);
  }
  if (observation.worker_state === "failed") {
    issues.push(
      nodeIssue(
        ctx,
        "worker_failed",
        "The Swamp Node worker failed.",
        "active",
        "failed",
      ),
    );
  }
  if (facts.dns) issues.push(...await dnsIssues(ctx, object(facts.dns)));
  const expectedPlatform = ctx.node.platform.toLowerCase() === "macos"
    ? "darwin"
    : ctx.node.platform.toLowerCase() === "linux"
    ? "linux"
    : null;
  const expectedArchitecture = architecture(
    facts.architecture === undefined
      ? ctx.node.architecture
      : facts.architecture,
  );
  if (expectedPlatform === null || expectedArchitecture === "other") {
    issues.push(
      nodeIssue(
        ctx,
        "inspection_failed",
        "Node managed identity cannot be verified.",
        "supported",
        "unsupported",
        true,
      ),
    );
  }
  if (
    expectedPlatform !== null &&
    platform(observation.platform) !== expectedPlatform
  ) {
    issues.push(
      nodeIssue(
        ctx,
        "platform_mismatch",
        "Node platform does not match.",
        expectedPlatform,
        platform(observation.platform),
      ),
    );
  }
  if (
    expectedArchitecture !== "other" &&
    architecture(observation.architecture) !== expectedArchitecture
  ) {
    issues.push(
      nodeIssue(
        ctx,
        "architecture_mismatch",
        "Node architecture does not match.",
        expectedArchitecture,
        architecture(observation.architecture),
      ),
    );
  }
  if (observation.wireguard !== true) {
    issues.push(
      nodeIssue(
        ctx,
        "wireguard_ip_mismatch",
        "Managed WireGuard address is not present.",
        true,
        typeof observation.wireguard === "boolean"
          ? observation.wireguard
          : null,
      ),
    );
  }
  if (Array.isArray(observation.disks)) {
    for (const raw of observation.disks) {
      try {
        const disk = object(raw);
        if (
          !["root", "home"].includes(String(disk.location)) ||
          !Number.isInteger(disk.available_kib) ||
          !Number.isInteger(disk.size_kib) || Number(disk.available_kib) < 0 ||
          Number(disk.size_kib) <= 0 ||
          Number(disk.available_kib) > Number(disk.size_kib) ||
          (disk.free_inodes !== null && !Number.isInteger(disk.free_inodes)) ||
          (disk.total_inodes !== null && !Number.isInteger(disk.total_inodes))
        ) throw new Error("Invalid disk state");
        if (
          Number(disk.available_kib) < 2 * 1024 * 1024 ||
          Number(disk.available_kib) < Number(disk.size_kib) / 10 ||
          (disk.free_inodes !== null && disk.total_inodes !== null &&
            Number(disk.free_inodes) < Number(disk.total_inodes) / 10)
        ) {
          issues.push(
            nodeIssue(
              ctx,
              "disk_low",
              "Node filesystem has low free space or free inodes.",
              "at least 10% and 2 GiB free space and 10% free inodes",
              `${disk.location}: ${disk.available_kib} KiB free of ${disk.size_kib} KiB; ${
                disk.total_inodes === null
                  ? "inodes unavailable"
                  : `${disk.free_inodes} of ${disk.total_inodes} inodes free`
              }`,
            ),
          );
        }
      } catch {
        issues.push(
          nodeIssue(
            ctx,
            "inspection_failed",
            "Node filesystem observation could not be verified.",
            null,
            null,
            true,
          ),
        );
      }
    }
  }
  if (facts.agent_eligible === true) {
    if (
      observation.agent_binary === true &&
      observation.agent_checksum !== ctx.node.agent_sha256
    ) {
      issues.push(
        nodeIssue(
          ctx,
          "agent_binary_mismatch",
          "Node agent binary does not match the pinned checksum.",
        ),
      );
    }
    if (
      observation.agent_binary === false || observation.agent_unit === false
    ) {
      issues.push(
        nodeIssue(
          ctx,
          "agent_missing",
          "Node agent binary or systemd unit is missing.",
        ),
      );
    }
    if (observation.agent_unit === true && observation.agent_active === false) {
      issues.push(
        nodeIssue(
          ctx,
          "agent_inactive",
          "Node agent systemd unit is not active.",
        ),
      );
    }
    if (
      observation.agent_binary === true &&
      (!checksum(observation.secret_checksum) ||
        observation.secret_checksum !== facts.secret_checksum)
    ) {
      issues.push(
        nodeIssue(
          ctx,
          "agent_secret_mismatch",
          "Node agent secret does not match the Gateway record.",
          "match",
          observation.secret_checksum === null ||
            observation.secret_checksum === undefined
            ? "missing"
            : "mismatch",
        ),
      );
    }
    if (facts.cli_foreign === true) {
      issues.push(
        nodeIssue(
          ctx,
          "cli_foreign",
          "The CLI was not installed by Orbit.",
          "orbit release",
          "foreign",
        ),
      );
    }
    if (facts.release_lag) {
      const lag = object(facts.release_lag);
      issues.push(
        nodeIssue(
          ctx,
          "release_lag",
          "The Node does not run the desired fleet state.",
          String(lag.expected),
          String(lag.observed),
        ),
      );
    }
    if (
      observation.agent_active === true &&
      ["subscriber_down", "disconnected", "missing", "stale"].includes(
        String(facts.view_problem),
      )
    ) {
      issues.push(
        nodeIssue(
          ctx,
          "agent_view_stale",
          "The Gateway has no fresh view of this Node agent.",
          "fresh",
          String(facts.view_problem),
        ),
      );
    }
  }
  if (facts.task_host) {
    issues.push(...await taskVmIssues(ctx, object(facts.task_host)));
  }
  return report("node", 1, issues, [ctx.node.id]);
}
