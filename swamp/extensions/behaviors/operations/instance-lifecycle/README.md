# Native Instance lifecycle

Each parent has one native workflow and a truthful root Node. Placed source,
destination and Route effects remain steps of that run.

- `rename_root.ts` changes a recorded checkout branch.
- `register_root.ts` inspects and adopts a recorded checkout.
- `create_root.ts` and `clone_root.ts` compose native source, environment and
  setup work.
- `transfer_root.ts` composes private native archive streams, atomic placement
  callbacks and canonical Route publication.

Transfer archive bytes use native file resources. Public step outputs contain
only archive handles, size, digest and filesystem identity. Linked worktrees
retain their staged index and objects at the destination. Source cleanup refuses
a replaced checkout and preserves shared worktree repositories.

The transfer DAG orders destination readiness before capture and publication
after cutover. Pre-cutover recovery restores source publication before removing
only proven destination files and unused candidate certificates. Post-cutover
failures retain the authoritative destination.

Run `transfer_archive_test.ts` and `transfer_paths_test.ts` for real local
Git/tar/filesystem effects. `transfer_native_test.py` executes the actual native
DAG with fixed Gateway and local effect boundaries.

No native action calls orbit-agent. The agent source remains frozen.
