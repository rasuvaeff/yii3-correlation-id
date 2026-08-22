<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

/**
 * Gives every request a correlation ID: reuses an acceptable incoming one,
 * generates a fresh one otherwise, publishes it via the request attribute and
 * the holder, and echoes it back in the response header.
 *
 * Place it first in the stack — everything downstream that logs should already
 * see the ID.
 *
 * A second instance further down the stack (the middleware registered twice,
 * a module that adds its own copy) adopts the ID the outer one already
 * published in the request attribute instead of minting a rival, so the logs,
 * the handler and the response header never disagree.
 *
 * @api
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    /**
     * The single spelling of the value {@see self::UUID_V4_PATTERN} publishes
     * and {@see self::__construct()} defaults `$validationPattern` to.
     *
     * It exists only so that neither of those two references a deprecated
     * constant: the package's own default is not a caller that should be
     * warned. Both evaluate to exactly the string 1.0.1 published, which is
     * what backward-compatibility tooling compares.
     *
     * @internal
     */
    private const string DEFAULT_VALIDATION_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Anchored with `$`, which PCRE also matches before a single trailing
     * `\n`: on its own this pattern accepts `"<uuid>\n"`.
     *
     * The value is frozen at exactly what 1.0.1 published. It is public API —
     * consumers compare against it and pass it back in as
     * `$validationPattern` — so tightening it in place would break them for a
     * gain the middleware never needed: `isAcceptable()` rejects every control
     * character before any pattern runs, so `"<uuid>\n"` is turned down under
     * this constant just as it is under the strict one.
     *
     * @deprecated Use {@see self::UUID_V4_PATTERN_STRICT} when validating IDs
     * in your own code, where the control-character guard is not there to
     * compensate for the loose anchor.
     */
    public const string UUID_V4_PATTERN = self::DEFAULT_VALIDATION_PATTERN;

    /**
     * The same format anchored with `\z`, which matches only at the very end
     * of the subject. Reuse this one outside the middleware — validating a
     * queue message's correlation id before `runWith()`, say — and a trailing
     * newline is rejected without a separate check.
     *
     * Passing it as `$validationPattern` changes nothing the middleware does:
     * the control-character guard already covers the difference.
     */
    public const string UUID_V4_PATTERN_STRICT = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    private const string CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    /**
     * @param string $headerName Read from the request and written to the response.
     * @param string $attributeName Request attribute carrying the ID downstream.
     * @param bool $acceptIncoming Whether a caller-sent ID may be reused. Set it
     * to false at a public trust boundary that must mint its own ID. Services
     * behind a trusted gateway normally keep it true to preserve propagation.
     * @param non-empty-string $validationPattern Incoming and generated IDs must
     * match this pattern.
     * @param int $maxLength Incoming and generated IDs may not exceed this length.
     * @param IncomingCorrelationIdPolicy $incomingPolicy Trust decision applied
     * after an incoming ID passes format and length validation.
     *
     * @throws InvalidArgumentException When the pattern is not a valid regex or maxLength is below 1.
     */
    public function __construct(
        private CorrelationIdGenerator $generator,
        private CorrelationIdHolder $holder,
        private string $headerName = 'X-Request-ID',
        private string $attributeName = 'correlationId',
        private bool $acceptIncoming = true,
        private string $validationPattern = self::DEFAULT_VALIDATION_PATTERN,
        private int $maxLength = 128,
        private IncomingCorrelationIdPolicy $incomingPolicy = new AcceptAllIncomingCorrelationIdPolicy(),
    ) {
        if ($maxLength < 1) {
            throw new InvalidArgumentException("Max length must be at least 1, got {$maxLength}");
        }

        if (@preg_match($validationPattern, '') === false) {
            throw new InvalidArgumentException("Invalid validation pattern \"{$validationPattern}\"");
        }
    }

    /**
     * @throws UnexpectedValueException When the generator returns an ID that
     * does not satisfy validationPattern or maxLength.
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Non-null exactly when an outer instance of this middleware already
        // owns the scope; the ID is then adopted rather than re-decided.
        $published = $this->publishedId($request);
        $id = $published ?? $this->resolveId($request);
        // `override()`, not `set()`: the middleware owns the request scope
        // rather than asserting it is the first writer. A stray ID left in the
        // holder (worker bootstrap that forgot to clear, a handler that called
        // `exit()`, the middleware registered twice) is dropped on the next
        // request instead of making `set()` throw on every request this worker
        // ever handles again. `set()` keeps its set-once contract for
        // application and queue code.
        $this->holder->override($id);

        try {
            $response = $handler->handle($request->withAttribute($this->attributeName, $id));
        } finally {
            if ($published === null) {
                $this->holder->clear();
            } else {
                // Nested instance: the scope belongs to the outer one, which
                // clears it itself. Clearing here would blank the holder for
                // every reader sitting between the two layers; restoring the
                // adopted ID also undoes a handler that wrote the holder
                // out of band. Only the outermost instance self-heals.
                $this->holder->override($published);
            }
        }

        return $response->withHeader($this->headerName, $id);
    }

    /**
     * The request attribute is populated server-side only — never from the
     * wire — but it still passes the full validation contract before being
     * adopted, so unrelated code writing that attribute cannot decide the
     * correlation ID.
     */
    private function publishedId(ServerRequestInterface $request): ?string
    {
        return $this->adoptable($request->getAttribute($this->attributeName));
    }

    private function adoptable(mixed $published): ?string
    {
        return is_string($published) && $this->isAcceptable($published) ? $published : null;
    }

    private function resolveId(ServerRequestInterface $request): string
    {
        if (!$this->acceptIncoming) {
            return $this->generateId();
        }

        $incoming = $request->getHeaderLine($this->headerName);

        return $this->isAcceptable($incoming) && $this->incomingPolicy->accepts($request, $incoming)
            ? $incoming
            : $this->generateId();
    }

    private function generateId(): string
    {
        $id = $this->generator->generate();

        if (!$this->isAcceptable($id)) {
            throw new UnexpectedValueException('Generated correlation ID does not satisfy validationPattern and maxLength');
        }

        return $id;
    }

    private function isAcceptable(string $id): bool
    {
        // Control characters are rejected before the user pattern runs, so the
        // guarantee holds whatever `validationPattern` is — including the
        // `$`-anchored default `UUID_V4_PATTERN`, which on its own would let a
        // trailing `\n` through. It stops CR/LF smuggled inside one legal
        // header line (PSR-7 does not guarantee a value is free of them, and
        // nyholm's own header check is `$`-anchored too),
        // ANSI/OSC escapes that would be replayed by a terminal reading the
        // logs, and NUL/TAB that corrupt log lines and downstream parsers.
        // It also rejects the `", "` join of several `X-Request-ID` headers
        // whenever one of them carries a control byte.
        return $id !== ''
            && preg_match(self::CONTROL_CHARACTER_PATTERN, $id) === 0
            && strlen($id) <= $this->maxLength
            && preg_match($this->validationPattern, $id) === 1;
    }
}
