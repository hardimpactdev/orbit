import { assert, assertEquals } from "jsr:@std/assert@1";
import { stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
Deno.test({
  name: "apps preparation creates parents, repairs only the folder, refuses symlinks and repeats unchanged",
  ignore: !hasAnsible,
  fn() {
    const folder = Deno.makeTempDirSync();
    const text = (output: Uint8Array) => new TextDecoder().decode(output).trim();
    const user = text(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout);
    const group = text(new Deno.Command("id", { args: ["-gn"] }).outputSync().stdout);
    try {
      const root = `${folder}/parents/apps`;
      Deno.writeTextFileSync(`${folder}/ansible.cfg`, "[defaults]\nstdout_callback=default\n");
      Deno.writeTextFileSync(`${folder}/play.yml`, stringify([{
        name: "Exercise apps preparation", hosts: "localhost", gather_facts: false,
        vars: { intent: { storage: { root, user, group } } },
        tasks: [{ name: "Prepare the apps folder", "ansible.builtin.import_tasks": new URL("../ansible/tasks/node-update-apps.yml", import.meta.url).pathname }],
      }]));
      const run = () => {
        const output = new Deno.Command("ansible-playbook", {
          args: ["-i", "localhost,", "-c", "local", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/play.yml`],
          env: { ANSIBLE_CONFIG: `${folder}/ansible.cfg`, ANSIBLE_NOCOLOR: "1", ANSIBLE_COLLECTIONS_PATH: `${folder}/collections` },
        }).outputSync();
        return { success: output.success, text: text(output.stdout) + text(output.stderr) };
      };
      const first = run();
      assert(first.success, first.text);
      Deno.writeTextFileSync(`${root}/checkout`, "untouched checkout");
      Deno.chmodSync(`${root}/checkout`, 0o600);
      Deno.chmodSync(root, 0o700);
      const repaired = run();
      assert(repaired.success, repaired.text);
      assertEquals(Deno.statSync(root).mode! & 0o777, 0o755);
      assertEquals(Deno.statSync(`${root}/checkout`).mode! & 0o777, 0o600);
      assertEquals(Deno.readTextFileSync(`${root}/checkout`), "untouched checkout");
      const repeated = run();
      assert(repeated.success, repeated.text);
      assert(/changed=0\s/.test(repeated.text), repeated.text);
      Deno.renameSync(root, `${folder}/existing`);
      Deno.symlinkSync(`${folder}/existing`, root);
      const refused = run();
      assert(!refused.success, refused.text);
      assert(refused.text.includes("node.settings_root_failed"), refused.text);
      assert(Deno.lstatSync(root).isSymlink);
    } finally {
      Deno.removeSync(folder, { recursive: true });
    }
  },
});
