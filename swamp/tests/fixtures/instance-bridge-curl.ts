/** Incus HTTP boundary: real ETag conditional writes, including transient and persistent failures. No host calls. */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = Deno.args;
const method = args.includes("PUT") ? "PUT" : "GET";
const name = args.find((arg) => arg.startsWith("http://"))!.split("/network-acls/")[1].split("?")[0];
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
