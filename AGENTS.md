# AGENTS.md — yii3-correlation-id

Guidance for AI agents working on this package. Read before changing code.

## What this is

A request correlation ID for Yii3 applications, in namespace
`Rasuvaeff\Yii3CorrelationId`. `CorrelationIdMiddleware` gives every request an
ID — reusing an acceptable incoming `X-Request-ID`, generating a UUIDv4
otherwise — publishes it as a request attribute and in `CorrelationIdHolder`,
and echoes it back on the response. `CorrelationIdContextProvider` feeds the ID
into the context of every `Yiisoft\Log\Logger` message.

Public API: `CorrelationIdMiddleware`, `CorrelationIdHolder`,
`CorrelationIdProvider`, `CorrelationIdGenerator` + `Uuidv4Generator`,
`CorrelationIdContextProvider`, `CorrelationIdHeaderInjector`,
`IncomingCorrelationIdPolicy` + `AcceptAllIncomingCorrelationIdPolicy`, and
`Exception\CorrelationIdNotSetException`.

This is single-service log correlation, deliberately not distributed tracing.
Spans, sampling and `traceparent` belong to `rasuvaeff/yii3-telemetry`; do not
grow them here.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **No ID reaches a reader unvalidated, by any route.** Two independent
   guards, not one. In the middleware: incoming, generated, and
   attribute-adopted IDs pass the control-character guard
   (`[\x00-\x1F\x7F]`, applied before and independently of the user pattern),
   `maxLength`, and `validationPattern` before they reach the holder, the
   response header, or the logs. In `CorrelationIdHolder`: `set()`,
   `override()` and `runWith()` apply their own non-empty / length / no-control
   check, because a queue or console caller reaches them without the middleware
   ever running. Never route a write past either guard, and never make the
   holder's guard depend on the middleware's configuration — they are separate
   `@api` boundaries and each cleans its own input.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **One shared holder.** The middleware writes `CorrelationIdHolder`; the
  context provider and application code read it. `config/di.php` deliberately
  does NOT bind it — `yiisoft/di` autowires it as a shared instance. Binding it
  (or aliasing it to itself) risks handing readers a second, empty holder, and
  the package would silently do nothing.
- **Application code gets read-only access.** Inject `CorrelationIdProvider`
  unless code owns a queue/CLI correlation scope. `runWith()` must always
  restore the previous scope in `finally`, including for nested callbacks.
- **Outgoing propagation replaces, never appends.** A stale request ID must not
  survive beside the current one. Outside a scope, injection is a no-op.
- **Policies run after validation.** Never pass malformed or oversized IDs to
  `IncomingCorrelationIdPolicy`. `acceptIncoming: false` is a hard bypass of the
  incoming *header* — it is not a bypass of the request attribute, which an
  outer instance of this same middleware may have already filled with an ID it
  minted itself.
- **A nested instance adopts, it does not decide.** `process()` first looks at
  the request attribute; a value there that passes the full validation contract
  is the ID, ahead of `acceptIncoming`, the header, and the generator. Ordering
  is load-bearing twice over: it stops one request from carrying two IDs (logs
  vs response header), and it stops an inner `acceptIncoming: true` instance
  from reading back the caller header an outer `acceptIncoming: false` instance
  deliberately ignored — the outer one does not strip that header.
- **`ContextProviderInterface` is not ours to bind.** It belongs to
  `yiisoft/log`, takes exactly one implementation, and the application composes
  its providers (`CompositeContextProvider`). Binding a foreign vendor's key
  from here is what triggers `yiisoft/config` duplicate-key errors.
- **The outermost instance owns the request scope; the holder is set-once for
  everyone else.** `process()` calls `override()` and clears in `finally`, so a
  stray ID (worker bootstrap, `exit()` in a handler) costs one request instead
  of poisoning the worker forever. Do not turn that back into `set()` —
  `set()`'s `LogicException` was a permanent 500 loop. A *nested* instance
  restores the adopted ID in `finally` instead of clearing: clearing would blank
  the holder for readers between the two layers while the outer instance is
  still unwinding. Do not collapse the two branches into a generic
  "restore whatever was there before" — that would restore a stray ID and undo
  the self-healing. The discriminator is "did this instance adopt the
  attribute", nothing else. Queue/CLI consumers should use `runWith()` so
  cleanup cannot be forgotten.
- **Generator, validation pattern, and maximum length are one contract.** A
  custom format needs all three to agree. Invalid generated output must fail
  before the handler runs, so business side effects cannot precede a response
  header failure.
- **Propagation is opt-in, and that is a security default.** `acceptIncoming`
  defaults to `false` as of 2.0.0: a middleware that has not been told where it
  sits mints its own ID, so a caller cannot pick the key its logs are filed
  under nor collapse two unrelated requests onto one ID. An internal service
  that direct client traffic cannot reach sets `true` explicitly to preserve
  the gateway ID. Do not flip the default back "for convenience" — and when
  writing an example or a test that is about the incoming header, pass
  `acceptIncoming: true` explicitly, or it silently stops exercising the path
  it claims to demonstrate. `IncomingCorrelationIdPolicy` is only ever
  consulted on the opt-in path.
- **Concurrency limit is real.** A shared holder is safe for sequential request
  handling (FPM, one-request-at-a-time workers) and unsafe under Swoole
  coroutines. Say so honestly in docs; do not claim blanket worker-safety.
- **One UUID constant, `\z`-anchored.** `UUID_V4_PATTERN` ends in `\z` as of
  2.0.0 (1.0.1 shipped `$`, which PCRE also matches before a single trailing
  `\n`, so the old value accepted `"<uuid>\n"` when reused on its own). It is
  the default `$validationPattern` and the value in `config/params.php`.
  Changing the constant's value is a BC break twice over — roave reports both
  the constant and the constructor's default parameter value — so it does not
  move again outside a major. Note what the anchor does *not* affect: inside
  `process()` the control-character guard rejects a trailing newline before any
  pattern runs, so a loose user-supplied `validationPattern` is equally safe
  there. `rejectsATrailingNewlineUnderEitherAnchor` pins exactly that, and
  deliberately keeps a `$`-anchored pattern spelled out as a test literal so
  that property keeps being tested after the constant moved on.
- **Control characters are rejected unconditionally.** A conforming PSR-7
  implementation rejects most of them in a header value, but not all: nyholm's
  own value check is `$`-anchored and lets a single trailing `\n` through. The
  guard runs before `validationPattern` precisely so a permissive custom pattern
  (`/^.{1,64}\z/s`, the "opaque token" shape the docs invite) cannot leak an
  ANSI escape or a NUL byte into the logs and outgoing headers.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing, plus
  `UPGRADE.md` when a major needs manual steps.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
