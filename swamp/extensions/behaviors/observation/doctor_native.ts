import { readNode } from "./doctor_native_node.ts";
import type { NodeIntent, Row } from "./doctor_contract.ts";
import { readApplications } from "./doctor_native_applications.ts";
import { readInfrastructure } from "./doctor_native_infrastructure.ts";
import { readRuntime } from "./doctor_native_runtime.ts";

export type NativeReadCall = { name: string; input: Row; node: NodeIntent };
type Local = (argv: string[]) => Promise<string>;
const component = (value: unknown): string => {
  if (
    typeof value !== "string" ||
    !/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/.test(value)
  ) {
    throw new Error("doctor.input_invalid");
  }
  return value;
};
export async function readDoctor(
  call: NativeReadCall,
  local: Local,
  signal?: AbortSignal,
): Promise<Row> {
  signal?.throwIfAborted();
  const { name, input, node } = call;
  if (name === "doctor.inspect-node") return await readNode(node, local);
  if (name === "account.lookup") {
    const user = component(input.user);
    return JSON.parse(
      await local([
        "python3",
        "-c",
        "import pwd,grp,json,sys; p=pwd.getpwnam(sys.argv[1]); print(json.dumps(dict(user=p.pw_name,uid=p.pw_uid,group=grp.getgrgid(p.pw_gid).gr_name,home=p.pw_dir,shell=p.pw_shell)))",
        user,
      ]),
    );
  }
  if (name.startsWith("incus.")) return await readIncus(name, input, local);
  const observations = { ...(node.observations as Row ?? {}), node };
  for (
    const read of [
      () => readApplications(name, input, local),
      () => readRuntime(name, input, local, observations),
      () => readInfrastructure(name, input, local),
    ]
  ) {
    const result = await read();
    if (result !== undefined) return result;
  }
  throw new Error("doctor.read_unavailable");
}
async function readIncus(name: string, input: Row, local: Local): Promise<Row> {
  const run = (...args: string[]) => local(["sudo", "-n", "incus", ...args]);
  const json = async (...args: string[]): Promise<Row[]> => {
    const value = JSON.parse(await run(...args));
    if (!Array.isArray(value)) throw new Error("doctor.incus_output_invalid");
    return value;
  };
  if (name === "incus.instances") {
    const project = component(input.project);
    await run("--project", project, "project", "show", "--", project);
    const names = (await run(
      "--project",
      project,
      "list",
      "--format",
      "csv",
      "--columns",
      "n",
    )).trim().split("\n").filter(Boolean).map(component);
    return { names };
  }
  if (name === "incus.images") {
    const images: Record<string, string[]> = {};
    for (
      const row of await json(
        "--project",
        component(input.project),
        "image",
        "list",
        "--format",
        "json",
      )
    ) {
      if (
        typeof row.fingerprint !== "string" ||
        !/^[a-f0-9]{64}$/.test(row.fingerprint) || row.fingerprint in images ||
        !Array.isArray(row.aliases)
      ) throw new Error("doctor.incus_output_invalid");
      images[row.fingerprint] = row.aliases.map((a) => component(a.name));
    }
    return { images };
  }
  if (name !== "incus.inventory") throw new Error("doctor.read_unavailable");
  const urls = async (path: string): Promise<string[]> => {
    const value = JSON.parse(await run("query", "--", path));
    if (!Array.isArray(value) || !value.every((v) => typeof v === "string")) {
      throw new Error("doctor.incus_output_invalid");
    }
    return value;
  };
  const resource = (url: string, prefix: string) => {
    const parsed = new URL(url, "http://incus.local");
    if (
      !parsed.pathname.startsWith(prefix) ||
      [...parsed.searchParams.keys()].some((k) => k.startsWith("project["))
    ) throw new Error("doctor.incus_output_invalid");
    return {
      name: component(decodeURIComponent(parsed.pathname.slice(prefix.length))),
      project: component(parsed.searchParams.get("project") ?? "default"),
    };
  };
  const instances = (await urls("/1.0/instances?all-projects=true")).map(
    (url) => resource(url, "/1.0/instances/"),
  );
  const volumes = [];
  for (const url of await urls("/1.0/storage-pools")) {
    const pool = resource(url, "/1.0/storage-pools/").name;
    const prefix = `/1.0/storage-pools/${
      encodeURIComponent(pool)
    }/volumes/custom/`;
    for (const item of await urls(`${prefix.slice(0, -1)}?all-projects=true`)) {
      volumes.push({ ...resource(item, prefix), pool });
    }
  }
  return { instances, volumes };
}
