import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
Deno.test({
  name:
    "task host withdrawal removes every owned input row, preserves unrelated rules, and repeats unchanged",
  ignore: !hasAnsible,
  fn() {
    const folder = Deno.makeTempDirSync();
    try {
      Deno.mkdirSync(`${folder}/bin`);
      Deno.writeTextFileSync(
        `${folder}/rules.json`,
        JSON.stringify([
          "ufw allow in on proofbr0 proto udp from any port 68 to any port 67 comment 'orbit:task-vms-dhcp'",
          "ufw allow in on proofbr0 proto tcp to 10.252.42.1 port 53 comment 'orbit:task-vms-dns-tcp'",
          "ufw allow in on proofbr0 proto udp to 10.252.42.1 port 53 comment 'orbit:task-vms-dns-udp'",
          "ufw allow in on proofbr0 proto udp from ::/0 port 68 to any port 67 comment 'orbit:task-vms-dhcp'",
          "ufw allow in on stale0 proto udp from any port 68 to any port 67 comment 'orbit:task-vms-dhcp'",
          "ufw allow 22 comment 'orbit:public-ssh-recovery'",
          "ufw allow 53 comment 'unrelated'",
          "ufw allow 53 comment 'orbit:task-vms-dns-udp-extra'",
        ]),
      );
      Deno.writeTextFileSync(
        `${folder}/ufw.ts`,
        `
const p = Deno.env.get("RULES")!;
const rules: string[] = JSON.parse(Deno.readTextFileSync(p));
if (Deno.args.join(" ") === "show added") {
  console.log(rules.join("\\n"));
} else if (Deno.args[0] === "--force" && Deno.args[1] === "delete") {
  const command = "ufw " + Deno.args.slice(2, -2).join(" ") + " comment '" + Deno.args.at(-1) + "'";
  const index = rules.indexOf(command);
  if (index === -1) Deno.exit(2);
  if (Deno.env.get("KEEP_RULES") !== "1") rules.splice(index, 1);
  Deno.writeTextFileSync(p, JSON.stringify(rules));
} else Deno.exit(2);
`,
      );
      Deno.writeTextFileSync(
        `${folder}/bin/ufw`,
        `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${folder}/ufw.ts' "$@"\n`,
      );
      Deno.chmodSync(`${folder}/bin/ufw`, 0o755);
      const tasks = parse(
        Deno.readTextFileSync(
          new URL(
            "../ansible/tasks/task-host-firewall-remove.yml",
            import.meta.url,
          ),
        ),
      );
      Deno.writeTextFileSync(
        `${folder}/play.yml`,
        stringify([{
          name: "Exercise withdrawal",
          hosts: "localhost",
          gather_facts: false,
          vars: {
            owned_input_comments:
              "orbit:task-vms-dhcp|orbit:task-vms-dns-tcp|orbit:task-vms-dns-udp",
          },
          tasks,
        }]),
      );
      const run = (keep = false) => {
        const result = new Deno.Command("ansible-playbook", {
          args: [
            "-i",
            "localhost,",
            "-c",
            "local",
            "-e",
            "ansible_python_interpreter={{ ansible_playbook_python }}",
            `${folder}/play.yml`,
          ],
          env: {
            PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
            RULES: `${folder}/rules.json`,
            KEEP_RULES: keep ? "1" : "0",
            ANSIBLE_NOCOLOR: "1",
            ANSIBLE_HOME: `${folder}/home`,
            ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          },
        }).outputSync();
        return {
          ok: result.success,
          output: new TextDecoder().decode(result.stdout) +
            new TextDecoder().decode(result.stderr),
        };
      };
      const refused = run(true);
      assert(!refused.ok);
      assert(refused.output.includes("node.task_host_firewall_remove_failed"));
      const first = run();
      assert(first.ok, first.output);
      assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/rules.json`)), [
        "ufw allow 22 comment 'orbit:public-ssh-recovery'",
        "ufw allow 53 comment 'unrelated'",
        "ufw allow 53 comment 'orbit:task-vms-dns-udp-extra'",
      ]);
      const second = run();
      assert(second.ok, second.output);
      assert(/changed=0\s/.test(second.output), second.output);
    } finally {
      Deno.removeSync(folder, { recursive: true });
    }
  },
});
