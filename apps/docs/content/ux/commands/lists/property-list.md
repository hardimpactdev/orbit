# Property List

Read-only grouped list for items that need several vertically stacked labeled
properties.

## Use When

- Items are grouped by an owning entity, such as node-scoped tool state.
- Each item has several values that scan better as `Label: value` lines.
- Empty or unknown values need explicit labels rather than empty cells.

## Avoid When

- The operator should select a row. Use [`data-list`](data-list.md), which means
  `Laravel\Prompts\datatable`.
- The data is naturally columnar and read-only. Use [`table`](table.md).
- A single entity is being shown. Use
  [`show-detail`](../details/show-detail.md).

## Contract

- Primitive name in renderer docs: `property-list`.
- Each group starts with a short heading such as `Node: app-1`.
- Each item starts with its primary label, followed by indented `Label: value`
  property lines.
- Empty values render as `—`; JSON retains `null`.
- The property list is display-only and has no highlighted or selected row.

## Implementation

Render with Orbit's Prompts-backed `App\Support\Prompts\PropertyList`
component. Do not call this component a data list.

## Example

```php
(new PropertyList([
    [
        'heading' => 'Node: app-1',
        'items' => [
            [
                'label' => 'composer',
                'properties' => [
                    'Expected' => 'installed',
                    'Managed' => 'yes',
                    'Version' => '—',
                ],
            ],
        ],
    ],
]))->display();
```

## Reference Implementation

- `orbit tool:list` — grouped read-only tool properties.

## Cross References

- [`data-list`](data-list.md) for interactive Laravel Prompts datatable rows
- [`table`](table.md) for compact read-only rows
