/** A disposable Unix HTTP daemon for the production image-directory identity check. */
export function stockImageTask(
    task: Record<string, unknown>,
    folder: string,
): void {
    if (task.name === "Require absolute image daemon paths") {
        const assertion = task["ansible.builtin.assert"] as { that: string[] };
        assertion.that.push(
            `container_image_directory == '${folder}'`,
            `container_image_socket == '${folder}/unix.socket'`,
        );
    }
}

export function stockImageDaemon(folder: string): () => void {
    Deno.mkdirSync(`${folder}/images`, { recursive: true });
    const child = new Deno.Command("python3", {
        args: [
            "-c",
            `
import subprocess, sys
p = subprocess.Popen(sys.argv[1:], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
ready = p.stdout.readline().strip().split()
if ready[:1] != [b'ready']:
    raise RuntimeError('fixture daemon did not start')
print(int(ready[1]))
`,
            Deno.execPath(),
            "run",
            "-A",
            new URL("../fixtures/task-vm-host-incus.ts", import.meta.url)
                .pathname,
            "--daemon",
        ],
        env: {
            HOST_FIXTURE: folder,
            INCUS_DIR: folder,
            INCUS_SOCKET: `${folder}/unix.socket`,
        },
    }).outputSync();
    if (!child.success) throw new Error(new TextDecoder().decode(child.stderr));
    const pid = Number(new TextDecoder().decode(child.stdout).trim());
    Deno.writeTextFileSync(`${folder}/daemon.pid`, String(pid));
    return () => Deno.kill(pid, "SIGTERM");
}
