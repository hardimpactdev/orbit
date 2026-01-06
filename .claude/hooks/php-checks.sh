#!/bin/bash

# Read JSON input from stdin
input=$(cat)

# Extract the file path from the tool input
file_path=$(echo "$input" | jq -r '.tool_input.file_path // empty')

# Only run if a PHP file was modified
if [[ "$file_path" == *.php ]]; then
    cd "$CLAUDE_PROJECT_DIR"

    log_file="storage/logs/laravel.log"

    # Store current log line count before running checks
    if [[ -f "$log_file" ]]; then
        log_lines_before=$(wc -l < "$log_file")
    else
        log_lines_before=0
    fi

    echo "Running Rector..."
    ./vendor/bin/rector 2>&1

    echo ""
    echo "Running Pint..."
    ./vendor/bin/pint 2>&1

    echo ""
    echo "Running PHPStan..."
    ./vendor/bin/phpstan analyse --memory-limit=512M 2>&1

    echo ""
    echo "Running tests..."
    ./vendor/bin/phpunit 2>&1

    # Check for new errors/warnings in laravel.log
    echo ""
    echo "Checking laravel.log for new errors/warnings..."
    if [[ -f "$log_file" ]]; then
        log_lines_after=$(wc -l < "$log_file")
        new_lines=$((log_lines_after - log_lines_before))

        if [[ $new_lines -gt 0 ]]; then
            new_errors=$(tail -n "$new_lines" "$log_file" | grep -E '\[(ERROR|WARNING|CRITICAL|ALERT|EMERGENCY)\]' 2>/dev/null)
            if [[ -n "$new_errors" ]]; then
                echo "⚠️  New errors/warnings found in laravel.log:"
                echo "$new_errors"
            else
                echo "✓ No new errors or warnings"
            fi
        else
            echo "✓ No new log entries"
        fi
    else
        echo "✓ No laravel.log file (no errors logged)"
    fi
fi
