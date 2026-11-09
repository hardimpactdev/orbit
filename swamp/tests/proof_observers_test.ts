/** Run the candidate play on three local fixture Nodes. Privilege, transport, systemd and the Linux update lock are fixture boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const source = new URL("../ansible/", import.meta.url);
const roles = ["gateway", "app-dev", "app-prod"];

type Task = {
  name: string;
  "ansible.builtin.get_url"?: Record<string, string>;
  "ansible.builtin.import_tasks"?: string;
  "ansible.builtin.systemd"?: Record<string, string>;
  "ansible.builtin.stat"?: Record<string, string>;
  "ansible.builtin.debug"?: Record<string, string>;
  vars?: { converge_replacement: Record<string, string> };
};
type Play = { connection: string; tasks: Task[] };

async function fixture() {
  const folder = Deno.makeTempDirSync();
  const playDir = `${folder}/swamp/ansible`;
  Deno.mkdirSync(playDir, { recursive: true });
  const [play] = parse(
    Deno.readTextFileSync(new URL("proof-observers.yml", source)),
  ) as Play[];
  Deno.mkdirSync(`${playDir}/tasks`);
  const lock = `${folder}/flock`;
  Deno.writeTextFileSync(lock, '#!/bin/sh\nshift 3\nexec "$@"\n');
  Deno.chmodSync(lock, 0o755);
  const replacement = parse(
    Deno.readTextFileSync(new URL("tasks/node-converge-replace.yml", source)),
  );
  Deno.writeTextFileSync(
    `${playDir}/tasks/node-converge-replace.yml`,
    JSON.stringify(replacement, (key, value) => {
      if (key === "owner" || key === "group") return undefined;
      if (
        key === "argv" && Array.isArray(value) && value[0] === "flock"
      ) {
        return [
          lock,
          ...value.slice(1).map((arg) =>
            arg === "mv" && Deno.build.os === "darwin" ? "gmv" : arg
          ),
        ];
      }
      return value;
    }),
  );
  play.connection = "local";
  play.tasks = play.tasks.flatMap((task: Task) => {
    if (task["ansible.builtin.import_tasks"]) {
      assert(task.vars);
      task.vars.converge_replacement.path = "{{ proof_binary }}";
    }
    if (task["ansible.builtin.systemd"]) {
      return [{
        name: task.name,
        "ansible.builtin.debug": { msg: task["ansible.builtin.systemd"].state },
      }];
    }
    if (task["ansible.builtin.stat"]) {
      task["ansible.builtin.stat"].path = "{{ proof_binary }}";
    }
    return [task];
  });
  Deno.writeTextFileSync(`${playDir}/play.yml`, JSON.stringify([play]));
  const hosts = Object.fromEntries(
    roles.map((
      role,
    ) => [`nested-${role}`, { proof_binary: `${folder}/${role}.agent` }]),
  );
  Deno.writeTextFileSync(
    `${folder}/inventory.json`,
    JSON.stringify({ all: { hosts } }),
  );
  for (const role of roles) {
    Deno.writeTextFileSync(`${folder}/${role}.agent`, "old observer");
  }
  const manifest =
    `${folder}/apps/gateway/resources/agent/frozen-manifest.json`;
  Deno.mkdirSync(`${folder}/apps/gateway/resources/agent`, { recursive: true });
  async function pin(content: string, badHash = false) {
    const artifact = `${folder}/published-agent`;
    Deno.writeTextFileSync(artifact, content);
    const digest = Array.from(
      new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(content),
        ),
      ),
      (v) => v.toString(16).padStart(2, "0"),
    ).join("");
    Deno.writeTextFileSync(
      manifest,
      JSON.stringify({
        artifacts: {
          "linux-x86_64": {
            url: `file://${artifact}`,
            sha256: badHash ? "0".repeat(64) : digest,
          },
        },
      }),
    );
  }
  function run() {
    const done = new Deno.Command("ansible-playbook", {
      args: ["-i", `${folder}/inventory.json`, `${playDir}/play.yml`],
      env: {
        ANSIBLE_HOME: `${folder}/home`,
        ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        ANSIBLE_NOCOLOR: "1",
      },
    }).outputSync();
    return {
      ok: done.success,
      output: new TextDecoder().decode(done.stdout) +
        new TextDecoder().decode(done.stderr),
    };
  }
  return {
    folder,
    pin,
    run,
    contents: () =>
      roles.map((role) => Deno.readTextFileSync(`${folder}/${role}.agent`)),
  };
}

Deno.test({
  name:
    "proof preparation replaces every seed observer with the candidate pin and repeats without changes",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("released observer 0.14.0");
      const first = f.run();
      assert(first.ok, first.output);
      assertEquals(f.contents(), roles.map(() => "released observer 0.14.0"));
      assertStringIncludes(first.output, '"msg": "restarted"');
      const repeat = f.run();
      assert(repeat.ok, repeat.output);
      assertEquals(
        (repeat.output.match(/changed=0/g) || []).length,
        3,
        repeat.output,
      );
      assertStringIncludes(repeat.output, '"msg": "started"');
    } finally {
      Deno.removeSync(f.folder, { recursive: true });
    }
  },
});

Deno.test({
  name:
    "proof preparation follows a changed candidate manifest without refreshing the seed",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("first released observer");
      assert(f.run().ok);
      await f.pin("next released observer");
      const next = f.run();
      assert(next.ok, next.output);
      assertEquals(f.contents(), roles.map(() => "next released observer"));
    } finally {
      Deno.removeSync(f.folder, { recursive: true });
    }
  },
});

Deno.test({
  name:
    "a bad candidate checksum preserves every old observer and refuses preparation",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("unverified bytes", true);
      const done = f.run();
      assertEquals(done.ok, false, done.output);
      assertEquals(f.contents(), roles.map(() => "old observer"));
      assertStringIncludes(done.output, "checksum");
      assert(
        !done.output.includes("TASK [Start the candidate observer]"),
        done.output,
      );
    } finally {
      Deno.removeSync(f.folder, { recursive: true });
    }
  },
});
