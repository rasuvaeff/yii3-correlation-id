# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.0 — 2026-08-22

Three breaking changes, all of them tightenings of a security default. See
[UPGRADE.md](UPGRADE.md) for the manual steps.

- **BREAKING.** `CorrelationIdMiddleware::$acceptIncoming` now defaults to
  `false`. A middleware that has not been told where it sits assumes a public
  trust boundary and mints its own ID, so a caller cannot choose the ID this
  service's logs are keyed by, nor make two unrelated requests share one. The
  package default in `config/params.php` follows. Services behind a trusted
  gateway that rely on the ID propagating across hops must now opt in with
  `acceptIncoming: true` — see UPGRADE.md.
- **BREAKING.** `CorrelationIdHolder::set()`, `override()` and `runWith()` now
  validate their argument and throw `InvalidArgumentException` for an empty ID,
  an ID longer than 4096 bytes, or an ID containing a control character
  (`\x00`-`\x1F`, `\x7F`). These write paths were unvalidated while the README
  pointed queue and console code at `runWith($message->correlationId, ...)`,
  where the ID usually originated as an untrusted HTTP header on another
  service — and from the holder it reached every log line
  (`CorrelationIdContextProvider`) and every outgoing request header
  (`CorrelationIdHeaderInjector`) verbatim, the latter being header injection
  whenever the PSR-7 implementation does not itself reject a CR or LF, which
  the specification does not require it to. Validation runs before the holder's
  state is touched, so a rejected call leaves the current scope exactly as it
  was and `runWith()` never invokes the callback. The 4096-byte ceiling is a
  sanity limit against log bloat, deliberately far above the middleware's own
  `maxLength` default of 128 so a legitimate custom format is never caught by
  it.
- **BREAKING.** `CorrelationIdMiddleware::UUID_V4_PATTERN` is now anchored with
  `\z` instead of `$`. PCRE `$` also matches before a single trailing `\n`, so
  the 1.0.1 value accepted `"<uuid>\n"` when reused on its own — validating a
  queue message's correlation id before `runWith()`, checking an ID read back
  from a database. Middleware behaviour is unchanged either way, because
  `isAcceptable()` rejects every control character before any pattern runs; the
  anchor only ever mattered to code using the constant outside the middleware.
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
- A second instance of the middleware further down the stack now adopts the ID
  the outer one published in the request attribute instead of resolving its own.
  Two consequences. With no acceptable incoming header, the inner instance used
  to generate a second ID: the handler and the log context carried it while the
  outer instance wrote its own to the response header, so one request appeared
  under two IDs. And an inner instance with the default `acceptIncoming: true`
  used to read the caller's header straight back after an outer instance with
  `acceptIncoming: false` had deliberately ignored it — the client-supplied ID
  reached the handler and the logs of a service that had minted its own
  precisely to avoid that. The attribute is adopted only after passing the same
  control-character, `maxLength` and `validationPattern` checks as an incoming
  header. A nested instance also restores the adopted ID in `finally` rather
  than clearing the holder, which the outer instance still owns; the outermost
  instance clears as before, so the self-healing above is unaffected.

## 1.0.1 — 2026-07-25

- Reject incoming correlation IDs containing `\n` or `\r`. PCRE `$` matches
  before a single trailing `\n`, so a smuggled `"<uuid>\n"` could otherwise pass
  `validationPattern` and become the request's correlation ID (visible in the
  request attribute, holder, logs, and response header). The check runs before
  the pattern regardless of `validationPattern`, so custom patterns are protected
  too.

## 1.0.0 — 2026-07-16

- Initial implementation.
