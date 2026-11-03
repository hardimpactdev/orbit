/** Native Route workflow fixtures: fake SSH records argv or runs it against disposable local files. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { type History, type NativeRepo, nativeRepo, ROOT } from "./native_harness.ts";
import { readCalls } from "./fake_tool.ts";
const TOOL = new URL("./fake_tool.ts", import.meta.url).href;
const PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";
export const exists = (path: string) => {
  try {
    Deno.lstatSync(path);
    return true;
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) return false;
    throw e;
  }
};
export const sha256 = async (text: string) =>
  Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
type Step = History["jobs"][number]["steps"][number] & { startedAt: string; completedAt: string };
export async function run(repo: NativeRepo, name: string, intent: unknown, kind: string, success = true, secrets = {}) {
  await repo.cli(["workflow", "validate", name]);
  const result = await repo.runWorkflow(name, {
    operation_id: 71,
    node_id: 7,
    kind,
    execution_secrets: secrets,
    intent,
  }, { success });
  assert(!JSON.stringify(result.history).includes("No such key"));
  const history = result.history;
  return {
    ...result,
    history,
    steps: Object.fromEntries(history.jobs.flatMap((j) => j.steps).map((s) => [s.name, s as Step])),
  };
}
function patchStrings<T>(value: T, replacements: [string, string][]): T {
  if (typeof value === "string") {
    return replacements.reduce<string>((v, [from, to]) => v.replaceAll(from, to), value) as T;
  }
  if (Array.isArray(value)) return value.map((v) => patchStrings(v, replacements)) as T;
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, patchStrings(v, replacements)])) as T;
  }
  return value;
}
export async function chart(name: string, names: string[]) {
  const flow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as History;
  assertEquals(flow.jobs[0].steps.map((s) => s.name), names);
}
export function skipped(steps: Record<string, Step>, prefix: string) {
  assertEquals([...new Set(Object.entries(steps).filter(([n]) => n.startsWith(prefix)).map(([, s]) => s.status))], ["skipped"]);
}
const SITE =
  "acl links php_files php_tests php_reloads caddy url_lines env_files trust lan leaf_indexes leaves site_nodes".split(
    " ",
  );
export async function routeFixture(kind: "create" | "update" | "target-set" | "removal" | "cluster") {
  const repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  await repo.copyBehaviors("operations/certificates");
  await repo.pull("@swamp/ssh");
  // Probe failures stay at the tool boundary, without opening even a loopback TLS connection.
  await repo.fakeBinary("openssl", "Deno.exit(1);");
  repo.env.PATH = `${repo.dir}:${Deno.env.get("PATH")}`;
  const identity = `${repo.dir}/identity`;
  await Deno.writeTextFile(identity, "synthetic identity", { mode: 0o600 });
  const nodes = Object.fromEntries(
    [5, 7, 8, 9].map(
      (n) => [String(n), {
        host: `node-${n}.invalid`,
        user: "orbit",
        port: 22,
        identity_file: identity,
        host_keys: [PIN],
        account: "orbit",
      }],
    ),
  );
  const empty = (keys: string[]) => Object.fromEntries(keys.map((k) => [k, []]));
  const phase = () => ({ ...empty(SITE), root_certificate: "", nodes });
  // The admitted plans deliberately keep cfgmgmt lists empty; only SSH exec effects run here.
  // deno-lint-ignore no-explicit-any
  const intent: Record<string, any> = kind === "create"
    ? {
      route_id: 14,
      domain: "shop.test",
      root_certificate: "",
      nodes,
      ...empty([...SITE, "ingress_ports", "probes", "ingress", "dns"]),
    }
    : {
      nodes,
      ...empty(
        "leaves leaf_indexes probes dns grace ingress ingress_ports edge_nodes cleanup lan_absent retire_nodes".split(
          " ",
        ),
      ),
      publish: phase(),
      app_url: phase(),
      retire: phase(),
      stage: phase(),
      router: phase(),
      offline: false,
      answer_nodes: [],
      answer_index: {},
      pools: phase(),
      lan_remove: [],
      ingress_close: [],
      firewall_nodes: [],
      check_nodes: [],
      root_certificate: "",
      candidate: [],
      lan_present: [],
      select: [],
      restore: phase(),
      restore_lan: [],
      restore_dns_plan: { dns: [], nodes },
    };
  // Root schemas reject unrelated fields, so retain only each family's captured intent.
  const fields: Record<string, string> = {
    update:
      "leaves leaf_indexes probes dns grace ingress ingress_ports edge_nodes cleanup lan_absent retire_nodes nodes publish app_url retire",
    "target-set": "leaves leaf_indexes probes dns cleanup lan_absent retire_nodes nodes publish stage router retire",
    removal:
      "offline nodes answer_nodes answer_index dns grace retire cleanup lan_absent retire_nodes pools lan_remove ingress_close firewall_nodes check_nodes",
    cluster:
      "leaves leaf_indexes root_certificate nodes candidate lan_present publish probes dns grace select retire cleanup lan_absent retire_nodes restore restore_lan restore_dns_plan lan_remove ingress_close firewall_nodes check_nodes",
  };
  if (kind !== "create") {
    for (const key of Object.keys(intent)) if (!fields[kind].split(" ").includes(key)) delete intent[key];
  }
  const names = [
    "route-create-csr",
    "route-create-sign",
    "route-create-site",
    "route-create-ingress",
    "route-create-dns",
    "route-target-retire",
    "route-target-stage",
    "route-certificate-remove",
    "route-dns-grace",
    "route-remove-firewall",
    "route-remove-check",
    "cluster-router-prepare",
    "cluster-router-probe",
    "cluster-router-select",
    "cluster-router-revert",
    ...(kind === "cluster"
      ? ["operation-cluster-router", "operation-cluster-router-unset"]
      : [`operation-route-${kind}`]),
  ];
  const log = `${repo.dir}/calls.jsonl`;
  const perform = async (code = 0, success = true, down = "", unset = false) => {
    const ssh = await repo.fakeBinary(
      "ssh",
      `import { shellSplit, logCall } from ${JSON.stringify(TOOL)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
logCall(${JSON.stringify(log)}, shellSplit(Deno.args.at(-1)!));
Deno.exit(${JSON.stringify(down)} && Deno.args.some(a => a.includes(${JSON.stringify(down)})) ? 255 : ${code});`,
    );
    for (const name of names) {
      await repo.workflow(name, (flow) => {
        for (const job of flow.jobs) {
          for (const step of job.steps) {
            const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
            if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: ssh });
          }
        }
      });
    }
    const name = kind === "cluster" ? "operation-cluster-router" + (unset ? "-unset" : "") : `operation-route-${kind}`;
    const action = kind === "cluster"
      ? "cluster.router_" + (unset ? "unset" : "set")
      : "route." + (kind === "removal" ? "destroy" : kind.replaceAll("-", "_"));
    return { ...await run(repo, name, intent, action, success), calls: readCalls<string[]>(log) };
  };
  const pools = (part: string, ...ids: number[]) => {
    intent[part].site_nodes = ids;
    intent[part].php_tests = ids.map((node_id) => ({ node_id, argv: ["/usr/sbin/php-fpm8.5", "-t"] }));
  };
  return {
    repo,
    intent,
    perform,
    pools,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}

export const LOCAL_PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB";
async function localFixture(families: string[] = []) {
  const repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  if (families.length) await repo.copyBehaviors(...families);
  if (families.includes("operations/routes/publication-command")) {
    await Deno.copyFile(
      `${ROOT}/extensions/behaviors/operations/routes/caddy-input.ts`,
      `${repo.dir}/extensions/behaviors/operations/routes/caddy-input.ts`,
    );
  }
  const tools = `${repo.dir}/ssh-tools`;
  await Deno.mkdir(tools);
  repo.env.PATH = `${tools}:${Deno.env.get("PATH")}`;
  const ssh = await repo.fakeBinary(
    "ssh-tools/ssh",
    `import {shellSplit,passThrough,readStdinBytes} from ${JSON.stringify(TOOL)};
const args = Deno.args;
if (args.includes("-M") || args.includes("-O")) Deno.exit(0);
if (Deno.env.get("FIXTURE_REQUIRE_PIN") === "1" && !(Deno.env.get("FIXTURE_ALLOW_CFGMGMT") === "1" && args.includes("StrictHostKeyChecking=accept-new"))) {
  const known = args.find(a => a.startsWith("UserKnownHostsFile="))?.split("=").slice(1).join("=");
  if (!args.includes("StrictHostKeyChecking=yes") || !known || !Deno.readTextFileSync(known).includes(${
      JSON.stringify(LOCAL_PIN)
    })) throw new Error("admitted pin required");
}
if (Deno.env.get("FIXTURE_ALLOW_CFGMGMT") === "1" && args.includes("StrictHostKeyChecking=accept-new")) {
  const command = args.at(-1)!;
  if (command.includes("/etc/") || command.includes("sudo")) throw new Error("Fixture refused privileged cfgmgmt command");
  await passThrough(["/bin/sh", "-c", command], await readStdinBytes());
  Deno.exit(0);
}
let argv = shellSplit(args.at(-1)!);
if (argv.slice(0,3).join(" ") === "sudo -n --") argv = argv.slice(3);
const sudo = argv.indexOf("sudo");
if (sudo >= 0) {
  const end = argv.indexOf("--", sudo);
  if (end >= 0) argv.splice(sudo, end - sudo + 1);
  else if (argv[sudo + 1] === "-u") argv.splice(sudo, 3);
  else argv.splice(sudo, 1);
}
if (argv.slice(0,2).join(" ") === "sh -c" && shellSplit(argv.at(-1)!)[0] === "/usr/bin/install") argv = shellSplit(argv.at(-1)!);
if (argv.includes("/bin/sh") || argv.includes("sh")) {
  argv[argv.length-1] = argv.at(-1)!.replace(/sudo --preserve-env=[A-Z_,]+ /g, "").replaceAll("sudo -u caddy ", "").replace(/sudo /g, "");
  if (argv.includes("/bin/sh")) argv[argv.indexOf("-c")+1] = argv[argv.indexOf("-c")+1].replace(/ -o root -g (?:root|caddy)/g, "");
}
if (argv.some(a => a.startsWith("/etc/"))) throw new Error("Fixture refused a real /etc path");
if (argv[0] === "sudo") throw new Error("Fixture refused real sudo");
await passThrough(argv,await readStdinBytes());
`,
  );
  const scp = await repo.fakeBinary(
    "ssh-tools/scp",
    `const [src,remote] = Deno.args.slice(-2);
const dst = remote.split(":").slice(1).join(":");
const copy = (s: string,d: string) => {if (Deno.statSync(s).isDirectory) { Deno.mkdirSync(d,{recursive:true}); for (const e of Deno.readDirSync(s)) copy(s+"/"+e.name,d+"/"+e.name); } else Deno.copyFileSync(s,d);};
copy(src,Deno.statSync(src).isDirectory ? dst+"/"+src.split("/").at(-1) : dst);`,
  );
  const userOut = await new Deno.Command("id", { args: ["-un"] }).output();
  const user = new TextDecoder().decode(userOut.stdout).trim();
  const connection = {
    nodeHost: "fixture",
    nodeUser: user,
    nodePort: 22,
    nodeIdentityFile: "/fixture/existing-key",
    become: true,
  };
  const replacements: [string, string][] = [["/tmp/orbit-route-", `${repo.dir}/remote-`]];
  const copyWorkflow = async (
    name: string,
    extra: [string, string][] = [],
    edit: (step: Record<string, unknown>) => void = () => {},
  ) => {
    await repo.workflow(name, (flow) => {
      Object.assign(flow, patchStrings(flow, [...replacements, ...extra]));
      for (const job of flow.jobs) {
        for (const step of job.steps) {
          delete step.target;
          edit(step);
          const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
          if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: scp });
        }
      }
    });
  };
  return {
    repo,
    tools,
    user,
    connection,
    copyWorkflow,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}

export async function firewallFixture() {
  const f = await localFixture([
    "operations/routes/mutation-command",
    "operations/routes/publication-command",
    "operations/routes/update-command",
  ]);
  const { repo } = f;
  const state = `${repo.dir}/ufw-state.json`, audit = `${repo.dir}/ufw-audit.jsonl`;
  Object.assign(repo.env, { FIXTURE_REQUIRE_PIN: "1", FIXTURE_UFW_STATE: state, FIXTURE_UFW_AUDIT: audit });
  const ufw = await repo.fakeBinary(
    "ssh-tools/ufw",
    `import {logCall} from ${JSON.stringify(TOOL)};
const path = Deno.env.get("FIXTURE_UFW_STATE")!;
// UFW serializes transactions; flock does the same at this fake executable boundary.
if (Deno.env.get("FIXTURE_UFW_LOCKED") !== "1") {
  const child = new Deno.Command("flock",{args:[path+".lock",Deno.execPath(),"run","-A","--quiet","--no-config","--no-lock",import.meta.filename!,...Deno.args],env:{FIXTURE_UFW_LOCKED:"1"},stdout:"inherit",stderr:"inherit"}).spawn();
  Deno.exit((await child.status).code);
}
const state = JSON.parse(Deno.readTextFileSync(path)), args = Deno.args;
logCall(Deno.env.get("FIXTURE_UFW_AUDIT")!,args);
if (args[0] === "status") {
  console.log("Status: "+(state.backend ?? "active"));
  const rows = [...state.tuples.map((r: {port:number;protocol:string;source:string}) => r.port+"/"+r.protocol+" ALLOW IN "+r.source),...state.legacy];
  rows.forEach((r,i) => console.log((args.includes("numbered") ? "["+(i+1)+"] " : "")+r));
} else if (args[0] === "allow" || args.slice(0,2).join(" ") === "delete allow") {
  if (state.fail_apply) Deno.exit(1);
  if (state.ignore_apply) Deno.exit(0);
  const row = {port:Number(args[args.indexOf("port")+1]),protocol:args[args.indexOf("proto")+1],source:args[args.indexOf("from")+1].replace("any","Anywhere")};
  const equal = (r: unknown) => JSON.stringify(r) === JSON.stringify(row);
  if (args[0] === "delete") state.tuples = state.tuples.filter((r: unknown) => !equal(r));
  else if (!state.tuples.some(equal)) state.tuples.push(row);
  Deno.writeTextFileSync(path,JSON.stringify(state));
} else throw new Error("unexpected UFW argv: "+args);
`,
  );
  for (const name of ["route-lan-apply", "route-ingress-apply"]) {
    await f.copyWorkflow(name, [["/usr/sbin/ufw", ufw]]);
  }
  const tuple = { port: 443, protocol: "tcp", source: "192.168.6.2" };
  const initialize = (tuples: unknown[] = [], legacy: string[] = [], extra = {}) =>
    Deno.writeTextFile(state, JSON.stringify({ tuples, legacy, ...extra }));
  const readState = () => JSON.parse(Deno.readTextFileSync(state));
  const lanIntent = (options: { ensure?: string; rules?: unknown[]; pins?: string[] } = {}) => {
    const rule = {
      node_id: 9,
      rules: options.rules ?? [{ ...tuple, ensure: options.ensure ?? "present", model_name: "r1" }],
    };
    return {
      control_node_id: 209,
      connection: f.connection,
      host_keys: options.pins ?? [LOCAL_PIN],
      rule,
    };
  };
  const lan = (options: Parameters<typeof lanIntent>[0] & { success?: boolean } = {}) =>
    run(
      repo,
      "route-lan-apply",
      lanIntent(options),
      "route.destroy",
      options.success ?? true,
    );
  const refused = () =>
    repo.cli([
      "workflow",
      "run",
      "route-lan-apply",
      "--input",
      JSON.stringify({
        operation_id: 1,
        node_id: 9,
        kind: "route.destroy",
        execution_secrets: {},
        intent: lanIntent({ pins: [] }),
      }),
    ], { success: false });
  await initialize();
  return { ...f, state, audit, tuple, initialize, readState, lan, refused, calls: () => readCalls<string[]>(audit) };
}

export async function publicationFixture() {
  const f = await localFixture(["operations/routes/publication-command", "operations/routes/mutation-command"]);
  const { repo } = f;
  const group = new TextDecoder().decode((await new Deno.Command("id", { args: ["-gn"] }).output()).stdout).trim();
  const host = `${repo.dir}/host`, commands = `${repo.dir}/commands`, audit = `${repo.dir}/audit`;
  await Deno.mkdir(`${host}/php/8.4/fpm/pool.d`, { recursive: true });
  await Deno.mkdir(commands);
  repo.env.FIXTURE_AUDIT = audit;
  const toolSource = `import {logCall} from ${JSON.stringify(TOOL)};
const name = import.meta.filename.split("/").at(-1);
const audit = Deno.env.get("FIXTURE_AUDIT");
logCall(audit,[name,...Deno.args]);
const fail = Deno.env.get("FIXTURE_FAIL");
if (["caddy","php-fpm8.4","dnsmasq"].includes(name) && fail === "validate") Deno.exit(1);
if (name === "systemctl" && fail === "reload") Deno.exit(1);
if (name === "systemctl" && Deno.args[0] === "reload" && Deno.env.get("FIXTURE_CADDY_DRIFT")) {
  Deno.writeTextFileSync(Deno.env.get("FIXTURE_CADDY_DRIFT")!, "# unexpected drift", { append: true });
}
if (name === "systemctl" && Deno.args.join(" ") === "restart dnsmasq" && fail === "backend") {
  try { Deno.statSync(audit+".failed"); } catch {Deno.writeTextFileSync(audit+".failed","failed once");Deno.exit(1);}
}
`;
  for (const name of ["caddy", "systemctl", "php-fpm8.4", "php-fpm8.5", "dnsmasq"]) {
    await repo.fakeBinary(`commands/${name}`, toolSource);
  }
  const live = `${host}/Caddyfile`, php = `${host}/php/8.4/fpm/pool.d/orbit-scopes.conf`;
  const records = `${host}/records`, catalog = `${host}/catalog`;
  const replacements: [string, string][] = [
    ["/etc/caddy/Caddyfile", live],
    ["/etc/php/", `${host}/php/`],
    ["/usr/bin/caddy", `${commands}/caddy`],
    ["/usr/sbin/php-fpm", `${commands}/php-fpm`],
    ["/usr/bin/systemctl", `${commands}/systemctl`],
    ["/etc/dnsmasq.d/orbit-records.conf", records],
    ["/var/lib/orbit/private-dns/catalog.json", catalog],
    ["/var/lib/orbit/private-dns", host],
    ["/usr/sbin/dnsmasq", `${commands}/dnsmasq`],
  ];
  for (
    const name of [
      "operation-route-caddy",
      "operation-route-caddy-delta",
      "operation-route-php-delta",
      "route-dns-publish",
      "operation-route-publication",
    ]
  ) {
    await f.copyWorkflow(name, replacements, (step) => {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (name === "operation-route-caddy" && task.behaviorType === "@swamp/cfgmgmt/file") {
        Object.assign(task.globalArgs!, { owner: f.user, group, become: false });
      }
      if (name === "operation-route-php-delta" && step.name === "apply-pool") {
        // The behavior composes /etc/php paths; redirect only its destination into the fixture host.
        const { argv } = (step.task as { inputs: { argv: string[] } }).inputs;
        argv[argv.length - 1] = "${{ '" + host + "/php/' + self.pool.version + '/fpm/pool.d/orbit-scopes.conf' }}";
      }
    });
  }

  const delta = (family: string, before: unknown, after: unknown, direction = "forward", success = true) =>
    run(
      repo,
      `operation-route-${family}-delta`,
      {
        node_id: 9,
        user: f.user,
        control_node_id: 209,
        host_keys: [LOCAL_PIN],
        connection: f.connection,
        before,
        after,
        direction,
      },
      "instance.transfer",
      success,
    );
  return {
    ...f,
    host,
    commands,
    audit,
    live,
    php,
    records,
    catalog,
    toolSource,
    delta,
    calls: () => readCalls<string[]>(audit),
  };
}
