---
paths:
  - 'tests/**'
---

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the tests that cover the change with stock Pest. Pass a file path or `--filter=testName` to `vendor/bin/pest --compact`.
- Rerun a test after each change to it.
- Run the project's full suite with `composer test`.
- After those tests pass, follow the root `AGENTS.md` check policy for task and non-task work. Follow the root contributor guide for CI and independent review.
