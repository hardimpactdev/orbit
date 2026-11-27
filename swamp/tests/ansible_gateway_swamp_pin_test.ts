/**
 * The swamp-pin play (swamp/ansible/tasks/gateway-swamp-pin.yml) with real Ansible modules: the release comes through the egress proxy
 * that the caller passes, `systemctl` and `curl` are stubs, and each started unit is a real process of the installed binary, so the
 * play's own check reads real `/proc/<pid>/exe` links.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const tasks = new URL("../ansible/tasks/gateway-swamp-pin.yml", import.meta.url).pathname;
const units = ["orbit-swamp-serve.service", "orbit-swamp-worker@a.service", "orbit-swamp-worker@b.service", "orbit-swamp-worker@c.service"];
const workers = units.slice(1).join(" ");
const decoder = new TextDecoder();

/** `systemctl stop|start|show` for the four units; a unit named in `broken` starts no process. Each step goes to `events`. */
const systemctl = (root: string) => `#!/bin/bash
root=${root}
case "$1" in
  stop) shift; echo "stop $* -> $(readlink "$root/bin/swamp")" >> "$root/events"
    for u in "$@"; do [ -s "$root/pids/$u" ] && kill -- -"$(cat "$root/pids/$u")" 2>/dev/null; rm -f "$root/pids/$u"; done ;;
  start) shift; echo "start $* -> $(readlink "$root/bin/swamp")" >> "$root/events"
    for u in "$@"; do
      grep -qxF "$u" "$root/broken" 2>/dev/null && continue
      setsid "$root/bin/swamp" -c 'while :; do sleep 1; done' < /dev/null > /dev/null 2>&1 & echo $! > "$root/pids/$u"
    done ;;
  show) cat "$root/pids/\${@: -1}" 2>/dev/null || echo 0 ;;
esac
`;

async function pin(broken: string[] = []) {
  const root = Deno.makeTempDirSync({ prefix: "swamp-pin-" });
  for (const folder of ["bin", "stubs", "pids", "lib/tasks", "repo/swamp", "repo/apps/gateway/resources/machine-software"]) {
    Deno.mkdirSync(`${root}/${folder}`, { recursive: true });
  }
  // The release is a copy of bash: it runs under any name, so its process's exe is the installed file.
  const release = Deno.readFileSync("/bin/bash");
  const sha256 = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", release)), (b) => b.toString(16).padStart(2, "0")).join("");
  const old = "6e0fe06f".repeat(8);
  Deno.mkdirSync(`${root}/lib/${old}`);
  Deno.writeTextFileSync(`${root}/lib/${old}/swamp`, "old release");
  Deno.writeTextFileSync(`${root}/lib/tasks/renew.yml`, "---\n");
  Deno.writeTextFileSync(`${root}/lib/worker-renewal.yml`, "---\n");
  Deno.symlinkSync(`${root}/lib/${old}/swamp`, `${root}/bin/swamp`);
  Deno.writeTextFileSync(`${root}/broken`, broken.map((unit) => unit + "\n").join(""));
  Deno.writeTextFileSync(`${root}/stubs/systemctl`, systemctl(root), { mode: 0o755 });
  Deno.writeTextFileSync(`${root}/stubs/curl`, `#!/bin/sh\necho "ready $*" >> ${root}/events\n`, { mode: 0o755 });
  // Only the proxy knows the release's host.
  const fetched: string[] = [];
  const proxy = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, (request) => {
    fetched.push(request.url);
    return new URL(request.url).host === "swamp.example.test" ? new Response(release) : new Response("no", { status: 404 });
  });
  const url = "http://swamp.example.test/swamp-linux-x86_64";
  Deno.writeTextFileSync(`${root}/repo/apps/gateway/resources/machine-software/core.yaml`,
    JSON.stringify({ tools: { swamp: { version: "20261114.210101.0-sha.537e03d1", artifacts: { amd64: { url, sha256 } } } } }));
  Deno.writeTextFileSync(`${root}/play.json`, JSON.stringify([{
    hosts: "localhost", gather_facts: false,
    vars: { control: { repository: `${root}/repo/swamp` }, swamp_pin_root: `${root}/lib`, swamp_pin_link: `${root}/bin/swamp` },
    tasks: [{ "ansible.builtin.import_tasks": tasks }],
  }]));
  const proxyUrl = `http://127.0.0.1:${proxy.addr.port}`;
  const done = await new Deno.Command("ansible-playbook", {
    args: ["-i", "localhost,", "-c", "local", `${root}/play.json`],
    clearEnv: true,
    env: {
      PATH: `${root}/stubs:${Deno.env.get("PATH")}`, HOME: root, ANSIBLE_HOME: `${root}/ansible`, ANSIBLE_LOCAL_TEMP: `${root}/tmp`,
      ANSIBLE_NOCOLOR: "1", HTTP_PROXY: proxyUrl, http_proxy: proxyUrl, NO_PROXY: "localhost,127.0.0.1", no_proxy: "localhost,127.0.0.1",
    },
  }).output();
  await proxy.shutdown();
  const running = units.map((unit) => {
    try {
      return Deno.realPathSync(`/proc/${Deno.readTextFileSync(`${root}/pids/${unit}`).trim()}/exe`);
    } catch {
      return "";
    }
  });
  for (const unit of units) {
    try {
      Deno.kill(-Number(Deno.readTextFileSync(`${root}/pids/${unit}`).trim()), "SIGKILL");
    } catch { /* not started */ }
  }
  const events = Deno.readTextFileSync(`${root}/events`).trim().split("\n");
  const listing = (folder: string) => [...Deno.readDirSync(folder)].map((entry) => entry.name).sort();
  const state = { link: Deno.readLinkSync(`${root}/bin/swamp`), lib: listing(`${root}/lib`) };
  Deno.removeSync(root, { recursive: true });
  return { ok: done.success, output: decoder.decode(done.stdout) + decoder.decode(done.stderr), root, sha256, old, url, fetched, running, events, state };
}

Deno.test({
  name: "the swamp-pin play installs the release through the proxy, restarts Swamp on it in order, checks it and removes the old release",
  ignore: !hasAnsible,
  async fn() {
    const run = await pin();
    assert(run.ok, run.output);
    assertEquals(run.fetched, [run.url]);
    const binary = `${run.root}/lib/${run.sha256}/swamp`;
    // Workers and serve stop on the old link; serve starts on the new one, the workers only after `/ready`.
    assertEquals(run.events, [
      `stop ${workers} orbit-swamp-serve.service -> ${run.root}/lib/${run.old}/swamp`,
      `start orbit-swamp-serve.service -> ${binary}`,
      "ready -sf -o /dev/null --noproxy * -m 2 --retry 60 --retry-delay 1 --retry-connrefused --retry-all-errors http://127.0.0.1:9418/ready",
      `start ${workers} -> ${binary}`,
    ]);
    assertEquals(run.running, [binary, binary, binary, binary]);
    assertEquals(run.state, { link: binary, lib: [run.sha256, "tasks", "worker-renewal.yml"] });
  },
});

Deno.test({
  name: "the swamp-pin play fails when a worker runs no process, and keeps the old release",
  ignore: !hasAnsible,
  async fn() {
    const run = await pin(["orbit-swamp-worker@b.service"]);
    assert(!run.ok, run.output);
    assertStringIncludes(run.output, "Swamp runs ");
    assertEquals(run.state.lib, [run.old, run.sha256, "tasks", "worker-renewal.yml"].sort());
  },
});
