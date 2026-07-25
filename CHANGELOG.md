# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-07-25

- Reject trailing newlines in the default `UUID_V4_PATTERN`: PCRE `$` matches
  before a trailing `\n`, which let `"<uuid>\n"` pass the pattern. Switched the
  anchor to `\z`. Custom patterns passed via `validationPattern` are unchanged.

## 1.0.0 — 2026-07-16

- Initial implementation.
