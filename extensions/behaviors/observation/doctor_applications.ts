import {
  type Family,
  type FamilyReport,
  type Issue,
  issue,
  nodeCheck,
  type ProbeContext,
  report,
  type Row,
  rows,
} from "./doctor_contract.ts";

function object(value: unknown): Row | null {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? value as Row
    : null;
}
function objects(value: unknown): Row[] {
  return Array.isArray(value)
    ? value.map(object).filter((v): v is Row => v !== null)
    : [];
}
function id(row: Row): number | string {
  if (typeof row.id !== "number" && typeof row.id !== "string") {
    throw new Error("doctor.application_intent_invalid");
  }
  return row.id;
}
function failure(
  family: Family,
  row: Row,
  code = `${family}.inspection_failed`,
) {
  return issue(
    family,
    code,
    row,
    "The inspection could not be verified.",
    "verifiable",
    "unverifiable",
    "unverifiable",
  );
}
function drift(family: Family, row: Row, code: string) {
  return issue(
    family,
    code,
    row,
    "The projection does not match managed intent.",
  );
}
function inspectFields(
  family: Family,
  row: Row,
  observed: Row,
  fields: Record<string, string>,
): Issue[] {
  const issues: Issue[] = [];
  let failed = false;
  for (const [field, code] of Object.entries(fields)) {
    if (observed[field] === false) {
      issues.push(drift(family, row, `${family}.${code}`));
    } else if (observed[field] !== true) failed = true;
  }
  if (failed) issues.push(failure(family, row));
  return issues;
}
function reachable(ctx: ProbeContext) {
  return !ctx.inspectionFailed && ctx.observation.reachable !== false;
}
function scoped(ctx: ProbeContext, row: Row): boolean {
  return (Array.isArray(row.related_node_ids) ? row.related_node_ids : [])
    .every((node) => ctx.nodes.some((n) => n.id === node));
}

// Only booleans leave this fixed local reader. Expected .env contents never cross the public API.
const HASHED_ENVIRONMENT = `import hashlib,json,os,pwd,re,stat,sys
mode,path,expected,user,suffix=sys.argv[1:]
try:
 if mode=='production':
  home=path; path=home+'/.env'; account=pwd.getpwnam(user); meta=os.lstat(path)
  ok=stat.S_ISREG(meta.st_mode) and meta.st_uid==account.pw_uid and meta.st_gid==account.pw_gid and stat.S_IMODE(meta.st_mode)==0o600
  current=home+'/current'
  if os.path.lexists(current):
   env=os.path.realpath(current)+suffix+'/.env'
   ok=ok and os.path.islink(env) and os.path.realpath(env)==path
  content=open(path,'rb').read(1048577)
  ok=ok and len(content)<=1048576 and hashlib.sha256(content).hexdigest()==expected
 else:
  meta=os.lstat(path); ok=stat.S_ISREG(meta.st_mode)
  content=open(path,'rb').read(1048577)
  values=re.findall(rb'^APP_URL=([^\\r\\n]*)',content,re.M)
  value=values[0].strip() if len(values)==1 else b''
  if len(value)>=2 and value[:1]==value[-1:] and value[:1] in (b'"',b"'"): value=value[1:-1]
  ok=ok and len(content)<=1048576 and len(values)==1 and hashlib.sha256(value).hexdigest()==expected
 print(json.dumps(ok))
except FileNotFoundError: print('false')
except Exception: print('null')
`;
async function environmentMatches(
  ctx: ProbeContext,
  mode: string,
  path: unknown,
  digest: unknown,
  user = "",
  suffix = "",
): Promise<boolean | null> {
  if (
    typeof path !== "string" || !path.startsWith("/") ||
    typeof digest !== "string" || !/^[a-f0-9]{64}$/.test(digest)
  ) return null;
  try {
    const text = await ctx.local([
      "sudo",
      "-n",
      "/usr/bin/python3",
      "-c",
      HASHED_ENVIRONMENT,
      mode,
      path,
      digest,
      user,
      suffix,
    ]);
    const result: unknown = JSON.parse(text);
    return typeof result === "boolean" ? result : null;
  } catch {
    return null;
  }
}
async function projects(ctx: ProbeContext): Promise<FamilyReport> {
  const all = rows(ctx, "projects");
  const issues: Issue[] = [];
  const observed: (number | string)[] = [];
  const checks: Record<string, string[]> = {
    [nodeCheck(ctx.node)]: ["project.node_unreachable"],
  };
  for (const row of all) {
    checks[id(row)] = ["project.repository_identity_mismatch"];
    if (row.identity_matches === false) {
      issues.push(
        drift("project", row, "project.repository_identity_mismatch"),
      );
    }
  }
  if (!reachable(ctx)) {
    if (all.length) {
      issues.push(
        issue(
          "project",
          "project.node_unreachable",
          nodeCheck(ctx.node),
          "Project state cannot be inspected because the Node is unreachable.",
          "reachable",
          "unreachable",
          "unverifiable",
        ),
      );
    }
    return report("project", all.length, issues, observed, checks);
  }
  for (const row of all) {
    try {
      const input = object(row.inspection);
      if (!input) throw new Error();
      const intended = objects(input.checkouts);
      const result = await ctx.run("doctor.inspect-project", input, true);
      const actual = objects(result.checkouts);
      if (
        actual.length !== intended.length ||
        actual.some((v, i) =>
          v.instance_id !== intended[i].instance_id ||
          !(v.matches === null || typeof v.matches === "boolean")
        )
      ) throw new Error();
      observed.push(id(row));
      if (actual.some((v) => v.matches === false)) {
        issues.push(
          drift("project", row, "project.repository_origin_mismatch"),
        );
      }
      if (actual.some((v) => v.matches === null)) {
        issues.push(failure("project", row));
      }
    } catch {
      issues.push(failure("project", row));
    }
  }
  return report("project", all.length, issues, observed, checks);
}
const DEVELOPMENT_FIELDS = {
  checkout: "checkout_missing",
  layout: "repository_layout_mismatch",
  origin: "origin_mismatch",
  history: "source_identity_mismatch",
};
const PRODUCTION_FIELDS = {
  home: "production_home_mismatch",
  selection: "release_selection_mismatch",
  root: "selected_release_root_mismatch",
  environment: "environment_projection_mismatch",
  php: "php_fpm_projection_mismatch",
  caddy: "caddy_projection_mismatch",
};
async function instances(ctx: ProbeContext): Promise<FamilyReport> {
  const all = rows(ctx, "instances");
  const issues: Issue[] = [];
  const observed: (number | string)[] = [];
  const checks = { [nodeCheck(ctx.node)]: ["instance.node_unreachable"] };
  if (!reachable(ctx)) {
    if (all.length) {
      issues.push(
        issue(
          "instance",
          "instance.node_unreachable",
          nodeCheck(ctx.node),
          "Instance state cannot be inspected because the Node is unreachable.",
          "reachable",
          "unreachable",
          "unverifiable",
        ),
      );
    }
    return report("instance", all.length, issues, observed, checks);
  }
  for (const row of all) {
    const age = typeof row.updated_at === "string"
      ? Date.now() - Date.parse(row.updated_at)
      : NaN;
    if (row.status === "removing") {
      if (age >= 10 * 60_000) {
        issues.push(
          issue(
            "instance",
            "instance.removal_stuck",
            row,
            "Instance removal has not completed.",
            "removed",
            "removing",
          ),
        );
      }
      continue;
    }
    if (row.provisioning === true) {
      if (age > 20 * 60_000) {
        issues.push(
          issue(
            "instance",
            "instance.provisioning_stuck",
            row,
            "Instance provisioning has not completed.",
            String(row.settled_status),
            String(row.status),
          ),
        );
      }
      continue;
    }
    if (row.status !== row.settled_status) {
      issues.push(
        issue(
          "instance",
          "instance.lifecycle_not_active",
          row,
          "Instance lifecycle does not match managed intent.",
          String(row.settled_status),
          String(row.status),
        ),
      );
    }
    if (row.source_layout !== "checkout" && row.source_layout !== "worktree") {
      issues.push(
        issue(
          "instance",
          "instance.source_layout_mismatch",
          row,
          "Instance source ownership does not match managed intent.",
          "checkout or worktree",
          String(row.source_layout),
        ),
      );
      continue;
    }
    if (row.association_missing === true) {
      issues.push(
        drift("instance", row, "instance.php_fpm_association_missing"),
      );
    }
    if (row.association_shared === true && row.association_missing !== true) {
      issues.push(
        drift("instance", row, "instance.php_fpm_association_shared"),
      );
    }
    try {
      const input = object(row.inspection);
      if (!input) throw new Error();
      let result: Row;
      if (row.production === true) {
        const { environment_sha256, ...safe } = input;
        result = await ctx.run("doctor.inspect-production-instance", {
          ...safe,
          environment: "",
        }, true);
        result.environment = await environmentMatches(
          ctx,
          "production",
          input.home,
          environment_sha256,
          String(input.user),
          String(input.application_suffix),
        );
      } else {result = await ctx.run(
          "doctor.inspect-development-source",
          input,
          true,
        );}
      issues.push(
        ...inspectFields(
          "instance",
          row,
          result,
          row.production === true ? PRODUCTION_FIELDS : DEVELOPMENT_FIELDS,
        ),
      );
    } catch {
      issues.push(failure("instance", row));
    }
    const applications = objects(row.applications);
    if (applications.length) {
      try {
        const result = await ctx.run("doctor.inspect-application-urls", {
          checkout: row.checkout,
          applications,
        }, true);
        if (
          !Array.isArray(result.matches) ||
          result.matches.length !== applications.length ||
          result.matches.some((v) => typeof v !== "boolean")
        ) throw new Error();
        for (let i = 0; i < applications.length; i++) {
          if (result.matches[i] === false) {
            issues.push(
              issue(
                "instance",
                "instance.laravel_url_mismatch",
                row,
                `The Laravel URL in application directory [${
                  applications[i].directory || "."
                }] does not match the Route that serves it.`,
              ),
            );
          }
        }
      } catch {
        issues.push(failure("instance", row));
      }
    }
    observed.push(id(row));
  }
  return report("instance", all.length, issues, observed, checks);
}
async function routes(ctx: ProbeContext): Promise<FamilyReport> {
  const all = rows(ctx, "routes").filter((row) =>
    row.custom_proxy !== true || row.owner_node_id === ctx.node.id ||
    ctx.nodes.some((n) => n.id === row.owner_node_id)
  );
  const issues: Issue[] = [];
  const observed: (number | string)[] = [];
  const checks: Record<string, string[]> = {};
  for (const row of all) {
    checks[id(row)] = ["route.lifecycle_not_active", "route.domain_reserved"];
    if (row.status !== "active") {
      issues.push(
        issue(
          "route",
          "route.lifecycle_not_active",
          row,
          `Route [${row.name}] lifecycle is not active.`,
          "active",
          String(row.status),
        ),
      );
    }
    if (row.reserved === true) {
      issues.push(
        issue(
          "route",
          "route.domain_reserved",
          row,
          `Route [${row.name}] holds a reserved name.`,
          "unreserved",
          "reserved",
        ),
      );
    }
    const attachments = objects(row.websocket);
    if (!attachments.length) {
      checks[id(row)].push(
        "route.websocket_collision",
        "route.websocket_inspection_failed",
      );
    } else {
      try {
        let collision = false;
        for (const attachment of attachments) {
          const input = object(attachment.input);
          if (
            !reachable(ctx) || !input ||
            !ctx.nodes.some((n) => n.id === attachment.node_id)
          ) {
            throw new Error();
          }
          if (attachment.node_id !== ctx.node.id) continue;
          const result = await ctx.run(
            "instance.inspect-laravel-routes",
            input,
            true,
          );
          if (typeof result.collision !== "boolean") throw new Error();
          collision = result.collision || collision;
        }
        checks[id(row)].push(
          "route.websocket_collision",
          "route.websocket_inspection_failed",
        );
        if (collision) {
          issues.push(
            issue(
              "route",
              "route.websocket_collision",
              row,
              "Laravel routes overlap the reserved public Reverb prefixes /app or /apps.",
              "no collision",
              "collision",
            ),
          );
        }
      } catch {
        issues.push(failure("route", row, "route.websocket_inspection_failed"));
      }
    }
    if (row.custom_proxy !== true || !("inspection" in row)) continue;
    if (!reachable(ctx)) continue;
    try {
      const input = object(row.inspection);
      if (!input) throw new Error();
      const result = await ctx.run("doctor.inspect-custom-proxy", input, true);
      observed.push(id(row));
      issues.push(
        ...inspectFields("route", row, result, {
          dns: "dns_mismatch",
          tls: "certificate_mismatch",
          caddy: "caddy_mismatch",
          upstream: "upstream_unreachable",
        }),
      );
    } catch {
      issues.push(failure("route", row));
    }
  }
  if (reachable(ctx)) checks[nodeCheck(ctx.node)] = ["route.node_unreachable"];
  else if (
    all.some((row) => row.custom_proxy === true && "inspection" in row)
  ) {
    issues.push(
      issue(
        "route",
        "route.node_unreachable",
        nodeCheck(ctx.node),
        "Custom proxy Route state cannot be inspected because the Node is unreachable.",
        "reachable",
        "unreachable",
        "unverifiable",
      ),
    );
  }
  return report("route", all.length, issues, observed, checks);
}
async function localRouteChecks(
  ctx: ProbeContext,
  families: Family[],
  reports: FamilyReport[],
) {
  for (const row of rows(ctx, "application_route_checks")) {
    const family = row.family === "route" ? "route" : "instance";
    if (!families.includes(family)) continue;
    const target = reports.find((r) => r.family === family)!;
    if (!scoped(ctx, row)) {
      if (row.kind === "scope" && family === "instance") {
        target.issues.push(
          failure("instance", row, "instance.related_node_unverifiable"),
        );
      }
      continue;
    }
    if (
      row.owner_node_id !== ctx.node.id &&
      !ctx.nodes.some((n) => n.id === row.owner_node_id)
    ) continue;
    if (row.kind === "scope") continue;
    if (
      row.kind === "websocket" &&
      rows(ctx, "routes").some((r) =>
        r.id === row.id &&
        objects(r.websocket).some((a) => a.node_id === ctx.node.id)
      )
    ) continue;
    try {
      if (!reachable(ctx)) throw new Error();
      const input = object(row.inspection);
      if (!input) throw new Error();
      if (row.kind === "websocket") {
        const result = await ctx.run(
          "instance.inspect-laravel-routes",
          input,
          true,
        );
        if (typeof result.collision !== "boolean") throw new Error();
        if (result.collision) {
          target.issues.push(
            issue(
              "route",
              "route.websocket_collision",
              row,
              "Laravel routes overlap the reserved public Reverb prefixes /app or /apps.",
              "no collision",
              "collision",
            ),
          );
        }
        target.observed_checks[id(row)] = [
          ...(target.observed_checks[id(row)] ?? []),
          "route.websocket_collision",
          "route.websocket_inspection_failed",
        ];
      } else if (row.kind === "backend_firewall") {
        const result = await ctx.run("firewall.inspect", input, true);
        if (result.backend !== "active" || !Array.isArray(result.ownerships)) {
          if (result.backend === "inactive" || result.backend === "absent") {
            target.issues.push(drift("route", row, "route.firewall_mismatch"));
          } else throw new Error();
        } else if (!result.ownerships.includes("exact")) {
          target.issues.push(drift("route", row, "route.firewall_mismatch"));
        }
      } else if (row.kind === "public") {
        const result = await ctx.run(
          "doctor.inspect-public-route",
          input,
          true,
        );
        const firewall = object(row.firewall);
        if (firewall) {
          const fw = objects(firewall.expected).length
            ? await ctx.run("firewall.inspect", firewall, true)
            : { matches: true };
          result.firewall = fw.matches;
        }
        if (family === "route") {
          target.observed_resources.push(id(row));
          let dns: boolean | null = null;
          try {
            const text = await ctx.local([
              "/usr/bin/timeout",
              "--kill-after=1s",
              "3s",
              "/usr/bin/getent",
              "ahostsv4",
              String(row.name),
            ]);
            const addresses = [
              ...new Set(
                text.trim().split(/\n/).filter(Boolean).map((line) =>
                  line.trim().split(/\s+/)[0]
                ),
              ),
            ];
            dns = addresses.length === 1 && addresses[0] === row.dns_address;
          } catch { /* unavailable remains null */ }
          result.dns = dns;
          if (row.backend_missing === true) result.firewall = false;
          target.issues.push(
            ...inspectFields("route", row, result, {
              ingress: "caddy_mismatch",
              forwarding: "upstream_unreachable",
              tls: "certificate_mismatch",
              firewall: "firewall_mismatch",
              dns: "dns_mismatch",
            }),
          );
        } else {target.issues.push(
            ...inspectFields("instance", row, result, {
              ingress: "public_ingress_mismatch",
              forwarding: "private_forwarding_mismatch",
              tls: "public_tls_mismatch",
              firewall: "public_firewall_mismatch",
            }),
          );}
      } else {
        const result: Row = row.kind === "router" && row.missing_site === true
          ? {
            caddy: true,
            tls: true,
            firewall: true,
            pool: row.target_set_matches,
          }
          : await ctx.run("doctor.inspect-private-route", input, true);
        if (row.kind === "workload") {
          result.routing = row.routing_scope_matches;
          result.association = row.association_matches;
          result.laravel = row.laravel === "skip"
            ? true
            : row.laravel === "check"
            ? await environmentMatches(
              ctx,
              "url",
              row.environment,
              row.url_sha256,
            )
            : null;
          if (row.expects_firewall !== true) result.firewall = true;
          target.issues.push(...inspectFields("instance", row, result, {
            routing: "private_routing_scope_mismatch",
            caddy: "workload_caddy_mismatch",
            tls: "private_certificate_mismatch",
            dns: "private_dns_mismatch",
            firewall: "private_firewall_mismatch",
            laravel: "laravel_url_mismatch",
            association: "route_association_mismatch",
          }));
        } else {
          if (row.expects_firewall !== true) result.firewall = true;
          target.issues.push(
            ...inspectFields("instance", row, result, {
              caddy: "router_caddy_mismatch",
              tls: "private_certificate_mismatch",
              firewall: "private_firewall_mismatch",
              pool: "target_set_mismatch",
            }),
          );
        }
      }
    } catch {
      target.issues.push(
        failure(
          family,
          row,
          row.kind === "websocket"
            ? "route.websocket_inspection_failed"
            : `${family}.inspection_failed`,
        ),
      );
    }
  }
  for (const r of reports) {
    r.status = report(r.family, r.checked, r.issues).status;
  }
}
/** Every probe runs locally. Related selected Nodes contribute their own local projection findings. */
export async function checkApplications(
  ctx: ProbeContext,
  families: Family[],
): Promise<FamilyReport[]> {
  const reports: FamilyReport[] = [];
  if (families.includes("project")) reports.push(await projects(ctx));
  if (families.includes("instance")) reports.push(await instances(ctx));
  if (families.includes("route")) reports.push(await routes(ctx));
  await localRouteChecks(ctx, families, reports);
  return reports;
}
