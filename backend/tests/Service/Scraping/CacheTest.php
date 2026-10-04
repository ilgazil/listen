<?php

namespace App\Tests\Service\Scraping;

use App\Service\Scraping\Cache;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private object $holder;

    protected function setUp(): void
    {
        $this->holder = new class {
            use Cache;

            public function __construct()
            {
                $this->cache = [];
            }

            public function doHas(string $key): bool
            {
                return $this->has($key);
            }

            public function doSet(string $key, mixed $value): static
            {
                return $this->set($key, $value);
            }

            public function doGet(string $key): mixed
            {
                return $this->get($key);
            }
        };
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        self::assertFalse($this->holder->doHas('unknown'));
    }

    public function testHasReturnsTrueAfterSet(): void
    {
        $this->holder->doSet('key', 'value');

        self::assertTrue($this->holder->doHas('key'));
    }

    public function testGetReturnsSetValue(): void
    {
        $this->holder->doSet('name', 'Harry Potter');

        self::assertSame('Harry Potter', $this->holder->doGet('name'));
    }

    public function testGetReturnsEmptyStringForMissingKey(): void
    {
        self::assertSame('', $this->holder->doGet('missing'));
    }

    public function testSetReturnsSelfForFluentChaining(): void
    {
        $result = $this->holder->doSet('k', 'v');

        self::assertSame($this->holder, $result);
    }

    public function testOverwriteExistingKey(): void
    {
        $this->holder->doSet('key', 'first');
        $this->holder->doSet('key', 'second');

        self::assertSame('second', $this->holder->doGet('key'));
    }

    public function testSetNullValueIsNotConsideredSet(): void
    {
        $this->holder->doSet('nullable', null);

        self::assertFalse($this->holder->doHas('nullable'));
        self::assertSame('', $this->holder->doGet('nullable'));
    }
}
