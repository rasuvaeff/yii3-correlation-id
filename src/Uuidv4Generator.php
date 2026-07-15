<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

/**
 * Generates RFC 4122 version 4 UUIDs from `random_bytes()`. Pure PHP: no
 * ext-uuid, no ramsey/uuid.
 *
 * @api
 */
final readonly class Uuidv4Generator implements CorrelationIdGenerator
{
    #[\Override]
    public function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }
}
