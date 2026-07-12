# Semantic Check

Use this reference when checking whether Orbit command docs describe the right
product behavior. A semantic check complements `composer docs-lint`; it does not
replace structural linting.

## Purpose

Find product-level mismatches that a linter cannot know:

- conflicts with `docs/mission.md`, `docs/architecture.md`,
  `docs/tech-stack.md`, `docs/concepts.md`, or a family concept document;
- command behavior that assigns ownership to the wrong node, state family, or
  transport edge;
- peer-identity or grant behavior that contradicts the shared authorization
  model;
- input, output, failure, or recovery contracts that are under-specified,
  over-specified, or inconsistent across split files;
- current-code drift encoded as ideal documentation;
- sibling commands inheriting a rule differently without saying why.

## Required Inputs

For a target command file or command directory, read only what is needed:

1. The target public page and technical files.
2. `docs/mission.md`, `docs/architecture.md`, `docs/tech-stack.md`, and
   `docs/concepts.md` sections relevant to the command's domain.
3. The family `README.md`, family concept document, and family doctor file,
   when present.
4. Sibling command docs only when the target appears to inherit or diverge from
   a family rule.
5. Command-designer references for the affected surface:
   - `command-documentation.md` for ownership, effects, prerequisites, state
     families, and test mapping;
   - `invocation-model.md` for input modes, `--json`, prompts, destructive
     consent, JSON failures;
   - `terminal-output.md` for human renderer and progress behavior.

Do not treat current implementation as authority unless the user explicitly asks
for implementation alignment. Current code is evidence, not the product
contract.

## Review Passes

### 1. Product Alignment

- Identify the command's product purpose and stable command surface.
- Check that it supports the mission and blueprint model rather than an
  implementation shortcut.
- Confirm the command uses product vocabulary, not backend-shaped names.

### 2. Ownership And Authority

- Identify what state the command reads or mutates: local settings, gateway
  intent, node reality, or durable history.
- Confirm the gateway remains authoritative for fleet intent.
- Confirm every gateway-backed CLI invocation remains a typed gateway client,
  regardless of the caller's node role or execution location.
- Check that doctor/family ownership is singular and product-level.

### 3. Peer Authorization And Transport

- Confirm gateway-backed calls authenticate a gateway-known WireGuard peer.
- Confirm the default path uses a stored grant edge plus the scoped permission
  required by the command.
- When the default grants gate does not apply, require one of the four named
  exception classes: gateway implicit authority, pre-grants bootstrap,
  local-only, or identity-gated self-management.
- Confirm self-targeting workload calls use self-grants; local filesystem
  context may resolve defaults but never authorizes a write.
- Confirm CLI-to-gateway HTTPS and gateway-to-node transport follows the
  architecture model. Flag direct caller-to-target orchestration unless the
  command is explicitly local-only or pre-grants bootstrap.

### 4. Input And Path Semantics

- Check required fields, defaults, forbidden combinations, and validation timing.
- Check interactive and non-interactive files agree with the canonical input
  table.
- Confirm `--json` selects JSON and non-interactive input mode only; it never
  grants destructive consent.
- Confirm destructive commands require explicit destructive consent.

### 5. Side Effects And Failure Semantics

- Confirm side effects begin only after inputs, peer identity, authorization,
  and path eligibility are resolved.
- Check failure codes use shared vocabulary unless a product-specific code is
  justified.
- Check recovery guidance points to the owning command or doctor family.
- Confirm failures are not successful idempotent outcomes unless the command
  explicitly owns idempotence.

### 6. Renderer And JSON Semantics

- Confirm human output and JSON output represent the same result.
- Check renderer files own presentation, not input collection or domain rules.
- Check JSON examples use the shared envelope and stable product DTOs.
- Check warning, next-command, and next-step metadata belong in the right
  envelope location.

### 7. Test Contract Semantics

- Confirm test mappings assert observable command contracts.
- Check split files map the behavior they own rather than duplicating all tests
  in the canonical contract.
- Flag missing coverage only when the documented behavior is substantial enough
  to require a dedicated owner.

## Findings

Classify each finding:

| Classification | Meaning |
| --- | --- |
| Confirmed issue | The docs contradict authoritative product docs or another command contract. |
| Safe doc fix | The intended behavior is clear and can be corrected without a product decision. |
| Needs decision | The docs expose a product ambiguity that should be decided outside the project before editing. |
| False positive | The suspected issue is intentional or already handled by another owning file. |
| Residual risk | The docs are plausible, but confidence depends on implementation or real-node verification outside this semantic check. |

Lead with confirmed issues and safe doc fixes. Keep false positives brief so the
review does not turn into a transcript of every checked item.

## Output Shape

Use this shape for a semantic check result:

```text
Scope: <path or command>

Findings
- [classification] <file>:<line> - <issue>. <why it matters>. <proposed fix or decision needed>.

Checks With No Finding
- <short note for important areas that were checked and looked consistent>

Residual Risk
- <anything not proven by documentation review alone>
```

When there are no findings, say that directly and list the most important
checks that passed. Do not imply implementation correctness unless
implementation was explicitly reviewed.
