import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
export const HEAD = "a".repeat(40),
  BASE = "b".repeat(40),
  MERGE = "c".repeat(40);
export function evidence(kind = "success") {
  const root = "/repos/hardimpactdev/orbit";
  const rows: Record<string, unknown> = {
    [root + "/pulls/3"]: {
      number: 3,
      state: "open",
      merged: kind === "merged",
      merge_commit_sha: kind === "merged" ? MERGE : null,
      draft: false,
      mergeable: true,
      mergeable_state: "clean",
      changed_files: 1,
      head: {
        sha: HEAD,
        ref: "task-91",
        repo: { full_name: "hardimpactdev/orbit" },
      },
      base: {
        sha: BASE,
        ref: "main",
        repo: { full_name: "hardimpactdev/orbit" },
      },
    },
    [root]: {
      default_branch: "main",
      archived: false,
      allow_squash_merge: true,
    },
    [root + "/pulls/3/files?per_page=100"]: [[{
      filename: "package.json",
      status: "modified",
    }]],
    [root + "/actions/workflows?per_page=100"]: {
      total_count: 2,
      workflows: [{
        id: 1,
        path: ".github/workflows/ci.yml",
        state: "active",
      }, {
        id: 2,
        path: ".github/workflows/production-smoke.yml",
        state: "active",
      }],
    },
    [
      root +
      `/actions/runs?head_sha=${HEAD}&event=pull_request&per_page=100`
    ]: {
      total_count: 2,
      workflow_runs: [1, 2].map((id) => ({
        id,
        workflow_id: id,
        check_suite_id: id,
        head_sha: HEAD,
        head_branch: "task-91",
        event: "pull_request",
        status: "completed",
        conclusion: "success",
      })),
    },
    [root + `/commits/${HEAD}/check-runs?per_page=100`]: [{
      total_count: 2,
      check_runs: ["Required checks", "smoke"].map((name, index) => ({
        id: index + 1,
        name,
        head_sha: HEAD,
        app: { slug: "github-actions" },
        check_suite: { id: index + 1 },
        status: kind === "checks" ? "in_progress" : "completed",
        conclusion: "success",
      })),
    }],
  };
  for (const commit of [HEAD, BASE]) {
    const value = JSON.stringify({
      dependencies: {
        library: kind === "major" && commit === HEAD ? "^2.0" : commit === HEAD ? "^1.1" : "^1.0",
      },
    });
    rows[root + `/contents/package.json?ref=${commit}`] = {
      type: "file",
      path: "package.json",
      encoding: "base64",
      size: value.length,
      content: btoa(value),
    };
  }
  return rows;
}
export async function mergeFixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120000;
  await repo.copyBehaviors("operations/dependency-merge");
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/_lib`);
  await Deno.copyFile(
    `${ROOT}/extensions/behaviors/_lib/versions.ts`,
    `${repo.dir}/extensions/behaviors/_lib/versions.ts`,
  );
  const state = `${repo.dir}/github.json`;
  const save = (kind = "success") =>
    Deno.writeTextFileSync(
      state,
      JSON.stringify({
        rows: evidence(kind),
        calls: [],
        lost_reply: false,
      }),
    );
  save();
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary(
    "tools/gh",
    `const path=Deno.env.get('FIXTURE_STATE')!;
// Revocations run concurrently; serialize the fake GitHub state and call log.
using lock=Deno.openSync(path+'.lock',{create:true,write:true});lock.lockSync(true);
const data=JSON.parse(Deno.readTextFileSync(path));const args=Deno.args;
const check=(v:boolean)=>{if(!v)throw new Error('Invalid fixture call');};
check(args[0]==='api'&&!args.join(' ').includes(Deno.env.get('GH_TOKEN')!));
let method='GET',endpoint='';
for(let i=1;i<args.length;){if(args[i]==='--method'){method=args[i+1];i+=2;}else if(args[i]==='--input'){check(JSON.stringify(JSON.parse(Deno.readTextFileSync(args[i+1])))===JSON.stringify({sha:'a'.repeat(40),merge_method:'squash'}));i+=2;}else if(args[i].startsWith('--'))i++;else{endpoint='/'+args[i].replace(/^\\//,'');i++;}}
data.calls.push([method,endpoint]);Deno.writeTextFileSync(path,JSON.stringify(data));
if(method==='DELETE')Deno.exit(0);
if(method==='PUT'){check(Deno.env.get('GH_TOKEN')===Deno.env.get('FIXTURE_WRITE'));const pr=data.rows['/repos/hardimpactdev/orbit/pulls/3'];pr.merged=true;pr.merge_commit_sha='c'.repeat(40);Deno.writeTextFileSync(path,JSON.stringify(data));if(data.lost_reply)Deno.exit(1);console.log(JSON.stringify({merged:true,sha:'c'.repeat(40)}));}
else{check(Deno.env.get('GH_TOKEN')===Deno.env.get('FIXTURE_READ'));check(!args.includes('--slurp'));if(args.includes('--paginate'))for(const page of data.rows[endpoint])console.log(JSON.stringify(page));else console.log(JSON.stringify(data.rows[endpoint]));}`,
  );
  Object.assign(repo.env, {
    PATH: `${repo.dir}/tools:${Deno.env.get("PATH")}`,
    FIXTURE_STATE: state,
    FIXTURE_READ: crypto.randomUUID(),
    FIXTURE_WRITE: crypto.randomUUID(),
  });
  repo.secrets = [repo.env.FIXTURE_READ, repo.env.FIXTURE_WRITE];
  const source = parse(
    await Deno.readTextFile(
      `${ROOT}/workflows/workflow-operation-dependency-merge.yaml`,
    ),
  ) as { jobs: { labels: unknown; steps: { labels: unknown }[] }[] };
  for (const job of source.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
    }
  }
  const rewrite = (value: unknown): unknown => {
    if (typeof value === "string") {
      value = value.replaceAll(
        "/tmp/orbit-dependency-merge-",
        `${repo.dir}/scratch-`,
      );
      return (value as string).startsWith("${{ vault.get(")
        ? (value as string).includes("write_token") ? "${{ env.FIXTURE_WRITE }}" : "${{ env.FIXTURE_READ }}"
        : value;
    }
    if (Array.isArray(value)) return value.map(rewrite);
    if (value && typeof value === "object") {
      return Object.fromEntries(
        Object.entries(value).map(([k, v]) => [k, rewrite(v)]),
      );
    }
    return value;
  };
  await repo.workflow("operation-dependency-merge", (flow) => {
    Object.assign(flow, rewrite(flow));
  });
  const inputs = {
    operation_id: 7,
    node_id: 209,
    kind: "tasks.dependency_merge",
    intent: {
      node_id: 209,
      project_id: 20,
      group_id: 91,
      publish_operation_id: 8,
      repository: "hardimpactdev/orbit",
      default_branch: "main",
      smoke_workflow: ".github/workflows/production-smoke.yml",
      pr_number: 3,
      head_sha: HEAD,
    },
    execution_secrets: Object.fromEntries(
      [["read_token", "read"], ["write_token", "write"]].map((
        [name, scope],
      ) => [name, {
        vault: "orbit-control-inputs",
        key: `dependency-merge-${scope}-12345678-1234-4123-8123-123456789012`,
      }]),
    ),
  };
  const read = () => JSON.parse(Deno.readTextFileSync(state));
  const run = async (success = true) => {
    await repo.cli(["workflow", "validate", "operation-dependency-merge"]);
    const result = JSON.parse(
      (await repo.cli([
        "workflow",
        "run",
        "operation-dependency-merge",
        "--stdin",
      ], { input: JSON.stringify(inputs), success })).stdout,
    );
    const history = JSON.parse(
      (await repo.cli(["workflow", "history", "get", result.id])).stdout,
    );
    assert(
      ![...Deno.readDirSync(repo.dir)].some((e) => e.name.startsWith("scratch-")),
    );
    const data = read();
    assertEquals(
      data.calls.filter((c: string[]) => c[0] === "DELETE").length,
      2,
    );
    return { history, data };
  };
  return {
    repo,
    state,
    save,
    read,
    run,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
