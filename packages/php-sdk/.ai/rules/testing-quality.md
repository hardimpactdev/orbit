# Testing and quality

Use Pest 5 with `describe()` and `it()`. Use TDD for behavior changes. Run
`composer test:affected` for red, green, and refactor. CI runs checks across all projects.

- Run `composer guidance:check` first. It must fail when the rule index, an
  indexed file, or material path coverage is missing. The failure must give the
  repository restoration command.
- Test exact methods, endpoints, query and body omission, explicit empty input,
  headers, DTO bounds, request IDs, error envelopes, redaction, object-state
  diagnostics, TLS verification, redirects, and root-CA one-shot behavior.
- Use Saloon fakes. Do not contact a live Gateway or node.
- Follow the root `AGENTS.md` check policy for task and non-task work. Run
  `git diff --check -- packages/php-sdk` from the monorepo root.
- Follow the root contributor guide for CI, independent review, and maintainer approval.
- Review the diff, staged files, branch, and upstream state before handoff.
  Keep authorized cross-component changes in the same feature branch. Never
  stage, commit, push, or deploy without authorization.
