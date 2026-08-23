# Impl handoff: macos-managed-agent-impl

candidate=3fd8a965a9d61782c2d5ef6e22630c446979c691
dirty=false
base=732e61be405b88c36be05aec3379f4bf6abfa1a2
branch=codex/macos-desktop-managed-agent
venue=host-macos
quality-check=.orbit/quality-gates/profiles/2026-08-23T19-53-20Z-3fd8a965a9d6
proof-receipt=ok
review=FIX DEFECT 1 addressed

install outcomes now drive update_machine::transition. Handoff file is removed only after complete success. On apply error the handoff stays and UpdateState returns to RestartReady or Verified so retry remains visible. Partial owner-bin replacement resumes via apply_bound_update + reconcile.

focused tests:
- installer::tests::keeps_handoff_and_returns_restart_ready_when_apply_fails_before_replacement
- installer::tests::resumes_from_preserved_handoff_after_partial_owner_bin_replacement
- update_machine::tests::keeps_handoff_and_returns_restart_ready_when_apply_fails_before_replacement
- update_machine::tests::removes_handoff_only_after_complete_success

Mini proof: Desktop 95848 owned Agent 95947; truncated staged archive left hashes unchanged and owner handoff retained; resume test installed the bound Desktop/Agent/CLI set; label Restart to Update Orbit 0.1.196.

evidence=`.orbit/evidence/macos-desktop-managed-agent-3fd8a965a/update-install-proof.txt`

Retained topology proof: passed - host topology kind=host-macos; host=mini; os=Darwin 27.0.0 arm64 / macOS 27.0 26A5416b; command=`cd apps/macos && cargo run --bin orbit-macos`; evidence=`.orbit/evidence/macos-desktop-managed-agent-3fd8a965a/update-install-proof.txt`
