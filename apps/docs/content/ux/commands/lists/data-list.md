# Data List

Interactive columnar list for selecting one registry entity and immediately
opening or acting on that selection.

## Terminology

In Orbit command requests, docs, implementation reports, and reviews, **data
list** means `Laravel\Prompts\datatable`. It is the Laravel Prompts component
with headers, rows, search, highlighting, and a selected row key.

`App\Support\Prompts\DataList` is not the data-list primitive and must not be
introduced or accepted as a substitute. Orbit's custom read-only grouped
label/value renderer is named [`property-list`](property-list.md).

## Use When

Use `data-list` in the following situations.

- The operator should scan several columns and select one row.
- Selecting a logical entity opens its detail view, such as `app:list`
  selecting an app and continuing to `app:show`.
- The finite registry list benefits from keyboard navigation and `/` search.

## Avoid When

Choose a different primitive in the following situations.

- The rows are read-only and selection has no follow-up action. Use
  [`table`](table.md).
- Each item needs a vertical group of labeled properties rather than columns.
  Use [`property-list`](property-list.md).
- A single entity is being shown. Use
  [`show-detail`](../details/show-detail.md).

## Contract

- Primitive name in command docs: `data-list`.
- Concrete implementation: `Laravel\Prompts\datatable`.
- The renderer declares the exact headers and row values from the command
  contract. Requested column names must not be rewritten into indented
  `Label: value` properties.
- Row keys are stable entity selectors; display columns do not become identity.
- `/` activates search and the hint names that shortcut.
- Selecting a row immediately continues to the documented detail or action
  surface.
- `--json` bypasses the interactive data list and emits the command's JSON
  envelope.

## Example

```php
use function Laravel\Prompts\datatable;

$selected = datatable(
    headers: ['Name', 'Repository', 'Instances', 'Workspaces'],
    rows: [
        'docs' => ['docs', 'hardimpact/docs', '2', '3'],
        'orbit' => ['orbit', 'hardimpact/orbit', '1', '0'],
    ],
    label: 'Select an app',
    hint: 'Press / to search',
    required: true,
);
```

## Reference Implementation

- `orbit app:list` — renders the Name, Repository, Instances, and Workspaces
  columns and opens the selected app's `app:show` drill-down. The
  prompt label is `Select an app`.

## Cross References

- [Laravel Prompts: datatable](https://laravel.com/docs/13.x/prompts#datatable)
- [`table`](table.md) for non-interactive read-only rows
- [`property-list`](property-list.md) for grouped labeled properties
