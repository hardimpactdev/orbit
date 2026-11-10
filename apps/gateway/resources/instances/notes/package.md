# This environment

You work in an Orbit development Instance: one container that holds one package. It has no web app and no URL to preview.

- The workspace is `/srv/workspace`. It is a Git repository of the Project.
- Two users share it. You run as `agent`. Orbit Processes run as `orbit`. Both are in the group `work`, so each can read and write the other's files.
- `/etc/orbit/instance` names the Instance and its Project.
- A development server that you start yourself, such as a documentation site, runs as `agent`. You see it in T3's browser through T3's port preview.
- Node, npm, Bun, Vite+ (`vp`), Composer and rg come from `/opt/orbit-runtime`. PHP comes from `/opt/orbit-runtime/current/php/<version>/bin`, which holds `php` and `frankenphp` for each PHP version. No `php` is on `PATH`; Composer runs with the first `php` on `PATH`. Claude Code and Codex come from `/opt/orbit-agent`. Both folders are read-only.
- There is no sudo.
- There is no direct internet access. Orbit fetches and pushes Git branches from the Node, outside this container. A push from here does not reach GitHub.
