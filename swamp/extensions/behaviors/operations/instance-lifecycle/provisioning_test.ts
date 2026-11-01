import { classifyProvisioningProfile, ProvisioningRefusal } from "./source_profile.ts";
import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { runLocalSource } from "./source_system.ts";
import { provisioningRoot } from "./provisioning_root.ts";
import {
  CloneCandidate,
  inspectProvisioningCandidate,
  prepareProvisioningSource,
  Provisioning,
  resolveProvisioningSource,
} from "./provisioning_source.ts";
import { provisioningVpRequirements } from "./provisioning_vp.ts";

const token = "private-git-token-47391",
  program = "printf private-setup-program-75231";

Deno.test("clone candidate placement has no agent pin dependency", () => {
  const candidate = {
    node_id: 9,
    environment: "development",
    base: "/srv/apps/proof/main",
    user: "orbit",
    repository: "https://github.com/example/proof.git",
    configured_branch: "main",
    target_branch: "main",
    expected_source: "/srv/apps/proof/main",
  };
  assertEquals(CloneCandidate.parse(candidate), candidate);
});
const args = {
  operation_id: 7,
  kind: "instance.create" as const,
  node_id: 9,
  run_id: "0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6",
};
function plan() {
  return Provisioning.parse({
    instance_id: 11,
    project_id: 1,
    node_id: 9,
    node_user: "orbit",
    platform: "linux",
    architecture: "x86_64",
    vp_materialize: { user: "orbit" },
    environment: "development",
    user: "orbit",
    checkout: "/srv/apps/proof/test",
    application: "/srv/apps/proof/test",
    agent_sha256: "a".repeat(64),
    project_type: "node-package",
    entry_point: "artisan",
    skip_profile: false,
    php_versions: ["8.5", "8.4"],
    source: {
      repository: "https://github.com/example/proof.git",
      checkout: "/srv/apps/proof/test",
      root: "/srv/apps",
      user: "orbit",
      group: "orbit",
      prepare_id: args.run_id,
      seed_repository: null,
    },
    resolve: {
      instance_name: "test",
      default_branch: "main",
      branch_override: null,
      seed_commit: null,
      network: true,
      protocols: ["https"],
    },
    setup: {
      instance_id: 11,
      project_id: 1,
      environment: "development",
      list: "setup",
      execution: {
        variant: "checkout",
        user: "orbit",
        directory: "/srv/apps/proof/test",
        seed_path: "",
        seed_commit: "",
      },
      steps: [],
    },
    already_active: false,
    retained_source: null,
  });
}
async function digest(value: string) {
  return [
    ...new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)),
    ),
  ].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}
class Gateway {
  admitted = plan();
  status = "queued";
  profiled = false;
  cancel = false;
  requests: { method: string; path: string; body: unknown }[] = [];
  resources: unknown[] = [];
  effects: NodeCall[] = [];
  outputs: unknown[] = [];
  programs: string[] = [];
  environment = {
    instance_id: 11,
    node_id: 9,
    environment: "development",
    base: "/srv/apps/proof/test",
    user: "orbit",
    maximum: 1048576,
    required_capacity: 2097152,
    preserve_workload_keys: false,
    runtime: null,
  };
  http: Http = async (method, path, raw) => {
    await Promise.resolve();
    this.requests.push({ method, path, body: raw });
    if (path.includes("/private-material")) {
      return {
        status: 200,
        body: {
          data: {
            git_read: { github_token: token },
            setup_programs: {
              steps: [{
                name: "prepare",
                timeout_seconds: 30,
                command: program,
              }],
            },
            environment_material: {
              content: "KEY=private-environment-94331\n",
              keys: ["KEY"],
              owned_keys: [],
              testing: null,
            },
          },
        },
      };
    }
    if (path.endsWith("/secret-output")) {
      this.outputs.push(raw);
      return { status: 200, body: { data: {} } };
    }
    if (method === "POST") {
      const body = raw as Record<string, unknown>;
      this.status = String(body.status);
      if ((body.result as { phase?: string })?.phase === "source") {
        this.profiled = true;
      }
    }
    return {
      status: 200,
      body: {
        data: {
          id: 7,
          kind: args.kind,
          node_id: 9,
          status: this.status,
          cancel_requested: this.cancel,
          input: {
            direct_provisioning: this.admitted,
            ...(this.profiled ? { environment_plan: this.environment } : {}),
          },
        },
      },
    };
  };
  invoke = (call: NodeCall): Promise<Record<string, unknown>> => {
    this.effects.push(call);
    const result: Record<string, unknown> = call.name === "account.lookup"
      ? { user: "orbit", group: "developers" }
      : call.name === "instance.resolve-development-source"
      ? { branch: "test", commit: "b".repeat(40) }
      : call.name === "instance.inspect-development-profile"
      ? { kind: "none", composer: null, entry_point: null }
      : call.name === "instance.read-development-environment-template"
      ? { environment_template: "KEY=source-private-template\n" }
      : call.name === "instance.inspect-environment-sync"
      ? { preflight: { state: "ok" }, existing: { state: "missing" } }
      : call.name === "instance.write-environment"
      ? { state: "changed" }
      : call.name === "tool.read-vp-project"
      ? { package: {}, locks: {}, workspaces: {}, npmrc: "" }
      : { created: true };
    return Promise.resolve(result);
  };
  context = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    writeResource: (
      _spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      this.resources.push(data);
      return Promise.resolve({ name });
    },
  };
  root = () =>
    provisioningRoot(
      "instance.create",
      () => this.http,
      this.invoke,
      (_step, script) => {
        this.programs.push(script);
        return Promise.resolve({
          exit_code: 0,
          timed_out: false,
          output_tail: "",
          truncated: false,
        });
      },
      {
        local: (mode, input) => {
          if (mode !== "project") throw new Error("unexpected Vite effect");
          return this.invoke({
            name: "tool.read-vp-project",
            input,
            pin: "",
            sudo: false,
            mutates: false,
            deadlineMs: 120000,
          });
        },
      },
    );
}
Deno.test("create runs source, protected template/environment and setup in one parent without child admission", async () => {
  const g = new Gateway();
  g.admitted.setup!.steps = [{
    name: "prepare",
    timeout_seconds: 30,
    command_digest: await digest(program),
  }];
  const root = g.root();
  const source = { branch: "test", commit: "b".repeat(40) };
  for (
    const stage of [
      "prepare",
      "resolve",
      "profile",
      "template",
      "environment",
      "vp",
      "setup",
    ] as const
  ) {
    await root.methods.stage.execute({
      ...args,
      stage,
      ...(["profile", "template"].includes(stage) ? { source } : {}),
    }, g.context);
  }
  assertEquals(await root.methods.finish.execute(args, g.context), {
    ready: true,
  });
  assertEquals(g.effects.map((row) => row.name), [
    "account.lookup",
    "instance.prepare-development-source",
    "account.lookup",
    "instance.resolve-development-source",
    "instance.inspect-development-profile",
    "account.lookup",
    "instance.read-development-environment-template",
    "instance.inspect-environment-sync",
    "instance.write-environment",
    "tool.read-vp-project",
  ]);
  assertEquals(g.programs, [program]);
  assertEquals(g.status, "succeeded");
  assertEquals(g.resources, [source]);
  assert(
    g.effects.filter((row) =>
      row.name === "instance.prepare-development-source" ||
      row.name === "instance.resolve-development-source"
    ).every((row) =>
      (row.input.git_read as { github_token: string }).github_token === token &&
      row.pin === "a".repeat(64) && row.sudo === false
    ),
  );
  const publicJson = JSON.stringify({
    resources: g.resources,
    results: g.requests.filter((row) => row.path.endsWith("/result")),
  });
  for (
    const sentinel of [
      token,
      program,
      "source-private-template",
      "private-environment-94331",
    ]
  ) assert(!publicJson.includes(sentinel));
  assertEquals(
    g.requests.some((row) =>
      /operation-executor|\/children|\/run$|\/primitive/.test(row.path)
    ),
    false,
  );
});
Deno.test("wrong Node and cancellation stop a provisioning stage before a local effect", async () => {
  const g = new Gateway();
  g.admitted.node_id = 17;
  await assertRejects(
    () =>
      g.root().methods.stage.execute({ ...args, stage: "prepare" }, g.context),
    ProvisioningRefusal,
  );
  assertEquals(g.effects, []);
  g.admitted.node_id = 9;
  g.cancel = true;
  await assertRejects(
    () =>
      g.root().methods.stage.execute({ ...args, stage: "prepare" }, g.context),
    ProvisioningRefusal,
  );
  assertEquals(g.effects, []);
});
Deno.test("Git private callbacks refuse late use and replay and never expose agent error text", async () => {
  let saved: ((material: Record<string, unknown>) => Promise<void>) | undefined,
    calls = 0;
  const value = plan();
  await assertRejects(
    () =>
      prepareProvisioningSource(value, {
        withPrivateMaterial: async (use) => {
          saved = use;
          await Promise.resolve();
        },
      }, () => {
        calls++;
        return Promise.resolve({ user: "orbit", group: "developers" });
      }),
    ProvisioningRefusal,
  );
  await assertRejects(() => saved!({ git_read: { github_token: token } }));
  assertEquals(calls, 0);
  await assertRejects(
    () =>
      prepareProvisioningSource(value, {
        withPrivateMaterial: async (use) => {
          await use({ git_read: { github_token: token } });
          await use({ git_read: { github_token: token } });
        },
      }, () => {
        calls++;
        return Promise.resolve({ user: "orbit", group: "developers" });
      }),
    ProvisioningRefusal,
  );
  assertEquals(calls, 2);
  const error = await assertRejects(
    () =>
      prepareProvisioningSource(value, {
        withPrivateMaterial: async (use) =>
          await use({ git_read: { github_token: token } }),
      }, () => {
        throw new Error(token);
      }),
    ProvisioningRefusal,
  );
  assert(!String(error).includes(token));
});
Deno.test("retry retains admitted source and refuses a changed checkout instead of resetting it", async () => {
  const value = plan();
  value.retained_source = {
    branch: "test",
    commit: "b".repeat(40),
    php_version: null,
    laravel: false,
  };
  const calls: string[] = [];
  await assertRejects(
    () =>
      prepareProvisioningSource(value, {
        withPrivateMaterial: () => {
          throw new Error("must not fetch credentials");
        },
      }, (call) => {
        calls.push(call.name);
        return Promise.resolve(
          call.name === "account.lookup"
            ? { user: "orbit", group: "developers" }
            : { branch: "changed", commit: "c".repeat(40) },
        );
      }),
    ProvisioningRefusal,
  );
  assertEquals(calls, [
    "account.lookup",
    "instance.inspect-development-source",
  ]);
});
Deno.test("a completed active create does not inspect or reset an advanced checkout", async () => {
  const value = Provisioning.parse({
    ...plan(),
    already_active: true,
    retained_source: {
      branch: "main",
      commit: "a".repeat(40),
      php_version: null,
      laravel: false,
    },
  });
  let effects = 0;
  let privateReads = 0;
  await prepareProvisioningSource(value, {
    withPrivateMaterial: () => {
      privateReads++;
      return Promise.resolve();
    },
  }, () => {
    effects++;
    return Promise.resolve({ branch: "main", commit: "b".repeat(40) });
  });
  assertEquals(effects, 0);
  assertEquals(privateReads, 0);
});

Deno.test("source classification uses the shared Composer range evaluator and refuses inconsistent framework evidence", () => {
  const classify = (
    composer: unknown,
    entry_point = "absent",
    project_type = "laravel-package" as Provisioning["project_type"],
  ) =>
    classifyProvisioningProfile({
      kind: "composer",
      composer: btoa(JSON.stringify(composer)),
      entry_point,
    }, { php_versions: ["8.5", "8.4"], project_type });
  assertEquals(classify({ require: { php: "^8.4 !=8.5.0" } }), {
    php_version: "8.4",
    laravel: false,
  });
  assertEquals(
    classify(
      { require: { php: "~8.4.0", "laravel/framework": "^13" } },
      "regular",
      "laravel-app",
    ),
    { php_version: "8.4", laravel: true },
  );
  assertThrows(() => classify({ require: { php: "^9" } }), ProvisioningRefusal);
  assertThrows(
    () =>
      classify(
        { require: { "laravel/framework": "^13" } },
        "absent",
        "laravel-app",
      ),
    ProvisioningRefusal,
  );
  assertThrows(
    () =>
      classify(
        { require: { "symfony/framework-bundle": "^8" } },
        "unsafe",
        "symfony-app",
      ),
    ProvisioningRefusal,
  );
});
Deno.test("production provisioning binds Project app account and performs only pinned local sudo source actions", async () => {
  const dev = plan();
  const { setup: _, ...rest } = dev;
  const prod = Provisioning.parse({
    ...rest,
    environment: "production",
    user: "orbit-app-1",
    checkout: "/home/orbit-app-1/releases/initial",
    application: "/home/orbit-app-1/releases/initial",
    home: { user: "orbit-app-1", home: "/home/orbit-app-1" },
    source: {
      user: "orbit-app-1",
      home: "/home/orbit-app-1",
      repository: "https://github.com/example/proof.git",
      instance: 11,
    },
    resolve: {
      relative_root: ".",
      application_suffix: "",
      branch: "main",
      clone_target: true,
    },
  });
  const calls: NodeCall[] = [];
  await prepareProvisioningSource(prod, {
    withPrivateMaterial: async (use) =>
      await use({ git_read: { github_token: token } }),
  }, (call) => {
    calls.push(call);
    return Promise.resolve({});
  });
  assertEquals(calls.map((row) => row.name), [
    "instance.prepare-production-user",
    "instance.prepare-production-source",
  ]);
  assert(calls.every((row) => row.sudo));
  assertThrows(() => Provisioning.parse({ ...prod, user: "orbit" }));
});
Deno.test("Vite+ preparation resolves exact npm and pnpm locks and refuses a moving pin", () => {
  const config = {
    package: {
      devDependencies: { "vite-plus": "^0.3" },
      packageManager: "npm@11.12.0",
      engines: { node: "^24" },
    },
    ".node-version": "24.22.0",
    locks: {
      "package-lock.json": JSON.stringify({
        packages: {
          "node_modules/vite-plus": {
            version: "0.3.3",
            engines: { node: ">=24" },
          },
        },
      }),
    },
  };
  assertEquals(provisioningVpRequirements(config, "/opt/orbit/vite-plus"), {
    store: "/opt/orbit/vite-plus",
    vp_version: "0.3.3",
    node_version: "24.22.0",
    package_manager: "npm",
    package_manager_version: "11.12.0",
    package_manager_hash: null,
  });
  assertThrows(() =>
    provisioningVpRequirements(
      { ...config, ".node-version": "24" },
      "/opt/orbit/vite-plus",
    )
  );
  assertEquals(
    provisioningVpRequirements({ package: {} }, "/opt/orbit/vite-plus"),
    null,
  );
  assertEquals(
    provisioningVpRequirements({
      package: {
        dependencies: { "vite-plus": "^0.3" },
        packageManager: "pnpm@10.20.0",
      },
      ".node-version": "24.22.0",
      locks: {
        "pnpm-lock.yaml":
          "importers:\n  .:\n    dependencies:\n      vite-plus:\n        version: 0.3.3\n",
      },
    }, "/opt/orbit/vite-plus")?.package_manager,
    "pnpm",
  );
});

Deno.test("clone candidate is inspected locally with its own pin and protected Git material before target resolve", async () => {
  const value = plan();
  const prod = Provisioning.parse({
    ...value,
    environment: "production",
    user: "orbit-app-1",
    setup: undefined,
    home: { user: "orbit-app-1", home: "/home/orbit-app-1" },
    checkout: "/home/orbit-app-1/releases/initial",
    application: "/home/orbit-app-1/releases/initial",
    source: {
      user: "orbit-app-1",
      home: "/home/orbit-app-1",
      repository: value.source.repository,
      instance: 11,
    },
    resolve: {
      relative_root: ".",
      application_suffix: "",
      branch: "main",
      clone_target: true,
    },
    candidate: {
      node_id: 4,
      agent_sha256: "c".repeat(64),
      environment: "development",
      user: "candidate",
      base: "/srv/candidate/proof",
      expected_source: "/srv/candidate/proof",
      repository: value.source.repository,
      configured_branch: "main",
      target_branch: "main",
    },
  });
  const calls: NodeCall[] = [];
  const use = {
    withPrivateMaterial: async (
      consume: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      await consume({ git_read: { github_token: token } });
    },
  };
  const candidate = await inspectProvisioningCandidate(prod, use, (call) => {
    calls.push(call);
    return Promise.resolve({
      source: "/srv/candidate/proof",
      commit: "b".repeat(40),
    });
  });
  assertEquals(calls.map((call) => [call.name, call.pin, call.sudo]), [[
    "instance.inspect-clone-candidate",
    "c".repeat(64),
    false,
  ]]);
  assertEquals(candidate, { commit: "b".repeat(40) });
  const error = await assertRejects(
    () =>
      resolveProvisioningSource(
        prod,
        use,
        () => Promise.resolve({ branch: "main", commit: "a".repeat(40) }),
        undefined,
        candidate.commit,
      ),
    ProvisioningRefusal,
  );
  assertEquals(
    (error as ProvisioningRefusal).code,
    "instance.clone_candidate_changed",
  );
});

Deno.test("provisioning failure records the native phase and cooperative cancellation records cancellation", async () => {
  const failed = new Gateway();
  await failed.root().methods.fail.execute(
    { ...args, failed_step: "vp" },
    failed.context,
  );
  assertEquals(failed.status, "failed");
  const callback = failed.requests.find((request) =>
    request.path.endsWith("/result")
  )!.body as { result: unknown };
  assertEquals(callback.result, { failed_step: "vp" });
  const cancelled = new Gateway();
  cancelled.cancel = true;
  await cancelled.root().methods.fail.execute({
    ...args,
    failed_step: "prepare",
  }, cancelled.context);
  assertEquals(cancelled.status, "cancelled");
  assertEquals(cancelled.effects, []);
});

async function nativeSourceGit(directory: string, ...args: string[]) {
  const value = await new Deno.Command("/usr/bin/git", {
    args: ["-c", "core.hooksPath=/dev/null", "-C", directory, ...args],
    env: { GIT_CONFIG_NOSYSTEM: "1", GIT_CONFIG_GLOBAL: "/dev/null" },
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(value.success, new TextDecoder().decode(value.stderr));
  return new TextDecoder().decode(value.stdout).trimEnd();
}
async function nativeSourceIdentity(flag: string) {
  const value = await new Deno.Command("/usr/bin/id", {
    args: [flag],
    stdout: "piped",
  }).output();
  return new TextDecoder().decode(value.stdout).trim();
}
async function nativeSourceFixture() {
  const root = await Deno.makeTempDir({ prefix: "orbit-source-local-" });
  const repository = `${root}/origin`, checkout = `${root}/apps/proof/feature`;
  await Deno.mkdir(repository);
  await nativeSourceGit(repository, "init", "--quiet", "--initial-branch=main");
  await nativeSourceGit(repository, "config", "user.name", "Orbit fixture");
  await nativeSourceGit(
    repository,
    "config",
    "user.email",
    "fixture@example.invalid",
  );
  await Deno.writeTextFile(`${repository}/README.md`, "source\n");
  await Deno.writeTextFile(`${repository}/.env.example`, "APP_NAME=example\n");
  await nativeSourceGit(repository, "add", ".");
  await nativeSourceGit(repository, "commit", "--quiet", "-m", "initial");
  const user = await nativeSourceIdentity("-un"),
    group = await nativeSourceIdentity("-gn");
  assert(
    user !== "root",
    "Run source ownership tests as a non-root Node account",
  );
  const source = {
    repository,
    checkout,
    root: `${root}/apps`,
    user,
    group,
    prepare_id: "fixture-attempt",
    seed_repository: null,
  };
  const call = (
    name: string,
    input: Record<string, unknown>,
    options: Partial<NodeCall> = {},
  ) =>
    runLocalSource({
      name,
      input,
      pin: "a".repeat(64),
      sudo: false,
      mutates: true,
      deadlineMs: 30000,
      ...options,
    });
  const prepare = () =>
    call("instance.prepare-development-source", {
      ...source,
      allow_existing: true,
      network: true,
      seed_commit: null,
      git_read: { github_token: null },
    });
  const resolve = (extra: Record<string, unknown> = {}) =>
    call("instance.resolve-development-source", {
      ...source,
      instance_name: "feature",
      default_branch: "main",
      branch_override: null,
      seed_commit: null,
      network: true,
      protocols: ["file"],
      git_read: { github_token: null },
      ...extra,
    });
  return {
    root,
    repository,
    checkout,
    user,
    group,
    source,
    call,
    prepare,
    resolve,
    cleanup: () => Deno.remove(root, { recursive: true }),
  };
}

Deno.test("create default dispatcher completes real source and environment effects without agent actions", async () => {
  const f = await nativeSourceFixture();
  try {
    const args = {
      operation_id: 17,
      kind: "instance.create" as const,
      node_id: 9,
      run_id: "0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6",
    };
    const environment = "APP_NAME=private_native_create\n";
    let status = "queued";
    const publicResults: unknown[] = [], outputs: unknown[] = [];
    const resources: Record<string, unknown>[] = [];
    const plan = {
      instance_id: 11,
      project_id: 1,
      node_id: 9,
      node_user: f.user,
      platform: "linux",
      architecture: "x86_64",
      vp_materialize: { user: f.user },
      environment: "development",
      user: f.user,
      checkout: f.checkout,
      application: f.checkout,
      agent_sha256: "a".repeat(64),
      project_type: "node-package",
      entry_point: "artisan",
      skip_profile: false,
      php_versions: ["8.5"],
      source: f.source,
      resolve: {
        instance_name: "feature",
        default_branch: "main",
        branch_override: null,
        seed_commit: null,
        network: true,
        protocols: ["file"],
      },
      setup: {
        instance_id: 11,
        project_id: 1,
        environment: "development",
        list: "setup",
        execution: {
          variant: "checkout",
          user: f.user,
          directory: f.checkout,
          seed_path: "",
          seed_commit: "",
        },
        steps: [],
      },
      already_active: false,
      retained_source: null,
    };
    const http: Http = (method, path, body) => {
      if (path.includes("/private-material")) {
        return Promise.resolve({
          status: 200,
          body: {
            data: {
              git_read: { github_token: null },
              setup_programs: { steps: [] },
              environment_material: {
                content: environment,
                keys: ["APP_NAME"],
                owned_keys: [],
                testing: null,
              },
            },
          },
        });
      }
      if (path.endsWith("/secret-output")) {
        outputs.push(body);
        return Promise.resolve({ status: 200, body: { data: {} } });
      }
      if (method === "POST") {
        status = String((body as Record<string, unknown>).status);
        publicResults.push(body);
      }
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            ...args,
            id: args.operation_id,
            status,
            cancel_requested: false,
            input: {
              direct_provisioning: plan,
              environment_plan: {
                instance_id: 11,
                node_id: 9,
                environment: "development",
                base: f.checkout,
                user: f.user,
                maximum: 1048576,
                required_capacity: 2097152,
                preserve_workload_keys: false,
                runtime: null,
              },
            },
          },
        },
      });
    };
    const root = provisioningRoot("instance.create", () => http);
    const context = {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      writeResource: (
        _spec: string,
        name: string,
        data: Record<string, unknown>,
      ) => {
        resources.push(data);
        return Promise.resolve({ name });
      },
    };
    await root.methods.stage.execute({ ...args, stage: "prepare" }, context);
    await root.methods.stage.execute({ ...args, stage: "resolve" }, context);
    const source = {
      branch: String(resources[0].branch),
      commit: String(resources[0].commit),
    };
    for (
      const stage of [
        "profile",
        "template",
        "environment",
        "vp",
        "setup",
      ] as const
    ) {
      await root.methods.stage.execute({ ...args, stage, source }, context);
    }
    assertEquals(await root.methods.finish.execute(args, context), {
      ready: true,
    });
    assertEquals(status, "succeeded");
    assertEquals(await Deno.readTextFile(`${f.checkout}/.env`), environment);
    const info = await Deno.lstat(`${f.checkout}/.env`);
    assertEquals(info.uid, Deno.uid());
    assertEquals(info.mode! & 0o777, 0o600);
    assertEquals(
      await nativeSourceGit(f.checkout, "symbolic-ref", "--short", "HEAD"),
      "feature",
    );
    assertEquals(outputs.length, 1);
    assert(
      !JSON.stringify({ publicResults, resources }).includes(
        "private_native_create",
      ),
    );
    assert(
      !JSON.stringify({ publicResults, resources }).includes(
        "APP_NAME=example",
      ),
    );
  } finally {
    await f.cleanup();
  }
});
