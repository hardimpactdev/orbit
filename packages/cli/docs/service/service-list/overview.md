# service:list overview

Lists configured services or available service templates.

- Default mode shows configured services with enabled/disabled status
- --available mode shows all available service templates grouped by category
- Shows version information, dependencies, and descriptions

Failure and recovery paths

- Returns empty list if no services configured

Inputs and options

- --available: Show available templates instead of configured services
- --json: Output as JSON

Key integrations

- ServiceManager for configured services
- ServiceTemplateLoader for available templates
