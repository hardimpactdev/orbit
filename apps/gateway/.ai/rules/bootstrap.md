---
paths:
  - 'app/Console/**'
  - 'app/Providers/**'
  - 'bootstrap/**'
  - 'config/**'
  - 'AGENTS.md'
  - 'boost.json'
  - 'composer.json'
  - 'composer.lock'
  - 'pint.json'
  - 'phpstan.neon'
  - 'phpunit.guidance.xml'
  - 'phpunit.xml'
  - '.ai/**'
  - '.agents/**'
  - '.codex/**'
---

# Bootstrap and project tooling

## Treat incomplete guidance as a bootstrap failure
Follow Required Guidance Bootstrap in `AGENTS.md`.

Keep project guidance sources in `.ai`, generated Codex skills in `.agents`, and the effective `AGENTS.md` synchronized. Do not patch vendor files or framework caches.
