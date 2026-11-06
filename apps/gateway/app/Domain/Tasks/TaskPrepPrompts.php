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

    /** The runner's plan of the cases file: it loads and checks the file and runs no case. */
    public function plan(): string
    {
        return 'python3 swamp/proof/runner/runner.py plan --file '.$this->cases();
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
        - Run `{$this->plan()}`. It must exit 0. A refusal, such as `step X is not allowed in setup`, means the cases file uses a step or key that the runner does not have. Use only what the runner has, in the case-file format of `docs/reference/verification-cases.mdx`. List a case that needs something the runner lacks under `uncovered`, with the capability named, and never write it as a step. Do not change the runner.
        - `fails_today` means the code fails a case. It never excuses a step the runner refuses. Keep every case that runs on the default branch, with its id and batch, unless the chart removes its behavior; never move a running case to `uncovered`. Keep each `uncovered` entry to one or two sentences.
        - Run `python3 -m unittest discover -s swamp/proof/runner/tests`. If it fails because of the content of the cases file, change no test and name the failing tests in your summary.
        - State a behavior as fact only when you found the code that does it, or when a case marked `fails_today` checks it. Say behavior that is the same for every command (keyed replay, `--json`, prompts) once, by link to the CLI standard; do not chart it or repeat it. Put no PR or issue number in the files.
        - In your summary list the behaviors the code lacks, the case ids you removed or renamed, and the runner tests that fail.
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

        Check them against `.agents/skills/preparing-commands/SKILL.md`, `.agents/skills/flow-tighten/SKILL.md`, `docs/cli/README.md`, `docs/reference/cli-ux.md`, `docs/reference/verification-cases.mdx` and the sibling command, if there is one. A sibling that is a draft has no approval. Read the code only to learn what exists. Walk every error code, flag, repeat and refusal of the page against the cases. Check that the commit holds these three files and nothing else.

        The author already ran `bin/check-flows`, `composer docs-build` and `composer docs-lint`. Run them again only to check a doubt.

        Run `{$this->plan()}` yourself. It reads the cases file and runs no case. If it exits with anything but 0, that is a finding: quote the refusal line, and do not approve. Run no other proof than the plan and `python3 -m unittest discover -s swamp/proof/runner/tests`; the unit tests must pass, or the author must name the failing tests as a blocker, which is a finding.

        Also check each of these; a break is a finding. Every case id that runs on the default branch (`git show origin/main:<cases file>`) is still a case with the same batch, and none moved to `uncovered`. `fails_today` is not used for a step the runner refuses. Every behavior the page states as fact exists in the code or has a `fails_today` case. Behavior that is the same for every command is not charted, repeated or cased per command. A rule is said once per page, with a link to the family page. The chart status and header lines follow the skill. No file holds a PR or issue number. A defect copied from the reference text or the sibling is a finding, even though the sibling has it.

        A finding is a place where the spec breaks a rule of those documents, contradicts itself or the sibling, or promises something that no case covers. A matter of taste or an optional improvement is not a finding; mention it in your summary after the findings and still approve. Report at most five findings, the most important first. A review may end with outcome `approved` and notes.

        End with outcome `approved` when you have no finding. Otherwise end with `changes_requested` and put every finding in your summary as a numbered list. Name the file, the problem and the fix for each finding.
        TEXT.$this->briefText(false);
    }

    public function fix(): string
    {
        return <<<TEXT
        An independent review of your spec of `{$this->command}` found the problems listed below. Fix every one that the skill and the Recall decisions settle, in the same three files, and change nothing else. If you disagree with a finding, say why in your receipt summary.

        Keep the spec as ONE commit: make the fixes, then run `git add -A && git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit --amend --no-edit`. Run the checks once, after the last fix, including `{$this->plan()}`, which must exit 0. Do not fetch or push.
        TEXT.$this->briefText(false);
    }

    /** The brief reaches every prompt; the reference text, such as a sibling's approved files, only the draft. */
    private function briefText(bool $draft): string
    {
        $text = $this->brief === null ? '' : "\n\nBrief of this run:\n".$this->brief;
        if ($draft && $this->reference !== null) {
            $text .= "\n\nReference text for this run. The workspace does not hold it, because it is not on the default branch; treat it as the shape to follow and as input, not as truth. It is a draft without approval, and a defect in it is not a precedent. Check what you copy from it against the skill and `docs/cli/README.md`:\n".$this->reference;
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
