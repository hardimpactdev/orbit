# macOS activation-policy blast radius

Candidate: `a716d1eea10463ed4f73ae70e02f973fb9c13b14`

`git diff --stat ca29251f3035e809c77f59d56aabd5b3bdb70d98 HEAD`
reports one insertion in `apps/macos/src/main.rs`.

A repository-wide search for `activation.?policy|Accessory` outside generated
Rust targets returns only the guarded call in `apps/macos/src/main.rs`.

The diff does not touch Cargo dependencies, package dependencies, native menu
or lifecycle code, the updater, release workflows, tests, or product docs. The
existing docs remain accurate because Darwin behavior is unchanged.

Result: the affected surface is complete and limited to the platform compile
boundary of the existing activation-policy statement.
