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
  "ansible.builtin.command"?: { argv: string[] };
  register?: string;
  changed_when?: boolean;
  until?: string;
  retries?: number;
  delay?: number;
  vars?: { converge_replacement: Record<string, string> };
};
type Play = { connection: string; tasks: Task[] };

async function fixture() {
  const folder = Deno.makeTempDirSync();
  const playDir = `${folder}/swamp/ansible`;
  Deno.mkdirSync(playDir, { recursive: true });
  const plays = parse(
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
  const report = `${folder}/report-check`;
  Deno.writeTextFileSync(
    report,
    '#!/bin/sh\nif test "$1" = never; then exit 1; fi\nif test -f "$2"; then exit 0; fi\n: > "$2"\nexit 1\n',
  );
  Deno.chmodSync(report, 0o755);
  for (const play of plays) {
    play.connection = "local";
    play.tasks = play.tasks.flatMap((task: Task) => {
      if (
        task.name ===
          "Wait for the Gateway to record the pinned observers and their new host facts"
      ) {
        return [{
          ...task,
          "ansible.builtin.command": {
            argv: [report, "{{ proof_report_mode }}", `${folder}/reported`],
          },
          retries: 2,
          delay: 0,
        }];
      }
      if (
        task.name === "Copy the host receipt check" ||
        task.name ===
          "Discard old host receipts while all observers are stopped"
      ) {
        return [{
          name: task.name,
          "ansible.builtin.debug": { msg: "Gateway receipt boundary" },
        }];
      }
      if (task["ansible.builtin.import_tasks"]) {
        assert(task.vars);
        task.vars.converge_replacement.path = "{{ proof_binary }}";
      }
      if (task["ansible.builtin.systemd"]) {
        return [{
          name: task.name,
          "ansible.builtin.debug": {
            msg: task["ansible.builtin.systemd"].state,
          },
        }];
      }
      if (task["ansible.builtin.stat"]) {
        task["ansible.builtin.stat"].path = "{{ proof_binary }}";
      }
      return [task];
    });
  }
  Deno.writeTextFileSync(`${playDir}/play.yml`, JSON.stringify(plays));
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
  const manifest = `${folder}/candidate-release.json`;
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
        proof_observer_release: {
          version: "0.14.0",
          artifacts: {
            "linux-x86_64": {
              url: `file://${artifact}`,
              sha256: badHash ? "0".repeat(64) : digest,
            },
          },
        },
      }),
    );
  }
  function run(reportMode = "late") {
    const done = new Deno.Command("ansible-playbook", {
      args: [
        "-i",
        `${folder}/inventory.json`,
        "-e",
        `@${manifest}`,
        "-e",
        `proof_report_mode=${reportMode}`,
        `${playDir}/play.yml`,
      ],
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
    "proof preparation installs the candidate artifact on every seed observer and repeats safely",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("released observer 0.14.0");
      const first = f.run();
      assert(first.ok, first.output);
      assertStringIncludes(first.output, "RETRYING");
      assertEquals(f.contents(), roles.map(() => "released observer 0.14.0"));
      assertStringIncludes(first.output, '"msg": "started"');
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

Deno.test({
  name: "a download error preserves every old observer and refuses preparation",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("missing artifact");
      Deno.removeSync(`${f.folder}/published-agent`);
      const done = f.run();
      assertEquals(done.ok, false, done.output);
      assertEquals(f.contents(), roles.map(() => "old observer"));
      assert(
        !done.output.includes("TASK [Start the candidate observer]"),
        done.output,
      );
    } finally {
      Deno.removeSync(f.folder, { recursive: true });
    }
  },
});

Deno.test({
  name: "installed digests cannot pass preparation when no new report arrives",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      await f.pin("released observer 0.14.0");
      const done = f.run("never");
      assertEquals(done.ok, false, done.output);
      assertEquals(f.contents(), roles.map(() => "released observer 0.14.0"));
      assertStringIncludes(done.output, "Wait for the Gateway to record");
    } finally {
      Deno.removeSync(f.folder, { recursive: true });
    }
  },
});
