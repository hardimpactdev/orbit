---
title: "Container instances and releases"
description: "Development and production spike results, design decisions, remaining questions, and the implementation plan for Incus-backed applications."
---

# Container instances and releases

The two Laravel spikes proved that an application can run in an Incus system container. A development copy kept its own files and database. Production switched between prepared containers while requests finished on the old release. A small production deployment reached its HTTPS URL in about 10.5 seconds with warm dependencies and 12.2 seconds when adding packages.

This is the design record and proposed implementation plan from the experiments on 8 November 2026. The experiments are complete; the managed Orbit and Conn features are not. Proposed behavior below does not replace the current [Instance lifecycle](/domains/applications), [releases](/reference/deployments), or [task execution](/reference/tasks) contracts. Implementation starts by reconciling those contracts, not by promoting the spike scripts into a second execution engine.

## Direction and vocabulary

The priority is fast, isolated development and deployment with few moving parts. Reuse prepared files, installed dependencies, Orbit's databases and Routes, and Incus's native snapshots. Avoid installing dependencies from nothing for every feature or transporting a newly built application image for every same-node release.

| Term | Meaning in this design |
| --- | --- |
| Project | Orbit's existing application/repository identity. An Incus project is only an Incus resource namespace; the two are not interchangeable. |
| Project VM | A development VM on a Node, providing a kernel boundary for one Project's main and feature containers. |
| Development Instance | One independently editable container, its Git checkout, runtime, environment, and database attachment. |
| Preparation container | A persistent production workspace that fetches source, installs locked dependencies and builds assets. It never serves application traffic. |
| Production Instance | The stable Orbit identity, Route, environment, and database attachments for the deployed application. |
| Release container | One candidate, active, or retained release beneath that production Instance. Creating a release does not create another production Instance. |

The development baseline is one outer VM per Project and one inner container per Instance. The production baseline is Incus containers on the production VM/Node, with Caddy outside them. An UpCloud VPS can supply the outer VM boundary itself. It does not need another nested VM solely to run system containers. A separate provider provisioning test is still needed before calling that path supported.

## Why Incus, and how Lima and Docker relate

[devvm](https://devvm.dev/) inspired the discussion: give a project a prepared machine in which its tools and agent run. Its [Lima backend](https://devvm.dev/docs/backends/lima/) creates VMs. [Lima](https://lima-vm.io/docs/) manages local virtual machines, including guests that run container tools. [Incus](https://linuxcontainers.org/incus/docs/main/explanation/containers_and_vms/) manages both system containers and VMs. Orbit already uses Incus on its Linux Nodes, so this design reuses that operational model rather than adding Lima there.

A system container fits a development workspace that contains PHP, Bun, a shell, services and an agent. Its filesystem can be copied with installed dependencies intact. It shares the outer machine's kernel. The outer VM separates that kernel from the physical Node; sibling containers do not each get a kernel boundary.

Docker can also run agents inside containers, and bind-mounting a host checkout is optional. It is not inherently limited to an agent editing host files. Both approaches can provide isolation. Docker builds can use caches, and blue/green traffic switching does not require Swarm. The choice here is to avoid adding an OCI build/registry/distribution workflow for this same-node use case, not a claim that Docker cannot be fast or safe.

The production design does take responsibility for release lifecycle, health, routing and cleanup. Those responsibilities remain even without Docker. Incus supplies machine lifecycle and copy-on-write storage; Orbit supplies the application contract.

## Development design

Each Project's development containers share an outer VM and use databases managed outside it.

```text
Development Node: Beast
├── Existing Orbit-managed PostgreSQL server
│   ├── main database and owner
│   └── feature database and owner
└── Project VM: own kernel
    └── Incus system containers: shared Project VM kernel
        ├── main: checkout + PHP + Bun + Conn/T3 backend
        └── feature: independent copy + its own Conn/T3 identity
```

### Create and use a feature

The intended flow is to select a source Instance, copy its prepared container, create the feature branch, clone its database through Orbit, replace Instance-specific values and identities, start its processes, and publish its own Orbit URL. The agent runs inside the feature container against a real directory such as `/workspace/app`. No writable host checkout is needed.

The copy includes useful workspace state such as `vendor`, `node_modules`, installed tools and source files. It must not silently copy the source's database identity, Conn pairing, provider login state or machine identity. Source credentials and copied jobs must not become active in the new Instance before its environment is replaced. The first implementation must define whether a dirty source is supported; the spike does not settle that product behavior.

A filesystem copy is not an exact copy of all external state. With PostgreSQL outside the container, database cloning is a separate operation. The spike used a quiescent application. A busy application's files and database need an explicit consistency boundary; independent snapshots do not make a cross-resource transaction. S3 objects, queues, mail, webhooks and other integrations also require Instance-specific behavior. Copying database rows must not cause a feature to execute the main Instance's pending jobs or send its real notifications.

Finishing a feature means delivering committed source through Git and disposing of its container and owned development database. Containers and database contents are not merged. Schema changes travel as migrations. Uncommitted work, unpushed commits and useful conversation history need to be retained or reported before removal. The proof showed Git delivery while main's database remained unchanged.

### Databases and locality

Development on Beast uses Beast's existing Orbit-managed PostgreSQL server. The original Services database choice added an avoidable network hop. Production can use a shared database server on Services. Placement is explicit; development and production need not use the same database Node.

Each feature gets its own database and owner on the shared server. A container per database server is unnecessary for this model. Reuse Orbit's connection records, creation, attachment, environment synchronization and destruction. Current source at `9ff162721` documents development cloning for MySQL and SQLite, but not PostgreSQL. The PostgreSQL dump/restore adapter in the spike is not shipped support.

The adapter used `pg_dump`, then `pg_restore --no-owner --no-privileges` into a newly provisioned target, with one restore transaction and error checking. It refused a nonempty target and compared normalized schema, data and sequence dumps. Roles, grants and passwords were owned by Orbit's database provisioning, not copied from the source. Permissions must distinguish databases even when they share a server. A database boundary does not provide CPU, memory or disk isolation from other databases on that server.

Database size will often dominate feature creation time. The one-second fixture clone is not evidence for a multi-gigabyte database. Start with the existing database layer plus the measured adapter; do not build a new database platform for this feature.

### Conn and the agent

Conn can continue spawning an agent in a directory. The missing product concept is where that directory lives. A project selection needs a stable Orbit Instance identity and its Conn/T3 environment, not only a path on the desktop's filesystem.

The spike ran a separate T3 backend in each inner container. Each accepted authenticated terminal commands in `/workspace/app`, rejected unauthenticated access, and had a distinct server identity. That proves remote execution placement. It does not prove Conn's container picker, automatic pairing, an actual AI provider turn, or conversation recovery after disposal.

The proposed first integration follows the proved arrangement: one backend per development container, exposed through Orbit's existing private connectivity and pairing model. Conn presents the Project and its Instances; it resolves the selected Instance to that container's backend and directory. A broker that enters many containers from one shared backend would be a different design and needs its own isolation proof.

Backend state, pairing credentials, provider authentication and conversations need explicit ownership. Runtime binaries may be cloned. Live server identity and authentication state may not simply be cloned. A stopped container must be woken by a control path outside that container. Decide how conversation history remains available after Instance disposal before shipping removal.

The earlier Laravel toolbar issue had two independent surfaces: PHP Data Bridge in the guest's effective PHP configuration, and Conn's fork-owned toolbar/header/RPC wiring. The development image needs the bridge and matching PHP extension/API. Conn's `FORK.md` must continue recording the toolbar, header relay, annotation queue, required RPCs and Orbit integration through upstream syncs. Enabling annotations is not the only reason a fork RPC can exist; registration and other host features have their own contracts. Do not disable the entire group when resolving an upstream conflict.

The production Annotator dependency experiment only proved package installation, bundling and asset delivery. It did not add an annotation backend. Conn's native annotation integration and an application's optional `@nckrtl/annotator` dependency are separate paths.

### PHP and process supervision

For development, `php artisan serve` is the proposed default inside the container. It launches PHP's built-in HTTP server using a PHP binary in the container. It does not borrow host PHP, and it does not eliminate the need to install PHP. A separate PHP-FPM service and inner Nginx/Caddy are unnecessary for this development arrangement; the outer Caddy owns the URL and TLS.

The built-in server normally handles requests with one worker unless configured otherwise. One application per container does not imply one user or one concurrent request. A long request can block another. The lower timings in this experiment do not establish that it outperforms PHP-FPM under concurrent load. PHP documents it as a [development server](https://www.php.net/manual/en/features.commandline.webserver.php), not a production server.

Represent the development server as an Orbit Process with normal supervision. A Laravel HTTP 500 normally fails that request without killing the server; restart policy covers process exits, not response status codes. Keep crash restart, desired-running state and keep-alive/idle policy separate. A process that exits successfully but should remain serving also needs a defined restart policy.

### Idle Instances

An idle container generally uses little CPU but can retain service memory and filesystem cache. In the development sample, about 695 MiB was charged to the container running Laravel and T3, including cache. It was not a near-zero-memory process. Clean stop removed the inner container's processes and reported container memory, while preserving its disk and database.

The desired policy is to stop inactive development containers after about one hour, and wake them when needed. This is stop/start, not saved RAM or process checkpointing. Active agent turns, terminals running commands, builds, requests, workers and keep-alive Processes must influence the policy. Merely opening a tab is not enough to define useful activity. Existing [runtime hibernation](/reference/app-dev-runtime-hibernation) provides a product surface. Stopping an entire container requires revisiting how that feature treats host PHP-FPM and prunes dependencies.

Stopping a child does not stop the Project VM. An outer-VM idle policy can save more memory when all its children are stopped, but must also account for other work and scheduled jobs. Container and VM wake should share one visible progress/failure path. The spike only manually tested stop/start. Automatic idle detection and waking on requests remain unbuilt.

## Database performance lessons

The session experiments changed more than the database engine. Network placement, connection setup, connection lifetime and durable disk writes each affected request time. Preserve those distinctions when comparing engines.

| Observation | Supported conclusion |
| --- | --- |
| Moving PostgreSQL from Services to Beast reduced a small warm sample's median request time from 95.166 ms to 24.635 ms | Local placement helped this development workload. The samples were small and still had outliers. |
| PostgreSQL connection setup from the container measured about 7.558 ms; `SELECT 1` about 0.2 ms | Connecting can cost far more than a trivial query. Nested networking was not shown to add tens of milliseconds per query. |
| Enabling persistent PostgreSQL PDO reduced a six-request warm median from 25.767 ms to 9.0745 ms | Connection reuse helped this sequential sample. It did not remove commit durability costs. |
| SQLite WAL/NORMAL still had roughly 10 ms session writes with short connection lifetimes | WAL/NORMAL does not mean that all writes complete asynchronously in memory. |
| Keeping another SQLite connection open reduced warm session writes; persistent PDO later measured a 0.1445 ms median | Connection lifecycle and WAL cleanup/reset were material. Exact syscall attribution was not traced. |
| One PostgreSQL session UPDATE took 270.721 ms while its backend repeatedly waited on `IO / WalSync` | The immediate bottleneck was durable WAL flush, not a sampled row lock or a saturated CPU. |

The long PostgreSQL event occurred while Beast was about 82.64% CPU idle. One member of the three-drive ZFS pool had much higher sampled flush latency than its peers. The pool reported no read/write/checksum errors. This is storage-tail-latency evidence, not a diagnosis of a failing SSD. Do not explain all intermittent middleware-out latency as generic server load or container overhead.

The retained development demo ended on Beast PostgreSQL, database-backed sessions, persistent PDO disabled, `synchronous_commit=on` and `fsync=on`. The temporary SQLite database and its settings were retained for comparison. Switching engines did not merge writes made in one back into the other.

Persistent PDO connections belong to PHP worker processes and matching connection configurations, not to a visitor or the container as a whole. They may remain until the process ends or the connection is closed/lost. More workers can mean more open database connections. Budget those connections, release transactions and locks, reset session-specific state where needed, and handle stale connections. Normal nonpersistent PDO remains the default in the PostgreSQL demo. See [PHP connection lifetime](https://www.php.net/manual/en/features.persistent-connections.php).

SQLite used WAL, `synchronous=NORMAL` and `busy_timeout=5000`. WAL improves reader/writer interaction but still permits only one writer at a time. Checkpoints and connection shutdown can perform I/O; long readers can delay WAL reuse. NORMAL trades durability after an OS/power failure for fewer commit-time syncs; persistent PDO alone does not turn durability off. Use SQLite's backup facilities for a live copy rather than copying only the main file. See [SQLite WAL](https://www.sqlite.org/wal.html) and Orbit's [SQLite settings](/solutions/sqlite-wal-default).

PostgreSQL's `synchronous_commit=off` can avoid waiting for a local durable commit, with the risk of losing recently acknowledged transactions after a crash. It is not persistent PDO, SQLite WAL mode, or `fsync=off`. A probe used it in explicit temporary transactions; it was not applied as the application's normal setting. Preserve PostgreSQL's durability defaults unless the application explicitly accepts that trade-off. See [PostgreSQL WAL settings](https://www.postgresql.org/docs/18/runtime-config-wal.html).

## Production design

The proposed production layout keeps preparation separate from serving and durable data outside the releases.

```text
Production Node / provider VM
├── Orbit-managed Caddy: stable public/private Route
├── preparation container: source + dependencies + asset build; no traffic
├── active release container: FrankenPHP + release environment
├── candidate container during deployment
└── retained previous release: stopped after draining

External durable services: PostgreSQL, object storage, shared sessions/queues
```

There are normally two running roles at most when preparation is active: prep and the live release. Deployment temporarily adds a candidate. Retained rollback containers still occupy disk even when stopped. “Two containers per project” describes the core roles, not a hard limit of two resource records through a deployment.

### Release sequence

The deployment follows these phases. Projects that require build-time public values must declare those inputs without exposing runtime secrets in assets.

1. Resolve an exact committed revision and serialize preparation for that production Instance.
2. Start prep and fetch the chosen revision. Install locked dependencies and build assets using retained caches. Keep production environment values and application traffic out of prep.
3. Stop prep cleanly and snapshot it. Clone the snapshot using the storage driver's native copy-on-write support. Give the candidate its own identity and endpoint.
4. Inject the production environment into the candidate. Generate environment-dependent config, route and view caches there, keeping them out of the reusable prep snapshot.
5. Run the application's migration and activation steps once. These operate on the shared production database; the database is not cloned per release.
6. Start the production server and check application readiness, including required database access. A failed build, activation or readiness check leaves the old release selected.
7. Switch the Route to the healthy candidate and verify through HTTPS. Send new requests to it. Drain existing requests on the previous release.
8. Drain the old server, stop its container, and retain the recorded rollback release. Clean up only releases and snapshots outside the retention policy.

The implementation needs one authoritative active-release identity and a recoverable relationship between that record and the proxy's actual target. A crash after proxy reload but before result projection must not trigger a blind second switch or delete the serving container. Use Swamp's native run/result/recovery model and observed machine state. The spike's local JSON file and `flock` are proof scaffolding, not the production control plane.

### Warm preparation is useful, but not pristine by definition

The selected approach reuses a persistent preparation checkout and its dependencies. It avoids starting from a blank directory on each release and avoids copying the live container's runtime changes back into the next release. That is a useful boundary even without a general-purpose filesystem-cleaning system.

`composer install --no-dev` reconciles dependencies against the lockfile. It does not prove that every generated file in an application checkout is fresh or untampered with. Build/install scripts can leave files, and scripts themselves execute code. Tracked source should match the chosen commit; each Project's build owns any generated output that needs cleaning. Define this narrowly. Do not add a whole new cleanup framework or claim bit-for-bit reproducibility from the spike.

The experiment proved that a file created only in the serving container did not appear in the next candidate. It did not prove that prep is immutable or trusted against malicious build inputs. Prep should have only the source/package access it needs; production database and application secrets arrive after cloning. Recipe/toolchain updates need a controlled prep rebuild, rather than leaving an old OS and PHP installation forever.

### Runtime and data

FrankenPHP works in classic mode as well as worker mode. The spike used classic mode with four PHP threads; worker mode is an optional later optimization with a different application-lifetime contract. PHP-FPM is not needed inside these release containers. For this path, the host needs routing and Incus management, not a PHP-FPM pool for each application. Existing host applications still need their current runtime until they are migrated.

The intended durable-file model is S3-compatible storage. Setting `FILESYSTEM_DISK=s3` alone does not implement it: the spike had no configured bucket/upload feature and did not test S3. Sessions, queues, cache namespaces and application keys must also survive the release boundary deliberately. Local temporary files and logs need a defined lifecycle.

Old and new code overlap during draining and rollback. Migrations therefore need an expand/contract strategy when compatibility matters. Failed readiness after a successful migration does not undo that migration, and code rollback does not reverse database history. Keep scheduler and queue-worker ownership explicit: never start duplicate scheduled jobs merely because a candidate booted. Long jobs, WebSockets and other persistent connections need their own drain policy.

Asset compatibility also crosses the switch. An old page can request a hashed asset after traffic has moved to the new container. Preserve versioned assets for the overlap/rollback window, or serve them from a shared store that retains those versions. S3 uploads and built frontend assets are distinct concerns. The spike's retained build files are not a deliberate asset-retention contract.

## What the spikes proved

### Development on Beast

The outer `project-vm` had a 4-vCPU limit, 6 GiB RAM limit and 30 GiB virtual disk. It ran Ubuntu 24.04 and its own kernel; the Ubuntu 26.04 inner containers shared that kernel. Inner storage was Btrfs. Root in a child mapped to an unprivileged UID in the VM. No host project directory was mounted. Laravel and T3 ran as `dev`.

| Check | Result |
| --- | --- |
| Container filesystem copy | 0.114 seconds, excluding stop/start and configuration |
| PostgreSQL fixture copy | 1.111 seconds; normalized schema/data/sequence dumps matched |
| Independence | Feature file and data changes did not affect main; feature owner could not access main's data |
| Application and backend | Both apps served HTTP; each T3 backend accepted its own authenticated terminal commands |
| Inner stop / wake | 1.274 seconds to stop; 3.745 seconds to feature HTTP 200 after wake |
| Delivery / disposal | Feature commit delivered through Git; feature container, database and owner removed; main remained usable |
| Outer VM restart | Main served again and retained its external database after a full stop/start |
| Browser / toolbar follow-up | `https://container-spike.test/` served through Orbit; PHP bridge produced HTML and `x-toolbar` data |

The database clone/isolation timings came from the original Services-hosted fixture. Moving main to Beast was subsequently verified, but feature creation on the relocated database was not rerun. The named feature database on Beast was reserved in the scripts, not created. Do not report that as end-to-end proof of the final local-database clone path.

### Production on app-prod

The production test used Ubuntu 24.04 guests, Incus 7.0.2, ZFS 2.4.1, FrankenPHP 1.13.5, PHP 8.5.12, Bun 1.4.0 and Laravel 13.40.0. Each container had a 1-CPU/768-MiB limit. Storage was a separate 4-GiB file-backed ZFS pool; these are not dedicated-disk throughput results. PostgreSQL and sessions were on Services.

The initial r1-to-r2 proof served 126 sampled HTTPS requests with no failures. An intentional eight-second request finished on r1 while new traffic reached r2. The same database session survived promotion, rollback and another promotion. A deliberately unhealthy candidate failed readiness and did not take over. The source of the next release remained prep, not the serving container.

| Deployment | First new HTTPS response | Completed old-container retirement | Sampled traffic |
| --- | --- | --- | --- |
| Warm repeat 1 | 10.525 s | 11.424 s | All successful |
| Warm repeat 2 | 10.322 s | 11.286 s | All successful |
| Warm repeat 3 | 10.601 s | 11.488 s | All successful; 218 samples across the three runs |
| New Spatie and Annotator dependencies | 12.231 s | 13.240 s | 86 samples, all successful |

The warm median was 10.525 seconds. Average prep took 5.667 seconds and clone-through-readiness took 4.200 seconds. Clone alone took 0.355–0.380 seconds. The new-dependency release installed Spatie Laravel Data 4.23.1 with nine new PHP packages in total, and Annotator 0.3.0 with six new npm packages in total. Composer downloaded the new archives during deployment. Its install took 3.550 seconds, Bun install 0.404 seconds, and the asset build 1.286 seconds. That release exercised a Spatie Data object before switching and served the 332,833-byte compiled JavaScript asset over HTTPS afterward.

These measurements start on app-prod before starting prep and end at an observed response from the new container through the actual HTTPS Route. They include dependency installation, asset rebuild, clean prep stop/snapshot, candidate setup, migrations, caches, health and switching. They exclude CLI-to-Gateway admission, Swamp scheduling, SSH connection setup, and the developer's dependency resolution/commit creation. Source fetch was from a local bare repository. Existing dependencies were warm, migrations had no pending changes in the timing runs, and the application was tiny. The initial compatibility proof did exercise an additive migration.

The request monitor paused 100 ms between sequential requests. No failed samples is useful evidence, not proof that every possible client saw zero interruption or that an outage shorter than the sampling gap was impossible. No throughput or concurrency benchmark was run. Registry slowness matters: the earlier first-install Composer attempt had stalled on a remote download. Warm caches reduce that exposure, but a new dependency can still be delayed by its upstream.

### Isolation and host changes

The outer VM provides the main development kernel boundary. Inner containers share it; a kernel escape could affect siblings in that VM. Production containers on one VM likewise share that VM's kernel. The absence of a coding agent in production reduces one source of arbitrary actions but does not remove application vulnerabilities. An Incus namespace alone is not a dedicated-kernel boundary.

Neither spike is a security audit. Management sockets, host mounts, network access, credentials and database roles still define reachable authority. Do not expose the host Incus socket or unrelated writable host paths to the development agent. Sharing a client VM across Projects changes the trust boundary and must be an explicit placement choice.

The app-prod spike installed Incus from the signed Zabbly LTS source after the distribution's Incus build hit AppArmor stacking problems. It also installed ZFS, capped ARC at 512 MiB, added scoped bridge/firewall rules, and ran a separate loopback Caddy proxy for release switching. Zabbly's compatibility setup changed `kernel.apparmor_restrict_unprivileged_unconfined=0` on the host. Existing PHP-FPM sites stayed in place. These changes are recorded for review; they are not an approved fleet-wide recipe. The development cached VM needed Secure Boot disabled. Convert the required runtime into the supported recipe and verify its host policy before rollout.

## Implementation plan

Build thin, usable paths. The recommended order is shared placement and database work, development end-to-end, then production end-to-end, followed by lifecycle hardening. Production specification can proceed alongside development, but sharing low-level Incus operations does not require a generic new orchestration framework.

### 1. Set the contracts and replacement boundaries

Write the ADRs and the affected command charts before changing behavior. Keep a stable production Instance with child release identities, and distinguish the development Project VM from an Incus project namespace. Reconcile the proposed container cloning with the current prohibition on copying whole Instance checkouts and per-Instance datasets. Preserve initial production creation from an eligible clean candidate unless a separate decision changes it: making a production Instance and deploying its next release are different operations.

Inventory the affected current paths: [Instances and database cloning](/domains/applications), [initial production cloning](/reference/instance-cloning), [releases and rollback](/reference/deployments), [PHP runtime](/reference/php-runtime), [Processes](/reference/processes-and-schedules), [environment synchronization](/reference/environment-variables), [Routes](/reference/routes), [Conn pairing](/reference/conn), and [hibernation](/reference/app-dev-runtime-hibernation). The old paths remain authoritative until their replacement ships. At each conversion, switch the covered path and remove the redundant writer; do not leave two controllers managing the same Instance.

Follow the current [command development pipeline](/reference/command-development-pipeline). The Gateway owns validation, intent, stable records and result projection. Swamp owns execution, with machine work in Ansible phases under the binding [shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Reuse native execution, secret handling, recovery and concurrency. Do not move the spike's Python orchestration, private credential export helpers or local lock files into Gateway lifecycle code.

This feature concerns interactive application Instances and production releases. It does not silently replace the existing task-group VM/agent runtime or the nested command-proof fleet. Those uses already have their own ownership and topology contracts.

**Deliverable:** agreed Instance/release identity, placement, data-copy and removal contracts; command charts and runnable proof cases; an explicit list of the code paths each slice replaces.

### 2. Deliver one managed development Instance and feature clone

Prepare a versioned Project VM/runtime recipe with inner Incus, PHP, Composer, Bun, the PHP bridge and the Conn backend. Record the Node, VM, container, image generation, path, endpoints and resource ownership through the existing model boundaries. Provision a main application without a writable host source mount. Give it an Orbit Route and supervised development processes.

Add PostgreSQL cloning to the existing database lifecycle. Create and attach a fresh database/owner before feature activation; copy with a defined consistency boundary; replace the environment and machine/backend identities; then start the feature. Make failure and removal clean up only the resources that this Instance owns. Measure the complete clone operation as well as its database and filesystem parts.

**Acceptance:** from one supported command/API flow, create a Project's main container on Beast, create a feature with a distinct URL and Beast-local PostgreSQL database, modify files and rows only in the feature, and show main is unchanged. Deliver a Git commit, remove the feature, and show main and unrelated resources still work. Repeat with a failed restore and an interrupted operation. Re-run the local-database path that the original spike did not cover.

### 3. Make the development path usable through Conn

Add Project/Instance selection that resolves to the container backend and its directory. Use existing Orbit pairing rather than copying server userdata. Validate real provider authentication and a real agent edit/test/commit entirely inside the container. Keep terminal, files, browser, logs, Laravel toolbar and annotation queue scoped to the selected Instance. Document the fork-owned changes in Conn's `FORK.md`, with regression coverage through an upstream sync.

Add whole-container sleep/wake using the existing Orbit activity and Process concepts. Stop after the chosen idle period only when policy permits. Wake from the Route or Conn selection, outside the sleeping backend, and show progress/failure. Preserve durable files and conversations, and define scheduled work while asleep. Add automatic shutdown of the outer VM after waking individual children is reliable.

**Acceptance:** select main and feature in Conn, run an actual agent turn in each, confirm their directories and identities, sleep one while the other stays usable, wake it through its URL and Conn, and recover after backend restart. A running agent/build prevents automatic stop; ordinary idle does not.

### 4. Deliver the first managed production deployment

Implement the prep → snapshot → candidate → activation → readiness → switch → drain → retain sequence in native Swamp/Ansible phases. Provision Incus and FrankenPHP through the supported recipe. Keep production environment delivery after cloning. Use the existing production Instance, deploy-step definitions, database attachments and Route; support a server definition that is not PHP-FPM.

Replace the spike's secondary hand-managed Caddy arrangement with the owned routing implementation. Preserve graceful request completion and recovery across the switch/result boundary. Expose the selected commit, runtime generation, active/candidate/previous release, failure, and stage timings in the normal Orbit surfaces.

**Acceptance:** deploy a real Laravel application with changed dependencies and a compatible migration, keep an old request running through the switch, reject a bad candidate, retain the same session/data, and roll back application code. Verify S3 upload persistence with a real bucket and credentials. Verify old hashed assets remain available during the overlap. Initially cover web-only applications; queue/scheduler support must pass the next slice before those workloads migrate.

### 5. Complete lifecycle and operational acceptance

Define and prove queue/scheduler handover, long-lived connection limits, release retention and failed-candidate cleanup. Test toolchain/prep refresh, full disk, memory pressure, backend/Node restart, failed proxy reload and interruption on either side of switching traffic. Preserve usable rollback releases and resource ownership throughout. Stopped snapshots consume storage; define retention and report actual pool use rather than treating clones as free.

Measure the managed path from the user's deploy request through Gateway/Swamp dispatch to the first new HTTPS response, with drain completion separate. Report cold provisioning, warm code changes, dependency changes, migrations and larger application builds separately. Repeat representative runs and test concurrent visitors; retain exact commit, runtime, host load and cache state with results. The observed 10–13 seconds is a baseline to protect, not a promised SLA or proof of Swamp end-to-end time.

Validate the same inner-container model on an explicitly provisioned UpCloud VM, including reboot, networking, storage, URL, database reachability and cleanup. Then migrate selected Projects through the supported path. Host PHP-FPM remains until its last dependent workload is migrated. Broader multi-node failover and a client self-service UI can follow the single-Node lifecycle; neither is proved by this spike.

**Deliverable:** operational proof, recovery/runbook, defined resource limits and retention, recorded timings, and removal of the replaced code for migrated workflows.

### Questions to settle in the contract slice

These choices need a product decision before their dependent behavior is implemented.

| Question | Starting recommendation for discussion |
| --- | --- |
| Project VM or client VM? | One VM per Project first. Permit client grouping only when sharing a kernel across those Projects is acceptable. |
| Consistent feature copy of a running app? | Start with a short, explicit quiescent copy, as proved; specify what is paused and how it resumes on failure. Measure the impact before designing live cross-resource snapshots. |
| Dirty source when creating a feature? | Decide whether to require a clean source or intentionally capture dirty work. Never silently discard it or claim it is committed. |
| Conn history after disposal? | Keep conversation ownership separate from a disposable runtime identity; select its durable location before shipping deletion. |
| Idle policy and schedules? | Start with the requested one-hour target, with active work/keep-alive preventing stop. Set scheduled-work behavior explicitly. |
| Production build outputs and secrets? | Project-owned output cleanup, pinned source/locks, explicit build inputs, runtime secrets only after cloning. No universal “pristine” guarantee. |
| Scope of the first production release? | Single Node, classic FrankenPHP, external PostgreSQL and S3, HTTP app first; expand after proving workers, assets and recovery. |

The first implementation task should deliver the managed main-plus-feature flow in slice 2, after slice 1 fixes its contract. It should not begin with a broad container management UI or a new runtime abstraction spanning every possible provider.

## Evidence and recovery map

Use these reports and resource identities to reproduce the proof or find its retained demo.

| Item | Location or identity |
| --- | --- |
| Design base | Orbit `9ff162721`, current main when this record was prepared; Conn's local `FORK.md` was inspected for its current protected features |
| Development scripts, report and raw evidence | Beast: `/home/nckrtl/spikes/orbit-container-1108/` |
| Development resources | Incus project `orbit-container-spike-1108`, outer `project-vm`, inner `main`; Route 471 at `https://container-spike.test/` |
| Development database | Beast connection 61, `spike1108-beast-main`; original Services connection 59 is an inactive rollback copy, not a replica |
| Development Git proof | `7f8040d9ffff3ccfe88ad94897a34a1aed1424e9` in `spike-app.bundle` |
| Production scripts, report and raw evidence | app-prod: `/home/orbit/spikes/production-container-1108/`; Mac copy: `/Users/nckrtl/Downloads/orbit-production-container-spike/` |
| Production resources | Incus project/pool `prod-spike-1108`; Route 472 at `https://container-spike.prod/`; Services connection 62, `spike1108-prod` |
| Production state at handoff | `deps1` active, `r2` retained as previous; prep stopped; `r1` also retained. The temporary speed and authoring containers were removed. |
| Production dependency revision | `22113fa1d2021f6c967015531560170400ff5fa5`, tag `dependencies`, in `production-app.bundle` and `dependency-change.bundle` |
| Production private environment and state | app-prod: `/root/orbit-prod-spike/release.env` and `state.json`; never copy their secrets into the report or repository |

Development evidence includes `clone-proof.log`, `t3-proof-retry.log`, `sleep-proof.log`, `git-proof.log`, `dispose-feature.log`, `php-data-bridge.json`, the PostgreSQL/SQLite comparison JSON files, and `pg-observation-114545-*`. Production evidence includes `proof-result.json`, `warm-deployment-measurements.json`, `dependency-deployment-result.json`, `dependency-cache-before.json`, `deps1-prepare.log`, and `dependency-https-check.json`. The companion `docs/solutions/container-runtime-evidence.json` preserves the reported figures without credentials or per-request session tokens.

The host reports retain the exact setup and cleanup procedures. Creation scripts use fixed spike names and must not be rerun over retained resources. Cleanup is scoped to these namespaces, Routes, database connections, units and firewall entries. Check current ownership before reverting a host package or kernel setting. This record grants no instruction to remove either live demo.
