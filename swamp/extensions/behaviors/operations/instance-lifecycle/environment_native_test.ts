import { assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { type Data, exists, git, instanceFixture, sha256 } from "../../../../tests/_support/instance_fixture.ts";
const NAME = "operation-instance-environment";
async function fixture() {
  const f = await instanceFixture();
  await f.workflow(NAME);
  const secret = `private-environment-${crypto.randomUUID()}`;
  f.repo.secrets = [secret];
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const inputs: Data = {
    operation_id: 31, node_id: 9, kind: "instance.sync_environment",
    execution_secrets: {
      environment: { vault: "orbit-control-inputs", key: "instance-environment-31" },
    },
    intent: {
      environment: {
        instance_id: 42, environment: "development", base: f.node, user: f.user, keys: [], owned_keys: ["OLD"],
        sha256: "", testing: null,
      },
      runtime: null, ssh_execution: f.connection,
    },
  };
  const render = async (values: Record<string, string>, testing?: Record<string, string>) => {
    const text = (v: Record<string, string>) => Object.keys(v).sort().map((k) => `${k}="${v[k]}"\n`).join("");
    const content = text(values);
    Object.assign(inputs.intent.environment, {
      keys: Object.keys(values).sort(), sha256: await sha256(content), testing: null,
    });
    delete inputs.execution_secrets.testing;
    await f.repo.cli(["vault", "put", "orbit-control-inputs", "instance-environment-31", content]);
    if (!testing) return [content];
    const test = text(testing);
    inputs.intent.environment.testing = { sha256: await sha256(test), test_database: "app_test" };
    inputs.execution_secrets.testing = { vault: "orbit-control-inputs", key: "instance-environment-testing-31" };
    await f.repo.cli(["vault", "put", "orbit-control-inputs", "instance-environment-testing-31", test]);
    return [content, test];
  };
  return { f, secret, inputs, render, sync: (success = true) => f.run(NAME, inputs, success) };
}
Deno.test("development environment writes a private file and reads only key names on rerun", async () => {
  const { f, secret, render, sync } = await fixture();
  await using _ = f;
  const [content] = await render({ APP_KEY: secret, APP_ENV: "local" });
  const { steps } = await sync();
  assertEquals(await Deno.readTextFile(`${f.node}/.env`), content);
  assertEquals((await Deno.stat(`${f.node}/.env`)).mode! & 0o777, 0o600);
  assertEquals(steps["Check the written file"].status, "succeeded");
  assertFalse(exists(f.calls));
  const again = await sync();
  assertEquals(
    again.steps["Read the key names"].outputs.stdout.split("\n").filter((s: string) => s && !s.startsWith("#")),
    ["APP_ENV", "APP_KEY"],
  );
});
Deno.test("a foreign environment key refuses before writing", async () => {
  const { f, secret, render, sync } = await fixture();
  await using _ = f;
  const original = "APP_ENV=old\nOLD=1\nexport FOREIGN=kept\n";
  await Deno.writeTextFile(`${f.node}/.env`, original);
  await render({ APP_KEY: secret, APP_ENV: "local" });
  const { steps } = await sync(false);
  assertEquals(steps["Check for foreign keys"].status, "failed");
  assertStringIncludes(steps["Read the key names"].outputs.stdout, "FOREIGN");
  assertFalse(
    Object.entries(steps).some(([name, step]) =>
      name.startsWith("Write .env, .env.testing") && step.status === "succeeded"
    ),
  );
  assertEquals(await Deno.readTextFile(`${f.node}/.env`), original);
});
Deno.test("testing environment replaces the file unless Git tracks it", async () => {
  const { f, secret, render, sync } = await fixture();
  await using _ = f;
  await Deno.writeTextFile(`${f.node}/.env.testing`, "KEEP=1\n");
  const [, test] = await render({ APP_KEY: secret }, {
    DB_CONNECTION: "pgsql", DB_DATABASE: "app_test", APP_ENV: "testing",
  });
  await sync();
  assertEquals(await Deno.readTextFile(`${f.node}/.env.testing`), test);
  assertEquals((await Deno.stat(`${f.node}/.env.testing`)).mode! & 0o777, 0o600);
  await Deno.writeTextFile(`${f.node}/.env.testing`, "TRACKED=1\n");
  git(f.node, "init", "-q");
  git(f.node, "add", ".env.testing");
  git(f.node, "-c", "user.name=t", "-c", "user.email=t@t", "commit", "-qm", "t");
  assertStringIncludes((await sync()).steps["Read the key names"].outputs.stdout, "#tracked");
  assertEquals(await Deno.readTextFile(`${f.node}/.env.testing`), "TRACKED=1\n");
});
Deno.test("a different environment hash fails the end check", async () => {
  const { f, secret, render, inputs, sync } = await fixture();
  await using _ = f;
  await render({ APP_KEY: secret });
  inputs.intent.environment.sha256 = "0".repeat(64);
  assertEquals((await sync(false)).steps["Check the written file"].status, "failed");
});
Deno.test("production environment refreshes config, FPM and Processes in order", async () => {
  const { f, secret, render, inputs, sync } = await fixture();
  await using _ = f;
  const app = `${f.node}/current`;
  await Deno.mkdir(`${app}/bootstrap/cache`, { recursive: true });
  await Deno.writeTextFile(`${app}/bootstrap/cache/config.php`, "<?php return [];");
  await Deno.writeTextFile(`${app}/artisan`, "");
  await f.ts("php8.4", `Deno.writeTextFileSync(${JSON.stringify(f.node + "/artisan-call")}, Deno.args.join(" "));`);
  inputs.intent.environment.environment = "production";
  inputs.intent.runtime = {
    config: { user: f.user, home: f.node, version: "8.4", application: app },
    fpm_service: "php8.4-fpm-orbit-app-7.service",
    processes: [
      { id: 5, runtime: "systemd", name: "orbit-process-5-worker.service" },
      { id: 6, runtime: "docker", name: "orbit-process-6" },
    ],
  };
  const path = `${f.repo.dir}/workflows/workflow-${NAME}.yaml`;
  await Deno.writeTextFile(path, (await Deno.readTextFile(path)).replaceAll("/usr/bin/php", `${f.tools}/php`));
  await render({ APP_KEY: secret });
  await sync();
  assertEquals(await Deno.readTextFile(`${f.node}/artisan-call`), "artisan config:cache --no-interaction");
  assertEquals(readCalls(f.calls), [["systemctl", "reload", "php8.4-fpm-orbit-app-7.service"], [
    "systemctl", "restart", "orbit-process-5-worker.service",
  ], ["docker", "restart", "orbit-process-6"]]);
});
