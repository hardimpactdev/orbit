---
name: solo-todo-handoff
description: Use when a user asks to hand off an existing Solo todo to a Claude implementation agent, mentions a todo handoff, asks for a compact /goal prompt from a Solo todo, or wants Claude Opus to implement an Orbit todo through Solo.
---

# Solo Todo Handoff

## Overview

Turn one existing Solo todo into a compact Claude Opus implementation goal.
Claude becomes the Orbit feature owner and must use
`.agents/skills/implementing-features/SKILL.md` until the todo's success
criteria are met or a real blocker is proven.

This skill does not create or refine the todo. It starts after the user names a
Solo todo id or URL.

## Workflow

1. Resolve the Solo project and todo id from the user request. For a
   `solo://proj/<project-id>/todo/<todo-id>` URL, pass `project_id` to Solo
   tools. For a bare id, use the current Solo project.
2. Read the todo with `todo_get(..., include_comments=true)`. If the todo links
   a scratchpad, read only the relevant headings or lines needed to understand
   the active slice.
3. Convert the todo into a `/goal` body with one objective, concrete success
   criteria, verification expectations, and the todo URL. Preserve links to raw
   examples instead of pasting long transcripts.
4. Keep the `/goal` body at or below 4000 characters. If the Solo UI or agent
   surface counts the required `agent_instructions` in the same field, reserve
   that space and shrink the `/goal` body accordingly. Do not send an over-limit
   goal.
5. Use `list_agent_tools` and select the enabled `Claude` tool. Spawn it with
   `spawn_agent`, `extra_args=["--model", "opus", "--effort", "medium"]`, and a
   name such as `todo-<todo-id>-opus`. If Claude Opus is not available, stop and
   report the available tools instead of silently using Sonnet.
6. Send the first prompt with `send_input`. The exact text passed to
   `send_input.input` must start with `/goal`. If Solo returns
   `agent_instructions`, include them inside the `/goal` body under the
   `Solo context` section; never prepend anything before `/goal`.
7. Report the Solo process id/name, todo URL, and `/goal` character count. The
   handoff is complete once `send_input` succeeds. Do not poll, supervise, or
   send follow-up prompts unless the user explicitly asks to inspect or resume
   that Solo process.

## Prompt Rules

- The `/goal` body starts with `/goal`.
- The submitted prompt starts with `/goal` as its first characters. No Solo
  orchestration context, preamble, whitespace, or commentary may appear before
  `/goal`.
- The goal says `implement`, not `investigate`, unless the todo is explicitly
  research-only.
- Include a direct reference to the Solo todo.
- Name `.agents/skills/implementing-features/SKILL.md` as required workflow.
- Keep prose minimal. Use bullets for success criteria.
- Do not paste full bug reports, logs, or transcripts when a link or short
  excerpt is enough.
- Do not ask the user questions that can be answered by reading the todo,
  comments, linked scratchpad, codebase, docs, or tests.
- Because this is a send-and-forget handoff, put the avoidable-question rule in
  the initial `/goal`; there is no midpoint correction loop.
- If success criteria remain ambiguous after those reads, stop before spawning
  Claude and ask for the missing acceptance boundary.

## Prompt Skeleton

```text
/goal Implement Solo todo <solo://proj/<project-id>/todo/<todo-id>> until every success criterion is met.

Required workflow:
- Read and follow AGENTS.md, HARNESS.md, and .agents/skills/implementing-features/SKILL.md.
- Act as the Orbit feature owner/orchestrator: create the Done Contract, prepare the implementation worktree with bin/orbit-prepare-worktree, delegate through Solo only when the workflow requires it, verify, and report completion evidence.

Goal:
- <one concrete objective from the todo>

Success criteria:
- <criterion from todo/comment/scratchpad>
- <criterion from todo/comment/scratchpad>
- Verification: <focused command(s), quality gate, E2E lane, or "derive from implementing-features and changed files">

Context:
- Solo todo: <solo URL>
- Solo context: <paste Solo's agent_instructions here, or "none returned">
- Linked scratchpad or raw examples: <solo URL/heading, short pointer, or none>
- Likely owned surface: <paths, command family, docs, or "discover narrowly after required reads">

Rules:
- Continue until all success criteria pass or an explicit blocker with evidence is reported.
- Resolve questions from the todo, codebase, docs, and tests before asking the user.
- This is a send-and-forget handoff; do not wait for orchestrator correction before continuing.
- Use TDD for behavior changes and keep docs, tests, and code aligned.
- Make the first narrow diff after reading required local files; do not drift into broad discovery.
```

## Handoff Boundary

This skill performs a one-time handoff. After `send_input` succeeds, report the
process identity and prompt size, then stop. Do not monitor Claude, poll for
completion, inspect its report, or send follow-up steering from this skill. A
later inspection or resume is a separate user request.
