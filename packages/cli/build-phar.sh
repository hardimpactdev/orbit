#!/bin/bash

# Generate command registry for PHAR
echo "<?php" > app/CommandRegistry.php
echo "" >> app/CommandRegistry.php
echo "namespace App;" >> app/CommandRegistry.php
echo "" >> app/CommandRegistry.php
echo "class CommandRegistry" >> app/CommandRegistry.php
echo "{" >> app/CommandRegistry.php
echo "    public static function getCommands(): array" >> app/CommandRegistry.php
echo "    {" >> app/CommandRegistry.php
echo "        return [" >> app/CommandRegistry.php

# Find all command classes
for file in app/Commands/*.php; do
    if [[ -f "$file" && "$file" != *"AGENTS.md" ]]; then
        class=$(basename "$file" .php)
        echo "            \\App\\Commands\\$class::class," >> app/CommandRegistry.php
    fi
done

echo "        ];" >> app/CommandRegistry.php
echo "    }" >> app/CommandRegistry.php
echo "}" >> app/CommandRegistry.php

echo "Generated command registry with $(find app/Commands -name "*.php" -type f | wc -l) commands"

# Build PHAR
php box.phar compile

# Clean up
rm -f app/CommandRegistry.php

echo "PHAR build complete"
