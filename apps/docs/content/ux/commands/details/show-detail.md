# Show Detail

Single-entity detail display for `*:show` commands.

## Use When

Use `show-detail` when a command renders one resolved Orbit entity, such as
`project:show`, `node:show`, `workspace:show`, `tool:show`, `schedule:show`,
`activity:show`, or `database:show`.

## Avoid When

Choose a different primitive in the following situations.

- Rendering multiple rows of comparable records. Use [`table`](../lists/table.md).
- The operator must choose an existing row. Use
  [`data-table-prompt`](../lists/data-table-prompt.md).
- Rendering long-running work. Use the [progress tree](../progress/progress-tree.md).

## Contract

These rules govern all uses of `show-detail` in Orbit commands.

- Primitive name in renderer docs: `show-detail`.
- The renderer prints one empty terminal line before the title and one empty
  terminal line after the final row.
- The title line is `┌  <family nice label singular>: <slug-target>`.
- The second line is a blank tree continuation line: `│`.
- Each property renders as `├  <Property><padding><Value>` except the final row,
  which uses `└`.
- A blank tree continuation line `│` renders between property rows. No spacer
  renders after the final `└` row.
- When terminal decoration is enabled, tree connector glyphs are dimmed. Title
  text, labels, and values remain normal terminal text.
- Property labels use human title case, not raw JSON keys.
- Values are one-line summaries. Lists render comma-separated.
- Missing values render as `—` (em dash) for human display; JSON keeps `null`.
- The renderer must not print `Showing ...`, Symfony tables, or nested section
  headings.

## Example

```text

┌  Database connection: ditis-hr
│
├  Driver   pgsql
│
├  Host     127.0.0.1
│
├  Port     5434
│
├  Name     ditis_hr
│
├  User     orbit
│
├  Apps     ditis-hr.test
│
└  Node     beast

```

## Implementation

Commands use `App\Console\Commands\Concerns\RendersShowDetails` from their
human renderer path:

```php
$this->renderShowDetails("Project: {$project['name']}", [
    'Domain' => $domain,
    'Environment' => $environment,
    'Node' => $node,
]);
```

## Cross References

See also these related resources.

- [`table`](../lists/table.md) for read-only multi-row output
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
