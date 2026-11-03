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
