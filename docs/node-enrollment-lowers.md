# Native enrollment lowers

Native parents share guest configuration and readiness without admitting a second Operation. The owning parent validates the Node and binds existing private material. These helpers create no credential authority and run no observer action command.

`operations/node/enrollment_configure.ts` exports `EnrollmentConfiguration` and `configureEnrolledNode(input, withPrivateMaterial, storeHash, signal?, installWorker?)`. The private callback supplies the admitted guest transport and protected material. Configuration creates or reuses the observer hash. The helper stores that hash before it starts the selected worker. A failed store stops worker installation. Credentials stay inside the callback; the public result contains only `configured` and a bounded error code.

`operations/node/enrollment_readiness.ts` exports `verifyEnrollmentWorker(input, transport?, signal?)`. A parent places this step on the exact admitted guest worker and label. It checks the account, passwordless sudo, root-owned configuration bytes and observer digest through fixed system commands. A configured proxy uses the canonical system transport. The helper reads files and never changes them. Cancellation before the call performs no private read or system command.

`operations/node/enrollment_settings.ts` exports `prepareEnrollmentSettings(input, transport?, signal?)`. It discovers the admitted candidate account and uses the canonical role storage action. It refuses the account home, its ancestors, hidden paths under that home, protected roots and managed checkouts before filesystem effects. Existing root ownership and symlink refusals come from the canonical storage action. It returns only a settings outcome; the parent commits stored settings.

The parent owns bootstrap, worker issuance, custody, rollback and the final result. A readiness result does not activate a Node or prove the rest of enrollment.
