/** The production Schedule run DAG with official SSH and harmless local transport fixtures. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists, random } from "../process-commands/native_fixture.ts";
import { scheduleFixture, type ScheduleFixture as Fixture } from "./native_fixture.ts";

const test = (name: string, body: (f: Fixture) => Promise<void>) =>
  Deno.test(name, async () => {
    await using f = await scheduleFixture();
    await body(f);
  });

const result = async (f: Fixture) =>
  (await f.history()).status === "succeeded" ? { ready: true } : { error_code: "schedule.run_failed" };

const calls = (f: Fixture) =>
  Deno.readTextFileSync(f.calls).split("\n").filter(Boolean).map((line) => JSON.parse(line) as string[]);

const execution = (f: Fixture) => calls(f).find((argv) => argv.includes("/usr/bin/systemd-run"))!;

test("the command uses only stdin and journals output before public success", async (f) => {
  const run = await f.runSchedule();
  assertEquals(run.status, "succeeded");
  assertEquals(await result(f), { ready: true });
  assert(Deno.readTextFileSync(f.journal).startsWith("schedule-journal-"));
  const argv = execution(f);
  assert(argv.includes("WorkingDirectory=" + f.repo.dir));
  assert(argv.includes("--identifier=orbit-schedule-" + f.inputs.intent.schedule.id));
  assert(!JSON.stringify(argv).includes(f.repo.secrets[0]));
  await f.repo.assertPrivateAbsent(JSON.stringify(await f.history()), Deno.readTextFileSync(f.calls));
});

test("a failed command and a timeout return bounded errors without business output", async (f) => {
  for (const command of ["printf private-output; exit 1", "sleep 3"]) {
    await f.setCommand(command + " # " + random());
    await f.runSchedule(false);
    assertEquals(await result(f), { error_code: "schedule.run_failed" });
    await f.repo.assertPrivateAbsent(JSON.stringify(await f.history()));
  }
});

test("native resume runs after the directory recovers", async (f) => {
  const directory = `${f.repo.dir}/unavailable-directory`;
  f.inputs.intent.schedule.working_directory = directory;
  await f.runSchedule(false);
  assertEquals(await result(f), { error_code: "schedule.run_failed" });
  await Deno.mkdir(directory);
  await f.repo.cli(["workflow", "resume", "task-command-run", "--run", f.lastRun().id, "--from", "Run the command"]);
  assertEquals(await result(f), { ready: true });
  assert(exists(f.journal));
});

test("an unavailable directory refuses before the command", async (f) => {
  // A missing user is refused by sudo on a real Node; the fixture's sudo is a pass-through.
  f.inputs.intent.schedule.working_directory = "/does-not-exist";
  await f.runSchedule(false);
  assertEquals(await result(f), { error_code: "schedule.run_failed" });
  assert(!exists(f.journal));
});

test("a calendar fire runs without an operation id", async (f) => {
  delete f.inputs.operation_id;
  await f.runSchedule();
  assertEquals(await result(f), { ready: true });
});

test("login shell and the upper timeout are native public arguments", async (f) => {
  Object.assign(f.inputs.intent.schedule, { login_shell: true, timeout_seconds: 86400 });
  await f.runSchedule();
  const argv = execution(f);
  assert(argv.includes("RuntimeMaxSec=86400"));
  assertEquals(argv.slice(-2), [f.inputs.intent.schedule.shell, "1"]);
});
