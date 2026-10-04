<?php

namespace App\Service\Scraping\Audible;

use anlutro\cURL\cURL;
use App\Entity\Book;
use App\Service\Scraping\ScraperInterface;

class AudibleScraper implements ScraperInterface
{
    public static string $NAME = 'audible';

    protected string $endpoint = 'https://www.audible.fr/';

    protected array $baseUrlQuery = [
        'ipRedirectOverride' => 'true',
        'overrideBaseCountry' => 'true',
    ];

    public function search(string $pattern): array
    {
        $pattern = strtolower($pattern);
        $pattern = preg_replace('/\W/', ' ', $pattern);
        $pattern = preg_replace('/\s+/', ' ', $pattern);

        $url = (new cURL())->buildUrl(
            $this->endpoint . 'search',
            ['keywords' => urlencode(trim($pattern))] + $this->baseUrlQuery,
        );

        return (new AudibleSearchParser((new cURL())->rawGet($url)->body))->getBooks();
    }

    public function get(string $code): Book|null
    {
        $body = (new cURL())->rawGet($this->endpoint . 'pd/Book/' . $code)->body;

        if (empty($body)) {
            return null;
        }

        return (new AudibleBookParser($body))->createBook();
    }
}
