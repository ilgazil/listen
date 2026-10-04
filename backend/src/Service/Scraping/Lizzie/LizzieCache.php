<?php

namespace App\Service\Scraping\Lizzie;

use anlutro\cURL\cURL;
use DateTime;

class LizzieCache
{
    protected const DATE_FORMAT = 'Y-m-d';
    protected string $endpoint = 'https://lizzie-api.staytuned.io/v1/';

    public function __construct(protected string $cacheDirectory)
    {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }
    }

    public function getLibrary(): array
    {
        return array_map(
            fn($raw) => new LizzieItem($raw),
            $this->getCache(),
        );
    }

    protected function getCache(): array
    {
        if (!$this->isValid()) {
            $this->update();
        }

        return json_decode(file_get_contents($this->cacheDirectory . DIRECTORY_SEPARATOR . $this->getCurrentFilename()));
    }

    protected function update(): void
    {
        $currentFilename = $this->getCurrentFilename();

        file_put_contents(
            $this->cacheDirectory . DIRECTORY_SEPARATOR . $this->getNewFilename(),
            json_encode(
                array_map(
                    fn($raw) => $raw->linkedContent,
                    json_decode(
                        (new cURL())->jsonGet($this->endpoint . 'catalog')->body
                    )->payload,
                ),
            ),
        );

        if (!empty($currentFilename)) {
            unlink($this->cacheDirectory . DIRECTORY_SEPARATOR . $currentFilename);
        }
    }

    protected function isValid(): bool
    {
        $filename = $this->getCurrentFilename();

        if (empty($filename)) {
            return false;
        }

        preg_match_all('/cache_(?P<date>.+).json/m', $filename, $matches);
        $date = DateTime::createFromFormat(self::DATE_FORMAT, $matches['date'][0]);

        return (new DateTime())->diff($date)->days < 1;
    }

    protected function getCurrentFilename(): string
    {
        return current(array_filter(scandir($this->cacheDirectory), fn($filename) => !str_starts_with($filename, '.')));
    }

    protected function getNewFilename(): string
    {
        return 'cache_' . (new DateTime())->format(self::DATE_FORMAT) . '.json';
    }
}
