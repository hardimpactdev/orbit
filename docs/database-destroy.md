# Database destruction

The Gateway records admitted database identities and the existing protected admin password. It starts one native Swamp workflow. Swamp builds every SQL statement, inventories test-prefix members, checks clients and dependencies, removes databases and unshared users, and restores PostgreSQL admission after a refusal or failure. No PHP probe, removal lock, generated SQL, or child Operation executes this work.

System database names and prefixes are refused before SQL. MySQL test-prefix inventory uses an exact, case-sensitive prefix. Every discovered member must match its admitted exact name or test prefix. Swamp verifies full MySQL grants for the recorded username before dropping any existing member. PostgreSQL checks its recorded owner before it fences connections. The session advisory lock spans the final blocker check and drops. Valkey preserves the default ACL user and all keys.

`destroyDatabase(input, withPrivateMaterial, signal, invoke, connect)` is exported for another native DAG to call as a placed step. The private callback returns `{password}` and never enters native outputs. The shared native effect and project steps pass a safe outcome using workflow lifetime.
