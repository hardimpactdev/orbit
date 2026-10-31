import { assert, assertEquals } from "jsr:@std/assert@1";
import { checkApplications } from "./doctor_applications.ts";
import type { ProbeContext, Row } from "./doctor_contract.ts";

function context(
  resources: Record<string, Row[]>,
  run: ProbeContext["run"] = () =>
    Promise.reject(new Error("unexpected invocation")),
): ProbeContext {
  const node = {
    id: 7,
    name: "dev",
    platform: "linux",
    architecture: "amd64",
    user: "orbit",
    managed: true,
    parked: false,
    roles: [],
    resources,
    agent_sha256: "a".repeat(64),
  };
  return {
    node,
    nodes: [node],
    observation: { reachable: true },
    inspectionFailed: false,
    privateMaterial: {},
    run,
    local: () => Promise.reject(new Error("unexpected local read")),
  };
}
function instance(overrides: Row = {}): Row {
  return {
    id: 1,
    name: "feature",
    status: "active",
    settled_status: "active",
    provisioning: false,
    production: false,
    source_layout: "checkout",
    inspection: {
      repository: "https://github.com/acme/site",
      checkout: "/srv/apps/site/feature",
      root: "/srv/apps",
      user: "orbit",
      group: "orbit",
      layout: "checkout",
      starting_commit: "a".repeat(40),
    },
    ...overrides,
  };
}
function codes(reports: Awaited<ReturnType<typeof checkApplications>>) {
  return reports.flatMap((r) => r.issues.map((i) => i.code));
}
Deno.test("development source identity fields remain distinct and in order", async () => {
  const calls: string[] = [];
  const ctx = context(
    { instances: [instance({ status: "failed" })] },
    (name) => {
      calls.push(name);
      return Promise.resolve({
        checkout: false,
        layout: false,
        origin: false,
        history: false,
      });
    },
  );
  assertEquals(codes(await checkApplications(ctx, ["instance"])), [
    "instance.lifecycle_not_active",
    "instance.checkout_missing",
    "instance.repository_layout_mismatch",
    "instance.origin_mismatch",
    "instance.source_identity_mismatch",
  ]);
  assertEquals(calls, ["doctor.inspect-development-source"]);
});
Deno.test("provisioning and removal skip execution until their separate stuck bounds", async () => {
  const ctx = context({
    instances: [
      instance({
        id: 1,
        status: "reserved",
        provisioning: true,
        updated_at: new Date().toISOString(),
      }),
      instance({
        id: 2,
        status: "reserved",
        provisioning: true,
        updated_at: "2020-01-01T00:00:00Z",
      }),
      instance({
        id: 3,
        status: "removing",
        updated_at: new Date().toISOString(),
      }),
      instance({
        id: 4,
        status: "removing",
        updated_at: "2020-01-01T00:00:00Z",
      }),
    ],
  });
  assertEquals(codes(await checkApplications(ctx, ["instance"])), [
    "instance.provisioning_stuck",
    "instance.removal_stuck",
  ]);
});
Deno.test("task workspaces can settle at source_resolved and worktree layout remains admitted", async () => {
  const ctx = context(
    {
      instances: [
        instance({
          status: "source_resolved",
          settled_status: "source_resolved",
          source_layout: "worktree",
        }),
      ],
    },
    () =>
      Promise.resolve({
        checkout: true,
        layout: true,
        origin: true,
        history: true,
      }),
  );
  const reports = await checkApplications(ctx, ["instance"]);
  assertEquals(codes(reports), []);
  assertEquals(reports[0].observed_resources, [1]);
});
Deno.test("Project identity drift survives unreachable Nodes without host calls", async () => {
  const ctx = context({
    projects: [{ id: 2, name: "site", identity_matches: false }],
  });
  ctx.inspectionFailed = true;
  assertEquals(codes(await checkApplications(ctx, ["project"])), [
    "project.repository_identity_mismatch",
    "project.node_unreachable",
  ]);
});
Deno.test("Project partial observations preserve mismatch beside unreadable checkout and reject reordered IDs", async () => {
  const ctx = context(
    {
      projects: [{
        id: 2,
        name: "site",
        identity_matches: true,
        inspection: {
          repository: "https://github.com/acme/site",
          checkouts: [{ instance_id: 1 }, { instance_id: 2 }],
        },
      }],
    },
    () =>
      Promise.resolve({
        checkouts: [{ instance_id: 1, matches: false }, {
          instance_id: 2,
          matches: null,
        }],
      }),
  );
  assertEquals(codes(await checkApplications(ctx, ["project"])), [
    "project.repository_origin_mismatch",
    "project.inspection_failed",
  ]);
  ctx.run = () =>
    Promise.resolve({
      checkouts: [{ instance_id: 2, matches: true }, {
        instance_id: 1,
        matches: true,
      }],
    });
  assertEquals(codes(await checkApplications(ctx, ["project"])), [
    "project.inspection_failed",
  ]);
});
Deno.test("production uses safe environment hash and reports association and unavailable projections", async () => {
  let seen: Row = {};
  const ctx = context({
    instances: [instance({
      production: true,
      association_missing: true,
      inspection: {
        user: "orbit-app-2",
        home: "/home/orbit-app-2",
        application_suffix: "/public",
        environment_sha256: "b".repeat(64),
      },
    })],
  }, (name, input) => {
    assertEquals(name, "doctor.inspect-production-instance");
    seen = input;
    return Promise.resolve({
      home: true,
      selection: false,
      root: true,
      environment: false,
      php: false,
      caddy: null,
    });
  });
  ctx.local = (argv) => {
    assertEquals(argv.slice(0, 4), ["sudo", "-n", "/usr/bin/python3", "-c"]);
    assertEquals(argv.slice(-5), [
      "production",
      "/home/orbit-app-2",
      "b".repeat(64),
      "orbit-app-2",
      "/public",
    ]);
    return Promise.resolve("true");
  };
  assertEquals(codes(await checkApplications(ctx, ["instance"])), [
    "instance.php_fpm_association_missing",
    "instance.release_selection_mismatch",
    "instance.php_fpm_projection_mismatch",
    "instance.inspection_failed",
  ]);
  assertEquals(seen.environment, "");
  assert(!("environment_sha256" in seen));
});
Deno.test("application URL check covers every winning directory and returns bounded findings", async () => {
  const ctx = context({
    instances: [
      instance({
        checkout: "/srv/apps/site/feature",
        applications: [{
          directory: "apps/admin",
          url: "https://admin.example.test",
        }, { directory: "apps/site", url: "https://example.test" }],
      }),
    ],
  }, (name, input) => {
    if (name === "doctor.inspect-application-urls") {
      assertEquals(input.applications, [{
        directory: "apps/admin",
        url: "https://admin.example.test",
      }, { directory: "apps/site", url: "https://example.test" }]);
      return Promise.resolve({ matches: [false, true] });
    }
    return Promise.resolve({
      checkout: true,
      layout: true,
      origin: true,
      history: true,
    });
  });
  const reports = await checkApplications(ctx, ["instance"]);
  assertEquals(codes(reports), ["instance.laravel_url_mismatch"]);
  assert(reports[0].issues[0].summary.includes("[apps/admin]"));
  assert(!JSON.stringify(reports).includes("/srv/apps"));
});
Deno.test("unselected related Nodes are unverifiable and never execute a cross-Node read", async () => {
  const ctx = context({
    application_route_checks: [{
      id: 1,
      name: "feature",
      family: "instance",
      owner_node_id: 7,
      related_node_ids: [8],
      kind: "scope",
    }, {
      id: 1,
      name: "feature",
      family: "instance",
      owner_node_id: 7,
      related_node_ids: [8],
      kind: "workload",
      inspection: {},
    }],
  });
  assertEquals(codes(await checkApplications(ctx, ["instance"])), [
    "instance.related_node_unverifiable",
  ]);
});
Deno.test("private Route workload and Router checks use local primitives and preserve all fields", async () => {
  const ctx = context({
    application_route_checks: [{
      id: 1,
      name: "feature",
      family: "instance",
      owner_node_id: 7,
      related_node_ids: [],
      kind: "workload",
      inspection: { domain: "site.test" },
      routing_scope_matches: false,
      association_matches: false,
      expects_firewall: true,
      laravel: "skip",
    }],
  }, (name) => {
    assertEquals(name, "doctor.inspect-private-route");
    return Promise.resolve({
      caddy: false,
      tls: false,
      dns: false,
      firewall: false,
      laravel: false,
    });
  });
  assertEquals(codes(await checkApplications(ctx, ["instance"])), [
    "instance.private_routing_scope_mismatch",
    "instance.workload_caddy_mismatch",
    "instance.private_certificate_mismatch",
    "instance.private_dns_mismatch",
    "instance.private_firewall_mismatch",
    "instance.route_association_mismatch",
  ]);
});
Deno.test("Route lifecycle and reserved names are checked even without host access", async () => {
  const ctx = context({
    routes: [{
      id: 3,
      name: "reserved.test",
      status: "failed",
      reserved: true,
      custom_proxy: true,
      owner_node_id: 7,
      inspection: {},
    }],
  });
  ctx.inspectionFailed = true;
  assertEquals(codes(await checkApplications(ctx, ["route"])), [
    "route.lifecycle_not_active",
    "route.domain_reserved",
    "route.node_unreachable",
  ]);
});
Deno.test("public custom-proxy independently records edge drift and backend firewall ownership", async () => {
  const ctx = context(
    {
      application_route_checks: [{
        id: 3,
        name: "public.test",
        family: "route",
        kind: "public",
        owner_node_id: 7,
        related_node_ids: [],
        inspection: { domain: "public.test" },
        dns_address: "10.0.0.7",
        firewall: { expected: [] },
      }, {
        id: 3,
        name: "public.test",
        family: "route",
        kind: "backend_firewall",
        owner_node_id: 7,
        related_node_ids: [],
        inspection: { expected: [] },
      }],
    },
    (name) =>
      Promise.resolve(
        name === "doctor.inspect-public-route"
          ? { ingress: false, forwarding: true, tls: false }
          : { backend: "active", ownerships: [] },
      ),
  );
  ctx.local = () =>
    Promise.resolve(
      "10.0.0.7 STREAM public.test\n10.0.0.7 DGRAM public.test\n",
    );
  assertEquals(codes(await checkApplications(ctx, ["route"])), [
    "route.caddy_mismatch",
    "route.certificate_mismatch",
    "route.firewall_mismatch",
  ]);
});
Deno.test("Reverb collision is inspected only on its admitted workload Node", async () => {
  const ctx = context({
    application_route_checks: [{
      id: 3,
      name: "app.test",
      family: "route",
      kind: "websocket",
      owner_node_id: 7,
      related_node_ids: [],
      inspection: {
        user: "orbit-app-2",
        home: "/home/orbit-app-2",
        application: "/home/orbit-app-2/current",
        php_version: "8.5",
      },
    }],
  }, (name) => {
    assertEquals(name, "instance.inspect-laravel-routes");
    return Promise.resolve({ collision: true });
  });
  assertEquals(codes(await checkApplications(ctx, ["route"])), [
    "route.websocket_collision",
  ]);
});
Deno.test("hashed APP_URL reader preserves duplicate, quote and symlink semantics without returning values", async () => {
  const directory = await Deno.makeTempDir({ prefix: "doctor-url-" });
  try {
    const expected = "https://app.example.test";
    const digest = [
      ...new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(expected),
        ),
      ),
    ].map((v) => v.toString(16).padStart(2, "0")).join("");
    const path = `${directory}/.env`;
    const ctx = context(
      {
        application_route_checks: [{
          id: 1,
          name: "feature",
          family: "instance",
          owner_node_id: 7,
          related_node_ids: [],
          kind: "workload",
          inspection: {},
          routing_scope_matches: true,
          association_matches: true,
          expects_firewall: false,
          laravel: "check",
          environment: path,
          url_sha256: digest,
        }],
      },
      () =>
        Promise.resolve({ caddy: true, tls: true, dns: true, firewall: true }),
    );
    ctx.local = async (argv) => {
      const result = await new Deno.Command(argv[2], {
        args: argv.slice(3),
        stdout: "piped",
        stderr: "null",
      }).output();
      assertEquals(result.success, true);
      const text = new TextDecoder().decode(result.stdout);
      assert(!text.includes(expected));
      return text;
    };
    await Deno.writeTextFile(
      path,
      `APP_URL='${expected}'\nOTHER=private-data\n`,
    );
    assertEquals(codes(await checkApplications(ctx, ["instance"])), []);
    await Deno.writeTextFile(
      path,
      `APP_URL=${expected}\nAPP_URL=${expected}\n`,
    );
    assertEquals(codes(await checkApplications(ctx, ["instance"])), [
      "instance.laravel_url_mismatch",
    ]);
    await Deno.rename(path, `${directory}/real-env`);
    await Deno.symlink(`${directory}/real-env`, path);
    assertEquals(codes(await checkApplications(ctx, ["instance"])), [
      "instance.laravel_url_mismatch",
    ]);
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
});
Deno.test("production environment hash checks ownership, mode and selected-release link locally", async () => {
  const home = await Deno.makeTempDir({ prefix: "doctor-production-" });
  try {
    const bytes = new TextEncoder().encode(
      "APP_KEY=fixture-private-value\nAPP_ENV=production\n",
    );
    const digest = [
      ...new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    ].map((v) => v.toString(16).padStart(2, "0")).join("");
    const user = new TextDecoder().decode(
      (await new Deno.Command("/usr/bin/id", { args: ["-un"], stdout: "piped" })
        .output()).stdout,
    ).trim();
    await Deno.mkdir(`${home}/releases/one/application`, { recursive: true });
    await Deno.symlink("releases/one", `${home}/current`);
    await Deno.writeFile(`${home}/.env`, bytes, { mode: 0o600 });
    await Deno.symlink(`${home}/.env`, `${home}/releases/one/application/.env`);
    const ctx = context(
      {
        instances: [
          instance({
            production: true,
            inspection: {
              user,
              home,
              application_suffix: "/application",
              environment_sha256: digest,
            },
          }),
        ],
      },
      () =>
        Promise.resolve({
          home: true,
          selection: true,
          root: true,
          environment: false,
          php: true,
          caddy: true,
        }),
    );
    ctx.local = async (argv) => {
      const result = await new Deno.Command(argv[2], {
        args: argv.slice(3),
        stdout: "piped",
        stderr: "null",
      }).output();
      const text = new TextDecoder().decode(result.stdout);
      assertEquals(result.success, true);
      assert(!text.includes("fixture-private-value"));
      return text;
    };
    assertEquals(codes(await checkApplications(ctx, ["instance"])), []);
    await Deno.chmod(`${home}/.env`, 0o640);
    assertEquals(codes(await checkApplications(ctx, ["instance"])), [
      "instance.environment_projection_mismatch",
    ]);
    await Deno.chmod(`${home}/.env`, 0o600);
    await Deno.remove(`${home}/releases/one/application/.env`);
    await Deno.writeTextFile(
      `${home}/releases/one/application/.env`,
      "a separate environment",
    );
    assertEquals(codes(await checkApplications(ctx, ["instance"])), [
      "instance.environment_projection_mismatch",
    ]);
  } finally {
    await Deno.remove(home, { recursive: true });
  }
});
