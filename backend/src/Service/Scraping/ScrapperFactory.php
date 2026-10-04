<?php

namespace App\Service\Scraping;

use App\Entity\Book;
use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;

class ScrapperFactory
{
    public function __construct(protected AudibleScraper $audibleScraper, protected LizzieScraper $lizzieScraper)
    {
    }

    public function fromBook(Book $book): ScraperInterface
    {
        switch ($book->getScraper()) {
            case AudibleScraper::$NAME:
                return $this->audibleScraper;
            case LizzieScraper::$NAME:
                return $this->lizzieScraper;
        }

        throw new \InvalidArgumentException('No scrapper matches name: ' . $book->getScraper());
    }
}
