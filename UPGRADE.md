# Upgrade

## 1.x → 2.0

Three breaking changes. Two of them can change behaviour silently — read the
first section even if your build is green.

### 1. Incoming correlation IDs are ignored unless you opt in

`CorrelationIdMiddleware::$acceptIncoming` used to default to `true`: any caller
sending a well-formed `X-Request-ID` decided what this service's logs were keyed
by. It now defaults to `false`, and so does `acceptIncoming` in
`config/params.php`.

**This is a silent behaviour change.** Nothing throws; IDs simply stop
propagating across service hops. The symptom is a gateway request and the
downstream request it triggered no longer sharing an ID in the logs.

You must act if any of the following is true:

- your service sits behind a gateway, load balancer, or another service that
  forwards a correlation ID, and you rely on that ID reaching your logs;
- you use `IncomingCorrelationIdPolicy` (including `TrustedProxyPolicy`-style
  custom policies). `acceptIncoming: false` skips the policy entirely and always
  mints a fresh ID, so an unchanged policy silently stops being consulted;
- you propagate an ID into outgoing requests with `CorrelationIdHeaderInjector`
  and expect the receiving service to adopt it.

In a Yii application, opt in through the application params:

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-correlation-id' => [
        'acceptIncoming' => true,
    ],
];
```

Constructing the middleware yourself:

```php
new CorrelationIdMiddleware(
    generator: $generator,
    holder: $holder,
    acceptIncoming: true,
);
```

Opt in **only** where direct client traffic cannot reach the service. On a
public ingress, keep the new default: it is what stops a client from choosing
its own correlation ID, replaying one ID across unrelated requests, or feeding
your log aggregation an ID that collides with a real one.

### 2. The holder rejects malformed IDs

`CorrelationIdHolder::set()`, `override()` and `runWith()` now throw
`InvalidArgumentException` when the ID is:

- empty;
- longer than 4096 bytes;
- carrying a control character (`\x00`-`\x1F`, `\x7F`) — a newline, a tab, a NUL
  byte, an ANSI escape.

The middleware's own write path is unaffected: an ID it publishes has already
passed a stricter contract. What changes is code that writes the holder
directly — typically a queue consumer or console command doing

```php
$holder->runWith($message->correlationId, static fn () => $job->run());
```

where `$message->correlationId` originated as an HTTP header on the service that
enqueued the job. If that value can be malformed, decide explicitly what should
happen instead of letting it through:

```php
$id = $message->correlationId;

if ($id === null || preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $id) !== 1) {
    $id = $generator->generate(); // or: skip the scope entirely
}

$holder->runWith($id, static fn () => $job->run());
```

Validation runs before the holder's state is touched, so a rejected call leaves
the current scope exactly as it was, and `runWith()` does not invoke the
callback. Nothing is left half-written for you to clean up.

The 4096-byte ceiling is a sanity limit against log bloat, not a format check.
It sits far above the middleware's `maxLength` default of 128, so a custom
format configured on the middleware is never rejected by the holder afterwards.

### 3. `UUID_V4_PATTERN` is anchored with `\z`

The constant's value changed from

```
/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
```

to the same expression ending in `\z/i`.

No action is needed in most cases. Middleware behaviour is identical under
either anchor, because the control-character guard rejects a trailing newline
before any pattern runs.

Act if you:

- **compare the constant against a stored literal** (a config file, a test
  fixture, a database column holding the pattern string). The comparison now
  fails — update the literal.
- **rely on `preg_match(UUID_V4_PATTERN, "<uuid>\n") === 1`.** It now returns
  `0`. This was the bug: PCRE `$` matches before a single trailing newline, so
  the old constant accepted a smuggled `"<uuid>\n"` when used on its own. If
  some code path depended on that acceptance, it was accepting a value it should
  not have.

`UUID_V4_PATTERN_STRICT`, which appeared briefly on an unreleased branch, does
not exist in 2.0.0 — `UUID_V4_PATTERN` is the strict spelling now. No released
version ever carried it.

### Not a breaking change, but worth knowing

The middleware takes ownership of the request scope: it writes the holder with
`override()` rather than set-once `set()`, and still clears in `finally`. A
stray ID left behind by a worker bootstrap or a handler that called `exit()`
used to make every subsequent request of that worker fail with a
`LogicException` until the process restarted. It now costs at most the one
request that discovers it. `set()` keeps its set-once contract for application
and queue code.

A second instance of the middleware further down the stack now adopts the ID the
outer one published instead of resolving its own, so one request can no longer
appear under two different IDs.
