export function repositoryIdentity(origin: string): string {
  let host: string, path: string;
  if (origin.startsWith("git@")) {
    const parts = /^git@([^:/\s]+):([^\s]+)$/.exec(origin);
    if (!parts) throw new Error("Invalid repository origin");
    [, host, path] = parts;
  } else {
    const url = new URL(origin);
    if (
      !["https:", "ssh:"].includes(url.protocol) || url.password ||
      url.search || url.hash || (url.username && url.username !== "git")
    ) throw new Error("Invalid repository origin");
    host = url.hostname;
    path = url.pathname;
  }
  path = path.replace(/^\/+|\/+$/g, "").replace(/\.git$/, "").replace(
    /\/+$/,
    "",
  );
  if (!path) throw new Error("Invalid repository origin");
  return `${host.toLowerCase()}/${path}`;
}
