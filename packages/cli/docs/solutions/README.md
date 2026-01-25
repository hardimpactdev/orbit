# Orbit CLI Solutions Documentation

This directory contains documented solutions to problems encountered during development and operation of Orbit CLI.

## Categories

### build-errors/
Issues related to building, compiling, or packaging the application.

### test-failures/
Test suite failures and their resolutions.

### runtime-errors/
Errors that occur during normal operation.

### performance-issues/
Performance bottlenecks and optimizations.

### database-issues/
Database connection, migration, and query problems.

### security-issues/
Security vulnerabilities and their fixes.

### ui-bugs/
User interface and display issues.

### integration-issues/
Problems with external service integrations.

### logic-errors/
Business logic bugs and their corrections.

## How to Use

1. **When encountering an issue**: Search this directory first
   ```bash
   grep -r "your error message" docs/solutions/
   ```

2. **After solving a problem**: Document it using the template
   - Use descriptive filenames: `[symptom]-[component]-[YYYYMMDD].md`
   - Include exact error messages
   - Document what didn't work and why
   - Provide the working solution with code examples

## Recent Solutions

- [PHAR Build Missing orbit-core Service Provider](build-errors/phar-missing-service-provider-orbit-core-20260122.md) - Critical build issue preventing PHAR distribution

## Contributing

When documenting a new solution:
1. Place it in the appropriate category directory
2. Use the documentation template (see any existing file)
3. Include prevention strategies
4. Cross-reference related issues