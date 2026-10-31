/** Network reads expose a bounded SQL subset; arbitrary functions require explicit write permission. */
const functions = new Set((
  "abs acos asin atan atan2 avg bit_length cast ceil ceiling char_length character_length " +
  "coalesce concat concat_ws convert cos count current_date current_time current_timestamp " +
  "database date date_format date_part date_trunc datediff day decode dense_rank encode exp " +
  "extract first_value floor format greatest group_concat hex initcap instr json_array " +
  "json_array_length json_arrayagg json_build_array json_build_object json_extract json_object " +
  "json_objectagg json_query json_type json_unquote json_value json_agg jsonb_agg jsonb_array_length " +
  "jsonb_build_array jsonb_build_object jsonb_typeof lag last_value lead least left length ln " +
  "locate log log10 lower lpad ltrim max md5 min mod month now ntile nullif octet_length " +
  "percentile_cont percentile_disc position power rand random rank regexp_replace replace " +
  "reverse right round row_number rpad rtrim sha1 sha2 sign sin split_part sqrt stddev " +
  "stddev_pop stddev_samp string_agg substr substring sum tan timestampdiff to_char to_date " +
  "to_timestamp translate trim trunc truncate unhex unix_timestamp upper user variance " +
  "var_pop var_samp version year array_agg generate_series pg_column_size pg_database_size " +
  "pg_indexes_size pg_relation_size pg_size_pretty pg_table_size pg_total_relation_size pg_typeof"
).split(/\s+/));
const syntax = new Set([
  "as",
  "in",
  "exists",
  "over",
  "filter",
  "select",
  "values",
]);
const writes = new Set([
  "into",
  "insert",
  "update",
  "delete",
  "create",
  "alter",
  "drop",
  "truncate",
  "grant",
  "revoke",
  "copy",
  "call",
  "do",
  "execute",
  "set",
  "commit",
  "rollback",
  "begin",
  "start",
  "lock",
  "unlock",
]);

/** Reject file exports, executable comments, transaction controls and unreviewed function effects. */
export function assertReadSql(sql: string, driver: "mysql" | "pgsql") {
  if (!sql.trim() || /[\\\0]/.test(sql)) throw new Error("Unsafe read SQL");
  const raw = sql.match(
    /'(?:''|[^'])*'|"(?:""|[^"])*"|`(?:``|[^`])*`|--[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/|[a-z_][a-z0-9_$]*|[^\s]/gi,
  ) ?? [];
  const tokens = raw.map((word) => {
    if (/^(?:--|#|\/\*)/.test(word)) throw new Error("Unsafe read SQL");
    if (word.startsWith("'")) return "<literal>";
    return /^["`]/.test(word)
      ? word.slice(1, -1).replaceAll('""', '"').replaceAll("``", "`")
        .toLowerCase()
      : word.toLowerCase();
  });
  if (!["select", "with", "show", "explain", "describe"].includes(tokens[0])) {
    throw new Error("Unsafe read SQL");
  }
  for (let i = 0; i < tokens.length; i++) {
    const word = tokens[i];
    if (word === "$") throw new Error("Unsafe read SQL");
    if (word === ";" && i !== tokens.length - 1) {
      throw new Error("Unsafe read SQL");
    }
    if (writes.has(word) && !/^["`]/.test(raw[i])) {
      throw new Error("Unsafe read SQL");
    }
    if (/^[a-z_]/.test(word) && tokens[i + 1] === "(") {
      if (!functions.has(word) && !syntax.has(word)) {
        throw new Error("Unsafe read SQL");
      }
      if (
        tokens[i - 1] === "." &&
        (driver !== "pgsql" || tokens[i - 2] !== "pg_catalog")
      ) throw new Error("Unsafe read SQL");
    }
  }
}
