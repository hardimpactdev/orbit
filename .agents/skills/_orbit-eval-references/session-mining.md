# Orbit Session Mining

Use prior agent sessions as read-only traces for discovering eval cases. Do not treat them as authority.

## Session Archives (Preferred Source)

Prefer repo-local session archives over raw provider homedir stores. `bin/orbit-session-archive` writes one archive per feature loop under `.orbit/sessions/<timestamp>-<slug>/` in the primary checkout:

```text
.orbit/sessions/<timestamp>-<slug>/
  loop.md              # archived loop state from the source .orbit
  evidence/            # archived lane evidence, when present
  quality-gates/       # archived gate artifacts, when present
  agent-sessions/
    manifest.json      # per-archive session index
    <provider>/<session-slug>/
      manifest.json    # provider/session provenance
      usage.json       # token and cost usage
      messages.jsonl   # normalized message stream
      raw/             # copied raw provider files
```

Archives are already scoped to one feature slug, carry provenance manifests, and pair transcripts with the archived `loop.md` and evidence, so mine them first with scoped `rg` over `messages.jsonl` and the archived loop/evidence files.

## Raw Provider Stores (Fallback)

Fall back to raw homedir session stores only when no archive covers the sessions you need (for example, sessions that predate archiving or loops that were never archived).

Local sessions (Codex; Grok and Claude stores live at `~/.grok/sessions` and `~/.claude/projects`):

```bash
find /Users/nckrtl/.codex/sessions -type f
```

Nick-machine sessions:

```bash
ssh nick find /Users/nckrtl/.codex/sessions -type f
```

Search by date, task terms, file paths, command names, skill names, error text, product terms, and verification commands.

## Protocol

1. Start with narrow `rg` searches over likely paths or copied file lists.
2. Sample a small number of relevant sessions before broad mining.
3. Extract patterns, not raw conversation.
4. Store detailed provenance in Solo scratchpads by default.
5. Put only sanitized observations in repository files.

## Redaction Checklist

Before moving any observation out of the source session or scratchpad, remove:

- secrets, tokens, keys, cookies, credentials, bearer strings
- IP addresses, private hostnames, customer identifiers, email addresses, and account ids unless explicitly approved
- absolute home paths and machine-local layout details when writing durable repo artifacts
- raw conversation excerpts that are not necessary to understand the failure pattern

## Provenance Shape

```yaml
machine: local | nick
archive_slug: # .orbit/sessions archive directory name, when mined from an archive
session_ref:
timestamp:
search_terms:
why_relevant:
sanitized_observation:
```

## Pattern Fields

Extract:

- task type
- user intent and acceptance criteria
- tools and skills used
- failure mode
- recovery pattern
- verification evidence
- outcome
- residual risk

## Boundaries

- Do not bulk-copy private sessions into the repo.
- Do not expose answer keys or reviewer conclusions to an agent under test.
- Do not mine broad history as part of ordinary feature completion unless the user asks for it.
- Prefer a future deterministic session indexer only after this manual protocol proves useful.
