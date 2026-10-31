import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import { nativeEffect } from "./native_effect.ts";
import { runSqliteLocal } from "./sqlite_local.ts";
import type { PrivateMaterialUse } from "./private_material.ts";
import { assertReadSql } from "./read_sql.ts";

const Input = z.object({
  driver: z.enum(["sqlite", "mysql", "pgsql"]),
  mode: z.enum(["query", "tables", "schema", "describe"]),
  table: z.string().min(1).max(255).nullable(),
  owner: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).optional(),
  path: z.string().startsWith("/").optional(),
}).strict();
const Material = z.object({
  sql: z.string().max(16384),
  dsn: z.string().max(4096),
  username: z.string().max(128).nullable(),
  password: z.string().max(16384),
}).strict();
const Value = z.union([z.string(), z.number().finite(), z.boolean(), z.null()]);
const Column = z.object({
  name: z.string(),
  type: z.string(),
  nullable: z.boolean(),
  default: z.string().nullable(),
  primary: z.boolean(),
}).strict();
export const ReadResult = z.union([
  z.object({
    columns: z.array(z.string()).max(500),
    rows: z.array(z.record(z.string(), Value)).max(500),
    row_count: z.number().int().min(0).max(500),
    truncated: z.boolean(),
  }).strict(),
  z.object({ tables: z.array(z.string()).max(500) }).strict(),
  z.object({
    tables: z.array(
      z.object({ name: z.string(), columns: z.array(Column).max(500) })
        .strict(),
    ).max(500),
  }).strict(),
  z.object({ table: z.string(), columns: z.array(Column).max(500) }).strict(),
  z.object({
    error_code: z.enum([
      "database.query_failed",
      "database.table_missing",
      "database.sqlite_unreadable",
    ]),
  }).strict(),
]);

/** Read-only transaction and fixed metadata queries. Credentials and user SQL stay on stdin. */
const php = String.raw`
$p=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
try {
 $mysql=str_starts_with($p['dsn'],'mysql:');
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10,PDO::ATTR_EMULATE_PREPARES=>false];
 if($mysql) $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS]=false;
 $db=new PDO($p['dsn'],$p['username'],$p['password'],$options);
 $db->exec($mysql?'START TRANSACTION READ ONLY':'BEGIN READ ONLY');
 if(!$mysql) $db->exec("SET LOCAL statement_timeout = '10s'");
 $query=function($sql,$parameters=[])use($db) {
   $statement=$db->prepare($sql);$statement->execute($parameters);$rows=[];$columns=[];$truncated=false;
   for($i=0;$i<$statement->columnCount();$i++) $columns[]=(string)($statement->getColumnMeta($i)['name']??$i);
   while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false) {
     foreach($row as &$value) if(!is_scalar($value)&&$value!==null) $value=null;
     unset($value);
     if(count($rows)>=500||strlen(json_encode([$columns,$rows,$row],JSON_THROW_ON_ERROR))>11000){$truncated=true;break;}
     $rows[]=$row;
   }
   $statement->closeCursor();return ['columns'=>$columns,'rows'=>$rows,'row_count'=>count($rows),'truncated'=>$truncated];
 };
 $tables=function()use($mysql,$query){return array_column($query($mysql?"SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME":"SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname=current_schema() ORDER BY tablename")['rows'],'name');};
 $describe=function($table)use($mysql,$query){
   $sql=$mysql?"SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable,COLUMN_DEFAULT AS default_value,COLUMN_KEY AS is_primary FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION":"SELECT a.attname AS name,pg_catalog.format_type(a.atttypid,a.atttypmod) AS type,NOT a.attnotnull AS nullable,pg_get_expr(ad.adbin,ad.adrelid) AS default_value,COALESCE(i.indisprimary,false) AS is_primary FROM pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid=ad.adrelid AND a.attnum=ad.adnum LEFT JOIN pg_catalog.pg_index i ON a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey) AND i.indisprimary WHERE a.attrelid=to_regclass(quote_ident(?)) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum";
   return array_map(fn($row)=>['name'=>(string)$row['name'],'type'=>(string)$row['type'],'nullable'=>$mysql?$row['nullable']==='YES':in_array($row['nullable'],[true,1,'1','t'],true),'default'=>$row['default_value']===null?null:(string)$row['default_value'],'primary'=>$mysql?$row['is_primary']==='PRI':in_array($row['is_primary'],[true,1,'1','t'],true)],$query($sql,[$table])['rows']);
 };
 $result=match($p['mode']) {
  'query'=>$query($p['sql']),
  'tables'=>['tables'=>$tables()],
  'schema'=>['tables'=>array_map(fn($table)=>['name'=>$table,'columns'=>$describe($table)],$tables())],
  'describe'=>['table'=>$p['table'],'columns'=>$describe($p['table'])],
 };
 $db->rollBack();
 if($p['mode']==='describe'&&$result['columns']===[]) $result=['error_code'=>'database.table_missing'];
 $json=json_encode($result,JSON_THROW_ON_ERROR);if(strlen($json)>12000)exit(1);echo $json;
} catch(Throwable $error){exit(1);}
`;
export async function pdoRead(
  input: Record<string, unknown>,
  signal?: AbortSignal,
): Promise<unknown> {
  signal?.throwIfAborted();
  try {
    if (input.mode === "query") {
      if (typeof input.sql !== "string" || typeof input.dsn !== "string") {
        throw new Error();
      }
      assertReadSql(
        input.sql,
        input.dsn.startsWith("mysql:") ? "mysql" : "pgsql",
      );
    }
  } catch {
    return { error_code: "database.query_failed" };
  }
  const child = localCommand("/usr/bin/php", {
    args: ["-r", php],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    signal: AbortSignal.any([
      AbortSignal.timeout(30000),
      ...(signal ? [signal] : []),
    ]),
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(input)));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 16384) throw new Error("Bounded read");
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    if (!status.success) return { error_code: "database.query_failed" };
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return JSON.parse(new TextDecoder().decode(bytes));
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* exited */ }
      await child.status.catch(() => {});
    }
  }
}

function redact(value: unknown, password: string): unknown {
  if (typeof value === "string") {
    let text = password ? value.replaceAll(password, "[REDACTED]") : value;
    text = text.replace(
      /-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)/g,
      "[REDACTED]",
    )
      .replace(/\b([a-z][a-z0-9+.-]*:\/\/)[^\/@\s]+@/gi, "$1[REDACTED]@")
      .replace(
        /\b((?:Proxy-)?Authorization)\s*:\s*[^\s'"]+(?:\s+[^\s'"]+)?/gi,
        "$1: [REDACTED]",
      )
      .replace(
        /\bBearer\s+(?:"[^"]*"|'[^']*'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,})/gi,
        "Bearer [REDACTED]",
      )
      .replace(
        /\b(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})\b/g,
        "[REDACTED]",
      )
      .replace(
        /\b((?:[A-Za-z][A-Za-z0-9]*[_-])*(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)(?:[_-](?:HASH|BASE))?)\s*([=:])\s*(?:"[^"]*"|'[^']*'|[^\s&#,}]+)/gi,
        "$1$2[REDACTED]",
      );
    return text;
  }
  if (Array.isArray(value)) return value.map((v) => redact(v, password));
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value).map((
        [k, v],
      ) => [String(redact(k, password)), redact(v, password)]),
    );
  }
  return value;
}

export async function readDatabase(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  sqlite = runSqliteLocal,
  pdo = pdoRead,
) {
  try {
    const input = Input.parse(raw);
    signal?.throwIfAborted();
    let result: z.infer<typeof ReadResult> | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      if (
        Object.values(material).some((v) =>
          typeof v === "string" && v.includes("\0")
        )
      ) throw new Error("Invalid read material");
      let reply: unknown;
      if (input.driver === "sqlite") {
        if (
          !input.owner || !input.path || material.dsn !== "" ||
          material.username !== null || material.password !== ""
        ) throw new Error("Foreign read material");
        reply = await sqlite({
          action: "read",
          input: {
            owner: input.owner,
            path: input.path,
            mode: input.mode,
            table: input.table,
            sql: material.sql,
          },
        }, signal);
      } else {
        if (
          !material.dsn.startsWith(`${input.driver}:`) || input.owner ||
          input.path
        ) throw new Error("Foreign read material");
        reply = await pdo(
          { ...material, mode: input.mode, table: input.table },
          signal,
        );
      }
      result = ReadResult.parse(
        redact(ReadResult.parse(reply), material.password),
      );
      if (new TextEncoder().encode(JSON.stringify(result)).length > 15000) {
        throw new Error("Bounded read");
      }
    });
    if (!result) throw new Error("No read material");
    return result;
  } catch {
    return { error_code: "database.query_failed" as const };
  }
}
export function databaseRead(makeHttp?: Parameters<typeof nativeEffect>[4]) {
  return nativeEffect(
    "database.read",
    "@nckrtl/database-read",
    ReadResult,
    (input, gateway, signal) =>
      readDatabase(input, (use) => gateway.withPrivateMaterial(use), signal),
    makeHttp,
  );
}
export const behavior = { ...databaseRead(), type: "@nckrtl/database-read" };
