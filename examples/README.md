# Examples

Run any script from the package root; none of them needs a server or any
environment variable.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/01-middleware-setup.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `01-middleware-setup.php` | Middleware in a PSR-15 stack under `acceptIncoming: true`: an ID is generated, a valid incoming one is reused, a junk one is replaced; the holder is cleared afterwards | no |
| `02-log-context.php` | `yiisoft/log` composed with `CorrelationIdContextProvider`: `requestId` on every line, and logging outside a request | no |
| `03-access-in-action.php` | Reading the ID from the request attribute and provider; `get()` vs `tryGet()`; exception-safe console scope | no |
| `04-custom-generator.php` | A ULID-like generator with a matching validation pattern; a UUID stops being acceptable (`acceptIncoming: true` to show it) | no |
| `05-gateway-mode.php` | A public gateway replaces an untrusted ID (the default); a trusted internal service opts in with `acceptIncoming: true` and preserves the gateway ID | no |
| `06-outgoing-request.php` | `runWith()` queue scope and outgoing PSR-7 header propagation | no |
| `07-trusted-proxy-policy.php` | A request-aware policy accepts valid IDs only from a trusted gateway IP; the policy runs only on the `acceptIncoming: true` path | no |

Scripts print generated UUIDs, so their output differs between runs.
