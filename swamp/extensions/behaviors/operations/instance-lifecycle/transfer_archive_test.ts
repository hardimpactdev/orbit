import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
import { userInfo } from "node:os";
import { createHash } from "node:crypto";
import type { FileContext } from "../database/sqlite_actions.ts";
import {
  captureTransferArchive,
  materializeTransferArchive,
} from "./transfer_archive.ts";

const runId = "43a17cb2-f652-48ad-91a4-3ba2d921caae";
async function command(program: string, args: string[]) {
  const output = await new Deno.Command(program, {
    args,
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(output.success, new TextDecoder().decode(output.stderr));
  return new TextDecoder().decode(output.stdout).trimEnd();
}
const git = (path: string, ...args: string[]) =>
  command("/usr/bin/git", [
    "-c",
    "core.hooksPath=/dev/null",
    "-C",
    path,
    ...args,
  ]);
async function fixture(layout: "checkout" | "worktree" = "checkout") {
  const directory = await Deno.makeTempDir({
    prefix: "orbit-native-transfer-test-",
  });
  const root = `${directory}/apps`,
    seed = `${root}/project/seed`,
    source = layout === "checkout" ? seed : `${root}/project/feature`;
  await Deno.mkdir(seed, { recursive: true });
  await git(seed, "init", "--quiet", "--initial-branch=main");
  await git(seed, "config", "user.name", "Orbit fixture");
  await git(seed, "config", "user.email", "fixture@example.invalid");
  await git(
    seed,
    "remote",
    "add",
    "origin",
    "https://github.com/example/transfer.git",
  );
  await Deno.writeTextFile(`${seed}/README.md`, "committed\n");
  await Deno.writeTextFile(`${seed}/.gitignore`, ".env\ndatabase.sqlite*\n");
  await git(seed, "add", ".");
  await git(seed, "commit", "--quiet", "-m", "fixture");
  if (layout === "worktree") {
    await git(seed, "worktree", "add", "--quiet", "-b", "feature", source);
  }
  await Deno.writeTextFile(`${source}/README.md`, "local edits\n");
  await Deno.writeTextFile(`${source}/untracked.txt`, "untracked\n");
  await Deno.writeTextFile(
    `${source}/.env`,
    "TRANSFER_PRIVATE_SENTINEL=retained\n",
    { mode: 0o644 },
  );
  await Deno.writeTextFile(
    `${source}/database.sqlite`,
    "not-a-consistent-snapshot",
  );
  await Deno.writeTextFile(`${source}/database.sqlite-wal`, "unfinished-wal");
  const nativePath = `${directory}/native-archive`;
  const ctx: FileContext = {
    extensionFile: (path) => path,
    modelId: "1995a0ac-99da-4860-abf0-8b09260710fa",
    modelType: { normalized: "@nckrtl/instance-transfer" },
    createFileWriter: (_spec, name) => ({
      getFilePath: () => Promise.resolve(nativePath),
      finalize: () => Promise.resolve({ name, version: 1 }),
    }),
    dataRepository: {
      stream: async function* () {
        const file = await Deno.open(nativePath, { read: true });
        yield* file.readable;
      },
    },
  };
  return {
    directory,
    root,
    seed,
    source,
    nativePath,
    ctx,
    capture: {
      source,
      user: userInfo().username,
      layout,
      repository_identity: "github.com/example/transfer",
      branch: layout === "checkout" ? "main" : "feature",
      sqlite_source: `${source}/database.sqlite`,
    },
    target: {
      destination: `${root}/project/destination`,
      root,
      environment: `${root}/project/destination/.env`,
      user: userInfo().username,
    },
    clean: () => Deno.remove(directory, { recursive: true }),
  };
}

for (const layout of ["checkout", "worktree"] as const) {
  Deno.test(`native ${layout} archive preserves dirty private source and excludes the selected SQLite files`, async () => {
    const f = await fixture(layout);
    try {
      const head = await git(f.source, "rev-parse", "HEAD");
      const archive = await captureTransferArchive(f.capture, runId, f.ctx);
      assertEquals(
        JSON.stringify(archive).includes("TRANSFER_PRIVATE_SENTINEL"),
        false,
      );
      assertEquals((await Deno.stat(f.nativePath)).mode! & 0o777, 0o600);
      assertEquals(archive.head, head);
      await materializeTransferArchive(f.target, archive, f.ctx);
      assertEquals(await git(f.target.destination, "rev-parse", "HEAD"), head);
      assertEquals(
        await git(f.target.destination, "symbolic-ref", "--short", "HEAD"),
        f.capture.branch,
      );
      assertEquals(
        await Deno.readTextFile(`${f.target.destination}/README.md`),
        "local edits\n",
      );
      assertEquals(
        await Deno.readTextFile(`${f.target.destination}/untracked.txt`),
        "untracked\n",
      );
      assertEquals(
        await Deno.readTextFile(f.target.environment),
        "TRANSFER_PRIVATE_SENTINEL=retained\n",
      );
      assertEquals((await Deno.stat(f.target.environment)).mode! & 0o007, 0);
      await assertRejects(
        () => Deno.stat(`${f.target.destination}/database.sqlite`),
        Deno.errors.NotFound,
      );
      await assertRejects(
        () => Deno.stat(`${f.target.destination}/database.sqlite-wal`),
        Deno.errors.NotFound,
      );
      assertEquals(
        await Deno.readTextFile(`${f.source}/README.md`),
        "local edits\n",
      );
      assertEquals(
        await Deno.readTextFile(`${f.source}/database.sqlite`),
        "not-a-consistent-snapshot",
      );
    } finally {
      await f.clean();
    }
  });
}

Deno.test("native archive digest refusal creates no destination and keeps the source", async () => {
  const f = await fixture();
  try {
    const archive = await captureTransferArchive(f.capture, runId, f.ctx);
    await assertRejects(
      () =>
        materializeTransferArchive(f.target, {
          ...archive,
          sha256: "0".repeat(64),
        }, f.ctx),
      Error,
      "instance.transfer_archive_invalid",
    );
    await assertRejects(
      () => Deno.stat(f.target.destination),
      Deno.errors.NotFound,
    );
    assertEquals(
      await Deno.readTextFile(`${f.source}/untracked.txt`),
      "untracked\n",
    );
    await materializeTransferArchive(f.target, archive, f.ctx);
  } finally {
    await f.clean();
  }
});

Deno.test("native transfer refuses source identity and target boundaries before replacement", async () => {
  const f = await fixture();
  try {
    await assertRejects(
      () =>
        captureTransferArchive(
          { ...f.capture, repository_identity: "github.com/other/project" },
          runId,
          f.ctx,
        ),
      Error,
      "instance.transfer_source_invalid",
    );
    await assertRejects(
      () =>
        captureTransferArchive(
          { ...f.capture, sqlite_source: `${f.directory}/foreign.sqlite` },
          runId,
          f.ctx,
        ),
      Error,
      "instance.transfer_archive_invalid",
    );
    const archive = await captureTransferArchive(f.capture, runId, f.ctx);
    await assertRejects(
      () =>
        materializeTransferArchive(
          { ...f.target, root: `${f.directory}/other` },
          archive,
          f.ctx,
        ),
      Error,
      "instance.transfer_archive_invalid",
    );
    await Deno.mkdir(f.target.destination);
    await Deno.writeTextFile(`${f.target.destination}/owned`, "preserve");
    await assertRejects(
      () => materializeTransferArchive(f.target, archive, f.ctx),
      Error,
      "instance.transfer_destination_exists",
    );
    assertEquals(
      await Deno.readTextFile(`${f.target.destination}/owned`),
      "preserve",
    );
  } finally {
    await f.clean();
  }
});

Deno.test("native extraction refuses an archive link that points outside the checkout", async () => {
  const f = await fixture();
  try {
    const archive = await captureTransferArchive(f.capture, runId, f.ctx);
    await command("/usr/bin/python3", [
      "-I",
      "-c",
      "import tarfile,sys; t=tarfile.open(sys.argv[1],'w'); m=tarfile.TarInfo('escape'); m.type=tarfile.SYMTYPE; m.linkname=sys.argv[2]; t.addfile(m); t.close()",
      f.nativePath,
      `${f.directory}/outside`,
    ]);
    const bytes = await Deno.readFile(f.nativePath);
    const ref = {
      ...archive,
      bytes: bytes.length,
      sha256: createHash("sha256").update(bytes).digest("hex"),
    };
    await assertRejects(() => materializeTransferArchive(f.target, ref, f.ctx));
    await assertRejects(
      () => Deno.stat(f.target.destination),
      Deno.errors.NotFound,
    );
    await assertRejects(
      () => Deno.stat(`${f.directory}/outside`),
      Deno.errors.NotFound,
    );
  } finally {
    await f.clean();
  }
});

Deno.test("worktree capture refuses a colliding bundle without losing a source file", async () => {
  const f = await fixture("worktree");
  try {
    await Deno.writeTextFile(
      `${f.source}/checkout.bundle`,
      "belongs to the source",
    );
    await assertRejects(
      () => captureTransferArchive(f.capture, runId, f.ctx),
      Error,
      "instance.transfer_archive_invalid",
    );
    assertEquals(
      await Deno.readTextFile(`${f.source}/checkout.bundle`),
      "belongs to the source",
    );
  } finally {
    await f.clean();
  }
});

Deno.test("worktree archive preserves staged objects, index and origin independently of the source", async () => {
  const f = await fixture("worktree");
  try {
    await Deno.writeTextFile(
      `${f.source}/staged-new.txt`,
      "staged new contents\n",
    );
    await git(f.source, "add", "README.md", "staged-new.txt");
    await Deno.writeTextFile(
      `${f.source}/README.md`,
      "unstaged after staging\n",
    );
    const before = await git(f.source, "status", "--porcelain=v1");
    const staged = await git(f.source, "diff", "--cached", "--binary");
    const archive = await captureTransferArchive(f.capture, runId, f.ctx);
    await materializeTransferArchive(f.target, archive, f.ctx);
    assertEquals(
      await git(f.target.destination, "status", "--porcelain=v1"),
      before,
    );
    assertEquals(
      await git(f.target.destination, "diff", "--cached", "--binary"),
      staged,
    );
    assertEquals(
      await git(f.target.destination, "config", "--get", "remote.origin.url"),
      "https://github.com/example/transfer.git",
    );
    assertEquals(await git(f.source, "status", "--porcelain=v1"), before);
    assertEquals(await git(f.source, "diff", "--cached", "--binary"), staged);
  } finally {
    await f.clean();
  }
});
