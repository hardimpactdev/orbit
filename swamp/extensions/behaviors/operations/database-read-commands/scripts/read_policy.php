<?php

declare(strict_types=1);

/** One bounded read-only query or metadata observation; no workflow control. */
function assertReadSql(string $sql, string $driver): void
{
    if (trim($sql) === '' || str_contains($sql, '\\') || str_contains($sql, "\0")) {
        throw new RuntimeException;
    }
    preg_match_all('~\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|--[^\n]*|\#[^\n]*|/\*[\s\S]*?\*/|[a-z_][a-z0-9_$]*|[^\s]~i', $sql, $matches);
    $raw = $matches[0];
    $tokens = array_map(static function (string $word): string {
        if (preg_match('~^(?:--|\#|/\*)~', $word)) {
            throw new RuntimeException;
        }
        if (str_starts_with($word, "'")) {
            return '<literal>';
        }
        return strtolower(in_array($word[0], ['"', '`'], true) ? str_replace(['""', '``'], ['"', '`'], substr($word, 1, -1)) : $word);
    }, $raw);
    if (!in_array($tokens[0] ?? '', ['select', 'with', 'show', 'explain', 'describe'], true)) {
        throw new RuntimeException;
    }
    $functions = explode(' ', 'abs acos asin atan atan2 avg bit_length cast ceil ceiling char_length character_length coalesce concat concat_ws convert cos count current_date current_time current_timestamp database date date_format date_part date_trunc datediff day decode dense_rank encode exp extract first_value floor format greatest group_concat hex initcap instr json_array json_array_length json_arrayagg json_build_array json_build_object json_extract json_object json_objectagg json_query json_type json_unquote json_value json_agg jsonb_agg jsonb_array_length jsonb_build_array jsonb_build_object jsonb_typeof lag last_value lead least left length ln locate log log10 lower lpad ltrim max md5 min mod month now ntile nullif octet_length percentile_cont percentile_disc position power rand random rank regexp_replace replace reverse right round row_number rpad rtrim sha1 sha2 sign sin split_part sqrt stddev stddev_pop stddev_samp string_agg substr substring sum tan timestampdiff to_char to_date to_timestamp translate trim trunc truncate unhex unix_timestamp upper user variance var_pop var_samp version year array_agg generate_series pg_column_size pg_database_size pg_indexes_size pg_relation_size pg_size_pretty pg_table_size pg_total_relation_size pg_typeof');
    $syntax = ['as', 'in', 'exists', 'over', 'filter', 'select', 'values'];
    $writes = explode(' ', 'into insert update delete create alter drop truncate grant revoke copy call do execute set commit rollback begin start lock unlock');
    foreach ($tokens as $index => $word) {
        if ($word === '$' || ($word === ';' && $index !== count($tokens) - 1)
            || (in_array($word, $writes, true) && !in_array($raw[$index][0], ['"', '`'], true))) {
            throw new RuntimeException;
        }
        if (preg_match('/^[a-z_]/', $word) && ($tokens[$index + 1] ?? '') === '(') {
            if ((!in_array($word, $functions, true) && !in_array($word, $syntax, true))
                || (($tokens[$index - 1] ?? '') === '.' && ($driver !== 'pgsql' || ($tokens[$index - 2] ?? '') !== 'pg_catalog'))) {
                throw new RuntimeException;
            }
        }
    }
}

function redact(mixed $value, string $password): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $result[is_string($key) ? redact($key, $password) : $key] = redact($item, $password);
        }
        return $result;
    }
    if (!is_string($value)) {
        return $value;
    }
    $value = $password !== '' ? str_replace($password, '[REDACTED]', $value) : $value;
    return preg_replace([
        '~-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)~',
        '~\b([a-z][a-z0-9+.-]*://)[^/@\s]+@~i',
        '~\b((?:Proxy-)?Authorization)\s*:\s*[^\s\'"]+(?:\s+[^\s\'"]+)?~i',
        '~\bBearer\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+/=]{7,})~i',
        '~\b(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})\b~',
        '~\b((?:[A-Za-z][A-Za-z0-9]*[_-])*(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)(?:[_-](?:HASH|BASE))?)\s*([=:])\s*(?:"[^"]*"|\'[^\']*\'|[^\s&#,}]+)~i',
    ], ['[REDACTED]', '$1[REDACTED]@', '$1: [REDACTED]', 'Bearer [REDACTED]', '[REDACTED]', '$1$2[REDACTED]'], $value);
}
