<?php

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function orbitSecretRules(bool $includeSessionAssignments = true): array
{
    $rules = [
        'private-key' => '~-----BEGIN ((?:ENCRYPTED |RSA |EC |OPENSSH )?PRIVATE KEY)-----[\s\S]*?-----END \1-----~',
        'private-key-header' => '/-----BEGIN (?:ENCRYPTED |RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'github-token' => '/\b(?:gh[opurs]|github_pat)_[A-Za-z0-9_]{20,}\b/',
        'aws-access-key' => '/(?<![A-Z0-9])(?:AKIA|ASIA)[A-Z0-9]{16}(?![A-Z0-9])/',
        'laravel-app-key' => '/\bAPP_KEY\s*=\s*base64:[A-Za-z0-9+\/]{43}=/',
        'slack-token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
        'bearer-token' => '/\bAuthorization:\s*Bearer\s+[A-Za-z0-9._~-]{20,}\b/i',
    ];

    if ($includeSessionAssignments) {
        $rules['secret-assignment'] = '~(\b(?:[A-Za-z0-9]+[_-])*(?:password|secret|token|api[_-]?key|client[_-]?secret|access[_-]?token)\b(?:\\\\)?["\x27]?\s*[:=]\s*(?:\\\\)?["\x27]?)((?!\[REDACTED:)[^\s"\x27\\\\]{16,})((?:\\\\)?["\x27]?)~i';
    }

    return $rules;
}

/**
 * @return array{text: string, raw_sha256: string, redactions: list<string>}
 */
function orbitRedactSecrets(string $text): array
{
    $redacted = $text;
    $redactions = [];

    foreach (orbitSecretRules() as $rule => $pattern) {
        if (preg_match($pattern, $redacted) !== 1) {
            continue;
        }

        $replacement = "[REDACTED:{$rule}]";
        $redacted = $rule === 'secret-assignment'
            ? (string) preg_replace($pattern, '$1'.$replacement.'$3', $redacted)
            : (string) preg_replace($pattern, $replacement, $redacted);
        $redactions[] = $rule;
    }

    return [
        'text' => $redacted,
        'raw_sha256' => hash('sha256', $text),
        'redactions' => $redactions,
    ];
}

/**
 * @return list<array{rule: string, match: string}>
 */
function orbitSecretFindings(string $contents, bool $includeSessionAssignments = true): array
{
    $findings = [];

    foreach (orbitSecretRules($includeSessionAssignments) as $rule => $pattern) {
        if (preg_match_all($pattern, $contents, $matches) === false) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $findings[] = ['rule' => $rule, 'match' => $match];
        }
    }

    return $findings;
}
