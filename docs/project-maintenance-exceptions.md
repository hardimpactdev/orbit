# Release-age exceptions in project config

Each private project extension owns its release-age exception records. The
configured seed preserves existing package, ecosystem, path, date and duration
values. New records live in an infinite Swamp resource on that project extension.
Configuration reads merge the seed with that resource. The first record for a
path, ecosystem and package keeps its original date and duration.

The shared Composer and JavaScript extensions accept exception records as input.
They do not read or write `maintainer-exceptions.json`. A security update still
changes the package manager's own exclusion setting where needed. It emits an
exception resource before changing that setting. The workflow returns that
resource even if the package command fails. The driver reconciles those records
into the project extension before accepting the run's result. Repeated receipts
append nothing twice. Failed persistence leaves the stage open for reconciliation.

The Swamp workflow intent saves its exception inputs. A retained workflow
reconciles its original inputs. Growing exception history does not change the
identity of work already in flight. Paths, policy, package target
and checked candidate still define that work and cannot change under its key.

Configuration resources advance to schema 3. Reads explicitly migrate the
unversioned v1 and schema 2 records. The four repository ledgers imported on
2026-10-27 are preserved in the private configs and in a fixture with their
GitHub blob hashes. Other adopter repositories have no ledger.

After review and deployment, repository removal PRs can delete the old ledger
alongside the maintenance package, script and config. Website still waits for
its separately scheduled proof. This change starts no live maintenance runs.
