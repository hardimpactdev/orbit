/** GitHub HTTP and bare Git effect boundaries; publication and CI use the tracked DAGs. */
import {
  checkResult,
  copyCheck,
  git,
  type Json,
  type Repository,
  repository,
  sha256,
  tool,
  write,
} from "./task_repository.ts";

export async function publication() {
  const t = await repository();
  const { repo, workspace, inputs } = t;
  const token = "synthetic-publication-token-" + "no-real-credential";
  const authorization = "AUTHORIZATION: basic " +
    btoa("x-access-token:" + token);
  await write(`${workspace}/code`, "one\n");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Fixture");
  const head = await t.git("rev-parse", "HEAD"),
    tree = await t.git("rev-parse", "HEAD^{tree}");
  const remote = `${repo.dir}/remote.git`;
  await git(repo.dir, "init", "--bare", "-q", remote);
  const api = {
    calls: [] as [string, string, unknown][],
    pulls: [] as unknown,
    loseReply: false,
    mints: 0,
    mintCopies: {} as Json,
    pull: {
      number: 12,
      state: "open",
      head: {
        ref: "task-1",
        sha: head,
        repo: { full_name: "acme/shop" },
      },
      base: { ref: "main" },
    },
  };
  const server = Deno.serve({
    hostname: "127.0.0.1",
    port: 0,
    onListen: () => {},
  }, async (request) => {
    const url = new URL(request.url);
    const path = url.pathname + url.search;
    if (request.method === "POST" && path.endsWith("/github-token")) {
      await request.text();
      for (
        const [name, ref] of Object.entries(api.mintCopies) as [
          string,
          Json,
        ][]
      ) {
        await repo.cli(["vault", "put", ref.vault, ref.key], {
          input: name === "git_authorization" ? authorization : token,
        });
      }
      api.mints++;
      return Response.json({
        data: { execution_secrets: api.mintCopies },
      });
    }
    if (request.headers.get("Authorization") !== "Bearer " + token) {
      return new Response(null, { status: 401 });
    }
    const raw = await request.text();
    api.calls.push([request.method, path, raw ? JSON.parse(raw) : null]);
    if (request.method === "POST") {
      api.pulls = [api.pull];
      if (api.loseReply) {
        return new Response("lost reply", { status: 503 });
      }
    }
    if (request.method === "DELETE") {
      return new Response(null, { status: 204 });
    }
    return Response.json(
      request.method === "GET" ? api.pulls : { number: 12 },
    );
  });
  const origin = `http://127.0.0.1:${server.addr.port}`;
  const realGit = new TextDecoder().decode(
    (await new Deno.Command("which", { args: ["git"] }).output()).stdout,
  ).trim();
  await repo.fakeBinary(
    "tools/git",
    `import {passThrough,readStdinBytes} from ${JSON.stringify(tool)};
const args=[...Deno.args],url="https://github.com/acme/shop.git";
if(args.includes(url)){
const a=Deno.env.get("GIT_CONFIG_VALUE_3")??"";
const digest=Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256",new TextEncoder().encode(a))),b=>b.toString(16).padStart(2,"0")).join("");
if(digest!==${JSON.stringify(await sha256(authorization))}||args.join(" ").includes(a))Deno.exit(97);
for(let i=0;i<args.length;i++)if(args[i]===url)args[i]=${JSON.stringify(remote)};
Deno.env.set("GIT_ALLOW_PROTOCOL","file");}
await passThrough([${JSON.stringify(realGit)},...args],await readStdinBytes());`,
  );
  await repo.fakeBinary(
    "tools/gh",
    `import {readStdin} from ${JSON.stringify(tool)};
const args=Deno.args,token=Deno.env.get("GH_TOKEN");if(args.join(" ").includes(token))throw Error("token in argv");
if(args.slice(0,2).join(" ")==="auth git-credential"){await readStdin();console.log("protocol=https\\nhost=github.com\\nusername=x-access-token\\npassword="+token);Deno.exit(0);}
if(args[0]!=="api")throw Error("api expected");
let method="GET",body,path;const fields=[];
for(let i=1;i<args.length;i++){if(args[i]==="--method")method=args[++i];else if(args[i]==="-f"){const field=args[++i],j=field.indexOf("=");fields.push([field.slice(0,j),field.slice(j+1)]);}else if(args[i]==="--input"){const file=args[++i];body=file==="-"?await readStdin():await Deno.readTextFile(file);}else path=args[i];}
let url=${
      JSON.stringify(origin)
    }+"/"+path.replace(/^\\//,"");if(fields.length)url+=(url.includes("?")?"&":"?")+new URLSearchParams(fields);
const response=await fetch(url,{method,body,headers:{Authorization:"Bearer "+token}});if(!response.ok){console.error("HTTP "+response.status);Deno.exit(1);}console.log(await response.text());`,
  );
  await repo.fakeBinary(
    "tools/curl",
    `const args=Deno.args;const response=await fetch(args.at(-1).replace("https://gateway.orbit",${
      JSON.stringify(origin)
    }),{method:"POST",body:args[args.indexOf("--data")+1]});if(!response.ok)Deno.exit(22);console.log(await response.text());`,
  );
  await repo.cli([
    "vault",
    "create",
    "local_encryption",
    "orbit-control-inputs",
  ]);
  const refs = {
    github_token: {
      vault: "orbit-control-inputs",
      key: "task-publish-token-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    },
    git_authorization: {
      vault: "orbit-control-inputs",
      key: "task-publish-token-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
    },
  };
  for (const [name, ref] of Object.entries(refs)) {
    await repo.cli(["vault", "put", ref.vault, ref.key], {
      input: name === "github_token" ? token : authorization,
    });
  }
  repo.secrets = [token, authorization];
  Object.assign(inputs, {
    operation_id: 15,
    node_id: 30,
    kind: "tasks.publish",
    execution_secrets: refs,
  });
  Object.assign(inputs.intent, {
    node_id: 30,
    check: 4,
    commit: head,
    tree,
    repository: "acme/shop",
    base: "main",
    branch: "task-1",
    remote: "https://github.com/acme/shop.git",
    refspec: head + ":refs/heads/task-1",
    object_format: "sha1",
    pull_head: "acme:task-1",
    pull_request: { title: "Export", body: "User prose $(touch inert)." },
  });
  delete inputs.intent.subtask;
  delete inputs.intent.ssr_port;
  Object.assign(inputs.intent.ssh_execution, {
    control_node_id: 209,
    public_key: "synthetic-public-key",
  });
  Object.assign(inputs.intent.ssh_execution.target, {
    name: "target",
    host: "192.0.2.30",
  });
  await t.flow(
    "operation-task-publish",
    (flow) =>
      Object.assign(
        flow,
        JSON.parse(
          JSON.stringify(flow).replaceAll(
            "/tmp/orbit-task-publish-",
            `${repo.dir}/publish-`,
          ).replaceAll(
            "/tmp/orbit-task-check-",
            `${repo.dir}/check-`,
          ),
        ),
      ),
  );
  return {
    ...t,
    token,
    authorization,
    head,
    remote,
    api,
    async publish(success = true) {
      await repo.cli(["workflow", "validate", "operation-task-publish"]);
      const r = await t.run("operation-task-publish", inputs, success);
      let result;
      if (r.history.status !== "succeeded") {
        result = {
          error_code: Object.values(r.steps).find((s: Json) => s.status === "failed")?.error,
        };
      } else {result = inputs.intent.pull_request === null
          ? { pull_request_url: null, pull_request_number: null }
          : JSON.parse(r.steps["Read PR result"].outputs.stdout);}
      return { ...r, result };
    },
    async [Symbol.asyncDispose]() {
      await server.shutdown();
      await t[Symbol.asyncDispose]();
    },
  };
}
export async function checkCI() {
  const t = await publication();
  const { inputs } = t;
  inputs.kind = "tasks.check";
  Object.assign(inputs.intent, {
    subtask: 4,
    check_id: 5,
    phase: "handoff",
    command: "test -f code",
    setup: [],
    deliverables: [],
    base: t.head,
    message: "Task check",
    check_name: "Required checks",
    branch: "task-1-handoff",
    ssr_port: null,
    admitted_at: new Date().toISOString(),
    command_deliverables: [],
  });
  inputs.execution_secrets = {};
  for (
    const [name, level] of [["github_token", "write"], [
      "checks_token",
      "read",
    ], ["git_authorization", "git"]]
  ) {
    inputs.execution_secrets[name] = {
      vault: "orbit-control-inputs",
      key: `task-check-${level}-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa`,
    };
  }
  inputs.intent.execution_references_json = JSON.stringify(
    inputs.execution_secrets,
  );
  t.api.mintCopies = inputs.execution_secrets;
  await copyCheck(t as Repository);
  await t.flow("task-check-ci");
  return {
    ...t,
    async execute(
      name = "operation-task-check",
      envelope: Json = inputs,
      success = true,
    ) {
      const r = await t.run(name, envelope, success);
      return { ...r, result: checkResult(r.history) };
    },
    reading(status = "in_progress", conclusion: string | null = null) {
      t.api.pulls = {
        total_count: 1,
        check_runs: [{
          id: 10,
          name: "Required checks",
          head_sha: inputs.intent.commit,
          status,
          conclusion,
          app: { slug: "github-actions" },
          output: { summary: "not public evidence" },
        }],
      };
    },
  };
}
