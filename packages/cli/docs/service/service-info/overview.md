# service:info overview

Shows detailed information about a service.

- Shows template info if available (description, versions, dependencies)
- Shows current configuration if service is configured
- Shows available configuration options from template schema

Failure and recovery paths

- Fails if service not found and no template exists

Inputs and options

- service (required): Service name
- --json: Output as JSON

Key integrations

- ServiceManager for current configuration
- ServiceTemplateLoader for template metadata and schema
