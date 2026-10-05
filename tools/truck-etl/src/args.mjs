// Command-line arguments: --name=value, --name value and boolean flags.

export class UsageError extends Error {}

/**
 * @param {string[]} argv arguments after the script name
 * @param {{values: string[], flags: string[]}} spec names of the options that take a value and of the flags
 * @returns {Record<string, string|boolean>}
 */
export function parseArgs(argv, { values, flags }) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (!arg.startsWith('--')) throw new UsageError(`unexpected argument ${JSON.stringify(arg)}`);
    const eq = arg.indexOf('=');
    const name = eq < 0 ? arg.slice(2) : arg.slice(2, eq);
    if (flags.includes(name)) {
      if (eq >= 0) throw new UsageError(`--${name} takes no value`);
      out[name] = true;
    } else if (values.includes(name)) {
      let value;
      if (eq >= 0) {
        value = arg.slice(eq + 1);
      } else {
        value = argv[++i];
        if (value === undefined || value.startsWith('--')) throw new UsageError(`--${name} needs a value`);
      }
      if (value === '') throw new UsageError(`--${name} needs a value`);
      if (name in out) throw new UsageError(`--${name} is given twice`);
      out[name] = value;
    } else {
      throw new UsageError(`unknown option --${name}`);
    }
  }
  return out;
}
