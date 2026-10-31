import type { Row } from "./doctor_contract.ts";

const budget = 1048576;
const recordCap = 8388608;
const header =
  /^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^.\[\]\s]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /gm;
type LogFile = { path: string; inode: number; size: number; mtime: number };
type Cursor = { path: string; inode: number; offset: number };

/** Bounded local log reads. Only exception identity and a normalized app frame leave this action. */
export async function collectLogs(input: Row): Promise<Row> {
  if (
    typeof input.log_directory !== "string" ||
    typeof input.base_directory !== "string" ||
    !input.log_directory.startsWith("/") ||
    !input.base_directory.startsWith("/")
  ) throw new Error("observation.log_input_invalid");
  const directory = input.log_directory.replace(/\/+$/, "");
  const files: LogFile[] = [];
  try {
    for await (const entry of Deno.readDir(directory)) {
      if (!/^laravel(?:.*)\.log$/.test(entry.name)) continue;
      const path = `${directory}/${entry.name}`;
      const stat = await Deno.stat(path);
      if (stat.isFile && stat.ino !== null) {
        files.push({
          path,
          inode: stat.ino,
          size: stat.size,
          mtime: stat.mtime?.getTime() ?? 0,
        });
      }
    }
  } catch (error) {
    if (!(error instanceof Deno.errors.NotFound)) {
      throw new Error("observation.log_read_failed");
    }
  }
  const single = files.find((file) => file.path === `${directory}/laravel.log`);
  const daily = files.filter((file) => /\/laravel-[^/]*\.log$/.test(file.path))
    .sort((left, right) =>
      left.mtime - right.mtime || left.path.localeCompare(right.path)
    );
  const current = single ?? daily.at(-1);
  if (input.cursor === null || input.cursor === undefined) {
    return {
      cursor: current
        ? { path: current.path, inode: current.inode, offset: current.size }
        : null,
      signals: [],
    };
  }
  const cursor = input.cursor as Cursor;
  if (
    !cursor || typeof cursor.path !== "string" ||
    !cursor.path.startsWith(directory + "/") ||
    cursor.path.slice(directory.length + 1).includes("/") ||
    !Number.isSafeInteger(cursor.inode) ||
    !Number.isSafeInteger(cursor.offset) || cursor.offset < 0
  ) throw new Error("observation.log_input_invalid");
  const known =
    files.find((file) =>
      file.path === cursor.path && file.inode === cursor.inode
    ) ?? files.find((file) => file.inode === cursor.inode);
  const reading = known ?? current;
  if (!reading) return { cursor, signals: [] };
  const start = known ? Math.min(cursor.offset, reading.size) : 0;
  const handle = await Deno.open(reading.path, { read: true });
  let records: string[] = [];
  let consumed = 0;
  try {
    await handle.seek(start, Deno.SeekMode.Start);
    const bytes = new Uint8Array(recordCap);
    let length = 0;
    let eof = false;
    const pull = async (limit: number) => {
      while (length < limit) {
        const read = await handle.read(bytes.subarray(length, limit));
        if (read === null) {
          eof = true;
          break;
        }
        length += read;
      }
      eof ||= start + length >= (await handle.stat()).size;
    };
    await pull(budget);
    let slice = sliceRecords(bytes.subarray(0, length), eof);
    if (slice.consumed === 0 && length > 0 && !eof) {
      await pull(recordCap);
      slice = sliceRecords(bytes.subarray(0, length), eof);
      if (slice.consumed === 0) {
        slice = {
          records: [new TextDecoder().decode(bytes.subarray(0, length))],
          consumed: length,
        };
      }
    }
    records = slice.records;
    consumed = slice.consumed;
    const fresh = await handle.stat();
    const next = start + consumed >= fresh.size && current &&
        (current.path !== reading.path || current.inode !== reading.inode)
      ? { path: current.path, inode: current.inode, offset: 0 }
      : {
        path: reading.path,
        inode: reading.inode,
        offset: start + consumed,
      };
    const roots = [
      input.base_directory.replace(/\\/g, "/").replace(/\/+$/, ""),
    ];
    try {
      const canonical = (await Deno.realPath(input.base_directory)).replace(
        /\\/g,
        "/",
      ).replace(/\/+$/, "");
      if (!roots.includes(canonical)) roots.push(canonical);
    } catch { /* Stored base path still identifies its app frames. */ }
    return {
      cursor: next,
      signals: records.map((record) => parseSignal(record, roots)).filter((
        signal,
      ) => signal !== null),
    };
  } catch {
    throw new Error("observation.log_read_failed");
  } finally {
    handle.close();
  }
}

function sliceRecords(bytes: Uint8Array, eof: boolean) {
  const text = new TextDecoder().decode(bytes);
  const starts = Array.from(text.matchAll(header), (match) => match.index!);
  if (!starts.length) return { records: [], consumed: eof ? bytes.length : 0 };
  const records: string[] = [];
  let consumed = 0;
  for (let i = 0; i < starts.length; i++) {
    const next = starts[i + 1];
    if (next !== undefined) {
      records.push(text.slice(starts[i], next));
      consumed = new TextEncoder().encode(text.slice(0, next)).length;
    } else if (eof) {
      records.push(text.slice(starts[i]));
      consumed = bytes.length;
    }
  }
  return { records, consumed };
}

function parseSignal(record: string, roots: string[]): Row | null {
  const match = Array.from(record.matchAll(header))[0];
  if (
    !match || !["ERROR", "CRITICAL", "ALERT", "EMERGENCY"].includes(match[2])
  ) return null;
  const exception = record.match(
    /\[object\] \(([A-Za-z_\\][A-Za-z0-9_\\]{0,511})\(code:\s*(-?\d{1,6})\)/,
  );
  if (!exception) return null;
  const className = exception[1].replaceAll("\\\\", "\\");
  const base = className.split("\\").at(-1)!;
  if (base === "ValidationException") return null;
  const statuses: Record<string, number> = {
    BadRequestHttpException: 400,
    UnauthorizedHttpException: 401,
    AccessDeniedHttpException: 403,
    NotFoundHttpException: 404,
    MethodNotAllowedHttpException: 405,
    NotAcceptableHttpException: 406,
    ConflictHttpException: 409,
    GoneHttpException: 410,
    LengthRequiredHttpException: 411,
    PreconditionFailedHttpException: 412,
    PreconditionRequiredHttpException: 428,
    UnsupportedMediaTypeHttpException: 415,
    UnprocessableEntityHttpException: 422,
    LockedHttpException: 423,
    TooManyRequestsHttpException: 429,
    ServiceUnavailableHttpException: 503,
  };
  const status = base === "HttpException" && Number(exception[2]) >= 100 &&
      Number(exception[2]) <= 599
    ? Number(exception[2])
    : statuses[base];
  if (status && status < 500) return null;
  let frame: string | null = null;
  for (
    const entry of record.matchAll(/^#\d+ (.{1,2048}?)\(\d+\): (.{1,2048})$/gm)
  ) {
    const path = entry[1].replaceAll("\\", "/");
    const root = roots.find((root) => path.startsWith(root + "/app/"));
    if (!root) continue;
    const relative = path.slice(root.length + 1);
    if (
      !/^app\/[A-Za-z0-9_./-]+$/.test(relative) ||
      relative.split("/").includes("..")
    ) continue;
    const call = entry[2].replaceAll("\\\\", "\\").trimEnd().replace(
      /\([^)]*\)$/,
      "",
    ).replace(/:\d+(?=})/g, "");
    if (!/^[A-Za-z0-9_\\:>{}().-]{1,1024}$/.test(call)) continue;
    frame = `${relative}:${call}`;
    break;
  }
  if (!frame) return null;
  const request = record.match(/"request_id"\s*:\s*"([A-Za-z0-9_.:-]{1,128})"/);
  return {
    exception_class: className,
    frame,
    request_id: request?.[1] ?? null,
    recorded_at: match[1],
  };
}
