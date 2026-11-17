# This environment

You work in an Orbit development Instance: one container that holds one monorepo with several projects.

- The workspace is `/srv/workspace`. It is a Git repository of the Project.
- Two users share it. You run as `agent`. The apps and their Orbit Processes run as `orbit`. Both are in the group `work`, so each can read and write the other's files.
- `/etc/orbit/instance` names the Instance, its Project, and its URL and range of development ports when it has a web app.
- Orbit Processes run as `orbit` units. A web app that Orbit serves answers at the Instance's URL.
- A development server that you start yourself, such as `npm run dev`, runs as `agent`. When `/etc/orbit/instance` names a URL, bind it to `0.0.0.0` on a port from 1024 to 65535 that is not in `ORBIT_DEV_PORTS_TAKEN`, allow the Instance's host name, and point hot reload at the URL and port. It then answers at that URL with the port, over WireGuard only. Open it in the browser panel of your client.
- Node, npm, Bun, Vite+ (`vp`), Composer and rg come from `/opt/orbit-runtime`. PHP comes from `/opt/orbit-runtime/current/php/<version>/bin`, which holds `php` and `frankenphp` for each PHP version. `php` on `PATH` is the Project's PHP version, so `composer` works. Claude Code, Codex and `agent-browser` come from `/opt/orbit-agent`. Both folders are read-only.
- Check a page in a browser two ways. For a headless check on the server, run `agent-browser`, for example `agent-browser open <url>`, then `agent-browser snapshot` or `agent-browser screenshot <file>`. It uses the one headless Chrome in `/opt/orbit-agent`, which `AGENT_BROWSER_EXECUTABLE_PATH` names; do not install or download another browser for it. For a check in a real browser, open the URL in the browser panel of your client, T3 or Conn.
- `agent` has passwordless sudo, so it is root in this container. `orbit` has no sudo.
- There is no direct internet access. HTTP and HTTPS leave through Orbit's egress proxy, which `HTTP_PROXY` and `HTTPS_PROXY` name, so package managers and Git over HTTPS work. The proxy resolves every name: there is no DNS here. Other protocols, Git over SSH included, do not leave, and the proxy refuses private and Orbit addresses.
- Orbit fetches and pushes the Instance's Git branches from the Node, outside this container. This container holds no GitHub credentials, so a push from here does not reach GitHub.
