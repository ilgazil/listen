<?php

namespace App\Service\Scraping;

use App\Entity\Book;

interface ScraperInterface
{
    public function search(string $pattern): array;

    public function get(string $code): Book|null;
}
