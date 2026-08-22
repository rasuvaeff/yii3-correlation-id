<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Uuidv4Generator::class)]
final class Uuidv4GeneratorTest
{
    // Layout of 8-4-4-4-12: dashes at 8/13/18/23, version at 14, variant at 19.
    private const int VERSION_POSITION = 14;
    private const int VARIANT_POSITION = 19;
    private const array RANDOM_POSITIONS = [
        0, 1, 2, 3, 4, 5, 6, 7,
        9, 10, 11, 12,
        15, 16, 17,
        20, 21, 22,
        24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35,
    ];

    private Uuidv4Generator $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new Uuidv4Generator();
    }

    public function generatesIdMatchingTheDefaultValidationPattern(): void
    {
        $id = $this->fixture->generate();

        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $id), 1);
        // The strict spelling too: the generator's output must satisfy the
        // constant a consumer is told to reuse, not only the looser default.
        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $id), 1);
    }

    public function generatesCanonicalHyphenatedLayout(): void
    {
        $parts = explode('-', $this->fixture->generate());

        Assert::same(array_map(strlen(...), $parts), [8, 4, 4, 4, 12]);
    }

    public function marksTheIdAsVersion4(): void
    {
        Assert::same(explode('-', $this->fixture->generate())[2][0], '4');
    }

    public function marksTheIdWithTheRfc4122Variant(): void
    {
        Assert::true(in_array(explode('-', $this->fixture->generate())[3][0], ['8', '9', 'a', 'b'], strict: true));
    }

    public function generatesLowercaseHexOnly(): void
    {
        Assert::same(preg_match('/^[0-9a-f-]+$/', $this->fixture->generate()), 1);
    }

    public function generatesUniqueIds(): void
    {
        $ids = [];

        for ($i = 0; $i < 10_000; ++$i) {
            $ids[$this->fixture->generate()] = true;
        }

        Assert::same(count($ids), 10_000);
    }

    /**
     * Format tests alone cannot see a generator that quietly loses entropy: a
     * stuck bit still yields a well-formed UUID. Over 1000 draws every random
     * position must take all 16 hex values (the odds of missing one by chance
     * are ~16 * (15/16)^1000).
     */
    public function everyRandomPositionSpansTheFullHexRange(): void
    {
        $seen = $this->observedCharacters(1000);
        $narrow = [];

        foreach (self::RANDOM_POSITIONS as $position) {
            if (count($seen[$position]) !== 16) {
                $narrow[$position] = count($seen[$position]);
            }
        }

        Assert::same($narrow, []);
    }

    public function versionAndVariantPositionsStayWithinTheirAllowedValues(): void
    {
        $seen = $this->observedCharacters(1000);

        // Numeric-looking array keys come back as ints — compare them as text.
        Assert::same($this->charactersAt($seen, self::VERSION_POSITION), ['4']);
        Assert::same($this->charactersAt($seen, self::VARIANT_POSITION), ['8', '9', 'a', 'b']);
    }

    /**
     * Two positions that always agree mean one random byte is doing the work of
     * two — the ID would carry less entropy than its length suggests.
     */
    public function distinctRandomPositionsCarryIndependentEntropy(): void
    {
        $ids = [];

        for ($i = 0; $i < 200; ++$i) {
            $ids[] = $this->fixture->generate();
        }

        $positions = [...self::RANDOM_POSITIONS, self::VARIANT_POSITION];
        $alwaysEqual = [];

        foreach ($positions as $left) {
            foreach ($positions as $right) {
                if ($left >= $right) {
                    continue;
                }

                foreach ($ids as $id) {
                    if ($id[$left] !== $id[$right]) {
                        continue 2;
                    }
                }

                $alwaysEqual[] = "{$left}={$right}";
            }
        }

        Assert::same($alwaysEqual, []);
    }

    /**
     * @param array<int, array<array-key, true>> $seen
     *
     * @return list<string>
     */
    private function charactersAt(array $seen, int $position): array
    {
        return array_map(strval(...), array_keys($seen[$position]));
    }

    /**
     * @return array<int, array<array-key, true>> Characters seen per UUID position.
     */
    private function observedCharacters(int $draws): array
    {
        $seen = [];

        for ($i = 0; $i < $draws; ++$i) {
            foreach (str_split($this->fixture->generate()) as $position => $char) {
                $seen[$position][$char] = true;
            }
        }

        foreach ($seen as $position => $chars) {
            ksort($chars);
            $seen[$position] = $chars;
        }

        return $seen;
    }
}
