import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const source = new URL("../ansible/schedule-logs.yml", import.meta.url);
const limit = 4 * 1024 * 1024;
const id = "123e4567-e89b-42d3-a456-426614174000";
const prefix = "2026-11-10T03:00:00+0000 node scheduler[17]: ";
const entry = (message: string) => `${prefix}${message.replaceAll("\n", "\n    ")}\n`;

function fixture(messages: string[]) {
  const folder = Deno.makeTempDirSync();
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ schedule: { id }, read: { lines: 17 } }));
  Deno.writeTextFileSync(`${folder}/messages.json`, JSON.stringify(messages));
  Deno.writeTextFileSync(`${folder}/journalctl`, `#!/usr/bin/python3
import json,sys
from pathlib import Path
Path(${JSON.stringify(`${folder}/args.json`)}).write_text(json.dumps(sys.argv[1:]))
try:
    for message in json.loads(Path(${JSON.stringify(`${folder}/messages.json`)}).read_text()):
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
    run() {
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
  fn() {
    const f = fixture(["private-schedule-output", "old service\ncontinued"]);
    try {
      assertEquals(f.run(), { state: "active", output: entry("old service\ncontinued") + entry("private-schedule-output"), truncated: false });
      assertEquals(JSON.parse(Deno.readTextFileSync(`${f.folder}/args.json`)), [
        `_SYSTEMD_UNIT=orbit-schedule-${id}.service`, "+", `SYSLOG_IDENTIFIER=orbit-schedule-${id}`,
        "--lines", "17", "--reverse", "--output=json",
        "--output-fields=__REALTIME_TIMESTAMP,_HOSTNAME,SYSLOG_IDENTIFIER,_COMM,SYSLOG_PID,_PID,MESSAGE", "--no-pager", "--quiet",
      ]);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal byte cap retains complete multibyte entries and excludes the first entry that cannot fit",
  ignore: !hasAnsible,
  fn() {
    const newest = "é".repeat(1_048_500) + "\nprivate-schedule-output";
    const older = "é".repeat(1_048_500);
    const f = fixture([newest, older, "x".repeat(1_000)]);
    try {
      const result = f.run();
      assertEquals(result, { state: "active", output: entry(older) + entry(newest), truncated: true });
      assert(new TextEncoder().encode(result.output).length <= limit);
      assertEquals(new TextDecoder("utf-8", { fatal: true }).decode(new TextEncoder().encode(result.output)), result.output);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal keeps a complete entry at the exact UTF-8 byte bound",
  ignore: !hasAnsible,
  fn() {
    const message = "x".repeat(limit - new TextEncoder().encode(entry("")).length);
    const f = fixture([message]);
    try {
      const result = f.run();
      assertEquals(result.output, entry(message));
      assertEquals(new TextEncoder().encode(result.output).length, limit);
      assertEquals(result.truncated, false);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "Schedule journal stops reading an oversized frame on the Node before transfer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(["é".repeat(16 * 1024 * 1024), "must not be read"]);
    try {
      assertEquals(f.run(), { state: "active", output: "", truncated: true });
      assertFalse([...Deno.readDirSync(f.folder)].some((entry) => entry.name === "fully-read"));
    } finally { f.cleanup(); }
  },
});
