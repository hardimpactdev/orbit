/** Helpers for fake tools that native tests put on a workflow's path. Dependency free, so fakes start fast. */

/** Split a POSIX shell word list the way Python's `shlex.split` does. */
export function shellSplit(line: string): string[] {
  const words: string[] = [];
  let word: string | null = null;
  for (let index = 0; index < line.length; index++) {
    const char = line[index];
    if (char === "'") {
      const end = line.indexOf("'", index + 1);
      if (end < 0) throw new Error("Unclosed single quote");
      word = (word ?? "") + line.slice(index + 1, end);
      index = end;
    } else if (char === '"') {
      word ??= "";
      for (index++; line[index] !== '"'; index++) {
        if (index >= line.length) throw new Error("Unclosed double quote");
        if (line[index] === "\\" && '"\\$`\n'.includes(line[index + 1])) index++;
        word += line[index];
      }
    } else if (char === "\\") {
      word = (word ?? "") + line[++index];
    } else if (/\s/.test(char)) {
      if (word !== null) words.push(word);
      word = null;
    } else {
      word = (word ?? "") + char;
    }
  }
  if (word !== null) words.push(word);
  return words;
}

/** Quote words for a POSIX shell the way Python's `shlex.join` does. */
export function shellJoin(words: string[]): string {
  return words.map((word) => /^[\w@%+=:,./-]+$/.test(word) ? word : `'${word.replaceAll("'", `'"'"'`)}'`).join(" ");
}

/** Run argv locally with stdin and pass its output and exit code through. */
export async function passThrough(argv: string[], stdin: Uint8Array): Promise<never> {
  const child = new Deno.Command(argv[0], { args: argv.slice(1), stdin: "piped", stdout: "piped", stderr: "piped" })
    .spawn();
  const writer = child.stdin.getWriter();
  await writer.write(stdin);
  await writer.close();
  const result = await child.output();
  await Deno.stdout.write(result.stdout);
  await Deno.stderr.write(result.stderr);
  Deno.exit(result.code);
}

export async function readStdinBytes() {
  return new Uint8Array(await new Response(Deno.stdin.readable).arrayBuffer());
}

export async function readStdin() {
  return new TextDecoder().decode(await new Response(Deno.stdin.readable).arrayBuffer());
}

/** Append one JSON line to a call log. */
export function logCall(file: string, call: unknown) {
  Deno.writeTextFileSync(file, JSON.stringify(call) + "\n", { append: true });
}

export function readCalls<T>(file: string): T[] {
  try {
    return Deno.readTextFileSync(file).split("\n").filter(Boolean).map((line) => JSON.parse(line));
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return [];
    throw error;
  }
}
