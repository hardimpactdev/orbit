import { constraintMatches } from "../operations/tools/constraints.ts";
import { normalizeVersion } from "../operations/tools/contract.ts";
import type { ToolInput } from "../operations/tools/contract.ts";
import {
  issue,
  nodeCheck,
  report,
  rows,
  unreachable,
} from "./doctor_contract.ts";
import type {
  Family,
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
async function execute(
  ctx: ProbeContext,
  name: string,
  input: Row,
  sudo?: boolean,
): Promise<Row> {
  const result = await ctx.run(name, input, sudo);
  if (typeof result.error_code === "string") throw result;
  return result;
}
const failure = (family: Family, row: Row | string) =>
  issue(
    family,
    `${family}.inspection_failed`,
    row,
    "The bounded inspection could not be verified.",
    "verifiable",
    "unverifiable",
    "unverifiable",
  );
const ids = (r: Row[]) => r.map((x) => x.id as number | string);
const checks = (ctx: ProbeContext, family: Family) => ({
  [nodeCheck(ctx.node)]: [`${family}.node_unreachable`],
});
const stopped = (runtime: unknown, state: unknown) =>
  runtime === "systemd"
    ? state === "inactive"
    : state === "created" || state === "exited";

async function processes(ctx: ProbeContext): Promise<FamilyReport> {
  const entries = rows(ctx, "processes"),
    issues: Issue[] = [],
    observed: (number | string)[] = [];
  if (ctx.inspectionFailed) return unreachable("process", ctx, entries.length);
  const asleep = new Map<string, boolean>();
  for (const row of entries) {
    try {
      const state = await execute(
        ctx,
        "doctor.inspect-process",
        object(row.input),
        true,
      );
      const samples = state.systemd_observations;
      if (
        typeof state.present !== "boolean" || !Array.isArray(samples) ||
        samples.length > 2 ||
        state.present !== (state.status !== null) ||
        (state.present &&
          !["active", "inactive", "running", "created", "exited", "other"]
            .includes(String(state.status))) ||
        (!state.present && samples.length !== 0) ||
        (state.present && row.runtime === "systemd" && !samples.length) ||
        (row.runtime === "docker" && samples.length)
      ) throw new Error("Invalid Process state");
      const observations = samples.map(object);
      if (
        observations.some((s) =>
          ![
            "active",
            "reloading",
            "inactive",
            "failed",
            "activating",
            "deactivating",
            "maintenance",
            "unknown",
          ].includes(String(s.active_state)) ||
          ![
            "dead",
            "condition",
            "start-pre",
            "start",
            "start-post",
            "running",
            "exited",
            "reload",
            "reload-signal",
            "reload-notify",
            "stop",
            "stop-watchdog",
            "stop-sigterm",
            "stop-sigkill",
            "stop-post",
            "final-watchdog",
            "final-sigterm",
            "final-sigkill",
            "failed",
            "dead-before-auto-restart",
            "failed-before-auto-restart",
            "dead-resources-pinned",
            "auto-restart",
            "auto-restart-queued",
            "cleaning",
            "mounting",
          ].includes(String(s.sub_state)) ||
          (s.restarts !== null &&
            (!Number.isInteger(s.restarts) || Number(s.restarts) < 0 ||
              Number(s.restarts) > 4294967295))
        )
      ) throw new Error("Invalid restart state");
      if (!state.present) {
        issues.push(
          issue(
            "process",
            "process.runtime_missing",
            row,
            "The managed Process runtime is missing.",
            "present",
            "absent",
          ),
        );
        continue;
      }
      const crash = row.runtime === "systemd" && row.desired === "running" &&
        (observations.some((s) =>
          s.active_state === "activating" && s.sub_state === "auto-restart"
        ) ||
          (observations.length === 2 && observations.every((s) =>
            s.restarts !== null
          ) &&
            Number(observations[1].restarts) >
              Number(observations[0].restarts)));
      if (crash) {
        issues.push(
          issue(
            "process",
            "process.crash_loop",
            row,
            "The Process is crash-looping while desired running.",
            "running",
            observations.map((s) =>
              `${s.active_state}/${s.sub_state}; NRestarts=${
                s.restarts ?? "unavailable"
              }`
            ).join(" -> "),
          ),
        );
        continue;
      }
      let healthy = row.desired === "stopped"
        ? stopped(row.runtime, state.status)
        : state.status === (row.runtime === "systemd" ? "active" : "running");
      if (
        !healthy && row.hibernation_path && row.desired === "running" &&
        !row.keep_alive && stopped(row.runtime, state.status)
      ) {
        const path = String(row.hibernation_path);
        if (
          !/^\/dev\/shm\/orbit\/hibernation\/app-instance-[1-9][0-9]*\.awake$/
            .test(path)
        ) throw new Error("Invalid marker");
        if (!asleep.has(path)) {
          const result = object(
            (await execute(ctx, "hibernation.times", { paths: [path] }, true))
              .times,
          );
          if (
            !(path in result) ||
            (result[path] !== null &&
              (!Number.isInteger(result[path]) || Number(result[path]) <= 0))
          ) throw new Error("Invalid marker state");
          asleep.set(path, result[path] === null);
        }
        healthy = asleep.get(path) === true;
      }
      if (!healthy) {
        issues.push(
          issue(
            "process",
            "process.state_mismatch",
            row,
            "The Process state differs from intent.",
            String(row.desired),
            String(state.status),
          ),
        );
      } else observed.push(row.id as number);
    } catch {
      issues.push(failure("process", row));
    }
  }
  return report(
    "process",
    entries.length,
    issues,
    observed,
    checks(ctx, "process"),
  );
}

const scheduleFields = [
  "permissions_match",
  "specification_match",
  "timer_state_match",
  "calendar_match",
  "execution_context_match",
  "completion_callback_match",
];
function safePath(v: unknown): v is string {
  return typeof v === "string" && v.startsWith("/") && !/[\0\r\n]/.test(v) &&
    v.slice(1).split("/").every((x) => !["", ".", ".."].includes(x));
}
async function schedules(ctx: ProbeContext): Promise<FamilyReport> {
  const entries = rows(ctx, "schedules"), issues: Issue[] = [];
  if (ctx.inspectionFailed) return unreachable("schedule", ctx, entries.length);
  const accounts = new Map<string, Row>();
  for (const row of entries) {
    try {
      if (row.placement_node_id !== ctx.node.id) {
        issues.push(
          issue(
            "schedule",
            "schedule.placement_mismatch",
            row,
            "The Schedule target differs from its host.",
          ),
        );
        continue;
      }
      if (row.intent_invalid) throw new Error("Unavailable target");
      const user = String(row.user);
      if (!/^[a-z_][a-z0-9_-]{0,31}$/.test(user)) {
        throw new Error("Invalid account");
      }
      if (!accounts.has(user)) {
        accounts.set(
          user,
          await execute(ctx, "account.lookup", { user }, false),
        );
      }
      const account = accounts.get(user)!;
      if (
        account.user !== user ||
        !/^[a-z_][a-z0-9_-]{0,31}$/.test(String(account.group)) ||
        !safePath(account.home) || !safePath(account.shell) ||
        (row.production_home && row.production_home !== account.home)
      ) throw new Error("Invalid account context");
      const input = {
        ...object(row.input),
        user,
        group: account.group,
        home: account.home,
        shell: row.production_home ? "/bin/bash" : account.shell,
        working_directory: row.working_directory ?? account.home,
        login_shell: !row.production_home,
      };
      if (!safePath(input.working_directory)) {
        throw new Error("Invalid working directory");
      }
      const result = await execute(ctx, "doctor.inspect-schedule", input, true);
      if (
        ["artifacts_present", ...scheduleFields].some((key) =>
          typeof result[key] !== "boolean"
        )
      ) throw new Error("Invalid Schedule state");
      if (!result.artifacts_present) {
        issues.push(
          issue(
            "schedule",
            "schedule.artifact_missing",
            row,
            "The Schedule artifacts are missing.",
          ),
        );
      } else {for (const field of scheduleFields) {
          if (!result[field]) {
            issues.push(
              issue(
                "schedule",
                `schedule.${
                  field === "permissions_match"
                    ? "artifact_permissions_mismatch"
                    : field.replace(/_match$/, "_mismatch")
                }`,
                row,
                "The Schedule differs from its managed intent.",
              ),
            );
          }
        }}
    } catch {
      issues.push(failure("schedule", row));
    }
  }
  const observedChecks = checks(ctx, "schedule"),
    inventories: Record<string, (number | string)[]> = {};
  const inventory = rows(ctx, "schedule_inventory")[0];
  if (inventory?.scan === true) {
    try {
      const result = await execute(ctx, "doctor.schedule-orphans", {
        known_ids: inventory.known_ids,
      }, true);
      if (
        !Array.isArray(result.ids) ||
        result.ids.some((id) =>
          typeof id !== "string" ||
          !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
            .test(id)
        )
      ) throw new Error("Invalid orphan IDs");
      inventories["schedule.orphan_artifact"] = [
        ...new Set(result.ids as string[]),
      ].sort();
      for (const id of inventories["schedule.orphan_artifact"]) {
        issues.push(
          issue(
            "schedule",
            "schedule.orphan_artifact",
            id,
            "A Schedule artifact has no matching intent.",
            "absent",
            "present",
          ),
        );
      }
      observedChecks[nodeCheck(ctx.node, "inventory")] = [
        "schedule.inspection_failed",
      ];
    } catch {
      issues.push(failure("schedule", nodeCheck(ctx.node, "inventory")));
    }
  }
  return report(
    "schedule",
    entries.length,
    issues,
    ids(entries),
    observedChecks,
    inventories,
  );
}

async function firewall(ctx: ProbeContext): Promise<FamilyReport> {
  const entries = rows(ctx, "firewall_rules"),
    expectations = rows(ctx, "firewall_expectations"),
    issues: Issue[] = [];
  if (ctx.inspectionFailed) {
    return report(
      "firewall",
      entries.length,
      entries.length || expectations.length
        ? [
          issue(
            "firewall",
            "firewall.node_unreachable",
            nodeCheck(ctx.node),
            "The Node cannot be inspected.",
            "reachable",
            "unreachable",
            "unverifiable",
          ),
        ]
        : [],
    );
  }
  const targets: Row[] = [];
  for (const row of entries) {
    if (row.status !== "active") {
      issues.push(
        issue(
          "firewall",
          "firewall.lifecycle_not_active",
          row,
          "The Firewall rule lifecycle is not active.",
          "active",
          String(row.status),
        ),
      );
    } else targets.push(row);
  }
  targets.push(...expectations);
  let complete = !targets.length;
  if (targets.length) {
    let result: Row | null = null;
    try {
      result = await execute(ctx, "firewall.inspect", {
        expected: targets.map((r) => r.shape),
        exclusive_families: [],
        stored: false,
      }, true);
      if (
        !["active", "inactive", "absent"].includes(String(result.backend)) ||
        (result.backend === "active" &&
          (!Array.isArray(result.ownerships) ||
            result.ownerships.length !== targets.length ||
            result.ownerships.some((s) =>
              !["exact", "missing", "drift"].includes(String(s))
            )))
      ) throw new Error("Invalid firewall state");
      complete = true;
    } catch {
      result = null;
    }
    targets.forEach((row, index) => {
      if (!result) issues.push(failure("firewall", row));
      else if (result.backend !== "active") {
        issues.push(
          issue(
            "firewall",
            "firewall.backend_inactive",
            row,
            "The Firewall backend is not active.",
            "active",
            String(result.backend),
          ),
        );
      } else {
        const state = (result.ownerships as unknown[])[index];
        if (state !== "exact") {
          issues.push(issue(
            "firewall",
            state === "missing"
              ? "firewall.rule_missing"
              : "firewall.rule_mismatch",
            row,
            "The Firewall rule differs from intent.",
            "exact",
            String(state),
          ));
        }
      }
    });
  }
  const observedChecks = checks(ctx, "firewall");
  if (complete) {
    observedChecks[nodeCheck(ctx.node, "inventory")] = [
      "firewall.inspection_failed",
    ];
  }
  return report(
    "firewall",
    entries.length,
    issues,
    ids([...entries, ...expectations]),
    observedChecks,
  );
}

const inventoryCommands: Record<string, string> = {
  apt: "tool.apt-inventory",
  composer: "tool.composer-inventory",
  vp: "tool.vp-inventory",
  brew: "tool.homebrew-formula-inventory",
  "brew-cask": "tool.homebrew-cask-inventory",
};
function supported(ctx: ProbeContext, manager: string): boolean {
  return ["apt", "composer"].includes(manager)
    ? ctx.node.platform === "linux"
    : manager === "brew-cask"
    ? ctx.node.platform === "macos"
    : ["linux", "macos"].includes(ctx.node.platform);
}
function packages(result: Row, manager: string): Row[] {
  if (
    !Array.isArray(result.packages) ||
    (manager === "vp" && typeof result.scan_complete !== "boolean")
  ) throw new Error("Invalid inventory");
  return result.packages.map((raw) => {
    const p = object(raw);
    if (
      typeof p.name !== "string" ||
      (manager.startsWith("brew")
        ? !Array.isArray(p.versions) || p.versions.some((v) =>
          typeof v !== "string"
        ) || typeof p.unverifiable !== "boolean"
        : !("version" in p) ||
          (p.version !== null && typeof p.version !== "string"))
    ) throw new Error("Invalid package");
    return p;
  });
}
function scopeFailure(error: unknown): string {
  const code = object(error).errorCode ?? object(error).error_code ??
    object(error).code;
  return typeof code === "string" && code.endsWith("_scope_absent")
    ? "absent"
    : typeof code === "string" && code.endsWith("_scope_conflict")
    ? "conflicting"
    : "incomplete";
}
async function tools(ctx: ProbeContext): Promise<FamilyReport> {
  const entries = rows(ctx, "tools"),
    environments = rows(ctx, "tool_environments"),
    issues: Issue[] = [],
    cache = new Map<string, Promise<Row>>();
  if (ctx.inspectionFailed) {
    return unreachable("tool", ctx, entries.length + environments.length);
  }
  const inventory = (manager: string): Promise<Row> => {
    if (!cache.has(manager)) {
      cache.set(
        manager,
        (async () => {
          if (
            !supported(ctx, manager) || !inventoryCommands[manager]
          ) throw new Error("Unsupported manager");
          const result = await execute(
            ctx,
            inventoryCommands[manager],
            {},
            false,
          );
          packages(result, manager);
          return result;
        })(),
      );
    }
    return cache.get(manager)!;
  };
  for (const row of entries) {
    try {
      const manager = String(row.manager);
      if (row.manager_node_id !== ctx.node.id || row.package_valid !== true) {
        throw new Error("Invalid manager");
      }
      const data = await inventory(manager),
        matches = packages(data, manager).filter((p) => p.name === row.package);
      const versions = matches.flatMap((p) =>
        manager.startsWith("brew")
          ? p.versions as string[]
          : p.version === null
          ? []
          : [String(p.version)]
      );
      if (
        matches.some((p) =>
          p.unverifiable === true ||
          (!manager.startsWith("brew") && p.version === null)
        ) || versions.length > 1 ||
        (!versions.length && manager === "vp" && !data.scan_complete)
      ) throw new Error("Ambiguous inventory");
      if (!versions.length) {
        issues.push(
          issue(
            "tool",
            "tool.not_installed",
            row,
            "The managed Tool is not installed.",
            true,
            false,
          ),
        );
      } else if (row.version_constraint !== null) {
        if (typeof row.version_constraint !== "string") {
          throw new Error("Invalid constraint");
        }
        const normalized = normalizeVersion(
          versions[0],
          manager as ToolInput["manager"],
        );
        if (!normalized) throw new Error("Unverifiable version");
        if (!constraintMatches(row.version_constraint)(normalized)) {
          issues.push(
            issue(
              "tool",
              "tool.version_mismatch",
              row,
              "The installed Tool version does not satisfy intent.",
              "satisfied",
              "rejected",
            ),
          );
        }
      }
    } catch {
      issues.push(failure("tool", row));
    }
  }
  const observedChecks = checks(ctx, "tool");
  if (environments.length) {
    try {
      const active = environments.filter((r) => r.active === true);
      const failures = environments.filter((r) => r.active !== true);
      if (active.length) {
        const version = await execute(ctx, "tool.vp-version", {}, false),
          scope = await execute(ctx, "tool.inspect-vp-scope", {}, false);
        const data = await execute(ctx, "tool.inspect-vp-store", {
          environments: active.map((r) => r.input),
        }, false);
        if (
          !Array.isArray(data.installed) ||
          data.installed.length !== active.length || data.installed.some((v) =>
            typeof v !== "boolean"
          ) || typeof scope.binary !== "string" ||
          typeof version.version !== "string"
        ) throw new Error("Invalid environment observation");
        const installed = data.installed;
        const normalized = normalizeVersion(
          version.version.replace(/^vp\s+/, "").trim(),
          "vp",
        );
        active.forEach((row, index) => {
          if (
            !normalized || !constraintMatches(`>=${row.vp_version}`)(normalized)
          ) failures.push(row);
          else if (
            !installed[index] ||
            scope.binary!.toString().split("/").slice(0, -2).join("/") !==
              object(row.input).store
          ) {
            issues.push(
              issue(
                "tool",
                "tool.vp_store_missing",
                nodeCheck(ctx.node, "environments"),
                "Recorded active vp store content is missing.",
                "ready",
                "not_ready",
              ),
            );
          }
        });
      }
      for (const _row of failures) {
        issues.push(
          issue(
            "tool",
            "tool.vp_store_inspection_failed",
            nodeCheck(ctx.node, "environments"),
            "The vp prerequisite or inspection did not complete.",
            "verifiable",
            "unverifiable",
            "unverifiable",
          ),
        );
      }
    } catch {
      issues.push(
        issue(
          "tool",
          "tool.vp_store_inspection_failed",
          nodeCheck(ctx.node, "environments"),
          "The vp prerequisite or inspection did not complete.",
          "verifiable",
          "unverifiable",
          "unverifiable",
        ),
      );
    }
    if (!issues.some((i) => i.code.startsWith("tool.vp_store"))) {
      observedChecks[nodeCheck(ctx.node, "environments")] = [
        "tool.inspection_failed",
        "tool.vp_store_missing",
        "tool.vp_store_inspection_failed",
      ];
    }
  }
  if (rows(ctx, "tool_inventory")[0]?.scan === true) {
    let complete = false;
    let allComplete = true;
    const scanIssues: Issue[] = [], packageIssues: Issue[] = [];
    let brew: Row | null = null, brewState = "complete";
    if (supported(ctx, "brew")) {
      try {
        brew = await execute(
          ctx,
          "tool.homebrew-scan",
          ctx.node.platform === "macos"
            ? { architecture: ctx.node.architecture }
            : {},
          false,
        );
      } catch (error) {
        try {
          brewState = scopeFailure(error);
        } catch {
          brewState = "incomplete";
        }
      }
    }
    for (const manager of ["brew", "brew-cask", "vp"]) {
      if (!supported(ctx, manager)) continue;
      let state = "complete", discovered: Row[] = [];
      try {
        if (manager === "vp") {
          const data = await inventory(manager);
          state = data.scan_complete ? "complete" : "incomplete";
          discovered = packages(data, manager).map((p) => {
            if (
              typeof p.name !== "string" ||
              !/^(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*\/[a-z0-9][a-z0-9._~-]*)$/
                .test(p.name) ||
              p.name.length > 214
            ) throw new Error("Invalid package name");
            const normalized = typeof p.version === "string"
              ? normalizeVersion(p.version, "vp")
              : null;
            return {
              name: p.name,
              installed_version: typeof p.version === "string"
                ? normalizeVersion(p.version, "vp")
                : null,
              dependency: false,
              block: p.name === "pnpm"
                ? "protected"
                : normalized === null
                ? "version_unreadable"
                : null,
            };
          });
        } else if (!brew) state = brewState;
        else {
          const scan = object(brew[manager === "brew" ? "formulae" : "casks"]);
          if (scan.state !== "complete" || !Array.isArray(scan.packages)) {
            throw new Error("Incomplete scan");
          }
          discovered = scan.packages.map(object);
          const seen = new Set();
          for (const p of discovered) {
            if (
              typeof p.name !== "string" || seen.has(p.name) ||
              !/^[a-zA-Z0-9@][a-zA-Z0-9@._+~/-]{0,254}$/.test(p.name) ||
              typeof p.dependency !== "boolean" ||
              !("installed_version" in p) ||
              (p.installed_version !== null &&
                typeof p.installed_version !== "string") ||
              !("block" in p) ||
              (p.block !== null && typeof p.block !== "string")
            ) throw new Error("Invalid discovery");
            seen.add(p.name);
          }
        }
      } catch (error) {
        try {
          state = scopeFailure(error);
        } catch {
          state = "incomplete";
        }
      }
      if (state !== "complete") {
        allComplete &&= state === "absent";
        complete ||= state === "absent";
        const informational = state === "absent" ||
          (state === "conflicting" &&
            !entries.some((r) => r.manager === manager));
        const finding = issue(
          "tool",
          "tool.inventory_scan",
          nodeCheck(ctx.node, "inventory"),
          "Package inventory scan is not complete.",
          "complete",
          state,
          informational ? "informational" : "unverifiable",
        );
        finding.resource_name = manager;
        scanIssues.push(finding);
        continue;
      }
      complete = true;
      for (
        const p of discovered.sort((a, b) =>
          String(a.name).localeCompare(String(b.name))
        )
      ) {
        if (
          entries.some((r) => r.manager === manager && r.package === p.name)
        ) continue;
        const normalized = typeof p.installed_version === "string"
          ? normalizeVersion(
            p.installed_version,
            manager as ToolInput["manager"],
          )
          : null;
        const version =
          normalized !== null && /^[A-Za-z0-9._+:-]{1,64}$/.test(normalized)
            ? normalized
            : "unknown";
        const block =
          typeof p.block === "string" && /^[a-z0-9_.-]{1,64}$/.test(p.block)
            ? p.block
            : "none";
        const finding = issue(
          "tool",
          "tool.package_unregistered",
          null,
          "Installed package has no Tool row.",
          manager,
          `version=${version};dependency=${
            p.dependency ? "yes" : "no"
          };adoption=${
            block === "none" ? "supported" : "unsupported"
          };block=${block}`,
          "informational",
        );
        finding.resource_name = String(p.name);
        packageIssues.push(finding);
      }
    }
    issues.push(...scanIssues, ...packageIssues);
    if (complete && allComplete) {
      observedChecks[nodeCheck(ctx.node, "inventory")] = [
        "tool.inventory_scan",
        "tool.inspection_failed",
      ];
    }
  }
  return report(
    "tool",
    entries.length + environments.length,
    issues,
    ids(entries),
    observedChecks,
  );
}

export async function checkRuntime(
  ctx: ProbeContext,
  families: Family[],
): Promise<FamilyReport[]> {
  const reports: FamilyReport[] = [];
  for (const family of families) {
    if (family === "process") reports.push(await processes(ctx));
    else if (family === "schedule") reports.push(await schedules(ctx));
    else if (family === "firewall") reports.push(await firewall(ctx));
    else if (family === "tool") reports.push(await tools(ctx));
  }
  return reports;
}
