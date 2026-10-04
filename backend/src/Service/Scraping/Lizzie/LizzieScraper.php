<?php

namespace App\Service\Scraping\Lizzie;

use App\Entity\Book;
use App\Service\Scraping\ScraperInterface;

class LizzieScraper implements ScraperInterface
{
    public static string $NAME = 'lizzie';

    public function __construct(protected LizzieCache $cache)
    {
    }

    public function search(string $pattern): array
    {
        $items = [];

        foreach ($this->cache->getLibrary() as $item) {
            if ($this->match($item, $pattern)) {
                $items[$item->getScrapId()] = $item;

                if (count($items) >= 20) {
                    break;
                }
            }
        }

        return array_map(
            fn($item) => $item->toBook(),
            array_values($items),
        );
    }

    public function get(string $code): Book|null
    {
        foreach ($this->cache->getLibrary() as $item) {
            if ($item->getScrapId() === $code) {
                return $item->toBook();
            }
        }

        return null;
    }

    protected function match(LizzieItem $item, $pattern): bool
    {
        return str_contains(strtolower($item->getTitle()), $pattern) || str_contains(strtolower($item->getAuthor()), $pattern);
    }
}
