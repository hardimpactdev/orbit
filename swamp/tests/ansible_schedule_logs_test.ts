import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const python = new TextDecoder().decode(new Deno.Command("sh", {args: ["-c", 'head -n1 "$(command -v ansible-playbook)" | cut -c3-']}).outputSync().stdout).trim();
const source = new URL("../ansible/schedule-logs.yml", import.meta.url);
const limit = 4 * 1024 * 1024;
const id = "123e4567-e89b-42d3-a456-426614174000";
const prefix = "2026-11-10T03:00:00+0000 node scheduler[17]: ";
const entry = (message: string) => `${prefix}${message.replaceAll("\n", "\n    ")}\n`;

function fixture(messages: (string | number[] | null)[], lines = 17) {
  const folder = Deno.makeTempDirSync();
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ schedule: { id }, read: { lines } }));
  Deno.writeTextFileSync(`${folder}/messages.json`, JSON.stringify(messages));
  Deno.writeTextFileSync(`${folder}/journalctl`, `#!/usr/bin/python3
import json,sys
from pathlib import Path
Path(${JSON.stringify(`${folder}/args.json`)}).write_text(json.dumps(sys.argv[1:]))
try:
    messages = json.loads(Path(${JSON.stringify(`${folder}/messages.json`)}).read_text())
    for message in messages[:int(sys.argv[sys.argv.index('--lines') + 1])]:
        raw = bytes(message) if isinstance(message, list) else message.encode('utf-8') if message is not None else None
        if raw is not None:
            if '--all' not in sys.argv and len(raw) > 4096:
                message = None
            else:
                try:
                    message = raw.decode('utf-8')
                    if any(byte < 32 and byte not in (9, 10) for byte in raw):
                        message = list(raw)
                except UnicodeDecodeError:
                    message = list(raw)
        print(json.dumps({'__REALTIME_TIMESTAMP': '1794279600000000', '_HOSTNAME': 'node', 'SYSLOG_IDENTIFIER': 'scheduler', 'SYSLOG_PID': '17', 'MESSAGE': message}, ensure_ascii=False), flush=True)
    Path(${JSON.stringify(`${folder}/fully-read`)}).touch()
except BrokenPipeError:
    pass
`);
  Deno.chmodSync(`${folder}/journalctl`, 0o755);
  const plays = parse(Deno.readTextFileSync(source)) as Record<string, unknown>[];
  const play = plays[1];
  play.hosts = "localhost";
  play.connection = "local";
  play.become = false;
  const tasks = play.tasks as Record<string, unknown>[];
  (tasks[0].orbit_schedule_journal as Record<string, unknown>).journalctl = `${folder}/journalctl`;
  Deno.writeTextFileSync(`${folder}/read.yml`, stringify([play]));
  return {
    folder,
    run(wiring = false) {
      if (!wiring) {
        const intent = JSON.parse(Deno.readTextFileSync(folder + "/intent.json"));
        const done = new Deno.Command(python, {
          args: [new URL("../ansible/library/orbit_schedule_journal.py", import.meta.url).pathname],
          stdin: "piped", stdout: "piped", stderr: "piped",
        }).spawn();
        // The real Ansible module accepts exactly its normal JSON stdin protocol.
        return (async () => {
          const writer = done.stdin.getWriter();
          await writer.write(new TextEncoder().encode(JSON.stringify({ANSIBLE_MODULE_ARGS: {
            schedule_id: id, lines, journalctl: folder + "/journalctl",
            ...(intent.container ? {container: intent.container, incus: folder + "/incus"} : {}),
          }})));
          await writer.close();
          const run = await done.output();
          assert(run.success, new TextDecoder().decode(run.stderr) + new TextDecoder().decode(run.stdout));
          const value = JSON.parse(new TextDecoder().decode(run.stdout));
          const result = {output: value.output, truncated: value.truncated, ...value.observation};
          Deno.writeTextFileSync(folder + "/result.json", JSON.stringify(result), {mode: 0o600});
          return result;
        })();
      }
      const params = (tasks[0].orbit_schedule_journal as Record<string, unknown>);
      try { Deno.statSync(folder + "/incus"); params.incus = folder + "/incus"; } catch { /* host read */ }
      Deno.writeTextFileSync(folder + "/read.yml", stringify([play]));
      const run = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/read.yml`],
        env: { ORBIT_RUN: folder, ANSIBLE_NOCOLOR: "1", ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_STDOUT_CALLBACK: "default",
          ANSIBLE_COLLECTIONS_PATH: `${folder}/collections`, ANSIBLE_LIBRARY: decodeURIComponent(new URL("../ansible/library", import.meta.url).pathname) },
      }).outputSync();
      const output = new TextDecoder().decode(run.stdout) + new TextDecoder().decode(run.stderr);
      assert(run.success, output);
      assertFalse(output.includes("private-schedule-output"), output);
      assertEquals(Deno.statSync(`${folder}/result.json`).mode! & 0o777, 0o600);
      return JSON.parse(Deno.readTextFileSync(`${folder}/result.json`));
    },
    cleanup() { Deno.removeSync(folder, { recursive: true }); },
  };
}

Deno.test({
  name: "migrated Schedule logs combine unit and command entries privately, in time order, with whole multiline messages",
  ignore: !hasAnsible,
  async fn() {
    const f = fixture(["private-schedule-output", "old service\ncontinued"]);
    try {
      assertEquals(await f.run(true), { state: "active", output: entry("old service\ncontinued") + entry("private-schedule-output"), truncated: false });
      assertEquals(JSON.parse(Deno.readTextFileSync(`${f.folder}/args.json`)), [
        `_SYSTEMD_UNIT=orbit-schedule-${id}.service`, "+", `SYSLOG_IDENTIFIER=orbit-schedule-${id}`,
        "--lines", "17", "--reverse", "--output=json", "--all",
        "--output-fields=__REALTIME_TIMESTAMP,_HOSTNAME,SYSLOG_IDENTIFIER,_COMM,SYSLOG_PID,_PID,MESSAGE", "--no-pager", "--quiet",
      ]);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal byte cap retains complete multibyte entries and excludes the first entry that cannot fit",
  ignore: !hasAnsible,
  async fn() {
    const newest = "é".repeat(1_048_500) + "\nprivate-schedule-output";
    const older = "é".repeat(1_048_500);
    const f = fixture([newest, older, "x".repeat(1_000)]);
    try {
      const result = await f.run();
      assertEquals(result, { state: "active", output: entry(older) + entry(newest), truncated: true });
      assert(new TextEncoder().encode(result.output).length <= limit);
      assertEquals(new TextDecoder("utf-8", { fatal: true }).decode(new TextEncoder().encode(result.output)), result.output);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal keeps a complete entry at the exact UTF-8 byte bound",
  ignore: !hasAnsible,
  async fn() {
    const message = "x".repeat(limit - new TextEncoder().encode(entry("")).length);
    const f = fixture([message]);
    try {
      const result = await f.run();
      assertEquals(result.output, entry(message));
      assertEquals(new TextEncoder().encode(result.output).length, limit);
      assertEquals(result.truncated, false);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal stops reading an oversized frame on the Node before transfer",
  ignore: !hasAnsible,
  async fn() {
    const f = fixture(["é".repeat(16 * 1024 * 1024), "must not be read"]);
    try {
      assertEquals(await f.run(), { state: "active", output: "", truncated: true });
      assertFalse([...Deno.readDirSync(f.folder)].some((entry) => entry.name === "fully-read"));
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal reads long fields with --all and handles null and non-UTF-8 byte-array messages",
  ignore: !hasAnsible,
  async fn() {
    const long = "private-schedule-output" + "x".repeat(5_000);
    const f = fixture([long, null, [104, 105, 255, 10, 120]]);
    try {
      assertEquals(await f.run(), {
        state: "active", output: entry("hi�\nx") + entry("") + entry(long), truncated: false,
      });
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal bounds double JSON encoding and crosses the Gateway's real framed client",
  ignore: !hasAnsible,
  async fn() {
    const messages = Array.from({ length: 800 }, (_, index) => `${index}:` + "\\".repeat(3_000));
    const f = fixture(messages, 800);
    try {
      const result = await f.run();
      assertEquals(result.truncated, true);
      const read = new Deno.Command("php", {
        args: [new URL("fixtures/schedule-inspection-frame.php", import.meta.url).pathname, `${f.folder}/result.json`],
      }).outputSync();
      assert(read.success, new TextDecoder().decode(read.stderr));
      const observed = JSON.parse(new TextDecoder().decode(read.stdout));
      assert(observed.frame_bytes < 8 * 1024 * 1024);
      assert(observed.entries > 0 && observed.entries < 800);
      assertEquals(result.output, messages.slice(0, observed.entries).reverse().map(entry).join(""));
    } finally { f.cleanup(); }
  },
});

Deno.test({name: "container Schedule inspection reads guest state and journal, and stopped inspection never execs or wakes", ignore: !hasAnsible, async fn() {
  const f = fixture(["private-schedule-output"]);
  const container = {project: "orbit-instances", name: "instance-7"};
  Deno.writeTextFileSync(f.folder + "/intent.json", JSON.stringify({schedule: {id}, container, read: {lines: 17}}));
  Deno.writeTextFileSync(f.folder + "/status", "Running");
  Deno.writeTextFileSync(f.folder + "/incus", [
    "#!/usr/bin/python3", "import os,sys,json", "from pathlib import Path", "folder=Path(__file__).parent", "a=sys.argv[1:]",
    "with (folder/'calls').open('a') as f: f.write(json.dumps(a)+'\\n')",
    "if a[0]=='query': print(json.dumps({'status':(folder/'status').read_text()})); sys.exit(0)",
    "assert a[0]=='exec' and a[a.index('--')-1]=='instance-7', a", "a=a[a.index('--')+1:]",
    "if os.path.basename(a[0])=='journalctl': os.execv(str(folder/'journalctl'), [str(folder/'journalctl')]+a[1:])",
    "assert a[:2]==['systemctl','show'], a",
    "if a[-1].endswith('.timer'): print('active')",
    "else: print('Result=success\\nExecMainExitTimestamp=Thu 2026-11-12 12:00:00 UTC\\nActiveState=inactive')",
  ].join("\n") + "\n");
  Deno.chmodSync(f.folder + "/incus", 0o755);
  try {
    const result = await f.run(true);
    assertEquals(result.state, "active");
    assertEquals(result.last_run_status, "success");
    assertEquals(result.last_run_at, "2026-11-12T12:00:00+00:00");
    assert(result.output.includes("private-schedule-output"));
    Deno.writeTextFileSync(f.folder + "/status", "Stopped");
    Deno.writeTextFileSync(f.folder + "/calls", "");
    const stopped = await f.run();
    assertEquals(stopped.state, "inactive");
    assertEquals(stopped.error_code, "schedule.target_inactive");
    assertEquals(stopped.output, "");
    const calls = Deno.readTextFileSync(f.folder + "/calls").trim().split("\n").map((line) => JSON.parse(line));
    assertEquals(calls.length, 1);
    assertEquals(calls[0][0], "query");
  } finally {f.cleanup();}
}});

Deno.test({name: "carried Schedule installs disabled and post-cutover activation failure stays advisory", ignore: !hasAnsible, async fn() {
  const folder = Deno.makeTempDirSync(), play = folder + "/play", guest = folder + "/guest";
  const schedule = {id, name: "laravel-scheduler", service: "orbit-schedule-" + id + ".service", timer: "orbit-schedule-" + id + ".timer",
    user: "nick", group: "work", home: "/home/nick", shell: "/bin/bash", login_shell: true, directory: "/srv/workspace/apps/my app",
    source_directory: "/host/workspace", command_hash: "a".repeat(64), mapped_command_hash: "a".repeat(64),
    calendar: "*-*-* *:*:00", timeout_seconds: 7, desired: "enabled", path: "/usr/bin:/bin", environment_file: "/srv/workspace/apps/my app/.env"};
  for (const dir of [play + "/tasks", play + "/templates", guest]) Deno.mkdirSync(dir, {recursive: true});
  const tasks = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-migrate-schedule-check.yml", import.meta.url))) as Record<string, unknown>[];
  const rewrite = (rows: Record<string, unknown>[]) => { for (const task of rows) {
    for (const key of ["block", "rescue"]) if (Array.isArray(task[key])) rewrite(task[key] as Record<string, unknown>[]);
    if (task["ansible.builtin.systemd_service"]) {
      delete task["ansible.builtin.systemd_service"];
      task["ansible.builtin.command"] = {argv: [folder + "/incus", "host-reload"]};
    }
    if (task["ansible.builtin.file"]) {
      const file = task["ansible.builtin.file"] as Record<string, unknown>;
      file.path = folder + "/host" + file.path;
    }
  }};
  rewrite(tasks);
  Deno.writeTextFileSync(play + "/tasks/instance-migrate-schedule-check.yml", stringify(tasks));
  const templates = Deno.readTextFileSync(new URL("../ansible/templates/instance-migrate-schedule.j2", import.meta.url))
    .replace("playbook_dir ~ '/../extensions/behaviors/operations/processes-schedules/unit-templates.json'",
      JSON.stringify(new URL("../extensions/behaviors/operations/processes-schedules/unit-templates.json", import.meta.url).pathname));
  Deno.writeTextFileSync(play + "/templates/instance-migrate-schedule.j2", templates);
  Deno.writeTextFileSync(folder + "/incus", [
    "#!/usr/bin/python3", "import sys,os", "from pathlib import Path", "f=Path(__file__).parent", "a=sys.argv[1:]",
    "if a[0]=='host-reload': sys.exit(0)",
    "if a[0]=='--project':",
    " p=a[-1].split('/',1)[1]; dest=f/'guest'/p; dest.parent.mkdir(parents=True,exist_ok=True); dest.write_text(sys.stdin.read()); os.chmod(dest,int(a[a.index('--mode')+1],8)); sys.exit(0)",
    "a=a[a.index('--')+1:]",
    "if a[0]=='systemd-analyze': sys.exit(0)",
    "assert a[0]=='systemctl', a",
    "if a[1]=='daemon-reload': sys.exit(0)",
    "if a[1]=='enable' and (f/'fail-activation').exists(): sys.exit(1)",
    "if a[1] in ['enable','disable']: (f/'timer-state').write_text('active' if a[1]=='enable' else 'inactive')",
    "if a[1]=='is-active': print((f/'timer-state').read_text())",
  ].join("\n") + "\n");
  Deno.chmodSync(folder + "/incus", 0o755);
  const include = {"ansible.builtin.include_tasks": "tasks/instance-migrate-schedule-check.yml"};
  const plays = [{hosts: "localhost", connection: "local", gather_facts: false,
    vars: {schedule, phase: "processes", container: {name: "instance-7"},
      incus_project: [folder + "/incus", "--project", "orbit-instances"], as_root: [folder + "/incus", "exec", "--project", "orbit-instances", "instance-7", "--"]},
    tasks: [include,
      {"ansible.builtin.assert": {that: "lookup('ansible.builtin.file', '" + folder + "/timer-state') == 'inactive'"}},
      {"ansible.builtin.set_fact": {phase: "retire"}},
      {"ansible.builtin.file": {path: folder + "/fail-activation", state: "touch", mode: "0600"}},
      include,
      {"ansible.builtin.assert": {that: ["schedule_outcomes[0].outcome == 'not_activated'", "schedule_outcomes[0].cause == 'schedule.container_activation_failed'"]}},
      {"ansible.builtin.file": {path: folder + "/fail-activation", state: "absent"}},
      include,
      {"ansible.builtin.assert": {that: "schedule_outcomes[1].outcome == 'activated'"}},
    ]}];
  Deno.writeTextFileSync(folder + "/schedule-timers.json", JSON.stringify([{schedule, calendar: schedule.calendar + " Europe/Amsterdam"}]));
  Deno.writeTextFileSync(play + "/test.yml", stringify(plays));
  try {
    const result = await new Deno.Command("ansible-playbook", {args: ["-i", "localhost,", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", play + "/test.yml"],
      env: {ORBIT_RUN: folder, ORBIT_SCHEDULE_COMMANDS: JSON.stringify({schedule_commands: {[id]: "echo private-schedule-output"}}),
        ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: folder + "/collections", ANSIBLE_HOME: folder + "/ansible"}}).output();
    const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
    assert(result.success, output);
    assertFalse(output.includes("private-schedule-output"));
    const service = Deno.readTextFileSync(guest + "/etc/systemd/system/" + schedule.service);
    const timer = Deno.readTextFileSync(guest + "/etc/systemd/system/" + schedule.timer);
    assert(service.includes("User=orbit\n"));
    assert(service.includes('EnvironmentFile=-"/srv/workspace/apps/my app/.env"\n'));
    assert(service.includes("TimeoutStartSec=7\n"));
    assert(timer.includes("OnCalendar=" + schedule.calendar + " Europe/Amsterdam\nPersistent=false\nDeferReactivation=true\n"));
    assertFalse(service.includes("private-schedule-output"));
    const verified = new Deno.Command("systemd-analyze", {args: ["verify", "--man=no",
      guest + "/etc/systemd/system/" + schedule.service, guest + "/etc/systemd/system/" + schedule.timer]}).outputSync();
    assert(verified.success, new TextDecoder().decode(verified.stderr));
    assertEquals(Deno.readTextFileSync(guest + "/etc/orbit/schedules/" + id + ".sh"), "# X-Orbit-Schedule-ID=" + id + "\necho private-schedule-output\n");
    assertEquals(Deno.statSync(guest + "/etc/orbit/schedules/" + id + ".sh").mode! & 0o777, 0o640);
  } finally { Deno.removeSync(folder, {recursive: true}); }
}});


Deno.test("carried fixed-time calendars preserve explicit zones and capture an unzoned source without changing the stored form", () => {
  const tasks = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-migrate-schedule-check.yml", import.meta.url))) as Record<string, unknown>[];
  const check = (tasks[0].block as Record<string, unknown>[]).find((task) => task.name === "Resolve the carried calendar in its source timezone")!;
  const args = (check["ansible.builtin.command"] as {argv: string[]}).argv;
  const folder = Deno.makeTempDirSync();
  try {
    const zone = folder + "/localtime";
    Deno.symlinkSync("/usr/share/zoneinfo/Europe/Amsterdam", zone);
    for (const [calendar, expected] of [
      ["*-*-* 03:00:00", "*-*-* 03:00:00 Europe/Amsterdam"],
      ["*-*-* 03:00:00 UTC", "*-*-* 03:00:00 UTC"],
      ["*-*-* 03:00:00 Europe/London", "*-*-* 03:00:00 Europe/London"],
      ["*-*-* *:*:00", "*-*-* *:*:00 Europe/Amsterdam"],
    ]) {
      const result = new Deno.Command("python3", {args: ["-c", args[2], calendar, zone]}).outputSync();
      assert(result.success, new TextDecoder().decode(result.stderr));
      const resolved = new TextDecoder().decode(result.stdout).trim();
      assertEquals(resolved, expected);
      const validated = new Deno.Command("systemd-analyze", {args: ["calendar", resolved]}).outputSync();
      assert(validated.success, new TextDecoder().decode(validated.stderr));
    }
    const unreadable = new Deno.Command("python3", {args: ["-c", args[2], "*-*-* 03:00:00", folder + "/missing"]}).outputSync();
    assertFalse(unreadable.success);
  } finally {Deno.removeSync(folder, {recursive: true});}
});
