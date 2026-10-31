# AI-router source retirement step

The owning setup, converge or teardown root composes `ai-router-remove-source` with its original Operation ID, kind and root Node ID. Public intent supplies the effect `target_node_id` and boolean `retired`; execution_secrets is empty. There is no separate Gateway Operation, private callback or secret delivery dependency.

The worker resolves `scripts/remove_source.py` from its fingerprinted extension bundle, then a placed command step executes that file on the same worker. It removes only `/var/lib/orbit/ai-router` (`retired: false`) or `/var/lib/orbit/proxycli` (`true`). Symlinked ancestors, symlink targets and non-directory targets fail. Missing paths are unchanged; descendant symlink referents and unrelated directories are preserved.

The action prints one public JSON object: `removed` on success, or a fixed error code with a nonzero exit status. The lifecycle must stop and verify the old owned runtime before this action. Its own terminal projection records the outcome; this lower starts no child Operation and makes no Gateway or agent call. No frozen agent source changes. Gateway lifecycle admission/cutover remains with its owning parent conversion; this PR does not claim that unconverted PHP callers have migrated.
