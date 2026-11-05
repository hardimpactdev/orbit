<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

/**
 * The text of one prep run for one command: where its spec lives, the prompts of the author and review agents, and the
 * title and body of the draft pull request. The skill and the chart carry the rules; a prompt only names the command, its
 * files and the commit the run needs. See docs/reference/tasks.md#prepare-a-command.
 */
final readonly class TaskPrepPrompts
{
    private function __construct(public string $command, public string $family, public string $name) {}

    public static function for(string $command): self
    {
        if (preg_match('/\A([a-z][a-z0-9]*(?:-[a-z0-9]+)*):([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\z/D', $command, $match) !== 1) {
            throw new ResourceOperationException('tasks.prep_command_invalid', 'The command must be `family:command` in lower case.', 422);
        }

        return new self($command, $match[1], $match[2]);
    }

    public function chart(): string
    {
        return 'docs/cli/'.$this->family.'/'.$this->name.'.mmd';
    }

    public function page(): string
    {
        return 'docs/cli/'.$this->family.'/'.$this->name.'.mdx';
    }

    public function cases(): string
    {
        return 'swamp/proof/'.$this->family.'/'.$this->name.'.cases.yaml';
    }

    public function draft(): string
    {
        return <<<TEXT
        You are the author of stage 1 (prep) of the command development pipeline for the Orbit command `{$this->command}`.

        Read `.agents/skills/preparing-commands/SKILL.md` and follow it. The stage chart is `docs/reference/pipeline/1-prep.mmd`.
        Use Recall (`resolve` and `decisions`) when it is available; otherwise rely on the docs in the repository.

        Draft the spec of this one command, and nothing else:
        - chart: `{$this->chart()}`
        - docs page: `{$this->page()}`
        - cases: `{$this->cases()}`

        Rules:
        - Change no code. Edit only these three files. If a sibling command or the family page must change too, say so in your receipt summary and leave them as they are.
        - Run `bin/check-flows`, `composer docs-build` and `composer docs-lint` when the tools are installed, and fix what they report. Say in your summary what you ran.
        - Do not open a pull request and do not fetch or push. A separate review agent checks your work after you.
        - Commit the three files as ONE commit with `git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit` and the message `docs({$this->family}): spec for {$this->command}`. Leave the working tree clean.
        TEXT;
    }

    public function review(): string
    {
        return <<<TEXT
        You are an independent reviewer of the spec of the Orbit command `{$this->command}`. You did not write it. You edit no file.

        The spec is the commit at HEAD of this workspace. It holds three files:
        - chart: `{$this->chart()}`
        - docs page: `{$this->page()}`
        - cases: `{$this->cases()}`

        Check them against `.agents/skills/preparing-commands/SKILL.md`, `.agents/skills/flow-tighten/SKILL.md`, `docs/cli/README.md`, `docs/reference/cli-ux.md`, `docs/reference/verification-cases.mdx` and the approved sibling command, if there is one. Read the code only to learn what exists. Walk every error code, flag, repeat and refusal of the page against the cases. Check that the commit holds these three files and nothing else.

        End with outcome `approved` only when you have no finding. Otherwise end with `changes_requested` and put every finding in your summary as a numbered list. Name the file, the problem and the fix for each finding.
        TEXT;
    }

    public function fix(): string
    {
        return <<<TEXT
        An independent review of your spec of `{$this->command}` found the problems listed below. Fix every one that the skill and the Recall decisions settle, in the same three files. If you disagree with a finding, say why in your receipt summary.

        Keep the spec as ONE commit: make the fixes, then run `git add -A && git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit --amend --no-edit`. Run the checks again as before. Do not fetch or push.
        TEXT;
    }

    public function title(): string
    {
        return 'docs('.$this->family.'): spec for '.$this->command;
    }

    public function body(): string
    {
        return <<<TEXT
        Stage 1 of the [command development pipeline](https://github.com/hardimpactdev/orbit/blob/main/docs/reference/command-development-pipeline.mdx) for `{$this->command}`. This draft holds the spec as its first commit:

        - chart: `{$this->chart()}`
        - docs page: `{$this->page()}`
        - cases: `{$this->cases()}`

        The spec was drafted by an author agent and checked by a separate review agent. The code and the runnable proof cases follow on this branch; the PR stays a draft until stage 4.
        TEXT;
    }
}
