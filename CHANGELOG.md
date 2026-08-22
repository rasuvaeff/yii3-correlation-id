# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **BREAKING.** `CorrelationIdMiddleware::UUID_V4_PATTERN` is now anchored with
  `\z` instead of `$`. PCRE `$` also matches before a single trailing `\n`, so
  the previous value accepted `"<uuid>\n"` on its own. The middleware was never
  affected (it rejects newlines explicitly), but the constant is public API and
  a consumer reusing it — for instance to validate a queue message's correlation
  id before `runWith()` — inherited the trap. Code comparing the constant's
  literal value, or relying on it accepting a trailing newline, must be updated.
- Reject every control character (`\x00`-`\x1F`, `\x7F`) in incoming and
  generated IDs, before `validationPattern` runs and independently of it. The
  previous check covered only `\n` and `\r`, leaving a permissive custom pattern
  (`/^.{1,64}\z/s` for "opaque tokens") free to pass ANSI/OSC escapes, NUL and
  TAB into the log context and outgoing headers.
- The middleware now takes ownership of the request scope: it calls
  `CorrelationIdHolder::override()` instead of `set()` and still clears in
  `finally`. A stray ID left in the holder (worker bootstrap that never cleared,
  a handler that called `exit()`, the middleware registered twice) used to make
  set-once `set()` throw `LogicException` on *every* subsequent request of a
  long-running worker — an unbounded run of 500s until the process restarted.
  It now costs at most the request that discovers it. `set()` keeps its set-once
  contract for application and queue code.

## 1.0.1 — 2026-07-25

- Reject incoming correlation IDs containing `\n` or `\r`. PCRE `$` matches
  before a single trailing `\n`, so a smuggled `"<uuid>\n"` could otherwise pass
  `validationPattern` and become the request's correlation ID (visible in the
  request attribute, holder, logs, and response header). The check runs before
  the pattern regardless of `validationPattern`, so custom patterns are protected
  too.

## 1.0.0 — 2026-07-16

- Initial implementation.
