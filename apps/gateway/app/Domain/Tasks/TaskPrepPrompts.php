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
        {$this->specRules()}
        - Do not open a pull request and do not fetch or push. A separate review agent checks your work after you.
        - Commit the three files as ONE commit with `git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit` and the message `docs({$this->family}): spec for {$this->command}`. Leave the working tree clean.
        TEXT.$this->briefText(true);
    }

    public function review(): string
    {
        return $this->reviewText('The spec is the commit at HEAD of this workspace. It holds three files:',
            'Check that the commit holds these three files and nothing else.', '',
            'the unit tests must pass, or the author must name the failing tests as a blocker, which is a finding.').$this->briefText(false);
    }

    /** The one review prompt: a first review of the spec commit, or a review after corrections, which adds the owner's review. */
    private function reviewText(string $scope, string $holds, string $extra, string $tests): string
    {
        return <<<TEXT
        You are an independent reviewer of the spec of the Orbit command `{$this->command}`. You did not write it. You edit no file.

        {$scope}
        - chart: `{$this->chart()}`
        - docs page: `{$this->page()}`
        - cases: `{$this->cases()}`

        Check them against `.agents/skills/preparing-commands/SKILL.md`, `.agents/skills/flow-tighten/SKILL.md`, `docs/cli/README.md`, `docs/reference/cli-ux.md`, `docs/reference/verification-cases.mdx` and the sibling command, if there is one. A sibling that is a draft has no approval. Read the code only to learn what exists. Walk every error code, flag, repeat and refusal of the page against the cases. {$holds}

        The author already ran `bin/check-flows`, `composer docs-build` and `composer docs-lint`. Run them again only to check a doubt.

        Run `{$this->plan()}` yourself. It reads the cases file and runs no case. If it exits with anything but 0, that is a finding: quote the refusal line, and do not approve. Run no other proof than the plan and `python3 -m unittest discover -s swamp/proof/runner/tests`; {$tests}

        Also check each of these; a break is a finding. Every case id that runs on the default branch (`git show origin/main:<cases file>`) is still a case with the same batch, and none moved to `uncovered`. `fails_today` is not used for a step the runner refuses. Every behavior the page states as fact exists in the code or has a `fails_today` case. Behavior that is the same for every command is not charted, repeated or cased per command. A rule is said once per page, with a link to the family page. The chart status and header lines follow the skill. No file holds a PR or issue number. A defect copied from the reference text or the sibling is a finding, even though the sibling has it.

        {$extra}A finding is a place where the spec breaks a rule of those documents, contradicts itself or the sibling, or promises something that no case covers. A matter of taste or an optional improvement is not a finding; mention it in your summary after the findings and still approve. Report at most five findings, the most important first. A review may end with outcome `approved` and notes.

        End with outcome `approved` when you have no finding. Otherwise end with `changes_requested` and put every finding in your summary as a numbered list. Name the file, the problem and the fix for each finding.
        TEXT;
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

    /** The rules of the cases file that the author follows and the reviewer enforces, in the first draft and in the corrections. */
    private function specRules(): string
    {
        return <<<TEXT
        - Run `{$this->plan()}`. It must exit 0. A refusal, such as `step X is not allowed in setup`, means the cases file uses a step or key that the runner does not have. Use only what the runner has, in the case-file format of `docs/reference/verification-cases.mdx`. List a case that needs something the runner lacks under `uncovered`, with the capability named, and never write it as a step. Do not change the runner.
        - `fails_today` means the code fails a case. It never excuses a step the runner refuses. Keep every case that runs on the default branch, with its id and batch, unless the chart removes its behavior; never move a running case to `uncovered`. Keep each `uncovered` entry to one or two sentences.
        - Run `python3 -m unittest discover -s swamp/proof/runner/tests`. If it fails because of the content of the cases file, change no test and name the failing tests in your summary.
        - State a behavior as fact only when you found the code that does it, or when a case marked `fails_today` checks it. Say behavior that is the same for every command (keyed replay, `--json`, prompts) once, by link to the CLI standard; do not chart it or repeat it. Put no PR or issue number in the files.
        - In your summary list the behaviors the code lacks, the case ids you removed or renamed, and the runner tests that fail.
        TEXT;
    }

    /** The author turn of the corrections: the review of the owner as the prompt, applied as a new commit on the pushed spec. */
    public function corrections(string $review): string
    {
        return <<<TEXT
        You are the author of the spec of the Orbit command `{$this->command}` (stage 1, prep). The spec is already a commit on this branch, and a draft pull request is open for it. The owner reviewed it and asked for corrections. Apply them.

        Rules:
        - Change only these three files: `{$this->chart()}`, `{$this->page()}` and `{$this->cases()}`. A correction that needs another file, a change of the runner or its tests, or a decision that the review leaves open is not yours to make: leave it out and say so in your receipt summary, with the reason.
        - Make the corrections as ONE new commit on top of the current HEAD, with `git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit` and the message `docs({$this->family}): apply the review of {$this->command}`. Never amend or rewrite a commit that is already on the branch. Leave the working tree clean.
        - Run `bin/check-flows`, `composer docs-build` and `composer docs-lint` when the tools are installed, and fix what they report. Say in your summary what you ran.
        {$this->specRules()}
        - Do not open a pull request and do not fetch or push. A separate review agent checks your work after you.

        The review of the owner:

        {$review}
        TEXT;
    }

    /** The review of a round after the corrections: the same review as the first, plus whether each correction of the owner is applied. */
    public function reviewCorrections(string $review): string
    {
        $extra = "This is a review after the owner asked for corrections. The first commit of the branch holds the spec; the commits after it are the corrections. Check with `git log --stat origin/main..HEAD` that the branch changes these three files and nothing else. Then check each correction that the owner asked for: it is applied, or the author's summary or the file gives a reason that a rule or a decision settles. A correction that needs another file or an owner decision may be left out. A requested correction left undone without a reason is a finding.\n\nThe review of the owner:\n\n".$review."\n\n";

        return $this->reviewText('The spec is the branch of this workspace, as it stands at HEAD. It holds three files:',
            'Check that the branch changes these three files and nothing else.', $extra,
            'the unit tests must pass. One exception: runner tests that fail only because they pin the old content of the cases file are not a finding of this review when the review of the owner defers that change to a separate decision. Name them in your notes and still judge the rest.');
    }

    /** The fix turn of a round after the corrections: it keeps the one corrections commit and never touches the pushed ones. */
    public function fixCorrections(): string
    {
        return <<<TEXT
        An independent review of your corrections to the spec of `{$this->command}` found the problems listed below. Fix every one that the skill and the Recall decisions settle, in the same three files, and change nothing else. If you disagree with a finding, say why in your receipt summary.

        Keep your corrections as ONE new commit on top of the pushed spec: make the fixes, then amend only your own corrections commit with `git add -A && git -c user.name=Orbit -c user.email=orbit@users.noreply.github.com commit --amend --no-edit`. Never amend or rewrite a commit that is already on the branch below it. Run the checks once, after the last fix, including `{$this->plan()}`, which must exit 0. Do not fetch or push.
        TEXT;
    }

    public function buildTitle(): string
    {
        return 'Build '.$this->command;
    }

    /** The fixed brief of the implementation subtask that follows an approved spec. See docs/cli/task/build.mmd. */
    public function buildBrief(string $head, string $branch): string
    {
        return <<<TEXT
        The owner approved the spec of the Orbit command `{$this->command}` at commit `{$head}` of the branch `{$branch}`. Build the command so that it does what the spec says.

        The approved spec:
        - chart: `{$this->chart()}`
        - docs page: `{$this->page()}`
        - cases: `{$this->cases()}`

        The workspace was synced, so the branch `origin/{$branch}` is in it. If HEAD of the workspace is not the approved head, run `git merge --ff-only {$head}` first, and stop and say so if that commit is missing or does not fast-forward. Do not change the spec. Write the code, the tests and the runnable proof cases that the spec asks for. The deliverables below must pass.
        TEXT;
    }

    /** The prompt of the caller of the first implementer turn; the admission puts the subtask in front of it. */
    public function buildTurn(): string
    {
        return 'Build the command from the approved spec, as the brief of this subtask says.';
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
