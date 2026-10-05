#!/usr/bin/env python3
"""Truck Planner - generated copies of the seed file.

tp_seeds.json (this directory) is the only place a seed is edited. The backend and the frontend each carry
a generated copy so that neither reads a file from docs/ at run time:

    src/TruckPlanner/Model/SeedsData.php                          a file that returns the seeds as a PHP array
    frontend/src/utils/truck/estimator/seeds.generated.ts         export const SEEDS = ...

This script writes those two files and nothing else. Its output is a pure function of tp_seeds.json: the
same seed file always gives the same bytes (key order of the source, shortest round-trip floats, LF line
endings, a trailing newline). Integers stay integers and reals stay reals (400.0 is written as 400.0).

    python generate_seed_copies.py            write both copies
    python generate_seed_copies.py --check    verify both copies match tp_seeds.json; exit 1 otherwise

Plain Python 3.10+, standard library only. Run it after every change to tp_seeds.json and commit the two
copies together with the seed file, the document and the regenerated golden cases (see README.md).
"""

import json
import math
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPOSITORY = os.path.normpath(os.path.join(HERE, "..", "..", ".."))
SOURCE = os.path.join(HERE, "tp_seeds.json")
SOURCE_NAME = "docs/truck-planner/reference/tp_seeds.json"
GENERATOR_NAME = "docs/truck-planner/reference/generate_seed_copies.py"
PHP_NAME = "src/TruckPlanner/Model/SeedsData.php"
TS_NAME = "frontend/src/utils/truck/estimator/seeds.generated.ts"


def is_scalar(value):
    return value is None or isinstance(value, (bool, int, float, str))


def number_text(value):
    """Shortest text that reads back as the same number. A real always shows that it is one."""
    if isinstance(value, int):
        return str(value)
    if not math.isfinite(value):
        raise ValueError("a seed may not be NaN or infinite")
    return repr(value)                                  # "400.0", "0.3", "1e-09": never a bare integer


# ---------------------------------------------------------------------------------------------------
# PHP
# ---------------------------------------------------------------------------------------------------

def php_string(text):
    return "'" + text.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_scalar(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return number_text(value)
    return php_string(value)


def php_value(value, depth):
    pad = "    " * depth
    if is_scalar(value):
        return php_scalar(value)
    if isinstance(value, list):
        if all(is_scalar(item) for item in value):
            return "[" + ", ".join(php_scalar(item) for item in value) + "]"
        lines = ["["]
        for item in value:
            lines.append(pad + "    " + php_value(item, depth + 1) + ",")
        lines.append(pad + "]")
        return "\n".join(lines)
    lines = ["["]
    for key in value:                                   # the order of the source file
        if key.lstrip("-").isdigit():
            raise ValueError("a numeric key (%r) would become an integer key in PHP" % key)
        lines.append(pad + "    " + php_string(key) + " => " + php_value(value[key], depth + 1) + ",")
    lines.append(pad + "]")
    return "\n".join(lines)


def php_text(seeds):
    header = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "/**",
        " * GENERATED FILE - do not edit.",
        " *",
        " * The seed assumptions of Truck Planner (model %s, seeds revision %d) as a PHP array."
        % (seeds["model_version"], seeds["seeds_revision"]),
        " *",
        " * Source:    " + SOURCE_NAME,
        " * Generator: " + GENERATOR_NAME,
        " *",
        " * To change a seed: edit the source, run the generator, commit both. The generator's --check and the",
        " * seed-sync test fail when this file and the source differ. Integers are integers and reals are reals",
        " * here exactly as in the source.",
        " */",
        "",
        "return " + php_value(seeds, 0) + ";",
        "",
    ]
    return "\n".join(header)


# ---------------------------------------------------------------------------------------------------
# TypeScript
# ---------------------------------------------------------------------------------------------------

def ts_scalar(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return number_text(value)
    return json.dumps(value, ensure_ascii=True)         # a JSON string is a valid TypeScript string


def ts_value(value, depth):
    pad = "  " * depth
    if is_scalar(value):
        return ts_scalar(value)
    if isinstance(value, list):
        if all(is_scalar(item) for item in value):
            return "[" + ", ".join(ts_scalar(item) for item in value) + "]"
        lines = ["["]
        for item in value:
            lines.append(pad + "  " + ts_value(item, depth + 1) + ",")
        lines.append(pad + "]")
        return "\n".join(lines)
    lines = ["{"]
    for key in value:                                   # the order of the source file
        lines.append(pad + "  " + json.dumps(key, ensure_ascii=True) + ": " + ts_value(value[key], depth + 1) + ",")
    lines.append(pad + "}")
    return "\n".join(lines)


def ts_text(seeds):
    header = [
        "/* eslint-disable */",
        "// GENERATED FILE - do not edit.",
        "//",
        "// The seed assumptions of Truck Planner (model %s, seeds revision %d)."
        % (seeds["model_version"], seeds["seeds_revision"]),
        "//",
        "// Source:    " + SOURCE_NAME,
        "// Generator: " + GENERATOR_NAME,
        "//",
        "// To change a seed: edit the source, run the generator, commit both. The generator's --check and the",
        "// seed-sync test fail when this file and the source differ.",
        "",
        "export const SEEDS = " + ts_value(seeds, 0) + ";",
        "",
        "export type Seeds = typeof SEEDS;",
        "",
    ]
    return "\n".join(header)


# ---------------------------------------------------------------------------------------------------

def targets():
    with open(SOURCE, "r", encoding="utf-8") as handle:
        seeds = json.load(handle)
    return [(PHP_NAME, php_text(seeds)), (TS_NAME, ts_text(seeds))]


def write():
    for (name, text) in targets():
        path = os.path.join(REPOSITORY, *name.split("/"))
        directory = os.path.dirname(path)
        if not os.path.isdir(directory):
            os.makedirs(directory)
        with open(path, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(text)
        print("wrote %s (%d bytes)" % (name, len(text.encode("utf-8"))))
    return 0


def check():
    stale = 0
    for (name, text) in targets():
        path = os.path.join(REPOSITORY, *name.split("/"))
        if not os.path.isfile(path):
            print("MISSING %s" % name)
            stale += 1
            continue
        with open(path, "r", encoding="utf-8", newline="") as handle:
            on_disk = handle.read()
        # A checkout may have turned LF into CRLF; that is not a difference in content.
        if on_disk.replace("\r\n", "\n") != text:
            print("STALE   %s differs from what %s generates" % (name, SOURCE_NAME))
            stale += 1
        else:
            print("ok      %s" % name)
    if stale > 0:
        print("%d of 2 copies do not match %s; run: python %s" % (stale, SOURCE_NAME, GENERATOR_NAME))
        return 1
    print("both copies match %s" % SOURCE_NAME)
    return 0


def main(argv):
    if len(argv) == 1:
        return write()
    if len(argv) == 2 and argv[1] == "--check":
        return check()
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
