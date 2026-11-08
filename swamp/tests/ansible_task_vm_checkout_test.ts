/** A fresh warm clone must contain files before dependency installation (refresh 13786). */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", {
  args: ["-c", "command -v ansible-playbook"],
}).outputSync().success;
// Run the shipped checkout tasks up to the digest/install work, with real Git.
const tasks = (parse(Deno.readTextFileSync(
  new URL("../ansible/tasks/task-vm-checkout.yml", import.meta.url),
)) as { block?: Record<string, unknown>[] }[]).slice(0, 4);
// The local test account need not have a same-named group (macOS uses staff).
// Prepare the parent ourselves; run every shipped Git task unchanged.
tasks[1].block = tasks[1].block!.filter((task) =>
  !task["ansible.builtin.file"]
);

function git(...args: string[]) {
  const result = new Deno.Command("timeout", {
    args: ["60", "git", ...args],
    env: { GIT_CONFIG_GLOBAL: "/dev/null", GIT_CONFIG_NOSYSTEM: "1" },
  }).outputSync();
  assert(result.success, new TextDecoder().decode(result.stderr));
  return new TextDecoder().decode(result.stdout).trim();
}

function fixture(
  run: (repo: string, checkout: string, old: string, head: string) => void,
) {
  const folder = Deno.makeTempDirSync();
  const repo = `${folder}/origin`;
  try {
    git("init", "--initial-branch=main", repo);
    Deno.mkdirSync(`${repo}/apps/gateway`, { recursive: true });
    Deno.writeTextFileSync(`${repo}/apps/gateway/composer.json`, "{}\n");
    Deno.writeTextFileSync(`${repo}/.gitignore`, "vendor/\nnode_modules/\n");
    git("-C", repo, "add", ".");
    const commit = () => {
      git(
        "-C",
        repo,
        "-c",
        "user.name=Test",
        "-c",
        "user.email=test@example.test",
        "-c",
        "commit.gpgsign=false",
        "commit",
        "-m",
        "fixture",
      );
      return git("-C", repo, "rev-parse", "HEAD");
    };
    const old = commit();
    Deno.mkdirSync(`${repo}/packages/php-sdk`, { recursive: true });
    Deno.writeTextFileSync(`${repo}/packages/php-sdk/composer.json`, "{}\n");
    git("-C", repo, "add", ".");
    const head = commit();
    Deno.mkdirSync(`${folder}/apps/orbit`, { recursive: true });
    run(repo, `${folder}/apps/orbit/workspace`, old, head);
  } finally {
    Deno.removeSync(folder, { recursive: true });
  }
}

function converge(repo: string, checkout: string, commit: string) {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.writeTextFileSync(
      `${folder}/play.yml`,
      stringify([{
        name: "Warm checkout before dependencies",
        hosts: "localhost",
        gather_facts: false,
        vars: {
          ansible_become: false,
          orbit_user: Deno.env.get("USER"),
          intent: { checkout: { repository: repo, path: checkout, commit } },
        },
        tasks: [...tasks, {
          // Composer's first project must exist at the dependency-install boundary.
          name: "Read the Composer project from the worktree",
          "ansible.builtin.command": {
            argv: ["test", "-f", `${checkout}/apps/gateway/composer.json`],
          },
          changed_when: false,
        }],
      }]),
    );
    const result = new Deno.Command("timeout", {
      args: [
        "60",
        "ansible-playbook",
        "-i",
        "localhost,",
        "-c",
        "local",
        "-e",
        "ansible_python_interpreter={{ ansible_playbook_python }}",
        `${folder}/play.yml`,
      ],
      env: {
        ANSIBLE_HOME: `${folder}/home`,
        ANSIBLE_NOCOLOR: "1",
        GIT_CONFIG_GLOBAL: "/dev/null",
        GIT_CONFIG_NOSYSTEM: "1",
      },
    }).outputSync();
    const output = new TextDecoder().decode(result.stdout) +
      new TextDecoder().decode(result.stderr);
    assert(result.success, output);
    assertEquals(git("-C", checkout, "rev-parse", "HEAD"), commit);
    assertEquals(git("-C", checkout, "branch", "--show-current"), "");
    return output;
  } finally {
    Deno.removeSync(folder, { recursive: true });
  }
}

Deno.test({
  name:
    "a new clone materializes files even when origin HEAD equals the pinned release",
  ignore: !hasAnsible,
  fn() {
    fixture((repo, checkout, _old, head) => {
      converge(repo, checkout, head);
      assertEquals(
        Deno.readTextFileSync(`${checkout}/packages/php-sdk/composer.json`),
        "{}\n",
      );
    });
  },
});

Deno.test({
  name: "a new clone can pin an older release than origin HEAD",
  ignore: !hasAnsible,
  fn() {
    fixture((repo, checkout, old) => {
      converge(repo, checkout, old);
      assertEquals(git("-C", checkout, "status", "--porcelain"), "");
    });
  },
});

Deno.test({
  name:
    "an unchanged warm checkout is a no-op and advancing preserves ignored dependencies",
  ignore: !hasAnsible,
  fn() {
    fixture((repo, checkout, old, head) => {
      converge(repo, checkout, old);
      Deno.mkdirSync(`${checkout}/vendor`);
      Deno.writeTextFileSync(`${checkout}/vendor/warm`, "cached dependency");
      assertStringIncludes(converge(repo, checkout, old), "changed=0");
      converge(repo, checkout, head);
      assertEquals(
        Deno.readTextFileSync(`${checkout}/vendor/warm`),
        "cached dependency",
      );
      assertEquals(
        Deno.readTextFileSync(`${checkout}/packages/php-sdk/composer.json`),
        "{}\n",
      );
    });
  },
});
