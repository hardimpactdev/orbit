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
