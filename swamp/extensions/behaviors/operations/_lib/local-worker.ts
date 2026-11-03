/** One seam for local subprocesses and filesystem effects, so native tests can substitute host tools. */
export function localCommand(
  command: string | URL,
  options?: Deno.CommandOptions,
): Deno.Command {
  return new Deno.Command(command, options);
}

export function localFilesystem(): typeof Deno {
  return Deno;
}
