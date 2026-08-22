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
3. **Every ID satisfies one validation contract.** Incoming, generated, and
   attribute-adopted IDs reach the holder, response header, and logs only after
   passing the control-character guard (`[\x00-\x1F\x7F]`, applied before and
   independently of the user pattern), `maxLength`, and `validationPattern`.
   Invalid incoming and attribute values are replaced; an invalid generator
   result fails before the handler runs. `UUID_V4_PATTERN` is anchored with
   `\z`, never `$`.
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
- **Trust boundaries preserve propagation deliberately.** Public ingress may
  use `acceptIncoming: false` to mint an ID. Internal services behind it keep
  the default `true` and rely on deployment controls to reject direct traffic.
- **Concurrency limit is real.** A shared holder is safe for sequential request
  handling (FPM, one-request-at-a-time workers) and unsafe under Swoole
  coroutines. Say so honestly in docs; do not claim blanket worker-safety.
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

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
