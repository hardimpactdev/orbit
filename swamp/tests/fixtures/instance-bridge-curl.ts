/** Incus HTTP boundary: real ETag conditional writes, including transient and persistent failures. No host calls. Also the wake's web check from the Node. */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = Deno.args;
const url = args.find((arg) => arg.startsWith("http://"))!;
// The wake's readiness check from the Node to the container's address and web port: the container of the run's intent answers as
// FrankenPHP does in the Incus fixture: `web` lists the status of each answer in turn and keeps its last; none means it does not listen.
if (!url.includes("/network-acls/")) {
  Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(["curl", ...args]) + "\n", {append: true});
  const container = JSON.parse(Deno.readTextFileSync(`${folder}/run/intent.json`)).container;
  const live = url === `http://${container.address}:${container.web_port}/` ? state.containers[container.name] : undefined;
  const web: number[] = live?.web ?? [];
  if (web.length === 0 || live.silent_until_boot || live.status !== "Running") Deno.exit(7);
  const status = web.length > 1 ? web.shift()! : web[0];
  Deno.writeTextFileSync(file, JSON.stringify(state));
  Deno.stdout.writeSync(new TextEncoder().encode(String(status)));
  Deno.exit(0);
}
const method = args.includes("PUT") ? "PUT" : "GET";
const name = url.split("/network-acls/")[1].split("?")[0];
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(["curl", method, name]) + "\n", {append: true});
const row = state["network-acls"].find((acl: {name: string}) => acl.name === name);
const send = (metadata: unknown, error = "", code = 0) => console.log(`HTTP/1.1 ${code || 200} response\r\nETag: "${state.aclVersion ?? 0}"\r\n\r\n` + JSON.stringify({metadata, error, error_code: code}));
const save = () => Deno.writeTextFileSync(file, JSON.stringify(state));
if (method === "PUT") {
  const value = JSON.parse(await new Response(Deno.stdin.readable).text());
  let error = "";
  if (state.etagConflicts > 0) {
    state.etagConflicts--;
    state.aclVersion = (state.aclVersion ?? 0) + 1;
    if (state.concurrentEndpoint) {
      row.egress.push(state.concurrentEndpoint);
      delete state.concurrentEndpoint;
    }
    error = "412 Precondition Failed: ETag mismatch";
  } else if (state.bridgeVethFailures > 0) {
    state.bridgeVethFailures--;
    error = 'Failed updating bridge NIC ACL: Unknown or missing host side veth device ""';
  } else if (!args.includes(`If-Match: "${state.aclVersion ?? 0}"`)) error = "412 ETag mismatch";
  if (error) {
    save(); send({}, error, error.includes("veth") ? 400 : 412); console.error(error); Deno.exit(22);
  }
  Object.assign(row, value);
  state.aclVersion = (state.aclVersion ?? 0) + 1;
  save();
  send({});
} else send(row);
