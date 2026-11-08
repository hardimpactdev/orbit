/**
 * The collections phase installs a release's collections in a directory keyed by the SHA-256 of requirements.yml, complete before any
 * play uses it. The real task file runs on localhost against a fake `ansible-galaxy` that logs its calls and may fail.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { encodeHex } from "jsr:@std/encoding@1/hex";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const tasks =
  new URL("../ansible/tasks/ansible-collections.yml", import.meta.url).pathname;
const requirements =
  "collections:\n  - {name: ansible.posix, version: 2.2.2}\n";
const key = encodeHex(
  new Uint8Array(
    await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(requirements),
    ),
  ),
);

/** A playbook folder with this requirements.yml and an ansible.cfg that names `cfgKey`, and an empty collections root. */
function world(cfgKey = key) {
  const folder = Deno.makeTempDirSync();
  const root = `${folder}/collections`;
  Deno.mkdirSync(root);
  Deno.mkdirSync(`${folder}/play/tasks`, { recursive: true });
  Deno.copyFileSync(tasks, `${folder}/play/tasks/ansible-collections.yml`);
  Deno.writeTextFileSync(`${folder}/play/requirements.yml`, requirements);
  Deno.writeTextFileSync(
    `${folder}/play/ansible.cfg`,
    `[defaults]\ncollections_path = ${root}/${cfgKey}:${root}\n`,
  );
  // install: an interrupted run may have left a MANIFEST.json; this one writes the collection, or fails when FAIL_INSTALL is set.
  Deno.writeTextFileSync(
    `${folder}/galaxy`,
    `#!/bin/sh\necho "$2" >> "${folder}/galaxy.log"\nfor last; do :; done\n` +
      `[ "$2" = install ] && [ -n "$FAIL_INSTALL" ] && exit 1\n` +
      `[ "$2" = install ] && mkdir -p "$last/ansible_collections/ansible/posix" && echo '{}' > "$last/ansible_collections/ansible/posix/MANIFEST.json"\nexit 0\n`,
  );
  Deno.chmodSync(`${folder}/galaxy`, 0o755);
  Deno.writeTextFileSync(
    `${folder}/play/play.yml`,
    JSON.stringify([{
      hosts: "localhost",
      gather_facts: false,
      vars: { collections_root: root, collections_galaxy: `${folder}/galaxy` },
      tasks: [{
        name: "Install",
        "ansible.builtin.import_tasks": "tasks/ansible-collections.yml",
      }],
    }]),
  );
  const run = (fail = false) =>
    new Deno.Command("ansible-playbook", {
      args: ["-i", "localhost,", "-c", "local", `${folder}/play/play.yml`],
      env: {
        ANSIBLE_HOME: `${folder}/home`,
        ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        ANSIBLE_NOCOLOR: "1",
        ...(fail ? { FAIL_INSTALL: "1" } : {}),
      },
    }).outputSync();
  const calls = () => {
    try {
      return Deno.readTextFileSync(`${folder}/galaxy.log`).trim().split("\n");
    } catch {
      return [];
    }
  };
  const exists = (path: string) => {
    try {
      Deno.statSync(path);
      return true;
    } catch {
      return false;
    }
  };
  return { folder, root, run, calls, exists };
}

Deno.test({
  name:
    "a new key installs into a partial directory, verifies it, marks it and only then names it by the key",
  ignore: !hasAnsible,
  fn() {
    const w = world();
    const done = w.run();
    assert(done.success, new TextDecoder().decode(done.stdout));
    assertEquals(w.calls(), ["install", "verify"]);
    assertEquals(
      Deno.readTextFileSync(`${w.root}/${key}/.orbit-complete`),
      `${key}\n`,
    );
    assert(
      w.exists(
        `${w.root}/${key}/ansible_collections/ansible/posix/MANIFEST.json`,
      ),
    );
    assert(!w.exists(`${w.root}/${key}.partial`));
    Deno.removeSync(w.folder, { recursive: true });
  },
});

Deno.test({
  name: "a complete key installs nothing",
  ignore: !hasAnsible,
  fn() {
    const w = world();
    Deno.mkdirSync(`${w.root}/${key}`);
    Deno.writeTextFileSync(`${w.root}/${key}/.orbit-complete`, `${key}\n`);
    const done = w.run();
    assert(done.success, new TextDecoder().decode(done.stdout));
    assertEquals(w.calls(), []);
    Deno.removeSync(w.folder, { recursive: true });
  },
});

Deno.test({
  name:
    "an interrupted install that wrote a manifest is never reused: the retry removes it and installs again, the previous key stays",
  ignore: !hasAnsible,
  fn() {
    const w = world();
    const previous = "a".repeat(64);
    Deno.mkdirSync(`${w.root}/${previous}`);
    Deno.writeTextFileSync(
      `${w.root}/${previous}/.orbit-complete`,
      `${previous}\n`,
    );
    // The interrupted run left a manifest in its partial directory, and a key directory without its marker.
    Deno.mkdirSync(
      `${w.root}/${key}.partial/ansible_collections/ansible/posix`,
      { recursive: true },
    );
    Deno.writeTextFileSync(
      `${w.root}/${key}.partial/ansible_collections/ansible/posix/MANIFEST.json`,
      "{}",
    );
    Deno.writeTextFileSync(`${w.root}/${key}.partial/stale`, "");
    Deno.mkdirSync(`${w.root}/${key}`);
    const failed = w.run(true);
    assert(!failed.success);
    assert(!w.exists(`${w.root}/${key}`), "a failed install names no key");
    assert(
      w.exists(`${w.root}/${previous}/.orbit-complete`),
      "the previous key stays",
    );

    const retried = w.run();
    assert(retried.success, new TextDecoder().decode(retried.stdout));
    assertEquals(w.calls(), ["install", "install", "verify"]);
    assert(w.exists(`${w.root}/${key}/.orbit-complete`));
    assert(
      !w.exists(`${w.root}/${key}/stale`),
      "nothing of the interrupted install remains",
    );
    assert(w.exists(`${w.root}/${previous}/.orbit-complete`));
    Deno.removeSync(w.folder, { recursive: true });
  },
});

Deno.test({
  name:
    "keeps the current and the previous key, removes the older ones, and leaves the legacy layout alone",
  ignore: !hasAnsible,
  fn() {
    const w = world();
    const older = ["b".repeat(64), "c".repeat(64), "d".repeat(64)];
    older.forEach((name, i) => {
      Deno.mkdirSync(`${w.root}/${name}`);
      const at = new Date(Date.UTC(2026, 10, 1 + i));
      Deno.utimeSync(`${w.root}/${name}`, at, at);
    });
    Deno.mkdirSync(`${w.root}/ansible_collections/community`, {
      recursive: true,
    });
    const done = w.run();
    assert(done.success, new TextDecoder().decode(done.stdout));
    assertEquals(
      [...Deno.readDirSync(w.root)].map((entry) => entry.name).sort(),
      ["ansible_collections", "d".repeat(64), key, `${key}.requirements.yml`]
        .sort(),
    );
    Deno.removeSync(w.folder, { recursive: true });
  },
});

Deno.test({
  name: "refuses an ansible.cfg that names another key than requirements.yml",
  ignore: !hasAnsible,
  fn() {
    const w = world("e".repeat(64));
    const done = w.run();
    assert(!done.success);
    assertEquals(w.calls(), []);
    Deno.removeSync(w.folder, { recursive: true });
  },
});

Deno.test("the shipped ansible.cfg names the key of the shipped requirements.yml first, then the legacy root", async () => {
  const shipped = await Deno.readTextFile(
    new URL("../ansible/requirements.yml", import.meta.url),
  );
  const shippedKey = encodeHex(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(shipped)),
    ),
  );
  const cfg = await Deno.readTextFile(
    new URL("../ansible/ansible.cfg", import.meta.url),
  );
  assertEquals(
    /^collections_path = (.*)$/m.exec(cfg)?.[1],
    `/opt/orbit/ansible/collections/${shippedKey}:/opt/orbit/ansible/collections`,
  );
});
