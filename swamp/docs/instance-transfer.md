# Native Instance transfer

Transfer runs as one native parent DAG. Source and destination actions use their
admitted workers. Private checkout bytes travel through Swamp's native file
writer and repository stream; they never enter workflow JSON or the Gateway.
Each archive binds its byte count, SHA-256, Git HEAD, branch, layout, and safe
origin.

A linked worktree becomes an independent checkout at the destination. Capture
preserves its index and includes staged objects as well as committed history.
Tracked edits, staged new files, untracked files, and private environment files
keep their contents. The source index and common repository remain unchanged.
The destination closes public permissions on its environment file.

The composing parent owns Instance placement and Process state. The Route
family's target actions prepare local workload access, certificates, firewall,
verification, and environment. Its bounded callbacks project Route state at
cutover or rollback. Publication reads the resulting stored state through the
canonical Caddy, PHP, and DNS methods.

Before cutover, a failed or cancelled transfer restores the source runtime
before destination cleanup. After cutover, it recovers forward. Cleanup uses the
admitted path and native ownership evidence; it never deletes an unproven
replacement. Temporary local archive and extraction files are removed when an
action ends. Native archive resources use the workflow lifetime.

No transfer action invokes orbit-agent. The agent source remains frozen.

Process batches use the canonical Process installer and local account templates.
The DAG verifies admitted destination ports, pauses source runtime, then
captures the archive. After atomic Instance and Process path relocation, it
prepares Vite traffic, publishes PHP/Caddy/DNS, installs the destination batch,
and verifies running Vite listeners. Only then does it retire source units and
files. A failed partial pause records native restoration intent before the first
stop; recovery restores source runtime before discarding destination resources.
No source installation material is readable after cutover.

A selected SQLite database uses the canonical database snapshot and seed actions
inside this DAG. Transfer currently accepts it only without PHP/Laravel runtime
or external database bindings: stopping managed Processes does not fence HTTP
writers. Export follows Process pause, the archive excludes the database and
sidecars, and destination installation precedes cutover. A bounded native merge
changes only DB_CONNECTION and DB_DATABASE in the archived environment; all
other private lines remain intact. Both participants clean their temporary DB
artifacts on success, failure and cancellation before terminal reporting. A
cleanup failure cannot claim success or completed restoration. Database bytes
use a native file resource and never enter JSON or Gateway.
