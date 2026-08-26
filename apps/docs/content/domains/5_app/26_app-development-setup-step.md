# App development setup defaults

Orbit stores reusable development setup steps on the app. Use
`app-development-setup-step:add`, `:list`, `:update`, and `:remove` to manage
them. These commands only manage defaults. They do not execute or copy steps;
new app-dev instance creation owns copying them into an independent instance
pipeline.

List requires `app:read`. Add, update, and remove require `app:write`.
Removal requires `--force` and explicit destructive consent. Ordering uses
`--before` or `--after`; command and timeout values remain stable until changed.
