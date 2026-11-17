# This environment

You work in an Orbit development Instance: one container that holds one web app.

- The workspace is `/srv/workspace`. It is a Git repository of the Project.
- Two users share it. You run as `agent`. The app and its Orbit Processes run as `www-data`. Both are in the group `www-data`, so each can read and write the other's files.
- `/etc/orbit/instance` names the Instance, its Project, its URL (`ORBIT_URL`), the range of development ports (`ORBIT_DEV_PORTS`) and the ports the Node keeps for itself (`ORBIT_DEV_PORTS_TAKEN`).
- The app is served at that URL by an Orbit Process, which runs as `www-data`. A change to the code shows there at the next request. Orbit Processes, such as queue workers, run as `www-data` units.
- A development server that you start yourself, such as `npm run dev`, runs as `agent`. On any port from 1024 to 65535, it answers at the Instance's URL with that port, such as `https://<domain>:5173`, over WireGuard only. Open that URL in the browser panel of your client. While nothing listens on the port, the URL answers 502. A port in `ORBIT_DEV_PORTS_TAKEN`, or one that a program on the Node listens on, reaches the Node, not you: when the URL shows something else, pick another port.
- For that server to answer:
  - Bind it to `0.0.0.0`, not `localhost`.
  - Allow the Instance's host name. For Vite: `server.allowedHosts` names the domain.
  - Point hot reload at the URL. For Vite: `server.hmr.host` is the domain, `server.hmr.protocol` is `wss`, and `server.hmr.clientPort` is the port.
- Node, npm, Bun, Vite+ (`vp`), Composer and rg come from `/opt/orbit-runtime`. PHP comes from `/opt/orbit-runtime/current/php/<version>/bin`, which holds `php` and `frankenphp` for each PHP version. `php` on `PATH` is the Project's PHP version, so `composer` works. Claude Code, Codex and `agent-browser` come from `/opt/orbit-agent`. Both folders are read-only.
- Check a page in a browser two ways. For a headless check on the server, run `agent-browser`, for example `agent-browser open <url>`, then `agent-browser snapshot` or `agent-browser screenshot <file>`. It uses the one headless Chrome in `/opt/orbit-agent`, which `AGENT_BROWSER_EXECUTABLE_PATH` names; do not install or download another browser for it. For a check in a real browser, open the URL in the browser panel of your client, T3 or Conn.
- `agent` has passwordless sudo, so it is root in this container. `www-data` has no sudo.
- There is no direct internet access. HTTP and HTTPS leave through Orbit's egress proxy, which `HTTP_PROXY` and `HTTPS_PROXY` name, so package managers and Git over HTTPS work. The proxy resolves every name: there is no DNS here. Other protocols, Git over SSH included, do not leave, and the proxy refuses private and Orbit addresses.
- Orbit fetches and pushes the Instance's Git branches from the Node, outside this container. This container holds no GitHub credentials, so a push from here does not reach GitHub.
