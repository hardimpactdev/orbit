import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import {
  productionHomeProgram,
  runLocalProductionHome,
} from "./production_home_system.ts";
import { sourceGitCommand } from "./source_system.ts";

const identity = {
  project_id: 17,
  user: "orbit-app-17",
  home: "/home/orbit-app-17",
};
const source = {
  ...identity,
  repository: "https://github.com/example/proof.git",
  instance: 117,
};

Deno.test("production native source binds its admitted Project account before effects", async () => {
  let calls = 0;
  const execute = () => {
    calls++;
    return Promise.resolve({});
  };
  for (
    const input of [
      { ...identity, user: "orbit-app-18" },
      { ...identity, home: "/home/orbit-app-18" },
      { ...identity, user: "root" },
      { user: identity.user, home: identity.home },
    ]
  ) {
    assert(
      Object.hasOwn(
        await runLocalProductionHome(
          { name: "instance.prepare-production-user", input },
          undefined,
          execute,
        ),
        "error_code",
      ),
    );
  }
  assertEquals(calls, 0);
  assertEquals(
    await runLocalProductionHome(
      { name: "instance.prepare-production-user", input: identity },
      undefined,
      execute,
    ),
    {},
  );
  assertEquals(calls, 1);
});

Deno.test("production clone reuses shared private Git rules without putting credentials in program text", async () => {
  const token = "ghs_private_production_sentinel";
  let called = false;
  const result = await runLocalProductionHome(
    {
      name: "instance.prepare-production-source",
      input: {
        ...source,
        allow_existing: true,
        git_read: { github_token: token },
      },
    },
    undefined,
    (program, body) => {
      called = true;
      assertEquals(program, productionHomeProgram);
      assert(!program.includes(token));
      assert(!JSON.stringify(body.input).includes(token));
      const git = body.git_private as {
        args: string[];
        env: Record<string, string>;
      };
      assert(git.args.includes("protocol.https.allow=always"));
      assert(git.args.includes("protocol.file.allow=never"));
      assert(git.args.includes("protocol.ext.allow=never"));
      assert(!git.args.join(" ").includes(token));
      assertEquals(
        git.env.GIT_CONFIG_VALUE_0,
        `Authorization: Basic ${btoa(`x-access-token:${token}`)}`,
      );
      assertEquals(body.git_public, sourceGitCommand([]));
      return Promise.resolve({});
    },
  );
  assert(called);
  assertEquals(result, {});
});

Deno.test("production source refuses unsupported actions and paths before effects", async () => {
  let calls = 0;
  const execute = () => {
    calls++;
    return Promise.resolve({});
  };
  for (
    const [name, input] of [
      ["instance.refresh-config-cache", identity],
      ["instance.write-directory-url", identity],
      ["execute-shell", identity],
      ["instance.resolve-production-source", {
        ...source,
        relative_root: "public",
        application_suffix: "/../other",
        branch: "main",
        clone_target: true,
        git_read: { github_token: null },
      }],
      ["instance.inspect-production-profile", {
        project_id: 17,
        user: identity.user,
        checkout: "/tmp/foreign",
        application: "/tmp/foreign",
        relative_root: "public",
        entry_point: "artisan",
      }],
    ] as const
  ) {
    assert(
      Object.hasOwn(
        await runLocalProductionHome({ name, input }, undefined, execute),
        "error_code",
      ),
    );
  }
  assertEquals(calls, 0);
});

async function pythonFixture(program: string) {
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-I", "-c", `${productionHomeProgram}\n${program}`],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
    clearEnv: true,
    env: {
      PATH: "/usr/bin:/bin",
      LANG: "C.UTF-8",
      TMPDIR: Deno.env.get("TMPDIR") ?? "/tmp",
    },
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(
      JSON.stringify({ public: sourceGitCommand([], undefined, ["file"]) }),
    ),
  );
  await writer.close();
  const result = await child.output();
  assert(result.success, new TextDecoder().decode(result.stderr));
  return JSON.parse(new TextDecoder().decode(result.stdout));
}
const localFixture = String.raw`
from types import SimpleNamespace
fixture=json.load(sys.stdin)
root=tempfile.mkdtemp(prefix='orbit-production-home-test-')
HOME_ROOT=root+'/homes';STATE_ROOT=root+'/state';ROOT_UID=os.geteuid()
os.mkdir(HOME_ROOT)
user='orbit-app-17';home=HOME_ROOT+'/'+user;os.mkdir(home,0o700)
account=SimpleNamespace(pw_uid=os.geteuid(),pw_gid=os.getegid(),pw_dir=home)
entry=lambda data:account
GIT_PUBLIC=fixture['public'];GIT_PRIVATE=fixture['public']
repository=root+'/origin';os.mkdir(repository)
env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_NOSYSTEM':'1'}
def repository_git(args):return run('/usr/bin/git',['-c','core.hooksPath=/dev/null','-C',repository]+args,env=env)
repository_git(['init','--quiet','--initial-branch=main']);repository_git(['config','user.name','Fixture']);repository_git(['config','user.email','fixture@example.invalid'])
with open(repository+'/README.md','w') as stream:stream.write('source\n')
with open(repository+'/composer.json','w') as stream:stream.write('{"require":{"php":"^8.4"}}')
with open(repository+'/artisan','w') as stream:stream.write('<?php\n')
with open(repository+'/.gitignore','w') as stream:stream.write('.env\n')
os.mkdir(repository+'/public')
with open(repository+'/public/index.php','w') as stream:stream.write('<?php\n')
repository_git(['add','.']);repository_git(['commit','--quiet','-m','initial'])
data={'project_id':17,'user':user,'home':home,'instance':117,'repository':repository,'allow_existing':True}
`;

Deno.test("production source clones and replays a real initial release with a private stable environment", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data);prepare_source(data)
 resolved=resolve(dict(data,relative_root='public',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial'
 print(json.dumps({'source':resolved,'link':os.readlink(release+'/.env'),'mode':stat.S_IMODE(os.stat(home+'/.env').st_mode),'profile':profile(dict(data,relative_root='public',checkout=release,application=release,entry_point='artisan')),'layout':selected(dict(data,relative_root='public',application_suffix=''))}))
finally:shutil.rmtree(root)
`);
  assertEquals(result.source.branch, "main");
  assertMatch(result.source.commit, /^[a-f0-9]{40}$/);
  assertEquals(result.link, "../../.env");
  assertEquals(result.mode, 0o600);
  assertEquals(result.layout, null);
  assertEquals(result.profile.kind, "composer");
  assertEquals(atob(result.profile.composer), '{"require":{"php":"^8.4"}}');
});

Deno.test("production source supports a Project whose application root is the release itself", async () => {
  assertEquals(
    await runLocalProductionHome(
      {
        name: "instance.inspect-production-profile",
        input: {
          project_id: 17,
          user: identity.user,
          relative_root: ".",
          checkout: `${identity.home}/releases/initial`,
          application: `${identity.home}/releases/initial`,
          entry_point: "artisan",
        },
      },
      undefined,
      () => Promise.resolve({ accepted: true }),
    ),
    { accepted: true },
  );
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data)
 resolve(dict(data,relative_root='.',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial'
 observed=profile(dict(data,relative_root='.',checkout=release,application=release,entry_point='artisan'))
 os.symlink('releases/initial',home+'/current')
 print(json.dumps({'profile':observed['kind'],'current':selected(dict(data,relative_root='.',application_suffix=''))}))
finally:shutil.rmtree(root)
`);
  assertEquals(result, { profile: "composer", current: "initial" });
});

Deno.test("production replay refuses foreign markers and profile refuses linked metadata", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data);resolve(dict(data,relative_root='public',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial'
 os.unlink(release+'/composer.json');os.symlink(repository+'/composer.json',release+'/composer.json')
 unsafe=profile(dict(data,relative_root='public',checkout=release,application=release,entry_point='artisan'))
 with open(state(data)+'/initial-clone','wb') as stream:stream.write(b'foreign')
 refused=False
 try:prepare_source(data)
 except Refusal:refused=True
 print(json.dumps({'unsafe':unsafe,'refused':refused,'source_preserved':os.path.isfile(release+'/README.md')}))
finally:shutil.rmtree(root)
`);
  assertEquals(result, {
    unsafe: { kind: "unsafe", composer: null, entry_point: null },
    refused: true,
    source_preserved: true,
  });
});

Deno.test("production layout clears its owned current link and refuses an escaping selection", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data);resolve(dict(data,relative_root='public',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial'
 os.symlink('releases/initial',home+'/current');value=dict(data,relative_root='public',application_suffix='')
 before=selected(value);clear_current(value);clear_current(value)
 os.symlink('/tmp',home+'/current');refused=False
 try:clear_current(value)
 except Refusal:refused=True
 print(json.dumps({'before':before,'refused':refused,'link':os.readlink(home+'/current'),'source_preserved':os.path.isfile(release+'/README.md')}))
finally:shutil.rmtree(root)
`);
  assertEquals(result, {
    before: "initial",
    refused: true,
    link: "/tmp",
    source_preserved: true,
  });
});

Deno.test("production candidate reads the owned current release and refuses dirty source", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data);resolve(dict(data,relative_root='public',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial';os.symlink('releases/initial',home+'/current')
 value=dict(data,base=home,expected_source=release,configured_branch='main',target_branch='main')
 before=inspect_candidate(value)
 with open(release+'/README.md','a') as stream:stream.write('dirty')
 refused=False
 try:inspect_candidate(value)
 except Refusal:refused=True
 print(json.dumps({'commit':before['commit'],'source':before['source']==release,'refused':refused}))
finally:shutil.rmtree(root)
`);
  assertMatch(result.commit, /^[a-f0-9]{40}$/);
  assertEquals(result.source, true);
  assertEquals(result.refused, true);
});

Deno.test("production Caddy access denies the home before granting only its selected public root", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data);resolve(dict(data,relative_root='public',application_suffix='',branch='main',clone_target=True));release=home+'/releases/initial'
 calls=[]
 def run(program,args,**kwargs):calls.append([program,args]);return b''
 grant_caddy(dict(data,relative_root='public',clone_target=True))
 print(json.dumps({'first':calls[0],'read_roots':[args[-1] for program,args in calls if 'u:caddy:r-X' in args],'environment_private':stat.S_IMODE(os.stat(home+'/.env').st_mode)==0o600,'home':home,'release':release}))
finally:shutil.rmtree(root)
`);
  assertEquals(result.first, ["/usr/bin/setfacl", [
    "-n",
    "-P",
    "-R",
    "-m",
    "u:caddy:---",
    "--",
    result.home,
  ]]);
  assertEquals(result.read_roots, [`${result.release}/public`]);
  assertEquals(result.environment_private, true);
});

Deno.test("production home cleanup requires its saved marker and recovers source state after account absence", async () => {
  const result = await pythonFixture(`${localFixture}
try:
 prepare_source(data)
 def absent(*args):raise KeyError()
 entry=absent;pwd.getpwnam=absent
 with open(state(data)+'/release-layout','wb') as stream:stream.write(b'foreign')
 refused=False
 try:remove_home(data)
 except Refusal:refused=True
 preserved=os.path.isdir(home)
 write_marker(state(data)+'/release-layout',clone_marker(data)+b'initial\\0')
 removed=remove_home(data);again=remove_home(data)
 print(json.dumps({'refused':refused,'preserved':preserved,'removed':removed,'again':again,'absent':not exists(home) and not exists(state(data))}))
finally:shutil.rmtree(root)
`);
  assertEquals(result, {
    refused: true,
    preserved: true,
    removed: { removed: true },
    again: { removed: false },
    absent: true,
  });
});
