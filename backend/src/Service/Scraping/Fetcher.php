<?php

namespace App\Service\Scraping;

use anlutro\cURL\cURL;
use anlutro\cURL\Request;

/**
 * GET HTTP pour le scraping. La lib cURL (anlutro) ne définit ni timeout ni
 * User-Agent : sans timeout, une page lente bloque /api/scrap indéfiniment ;
 * sans UA navigateur, les protections anti-bot peuvent servir une page dégradée.
 */
final class Fetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public static function get(string $url, int $encoding = Request::ENCODING_RAW): string
    {
        return (new cURL())
            ->newRequest('get', $url, null, $encoding)
            ->setOptions([
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => self::USER_AGENT,
            ])
            ->send()
            ->body;
    }
}
