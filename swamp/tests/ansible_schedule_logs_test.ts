import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const source = new URL("../ansible/schedule-logs.yml", import.meta.url);

Deno.test({
  name: "migrated Schedule logs combine retained unit and command identifiers without public journal output",
  ignore: !hasAnsible,
  fn() {
    const folder = Deno.makeTempDirSync();
    try {
      const id = "123e4567-e89b-42d3-a456-426614174000";
      const marker = "private-schedule-output";
      Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ schedule: { id }, read: { lines: 17 } }));
      Deno.writeTextFileSync(`${folder}/journalctl`, `#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\nPath(${JSON.stringify(`${folder}/args.json`)}).write_text(json.dumps(sys.argv[1:]))\nprint(${JSON.stringify(marker)})\n`);
      Deno.chmodSync(`${folder}/journalctl`, 0o755);
      const plays = parse(Deno.readTextFileSync(source)) as Record<string, unknown>[];
      const play = plays[1];
      play.hosts = "localhost";
      play.connection = "local";
      play.become = false;
      const tasks = play.tasks as Record<string, unknown>[];
      const command = tasks[0]["ansible.builtin.command"] as { argv: string[] };
      command.argv[0] = `${folder}/journalctl`;
      Deno.writeTextFileSync(`${folder}/read.yml`, stringify([play]));
      const run = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/read.yml`],
        env: { ORBIT_RUN: folder, ANSIBLE_NOCOLOR: "1", ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: `${folder}/collections` },
      }).outputSync();
      const output = new TextDecoder().decode(run.stdout) + new TextDecoder().decode(run.stderr);
      assert(run.success, output);
      assertFalse(output.includes(marker), output);
      assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/args.json`)), [
        `_SYSTEMD_UNIT=orbit-schedule-${id}.service`, "+", `SYSLOG_IDENTIFIER=orbit-schedule-${id}`,
        "--lines", "17", "--reverse", "--output=short-iso", "--utc", "--no-pager", "--quiet",
      ]);
      assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/result.json`)), { state: "active", output: `${marker}\n` });
      assertEquals(Deno.statSync(`${folder}/result.json`).mode! & 0o777, 0o600);
    } finally {
      Deno.removeSync(folder, { recursive: true });
    }
  },
});
