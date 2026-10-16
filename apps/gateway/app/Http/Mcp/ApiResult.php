<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Support\ValidatedData;
use stdClass;

/** The status and body an API operation answered an MCP tool call with. */
final readonly class ApiResult
{
    public function __construct(public int $status, public string $body) {}

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * The JSON document, or null when the body is empty or not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }

        $decoded = JsonObjects::decode($this->body);

        if ($decoded instanceof stdClass) {
            return [];
        }

        if (! is_array($decoded)) {
            return null;
        }

        return ValidatedData::object($decoded);
    }
}
