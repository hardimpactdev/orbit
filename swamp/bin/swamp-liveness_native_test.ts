/** Prove the empty inode-probe bind works with the shipped systemd sandbox. */
import { assertEquals } from "jsr:@std/assert@1.0.19";

const decoder = new TextDecoder();
async function command(args: string[]) {
  const result = await new Deno.Command(args[0], { args: args.slice(1), stdout: "piped", stderr: "piped", signal: AbortSignal.timeout(20_000) }).output();
  assertEquals(result.code, 0, decoder.decode(result.stdout) + decoder.decode(result.stderr));
  return decoder.decode(result.stdout);
}

Deno.test("metadata-only bind keeps home hidden and read only", async () => {
  const root = await Deno.makeTempDir({ prefix: "swamp-inode-probe-", dir: Deno.env.get("HOME") });
  try {
    const probe = `${root}/empty`, secret = `${root}/operator-sentinel`;
    await Deno.mkdir(probe, { mode: 0o700 });
    await Deno.writeTextFile(secret, "synthetic native test sentinel", { mode: 0o600 });
    const prefix = Deno.uid() === 0 ? [] : ["sudo", "-n"];
    await command([...prefix, "true"]);
    // Open the actual host sentinel as a positive control, without reading it.
    const expected = JSON.parse(
      await command([
        ...prefix,
        "/usr/bin/python3",
        "-c",
        `
import os,json,sys
os.close(os.open(sys.argv[2],os.O_RDONLY))
s=os.statvfs(sys.argv[1])
print(json.dumps({'fsid':str(s.f_fsid),'inodes':str(s.f_files)}))`,
        probe,
        secret,
      ]),
    );
    const script = `
import os,json,sys
p='/run/swamp-datastore-inode-probe';s=os.statvfs(p)
try:
 fd=os.open(sys.argv[1],os.O_RDONLY)
except (PermissionError,FileNotFoundError): home_denied=True
else:
 os.close(fd);home_denied=False
try:
 fd=os.open(p+'/forbidden',os.O_WRONLY|os.O_CREAT,0o600)
except OSError as e: write_errno=e.errno
else:
 os.close(fd);write_errno=None
print(json.dumps({'fsid':str(s.f_fsid),'inodes':str(s.f_files),'entries':os.listdir(p),'homeDenied':home_denied,'writeErrno':write_errno}))`;
    const properties = [
      "User=root",
      "NoNewPrivileges=yes",
      "ProtectSystem=strict",
      "ProtectHome=yes",
      "PrivateTmp=yes",
      `BindReadOnlyPaths=${probe}:/run/swamp-datastore-inode-probe`,
    ];
    const unit = `--unit=orbit-swamp-inode-probe-test-${crypto.randomUUID()}`;
    const run = [...prefix, "systemd-run", "--quiet", "--wait", "--pipe", unit, ...properties.map((p) => `--property=${p}`)];
    const value = JSON.parse(await command([...run, "/usr/bin/python3", "-c", script, secret]));
    assertEquals(value, { ...expected, entries: [], homeDenied: true, writeErrno: 30 });
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
