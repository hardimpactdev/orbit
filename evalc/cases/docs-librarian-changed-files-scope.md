# Case: Docs Librarian Changed-Files Scope

## Input Request

"The feature changes command documentation and implementation. Use a
documenter/librarian agent where it helps."

## Expected Workflow

- Routes as a documentation-heavy feature.
- Uses a Claude documenter/librarian worker only when documentation ownership is
  substantial and separable.
- Limits docs review to changed files, named authority docs, and immediate
  context needed to judge the diff.
- Uses full docs drift audit only when explicitly requested.
- Reconciles docs and code before commit.

## Expected Evidence

- Docs-owned surface and authority docs read.
- Documenter/librarian worker id or reason it was skipped.
- Docs-lint result when product docs changed.
- Feature owner acceptance of docs contract before code relies on it.
- Any unresolved product decision or authority conflict.

## Forbidden Mistakes

- Turning every docs review into a whole-project drift audit.
- Letting the documenter make final product decisions.
- Splitting docs and code when the contract is still unstable.
- Treating session artifacts under `docs/superpowers/` as product authority.

## Grading Rubric

- Pass: Documentation work is bounded, authority-aware, and reconciled with the
  implementation.
- Partial: Useful docs review happened, but scope or authority chain is vague.
- Fail: Broad audit drift or product-decision ownership escapes the slice.
