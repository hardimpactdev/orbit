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
    private function __construct(public string $command, public string $family, public string $name, private ?string $brief = null, private ?string $reference = null) {}

    public static function for(string $command, ?string $brief = null, ?string $reference = null): self
    {
        if (preg_match('/\A([a-z][a-z0-9]*(?:-[a-z0-9]+)*):([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\z/D', $command, $match) !== 1) {
            throw new ResourceOperationException('tasks.prep_command_invalid', 'The command must be `family:command` in lower case.', 422);
        }

        return new self($command, $match[1], $match[2], $brief === null || trim($brief) === '' ? null : trim($brief), $reference === null || trim($reference) === '' ? null : trim($reference));
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
        TEXT.$this->briefText(true);
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

        The author already ran `bin/check-flows`, `composer docs-build` and `composer docs-lint`. Run them again only to check a doubt, and run no proof.

        A finding is a place where the spec breaks a rule of those documents, contradicts itself or the sibling, or promises something that no case covers. A matter of taste or an optional improvement is not a finding; mention it in your summary after the findings and still approve. Report at most five findings, the most important first. A review may end with outcome `approved` and notes.

        End with outcome `approved` when you have no finding. Otherwise end with `changes_requested` and put every finding in your summary as a numbered list. Name the file, the problem and the fix for each finding.
        TEXT.$this->briefText(false);
    }

    public function fix(): string
    {
        return <<<TEXT
        An independent review of your spec of `{$this->command}` found the problems listed below. Fix every one that the skill and the Recall decisions settle, in the same three files, and change nothing else. If you disagree with a finding, say why in your receipt summary.

        Keep the spec as ONE commit: make the fixes, then run `git add -A && git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit --amend --no-edit`. Run the three checks once, after the last fix. Do not fetch or push.
        TEXT.$this->briefText(false);
    }

    /** The brief reaches every prompt; the reference text, such as a sibling's approved files, only the draft. */
    private function briefText(bool $draft): string
    {
        $text = $this->brief === null ? '' : "\n\nBrief of this run:\n".$this->brief;
        if ($draft && $this->reference !== null) {
            $text .= "\n\nReference text for this run. The workspace does not hold it, because it is not on the default branch; treat it as the approved sibling and as the shape to follow:\n".$this->reference;
        }

        return $text;
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
