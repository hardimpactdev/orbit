import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

/** One unprivileged playbook launch; Git and its timeout are faked, with no network or sleeps. */
Deno.test("migration source preflight distinguishes failure and timeout before changing the checkout", async () => {
  const folder = await Deno.makeTempDir();
  try {
    await Deno.writeTextFile(`${folder}/ansible.cfg`, "[defaults]\n");
    await Deno.mkdir(`${folder}/bin`);
    await Deno.mkdir(`${folder}/run`);
    await Deno.writeTextFile(`${folder}/run/source-credential`, "https://x-access-token:fixture@github.com\n");
    await Deno.writeTextFile(`${folder}/checkout`, "serving checkout\n");
    await Deno.writeTextFile(`${folder}/awake`, "awake\n");
    await Deno.writeTextFile(`${folder}/process`, "running\n");
    await Deno.writeTextFile(`${folder}/bin/timeout`, `#!/usr/bin/env python3
import json, os, pathlib, stat, sys
args = sys.argv[1:]
assert args[:3] == ["--kill-after=1", "30", "git"]
assert args[-4:] == ["--heads", "--", "git@github.com:Platform11/servauto-app.git", "refs/heads/heads/x"]
assert os.environ["GIT_TERMINAL_PROMPT"] == "0"
assert os.environ["GIT_CONFIG_GLOBAL"] == "/dev/null"
assert os.environ["GIT_CONFIG_NOSYSTEM"] == "1"
assert os.environ["GIT_ALLOW_PROTOCOL"] == "https"
assert "protocol.allow=never" in args and "protocol.https.allow=always" in args
assert "http.followRedirects=false" in args and "credential.helper=" in args
helper = next(arg for arg in args if arg.startswith("credential.helper=store --file="))
credential = pathlib.Path(helper.split("--file=", 1)[1])
assert stat.S_IMODE(credential.stat().st_mode) == 0o600
assert credential.read_text() == "https://x-access-token:fixture@github.com\\n"
with open(os.environ["SOURCE_CALLS"], "a") as calls:
    calls.write(json.dumps({"credential": str(credential), "case": os.environ["SOURCE_CASE"]}) + "\\n")
sys.stderr.write(os.environ.get("SOURCE_STDERR", ""))
sys.exit(int(os.environ["SOURCE_RC"]))
`);
    await Deno.chmod(`${folder}/bin/timeout`, 0o755);
    const tasks = parse(await Deno.readTextFile(new URL("../ansible/tasks/instance-migrate-phase.yml", import.meta.url))) as Record<string, unknown>[];
    const source = tasks.find(task => task.name === "Check remote deployment access before any Node change");
    assert(source);
    await Deno.writeTextFile(`${folder}/source-only.yml`, stringify([source]));
    await Deno.writeTextFile(`${folder}/full-phase.yml`, stringify(tasks));
    const plays = [
      {name: "source", hosts: "all", connection: "local", gather_facts: false,
       vars: {ansible_python_interpreter: "python3", phase: "container", migrate: {source: {repository: "git@github.com:Platform11/servauto-app.git", branch: "heads/x", access_denied_message: "The GitHub App orbit-nckrtl cannot read Platform11/servauto-app: install it for that organisation or add the repository."}}},
       environment: {PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ORBIT_RUN: `${folder}/run`, SOURCE_CALLS: `${folder}/calls`, SOURCE_RC: "{{ source_rc }}", SOURCE_CASE: "{{ inventory_hostname }}", SOURCE_STDERR: "{{ source_stderr | default('') }}"},
       tasks: [{block: [{"ansible.builtin.include_tasks": {file: "{{ 'source-only.yml' if source_rc | int == 0 else 'full-phase.yml' }}"}}, {"ansible.builtin.set_fact": {source_passed: true}}],
         rescue: [{"ansible.builtin.assert": {that: ["source_rc | int != 0", "migrate_refusal_code == expected_code"]}}]},
         {"ansible.builtin.assert": {that: ["source_passed | default(false) == (source_rc | int == 0)"]}},
         {"ansible.builtin.copy": {dest: `${folder}/{{ inventory_hostname }}.json`, content: "{{ {'passed': source_passed | default(false), 'code': migrate_refusal_code | default(none), 'message': migrate_refusal_message | default(none), 'credential': migrate_source_private.path} | to_json }}", mode: "0600"}}]},
    ];
    await Deno.writeTextFile(`${folder}/play.yml`, stringify(plays));
    await Deno.writeTextFile(`${folder}/inventory.yml`, stringify({all: {hosts: {
      readable: {source_rc: 0, expected_code: ""},
      unreadable: {source_rc: 2, expected_code: "source.head_unavailable"},
      slow: {source_rc: 124, expected_code: "source.read_timed_out"},
      killed: {source_rc: 137, expected_code: "source.read_timed_out"},
      network: {source_rc: 128, source_stderr: "fatal: Could not resolve host: github.com", expected_code: "source.head_unavailable"},
      authentication: {source_rc: 128, source_stderr: "fatal: Authentication failed for ghs_private_diagnostic", expected_code: "source.github_app_denied"},
      username: {source_rc: 128, source_stderr: "fatal: could not read Username for https://github.com: terminal prompts disabled", expected_code: "source.github_app_denied"},
      forbidden: {source_rc: 128, source_stderr: "fatal: The requested URL returned error: 403", expected_code: "source.github_app_denied"},
    }}}));
    const done = await new Deno.Command("ansible-playbook", {args: ["-i", `${folder}/inventory.yml`, `${folder}/play.yml`],
      env: {ORBIT_RUN: `${folder}/run`, ANSIBLE_CONFIG: `${folder}/ansible.cfg`, ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: "", ANSIBLE_NOCOLOR: "1", ANSIBLE_FORKS: "3"}}).output();
    assert(done.success, new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr));
    const calls = (await Deno.readTextFile(`${folder}/calls`)).trim().split("\n").map(line => JSON.parse(line));
    assertEquals(calls.length, 8);
    assertEquals(new TextDecoder().decode(done.stdout).includes("ghs_private_diagnostic"), false);
    for (const name of ["authentication", "username", "forbidden"]) {
      const denied = JSON.parse(await Deno.readTextFile(`${folder}/${name}.json`));
      assertEquals(denied.code, "source.github_app_denied");
      assertEquals(denied.message, "The GitHub App orbit-nckrtl cannot read Platform11/servauto-app: install it for that organisation or add the repository.");
    }
    const missing = JSON.parse(await Deno.readTextFile(`${folder}/unreadable.json`));
    assertEquals(missing.message, "The remote branch heads/x does not exist in git@github.com:Platform11/servauto-app.git.");
    const network = JSON.parse(await Deno.readTextFile(`${folder}/network.json`));
    assertEquals(network.message, "Could not reach the source: git@github.com:Platform11/servauto-app.git.");
    for (const call of calls) {
      let removed = false;
      try { await Deno.stat(call.credential); } catch (error) { if (error instanceof Deno.errors.NotFound) removed = true; else throw error; }
      assert(removed);
    }
    const slow = JSON.parse(await Deno.readTextFile(`${folder}/slow.json`));
    assertEquals(slow.passed, false);
    assertEquals(slow.message, "Could not reach the source in 30 s: git@github.com:Platform11/servauto-app.git.");
    assertEquals(await Deno.readTextFile(`${folder}/checkout`), "serving checkout\n");
    assertEquals(await Deno.readTextFile(`${folder}/awake`), "awake\n");
    assertEquals(await Deno.readTextFile(`${folder}/process`), "running\n");
  } finally { await Deno.remove(folder, {recursive: true}); }
});

/** Real Git proves the local branch needs no remote ref when all of its commits were pushed elsewhere. */
Deno.test("a never-pushed branch at a pushed commit passes source and retirement checks and copies locally", async () => {
  const folder = await Deno.makeTempDir();
  const git = (...args: string[]) => {
    const result = new Deno.Command("git", {args: ["-c", "user.name=Orbit", "-c", "user.email=fixture@example.test", ...args]}).outputSync();
    assert(result.success, new TextDecoder().decode(result.stderr));
    return new TextDecoder().decode(result.stdout).trim();
  };
  try {
    for (const path of ["bin", "run", "source"]) await Deno.mkdir(`${folder}/${path}`);
    await Deno.writeTextFile(`${folder}/ansible.cfg`, "[defaults]\n");
    git("init", "--quiet", "--initial-branch=main", `${folder}/source`);
    await Deno.writeTextFile(`${folder}/source/tracked.txt`, "pushed\n");
    git("-C", `${folder}/source`, "add", ".");
    git("-C", `${folder}/source`, "commit", "--quiet", "-m", "pushed");
    git("clone", "--quiet", "--bare", `${folder}/source`, `${folder}/origin.git`);
    git("clone", "--quiet", `${folder}/origin.git`, `${folder}/checkout`);
    git("-C", `${folder}/checkout`, "checkout", "--quiet", "-b", "never-pushed");
    assertEquals(git("-C", `${folder}/checkout`, "rev-list", "--count", "HEAD", "--not", "--remotes"), "0");
    assertEquals(git("ls-remote", "--heads", `${folder}/origin.git`, "refs/heads/never-pushed"), "");
    await Deno.writeTextFile(`${folder}/run/source-credential`, "");
    await Deno.writeTextFile(`${folder}/bin/timeout`, `#!/usr/bin/env python3
import os, subprocess, sys
args = sys.argv[1:]
assert args[:3] == ["--kill-after=1", "30", "git"]
assert args[-4:] == ["--heads", "--", "https://github.com/owner/repo.git", "refs/heads/main"]
# Only the HTTPS transport is faked. Real Git reads the fixed, private local origin.
env = dict(os.environ, GIT_ALLOW_PROTOCOL="file")
result = subprocess.run(["git", "ls-remote", "--exit-code", "--heads", "--", os.environ["FIXTURE_ORIGIN"], args[-1]], env=env, capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
sys.exit(result.returncode)
`);
    await Deno.chmod(`${folder}/bin/timeout`, 0o755);
    const phase = parse(await Deno.readTextFile(new URL("../ansible/tasks/instance-migrate-phase.yml", import.meta.url))) as Record<string, unknown>[];
    const source = phase.find(task => task.name === "Check remote deployment access before any Node change");
    assert(source);
    await Deno.copyFile(new URL("../ansible/tasks/instance-migrate-retirement-check.yml", import.meta.url), `${folder}/retirement.yml`);
    const plays = [{hosts: "localhost", connection: "local", gather_facts: false,
      vars: {ansible_python_interpreter: "python3", phase: "container", retired: {path: `${folder}/checkout`},
        migrate: {checkout: `${folder}/checkout`, branch: "never-pushed", source: {repository: "https://github.com/owner/repo.git", branch: "main"}}},
      tasks: [source, {"ansible.builtin.include_tasks": {file: `${folder}/retirement.yml`}},
        {"ansible.builtin.assert": {that: ["retirement_unpushed | length == 0", "migrate.branch == 'never-pushed'", "migrate.source.branch == 'main'"]}},
        {"ansible.builtin.command": {argv: ["git", "-c", "core.hooksPath=/dev/null", "clone", "--quiet", "--no-hardlinks", "--branch", "{{ migrate.branch }}", "{{ migrate.checkout }}", `${folder}/workspace`]}, changed_when: false},
      ]}];
    await Deno.writeTextFile(`${folder}/play.yml`, stringify(plays));
    const result = await new Deno.Command("ansible-playbook", {args: ["-i", "localhost,", `${folder}/play.yml`],
      env: {PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ORBIT_RUN: `${folder}/run`, FIXTURE_ORIGIN: `${folder}/origin.git`,
        ANSIBLE_CONFIG: `${folder}/ansible.cfg`, ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: "", ANSIBLE_NOCOLOR: "1"}}).output();
    assert(result.success, new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr));
    assertEquals(git("-C", `${folder}/workspace`, "symbolic-ref", "--short", "HEAD"), "never-pushed");
    assertEquals(git("-C", `${folder}/workspace`, "rev-parse", "HEAD"), git("-C", `${folder}/checkout`, "rev-parse", "HEAD"));
    assertEquals(await Deno.readTextFile(`${folder}/workspace/tracked.txt`), "pushed\n");
  } finally { await Deno.remove(folder, {recursive: true}); }
});
