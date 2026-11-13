/**
 * Swamp ends a `${{ … }}` expression at the first `}}` after `${{`, even inside a CEL map or string literal
 * (Operations 19600 and 19602: a map closed with `}}` reached the finish step as a string).
 * These helpers cut expressions the same way, so tests see what Swamp sees.
 */

/** The expressions of one value, each cut at its first `}}`, as Swamp reads them. */
export function swampExpressions(value: string): string[] {
  return [...value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)].map((match) => match[1].trim());
}

/** The single expression a whole `${{ … }}` value holds, cut as Swamp cuts it; throws when Swamp would end it early. */
export function swampExpression(value: string): string {
  const trimmed = value.trim();
  const match = trimmed.match(/^\$\{\{([\s\S]*?)\}\}/);
  if (match === null || match[0].length !== trimmed.length) {
    throw new Error(`Swamp ends this expression before its end: ${trimmed.slice(0, 80)}…`);
  }
  return match[1].trim();
}

/**
 * Why a cut expression is not whole CEL, or null when its brackets and string literals close.
 * A `}}` inside a map or a string literal cuts the expression with an open `{`, `[`, `(` or quote.
 */
export function unclosed(expression: string): string | null {
  const open: string[] = [];
  const pairs: Record<string, string> = { ")": "(", "]": "[", "}": "{" };
  for (let i = 0; i < expression.length; i++) {
    const char = expression[i];
    if (char === '"' || char === "'") {
      const triple = expression.startsWith(char.repeat(3), i);
      const quote = triple ? char.repeat(3) : char;
      let end = i + quote.length;
      while (end < expression.length && !expression.startsWith(quote, end)) end += expression[end] === "\\" ? 2 : 1;
      if (end >= expression.length) return `unclosed ${quote}`;
      i = end + quote.length - 1;
    } else if ("([{".includes(char)) {
      open.push(char);
    } else if (char in pairs) {
      if (open.pop() !== pairs[char]) return `unmatched ${char}`;
    }
  }
  return open.length > 0 ? `unclosed ${open.at(-1)}` : null;
}
