# This environment

You work in an Orbit development Instance: one container that holds one package. It has no web app and no URL to preview.

- The workspace is `/srv/workspace`. It is a Git repository of the Project.
- Two users share it. You run as `agent`. Orbit Processes run as `orbit`. Both are in the group `work`, so each can read and write the other's files.
- `/etc/orbit/instance` names the Instance and its Project.
- A development server that you start yourself, such as a documentation site, runs as `agent`. The Instance has no URL, so nothing outside the container reaches it. Check it from the container, for example with `curl`.
- Node, npm, Bun, Vite+ (`vp`), Composer and rg come from `/opt/orbit-runtime`. PHP comes from `/opt/orbit-runtime/current/php/<version>/bin`, which holds `php` and `frankenphp` for each PHP version. When the Project has a PHP version, `php` on `PATH` is that version, so `composer` works. Claude Code and Codex come from `/opt/orbit-agent`. Both folders are read-only.
- Only in a nested-fleet container, `agent` has passwordless sudo.
- There is no direct internet access. HTTP and HTTPS leave through Orbit's egress proxy, which `HTTP_PROXY` and `HTTPS_PROXY` name, so package managers and Git over HTTPS work. The proxy resolves every name: there is no DNS here. Other protocols, Git over SSH included, do not leave, and the proxy refuses private and Orbit addresses.
- Orbit fetches and pushes the Instance's Git branches from the Node, outside this container. This container holds no GitHub credentials, so a push from here does not reach GitHub.
