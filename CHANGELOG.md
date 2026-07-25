# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-07-25

- Reject incoming correlation IDs containing `\n` or `\r`. PCRE `$` matches
  before a single trailing `\n`, so a smuggled `"<uuid>\n"` could otherwise pass
  `validationPattern` and become the request's correlation ID (visible in the
  request attribute, holder, logs, and response header). The check runs before
  the pattern regardless of `validationPattern`, so custom patterns are protected
  too.

## 1.0.0 — 2026-07-16

- Initial implementation.
