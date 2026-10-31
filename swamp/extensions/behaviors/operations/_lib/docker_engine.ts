import {
  localSystemCommand,
  type SystemOutput,
  type SystemTransport,
} from "../node/system_command.ts";

export type DockerJson =
  | null
  | boolean
  | number
  | string
  | readonly DockerJson[]
  | { readonly [key: string]: DockerJson };

/** Private Engine request. Construct this inside the owning material callback. */
export type DockerCreate = {
  readonly Image: string;
  readonly Labels?: Readonly<Record<string, string>>;
  readonly [key: string]: DockerJson | undefined;
};

/** Transport only. The family owns names, labels, validation and rollback. */
export class DockerEngine {
  constructor(private transport: SystemTransport = localSystemCommand) {}

  call(
    args: readonly string[],
    signal?: AbortSignal,
    timeoutMs = 900_000,
    stdin?: string,
  ): Promise<SystemOutput> {
    return this.run(
      "/usr/bin/docker",
      ["--host=unix:///var/run/docker.sock", ...args],
      stdin,
      signal,
      timeoutMs,
    );
  }

  create(
    name: string,
    body: DockerCreate,
    signal?: AbortSignal,
  ): Promise<SystemOutput> {
    if (!/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/.test(name)) {
      throw new Error("Docker container name is invalid");
    }
    return this.run(
      "/usr/bin/curl",
      [
        "-q",
        "--fail",
        "--silent",
        "--max-time",
        "900",
        "--unix-socket",
        "/var/run/docker.sock",
        "--request",
        "POST",
        "--header",
        "Content-Type: application/json",
        "--data-binary",
        "@-",
        `http://localhost/containers/create?name=${encodeURIComponent(name)}`,
      ],
      JSON.stringify(body),
      signal,
      900_000,
    );
  }

  private async run(
    program: string,
    args: readonly string[],
    stdin: string | undefined,
    signal: AbortSignal | undefined,
    timeoutMs: number,
  ): Promise<SystemOutput> {
    try {
      return await this.transport({
        command: "/usr/bin/sudo",
        args: ["-n", "--", program, ...args],
        ...(stdin === undefined ? {} : { stdin }),
        timeoutMs,
      }, signal);
    } catch {
      throw new Error("Docker Engine command has no confirmed outcome");
    }
  }
}
