<?php

namespace App\Tests\Service\Api;

use App\Service\Api\UnFichierApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnFichierApiTest extends TestCase
{
    public function testGetOrphansFiltersOutKnownIds(): void
    {
        $api = new class(new NullLogger()) extends UnFichierApi {
            protected function listFiles(): array
            {
                return [
                    (object) ['url' => 'https://1fichier.com/?aaaa1111', 'filename' => 'livre-a.zip', 'size' => 1024, 'date' => 1000],
                    (object) ['url' => 'https://1fichier.com/?bbbb2222', 'filename' => 'livre-b.zip', 'size' => 2048, 'date' => 2000],
                    (object) ['url' => 'https://1fichier.com/?cccc3333', 'filename' => 'livre-c.zip', 'size' => 4096, 'date' => 3000],
                    (object) ['url' => 'https://1fichier.com/??', 'filename' => 'sans-hash.zip', 'size' => 512, 'date' => 4000],
                ];
            }
        };

        $orphans = $api->getOrphans(['aaaa1111']);

        self::assertCount(2, $orphans);
        self::assertSame('bbbb2222', $orphans[0]['id']);
        self::assertSame('livre-b.zip', $orphans[0]['name']);
        self::assertSame(2048, $orphans[0]['size']);
        self::assertSame('cccc3333', $orphans[1]['id']);
    }

    public function testGetOrphansReturnsEmptyListWhenAllFilesAreKnown(): void
    {
        $api = new class(new NullLogger()) extends UnFichierApi {
            protected function listFiles(): array
            {
                return [
                    (object) ['url' => 'https://1fichier.com/?known1', 'filename' => 'livre-a.zip', 'size' => 1, 'date' => 1],
                    (object) ['url' => 'https://1fichier.com/?known2', 'filename' => 'livre-b.zip', 'size' => 1, 'date' => 1],
                ];
            }
        };

        self::assertSame([], $api->getOrphans(['known1', 'known2']));
    }

    public function testGetStoredIdsReturnsAllAccessIds(): void
    {
        $api = new class(new NullLogger()) extends UnFichierApi {
            protected function listFiles(): array
            {
                return [
                    (object) ['url' => 'https://1fichier.com/?aaaa1111', 'filename' => 'livre-a.zip', 'size' => 1024, 'date' => 1000],
                    (object) ['url' => 'https://1fichier.com/?bbbb2222', 'filename' => 'livre-b.zip', 'size' => 2048, 'date' => 2000],
                    (object) ['url' => 'https://1fichier.com/??', 'filename' => 'sans-hash.zip', 'size' => 512, 'date' => 4000],
                ];
            }
        };

        $ids = $api->getStoredIds();

        self::assertCount(2, $ids);
        self::assertSame(['aaaa1111', 'bbbb2222'], $ids);
    }

    public function testGetStoredIdsReturnsEmptyListWhenNoFiles(): void
    {
        $api = new class(new NullLogger()) extends UnFichierApi {
            protected function listFiles(): array
            {
                return [];
            }
        };

        self::assertSame([], $api->getStoredIds());
    }
}