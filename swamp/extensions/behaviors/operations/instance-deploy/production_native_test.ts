import { assert, assertEquals, assertFalse, assertNotEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { productionFixture } from "../../../../tests/_support/production_deploy_fixture.ts";
import { exists, git, TOOL } from "../../../../tests/_support/instance_fixture.ts";
Deno.test("production deploy prepares, activates, restarts and cleans up without private output", async () => {
  const { f, home, release, directory, head, sentinel, inputs, command, deploy, calls } = await productionFixture();
  await using _ = f;
  await command(`test -z "$(cat)"; printf %s ${sentinel}; printf "$PWD" > ${f.node}/before-ran`);
  await command(`printf %s ${sentinel}; readlink ${home}/current > ${f.node}/after-saw`, "after_activation");
  inputs.intent.deployment.needs_vp = true;
  const before = (await Deno.lstat(`${home}/current`)).ino;
  const { steps } = await deploy();
  assertEquals(steps["Read Vite manager CLI"].status, "skipped");
  assertEquals(steps["Publish public Vite CLI"].status, "skipped");
  assertEquals(steps["dev-vp-manager-ready"].status, "succeeded");
  assertEquals(git(directory, "rev-parse", "HEAD"), head);
  assertEquals(await Deno.readLink(`${home}/current`), `releases/${release}`);
  assertNotEquals((await Deno.lstat(`${home}/current`)).ino, before);
  assertFalse(exists(`${home}/current.next`));
  assertEquals(await Deno.readLink(`${directory}/.env`), "../../.env");
  assertEquals(await Deno.readLink(`${directory}/storage`), `${home}/storage`);
  assertEquals(await Deno.readLink(`${directory}/public/storage`), "../storage/app/public");
  assertEquals(await Deno.readTextFile(`${home}/storage/app/kept.txt`), "kept\n");
  assertFalse(exists(`${home}/storage/app/new.txt`));
  assertEquals(await Deno.readTextFile(`${home}/.env`), `APP_KEY="${sentinel}"\n`);
  // The Instance account writes .env 0640; root only gives it the orbit-env group, and -h never follows a link.
  assertEquals((await Deno.stat(`${home}/.env`)).mode! & 0o777, 0o640);
  assertEquals(await Deno.readTextFile(`${f.node}/before-ran`), directory);
  assertEquals((await Deno.readTextFile(`${f.node}/after-saw`)).trim(), `releases/${release}`);
  assertEquals(calls(), [["chgrp", "-h", "orbit-env", "--", `${home}/.env`], ["setfacl", "-m", "u:caddy:--x", "--", directory], [
    "setfacl", "-P", "-R", "-m", "u:caddy:r-X", "--",
    `${directory}/public`,
  ], ["systemctl", "reload-or-restart", "orbit-process-3-horizon.service"]]);
  assertFalse(exists(`${f.node}/credential`));
  assertFalse([...Deno.readDirSync(f.node)].some((e) => e.name.startsWith("step-")));
  assertFalse(exists(`${home}/releases/old`));
  assert(exists(`${home}/releases/initial`));
  assertEquals(steps["Check the deployment"].status, "succeeded");
  assertEquals(steps["Run the deploy steps-0"].status, "skipped");
});
Deno.test("a pinned commit off the first-parent chain stops before environment and current", async () => {
  const { f, home, side, pin, deploy, calls } = await productionFixture();
  await using _ = f;
  pin(side);
  const { steps } = await deploy(false);
  assertEquals(steps["Check the branch commit"].status, "failed");
  assertEquals(await Deno.readLink(`${home}/current`), "releases/initial");
  assertEquals(await Deno.readTextFile(`${home}/.env`), "APP_KEY=old\n");
  assertEquals(calls(), []);
});
Deno.test("a foreign production environment key refuses before writing", async () => {
  const { f, home, deploy } = await productionFixture();
  await using _ = f;
  await Deno.writeTextFile(`${home}/.env`, "APP_KEY=old\nHAND_MADE=1\n");
  assertEquals((await deploy(false)).steps["Check for foreign keys"].status, "failed");
  assertEquals(await Deno.readTextFile(`${home}/.env`), "APP_KEY=old\nHAND_MADE=1\n");
  assertEquals(await Deno.readLink(`${home}/current`), "releases/initial");
});
Deno.test("a failed before step keeps the old release and skips later commands and cleanup", async () => {
  const { f, home, sentinel, command, deploy } = await productionFixture();
  await using _ = f;
  await command(`private_${sentinel} {`);
  await command(`touch ${f.node}/second-ran`);
  const { steps } = await deploy(false);
  assertEquals([steps["Run before steps-0"].status, steps["Run before steps-1"].status, steps["Swap current"].status], [
    "failed", "skipped", "skipped",
  ]);
  assertEquals(await Deno.readLink(`${home}/current`), "releases/initial");
  assertFalse(exists(`${f.node}/second-ran`));
  assert(exists(`${home}/releases/old`));
  assertFalse([...Deno.readDirSync(f.node)].some((e) => e.name.startsWith("step-")));
});
Deno.test("a failed restart keeps the new release and stops after steps", async () => {
  const { f, home, release, command, deploy } = await productionFixture();
  await using _ = f;
  await Deno.writeTextFile(f.fail, "reload-or-restart");
  await command(`touch ${f.node}/after-ran`, "after_activation");
  const { steps } = await deploy(false);
  assertEquals(await Deno.readLink(`${home}/current`), `releases/${release}`);
  assertFalse(exists(`${f.node}/after-ran`));
  assert(exists(`${home}/releases/old`));
  assertEquals(steps["Restart Processes-0"].status, "failed");
});
Deno.test("a release that a process still uses stays", async () => {
  const { f, home, deploy } = await productionFixture();
  await using _ = f;
  const child = new Deno.Command("sleep", { args: ["300"], cwd: `${home}/releases/old` }).spawn();
  try {
    const { steps } = await deploy();
    assert(exists(`${home}/releases/old`));
    assertEquals(steps["Clean up releases-0"].status, "skipped");
    assertEquals(steps["Check the deployment"].status, "succeeded");
  } finally {
    child.kill("SIGKILL");
    await child.status;
  }
});
Deno.test("an unreadable process scan retains the release as a cleanup warning", async () => {
  const { f, home, deploy } = await productionFixture();
  await using _ = f;
  await Deno.writeTextFile(f.fail, "find");
  const { steps } = await deploy();
  assert(exists(`${home}/releases/old`));
  assertEquals(steps["Find releases in use-0"].status, "failed");
  assertEquals(steps["Clean up releases-0"].status, "skipped");
});
Deno.test("a user step that moves checkout fails the end check", async () => {
  const { f, home, first, release, command, deploy } = await productionFixture();
  await using _ = f;
  await command(`git checkout --quiet --detach ${first}`, "after_activation");
  assertEquals((await deploy(false)).steps["Check the deployment"].status, "failed");
  assertEquals(await Deno.readLink(`${home}/current`), `releases/${release}`);
});
Deno.test("an empty persistent directory is seeded once", async () => {
  const { f, home, directory, first, pin, deploy } = await productionFixture();
  await using _ = f;
  pin(first);
  await Deno.remove(`${home}/storage`, { recursive: true });
  assertEquals((await deploy()).steps["Check the branch commit"].status, "succeeded");
  assertEquals(git(directory, "rev-parse", "HEAD"), first);
  assertEquals(await Deno.readTextFile(`${home}/storage/app/kept.txt`), "from the release\n");
  assertEquals(await Deno.readTextFile(`${home}/storage/app/new.txt`), "new\n");
});
Deno.test("production publishes only manager CLI and uses app-owned Vite cache", async () => {
  const { f, home, origin, inputs, sentinel, deploy, calls } = await productionFixture();
  await using _ = f;
  const id = "9fd64da7-2e68-4b7c-983a-55707946c795";
  inputs.intent.runtime_definitions.schedules = [{
    id, user: f.user, home,
    working_directory: `${home}/current`,
    shell: "/bin/bash", login_shell: false, calendar: "*-*-* 03:00:00", command_hash: "a".repeat(64),
    timeout_seconds: 30, desired: "disabled",
  }];
  const manager = `${f.node}/manager/.local/share/vite-plus/bin/vp`;
  await Deno.mkdir(`${f.node}/manager/.local/share/vite-plus/bin`, { recursive: true });
  await Deno.writeTextFile(
    manager,
    `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n` + `
import { logCall } from ${JSON.stringify(TOOL)};
logCall(${JSON.stringify(f.calls)}, ["vp", Deno.env.get("VP_HOME"), ...Deno.args]);
if (Deno.args.includes("--version")) console.log("vp v0.3.3\\n\\nLocal vite-plus:\\n  vite-plus Not found\\nEnvironment:\\n  Node.js v26.13.0");
else if (Deno.args.includes("list")) console.log(JSON.stringify({ node: [{ version: "24.22.0" }], package_managers: { npm: [{ version: "11.12.0" }] } }));
`,
    { mode: 0o755 },
  );
  await Deno.writeTextFile(`${f.node}/manager/.local/share/vite-plus/private.json`, sentinel);
  await Deno.writeTextFile(
    `${origin}/package.json`,
    JSON.stringify({
      devDependencies: { "vite-plus": "0.3.3" },
      packageManager: "npm@11.12.0",
      engines: { node: "24.22.0" },
    }),
  );
  await Deno.writeTextFile(
    `${origin}/package-lock.json`,
    JSON.stringify({ packages: { "node_modules/vite-plus": { version: "0.3.3" } } }),
  );
  git(origin, "add", ".");
  git(origin, "commit", "-qm", "Vite project");
  inputs.intent.deployment.needs_vp = true;
  const { steps } = await deploy();
  assertEquals(steps["Check Schedule timers-0"].status, "succeeded");
  assertStringIncludes(
    await Deno.readTextFile(`${f.node}/etc/systemd/system/orbit-schedule-${id}.timer`),
    "OnCalendar=*-*-* 03:00:00",
  );

  const published = `${f.node}/public/bin/vp`;
  assertEquals(await Deno.readFile(published), await Deno.readFile(manager));
  assertEquals((await Deno.stat(published)).mode! & 0o777, 0o755);
  assertFalse(exists(`${f.node}/public/private.json`));
  assertEquals(steps["Publish public Vite CLI"].status, "succeeded");
  const rows = calls().filter((row) => row[0] === "vp");
  assertEquals(rows.length, 3);
  assert(rows.every((row) => row[1] === `${home}/.local/share/vite-plus`));
  assertEquals(rows[0].slice(2), ["--version"]);
  assert(rows.slice(1).every((row) => row[2] === "-C" && row[3] === f.node));
});
