import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  commandFailure,
  credentialCommand,
  CredentialFailure,
  dependencyCredential,
} from "../extensions/behaviors/_lib/dependency_credentials.ts";
import { serviceDetection } from "../extensions/behaviors/_lib/service_detection.ts";
import { WEBSITE_PROJECT as project } from "./_support/dependency_projects.ts";
import { behavior, curlHttp } from "../extensions/behaviors/orbit_gateway.ts";
import { behavior as scanner } from "../extensions/behaviors/dependency_scanner.ts";
import {
  indexName,
  IndexSchema,
} from "../extensions/behaviors/_lib/dependency_results_schema.ts";
import { fixture, original } from "./_support/project_scheduler.ts";
const fixtureText = (name: string) =>
  Deno.readTextFile(
    new URL(`./fixtures/dependency-read-token-v1/${name}`, import.meta.url),
  );
const canonical = JSON.parse(await fixtureText("success.json")).data;
const credential = () => ({
  ...canonical,
  expires_at: new Date(Date.now() + 3600000).toISOString(),
});
Deno.test("token contract validates scope and expiry and safely classifies refusal", async () => {
  const good = credential();
  const http = (
    method: string,
    path: string,
    body?: Record<string, unknown>,
  ) => {
    assertEquals([method, path, body], [
      "POST",
      "/api/v1/projects/20/dependency-read-token",
      {},
    ]);
    return Promise.resolve({ status: 200, body: { data: good } });
  };
  assertEquals(await dependencyCredential(project, http), good);
  const rejected = JSON.parse(await fixtureText("rejected.json")) as {
    patch: Record<string, unknown> | null;
    status: number;
    error: string;
  }[];
  for (const { patch, status, error } of rejected) {
    const body = patch
      ? { data: { ...good, ...patch } }
      : { message: good.token };
    await assertRejects(
      () =>
        dependencyCredential(project, () => Promise.resolve({ status, body })),
      CredentialFailure,
      error,
    );
  }
});
Deno.test("signed GitHub App bearer tokens are accepted without exposing their value", async () => {
  const signed = {
    ...credential(),
    token: "ghs_header.payload-with-hyphens.signature",
  };
  const actual = await dependencyCredential(
    project,
    () => Promise.resolve({ status: 200, body: { data: signed } }),
  );
  assertEquals(actual, signed);
});
Deno.test("bearer tokens reject whitespace controls and non-ASCII characters", async () => {
  for (
    const token of [
      "ghs_value\n",
      "ghs_\rvalue",
      "ghs_ value",
      "ghs_\tvalue",
      "ghs_\0value",
      "ghs_é",
      "ghs_value?query",
      "ghs_value=middle",
    ]
  ) {
    await assertRejects(
      () =>
        dependencyCredential(project, () =>
          Promise.resolve({
            status: 200,
            body: { data: { ...credential(), token } },
          })),
      CredentialFailure,
      "token_invalid",
    );
  }
});
Deno.test("Git gets a host-bound askpass token without putting it in argv or the clone", async () => {
  const root = await Deno.makeTempDir(), old = Deno.env.get("PATH")!;
  try {
    await Deno.mkdir(`${root}/source`);
    await Deno.writeTextFile(
      `${root}/git`,
      await fixtureText("git-probe.py"),
    );
    await Deno.chmod(`${root}/git`, 0o755);
    Deno.env.set("PATH", `${root}:${old}`);
    const home = (await credentialCommand(`${root}/source`, "git", [
      "clone",
      "--",
      credential().clone_url,
      root,
    ], credential())).trim();
    await assertRejects(() => Deno.stat(home), Deno.errors.NotFound);
  } finally {
    Deno.env.set("PATH", old);
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("private stderr yields only fixed diagnostic codes", () => {
  for (
    const [stage, stderr, code] of [
      ["git", "authentication failed ephemeral_sentinel", "git_auth_failed"],
      ["gh", "HTTP 403 ephemeral_sentinel", "gh_auth_failed"],
      ["git", "could not resolve ephemeral_sentinel", "git_transport_failed"],
      ["gh", "random secret ephemeral_sentinel", "gh_lookup_failed"],
    ] as const
  ) {
    const e = commandFailure(stage, { code: 1, stdout: "", stderr });
    assertEquals(e.message, code);
    assert(!JSON.stringify(e).includes("ephemeral_sentinel"));
  }
});
Deno.test("refused token publishes failed sources, unknown PR and safe diagnostics", async () => {
  const f = fixture(), diagnostics: unknown[] = [];
  try {
    await serviceDetection(
      {
        ...original,
        worker: { ...original.worker, jsPackageManagers: { ".": "npm" } },
      },
      "refused",
      f.ctx,
      curlHttp(f.ctx.globalArgs),
      (d) => diagnostics.push(d),
    );
    const index = IndexSchema.parse(
      [...f.data].find(([name]) =>
        name.includes("dependencies-v1-project")
      )![1],
    );
    assertEquals(index.last_attempt.status, "failed");
    assert(
      index.sources.every((s) =>
        s.last_attempt.error_code === "source_acquisition_failed"
      ),
    );
    assertEquals(index.batch_pr.status, "unknown");
    assertEquals(index.batch_pr.error_code, "token_refused");
    assertEquals(diagnostics, [{
      stage: "token",
      code: "token_refused",
      exit_status: null,
    }]);
  } finally {
    await f.close();
  }
});
Deno.test("standalone refresh publishes current evidence and rejects a stale publication", async () => {
  const f = fixture(credential());
  try {
    const index = IndexSchema.parse(
      JSON.parse(await fixtureText("failed-scan-index.json")),
    );
    const key = `orbit-gateway/${await indexName(20, index.source_ref)}`;
    f.data.set(key, index);
    const invocations: unknown[] = [];
    await scanner.methods.refresh.execute({}, {
      ...f.ctx,
      runModel: async (args) => {
        invocations.push(args);
        if (args.method === "prepare_dependency_refresh") {
          await behavior.methods.prepare_dependency_refresh.execute(
            args.arguments as { runId: string },
            f.ctx,
          );
        }
        if (args.method === "publish_dependency_refresh") {
          await behavior.methods.publish_dependency_refresh.execute(
            args.arguments as Parameters<
              typeof behavior.methods.publish_dependency_refresh.execute
            >[0],
            f.ctx,
          );
        }
        return { ok: true };
      },
    });
    const current = IndexSchema.parse(f.data.get(key));
    assertEquals(current.batch_pr.status, "absent");
    assert(current.batch_pr.checked_at > index.batch_pr.checked_at);
    assert(
      !JSON.stringify([...f.data.values(), invocations]).includes(
        credential().token,
      ),
    );
    let writes = 0;
    await behavior.methods.publish_dependency_refresh.execute({
      projectId: 20,
      expectedPublicationId: crypto.randomUUID(),
      evidence: { pr: current.batch_pr, links: [] },
    }, {
      ...f.ctx,
      writeResource: (...args) => {
        writes++;
        return f.ctx.writeResource(args[0], args[1], args[2]);
      },
    });
    assertEquals(writes, 0);
  } finally {
    await f.close();
  }
});

Deno.test("scan diagnostics survive a thrown scan and identify the project and run", async () => {
  const f = fixture();
  try {
    await assertRejects(
      () =>
        scanner.methods.scan.execute({ projectId: 20, runId: "thrown-scan" }, {
          ...f.ctx,
          runModel: () => Promise.resolve({ ok: false }),
        }),
      Error,
      "dependency_scan_configuration_unavailable",
    );
    assertEquals(f.data.get("orbit-gateway/scan-diagnostics"), {
      project_id: 20,
      run_id: "thrown-scan",
      failures: [{ stage: "scan", code: "scan_failed", exit_status: null }],
    });
  } finally {
    await f.close();
  }
});
Deno.test("Project settings failures are distinct from credential failures", async () => {
  const f = fixture(), diagnostics: unknown[] = [];
  try {
    f.mismatch();
    await serviceDetection(
      {
        ...original,
        worker: { ...original.worker, jsPackageManagers: { ".": "npm" } },
      },
      "settings-mismatch",
      f.ctx,
      curlHttp(f.ctx.globalArgs),
      (d) => diagnostics.push(d),
    );
    assertEquals(diagnostics, [{
      stage: "settings",
      code: "project_settings_unavailable",
      exit_status: null,
    }]);
  } finally {
    await f.close();
  }
});
