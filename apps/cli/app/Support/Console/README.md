# Console notes

Implementation notes for the CLI design standard (`docs/reference/cli-ux.md`). They describe the code under this folder.

## Terminal rendering

Wrap panel content inside its borders. Account for visible character width rather than ANSI bytes. Long labels and errors must not collide with borders or depend on terminal auto-wrap. Summaries and detail lines have distinct jobs and do not repeat the same full error twice. A human issue cap reports omitted items and does not truncate the machine result.

Measure ordinary terminal cells without assuming emoji sequences collapse into one glyph. Keep each grapheme intact when wrapping, but count its visible base characters separately. Combining marks and joiners add no cells. Verify widths in the actual terminal. Text measurements do not prove terminal alignment.

An initially unstarted nested progress panel starts and settles while the outer callback is active. It shares the surrounding renderer without moving either callback to a child process.

Prompt, progress, and interruption state belong to one invocation. Finishing a nested or sequential command restores the surrounding command's prompt configuration, output, cursor, terminal settings, and interrupt intent. A forced color option does not make a pipe repaintable or permit escape codes in machine output.

## Shared helpers

The shared implementation lives under `apps/cli/app/Support/Console`. `ConsoleMode` keeps machine output, prompt admission, decoration, repainting, and terminal width separate. `GatewayCommand` resolves these facts after input binding when a command requests its shared helpers. `CommandPrompts` takes a factory for a Laravel Prompts instance and owns its input and terminal scope. Commands still decide which inputs may be prompted and how to report a typed `PromptAborted` failure.

`HumanRenderer` returns terminal bytes for details, tables, properties, and failures. Write these bytes through `ConsoleWriter` so literal formatter tags remain data. `ProgressDisplay` admits known steps and runs each callback in the parent process. Its `during()` method returns the callback result without inferring product success. Set the terminal state with `complete()` after checking that result, then call `finish()` with the verified outcome. `SpinnerDisplay::during()` reports a completed wait and preserves the callback's value or exception. An inner spinner shares the active tree's rendering and animation clock.

These helpers do not migrate a command automatically. Keep the command unverified until its documented input, output, and terminal cases pass.
