# Command Docs Linter Rules

The command docs linter checks converted `docs/commands/**` files for structure,
contract coherence, reference integrity, complexity, and prose ambiguity. The
raw CLI default fails on errors and reports warnings. The `composer docs-lint`
script runs the CLI with `--strict --format=agent`, so warnings fail project
verification and agent-facing output stays compact. Use `composer docs-lint --
--format=text` for the expanded human report.

## Baseline

`tool/docs-linter/baseline.json` can suppress a known warning count for a
specific `(path, rule_id, severity)` tuple. If the count grows, the linter emits
`command_docs.baseline` as an error so new drift cannot hide inside the baseline.

## Structure

| Rule | Severity | Checks | Fix |
| --- | --- | --- | --- |
| `command_docs.converted_family_structure` | error | Converted family directories contain the expected family-level files and command directories. | Restore the missing family file or move the command into the converted directory shape. |
| `command_docs.command_directory_structure` | error | Command directories contain the public page, technical directory, and required technical slots. | Add or rename the missing command documentation file. |
| `command_docs.family_command_prefix` | error | Non-operation command families only contain commands that start with the family command prefix. | Move the command into the matching family directory or rename the command to use the family prefix. |
| `command_docs.no_command_ambiguity_files` | error | Converted command directories do not keep `ambiguity.md` files in the docs tree. | Move ambiguity review notes to Solo scratchpads or another non-contract location. |
| `command_docs.technical_slot_semantics` | error | Technical slot files use the expected numeric slot and command slug semantics. | Rename the slot file or move the content to the correct slot. |

## Contracts

| Rule | Severity | Checks | Fix |
| --- | --- | --- | --- |
| `command_docs.canonical_technical_contract` | error | Canonical technical contracts include the required sections and command signature. | Add the missing canonical section or correct the signature. |
| `command_docs.destructive_consent` | error | Destructive commands document explicit consent behavior. | Add the required consent contract and JSON failure shape. |
| `command_docs.shared_failure_vocabulary` | error | Shared failures use canonical error code vocabulary instead of synonyms. | Replace stale or command-specific synonyms with registered shared codes. |
| `command_docs.product_code_namespace` | error | Machine-readable product codes use singular product prefixes. | Use prefixes such as `node.` instead of plural family prefixes. |
| `command_docs.drift_issue_suffix` | error | Stale physical artifacts use the `_extra` issue-code suffix, matching the probe drift kind. | Replace `_orphaned` machine codes with `_extra`; keep orphaned/stale only as prose when useful. |
| `command_docs.exit_status_policy` | error | Converted commands inherit the shared exit status policy instead of defining per-command numeric exit-code classes. | Replace command-specific numeric exit-code sections with shared exit status wording. |
| `command_docs.app_php_version_contract` | error | App command docs avoid stale PHP option aliases and node-default wording. | Use the canonical PHP-version option language. |
| `command_docs.doctor_issue_code_prefix` | error | Doctor issue codes use singular product prefixes. | Rename issue codes to the canonical singular prefix. |
| `command_docs.doctor_warning_coherence` | error or warning | JSON warnings with doctor handoffs reference registered state families and supported doctor fix/adopt maps. | Register command handoff warnings or align the warning with the doctor issue map. |
| `command_docs.error_code_registry` | error or warning | JSON `error.code` examples and exhaustive code tables match registered shared and product codes. | Register the code, add the missing example, or remove stale table entries. |
| `command_docs.app_node_write_denial` | error | App-node write denial behavior is documented for write commands. | Add the app-node denial contract to the relevant technical docs. |
| `command_docs.input_mode_contract` | error | Canonical input contracts link the shared Invocation Model without repeating generic mode prose, and interactive/non-interactive input mode docs agree with command signatures. | Add the shared Invocation Model link, remove duplicated generic input-mode wording, add the missing input-mode file, or correct the mode behavior. |
| `command_docs.public_json_option_contract` | error | Public pages mention JSON output when the canonical signature supports `--json`. | Add concise public `--json` option wording. |
| `command_docs.human_progress_tree` | error | Human renderers use the expected progress-tree contract. | Document the progress tree or remove unsupported progress claims. |
| `command_docs.read_command_no_live_probe` | error | Read commands do not document live probes as part of read behavior. | Move live checks to doctor or write-path contracts. |
| `command_docs.json_envelope` | error | JSON renderer docs include an Envelope section that links the shared JSON Envelope contract without repeating generic success/error prose. | Add the shared envelope link, remove duplicated envelope wording, or correct incompatible JSON output. |
| `command_docs.json_renderer_examples` | error or warning | JSON examples are parseable, use valid envelopes, and match canonical entity shapes. | Fix invalid JSON, remove envelope conflicts, or align entity payloads with registries. |
| `command_docs.json_warning_shape` | error | Warning payloads use canonical machine-readable warning fields. | Add required warning fields and remove unsupported shapes. |
| `command_docs.next_action_contract` | error | JSON examples keep `next_steps` as a success onboarding checklist and `next_command` as warning/error recovery metadata. | Move human checklists to `success.data.next_steps`, or move machine-runnable repair commands to warning/error metadata. |
| `command_docs.signature_option_consistency` | error | Technical companion files only mention options present in the canonical signature, except registered shared references. | Add the option to the signature, remove the stale mention, or register a shared option exception. |

## References

| Rule | Severity | Checks | Fix |
| --- | --- | --- | --- |
| `command_docs.markdown_link_integrity` | error | Markdown links point to existing local files. | Correct or remove the broken link. |
| `command_docs.concept_index` | error | Top-level `docs/CONCEPTS.md` family concept blocks match the terms defined by owning `{family}-concepts.md` files. | Update the family concept definitions or refresh the marked concept-index block in `docs/CONCEPTS.md`. |
| `command_docs.behavior_contract_structure` | warning | Canonical technical Behavior Contract sections use meaningful command-specific level-3 subsections and do not rely on placeholder headings such as `### Core Behavior`. | Replace placeholder headings with concrete behavior/rule sections such as `### Visibility Rules`, `### Trust Material Rules`, or `### Gateway Bootstrap And Convergence`; keep `### Scope Boundaries` only alongside command-specific behavior sections. |
| `command_docs.doctor_relationship_reference` | error | State-family docs reference the matching doctor page where required. | Add the doctor relationship reference or update the state-family registry. |
| `command_docs.role_companion_coverage` | error | Role-specific command behavior has matching role companion coverage. | Add the missing role companion file or coverage language. |
| `command_docs.technical_test_mapping` | error or warning | Technical files include meaningful Test Mapping sections for owned behavior, codes, warnings, and consent paths. | Add a concrete test mapping row naming the owner and covered behavior. |
| `command_docs.test_mapping_format` | error | Test Mapping sections use the expected table format and do not repeat missing-file process guidance owned by the shared command documentation. | Rewrite the section as a `Path`/`Coverage` table and list planned missing test files without local boilerplate. |

## Complexity

| Rule | Severity | Checks | Fix |
| --- | --- | --- | --- |
| `command_docs.command_contract_complexity` | warning | Canonical technical command contracts exceed calibrated complexity thresholds for input fields, conditional required fields, caller-role paths, behavior items, failure cases, doctor handoffs, or scope-boundary clauses. | Group behavior by path, extract stable concepts, or add stronger split-file test ownership. |
| `command_docs.document_complexity` | warning | Narrative prose exceeds calibrated complexity thresholds for sentence length, paragraph length, bullet length, LIX, or duplicate headings. | Split prose by actor, condition, action, and observable result. |

## Prose

| Rule | Severity | Checks | Fix |
| --- | --- | --- | --- |
| `command_docs.requirement_smell` | warning | Narrative prose contains high-signal ambiguity phrases such as `as needed`, `if possible`, or `and/or`. | Replace vague qualifiers with actor, condition, obligation, and observable result. |
