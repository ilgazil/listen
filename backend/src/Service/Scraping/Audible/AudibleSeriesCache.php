<?php

namespace App\Service\Scraping\Audible;

use DateTime;

/**
 * Cache quotidien des pages série Audible : Audible renvoie régulièrement une 503
 * (anti rate-limiting) sur ces pages — une fois obtenue, la page est conservée une
 * journée pour ne pas dépendre de sa bonne volonté à chaque recherche.
 */
class AudibleSeriesCache
{
    protected const DATE_FORMAT = 'Y-m-d';

    public function __construct(protected string $cacheDirectory)
    {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }
    }

    /** HTML de la page série en cache (< 24 h), sinon null. */
    public function get(string $seriesId): string|null
    {
        $filename = $this->currentFilename($seriesId);

        if ($filename === null) {
            return null;
        }

        return (string) file_get_contents($this->cacheDirectory . DIRECTORY_SEPARATOR . $filename);
    }

    public function set(string $seriesId, string $html): void
    {
        // Un fichier par jour (même principe que LizzieCache) : l'ancien est supprimé.
        foreach ($this->existingFilenames($seriesId) as $filename) {
            unlink($this->cacheDirectory . DIRECTORY_SEPARATOR . $filename);
        }

        file_put_contents(
            $this->cacheDirectory . DIRECTORY_SEPARATOR . $this->filename($seriesId, (new DateTime())->format(self::DATE_FORMAT)),
            $html,
        );
    }

    /** Fichier du jour pour cette série, s'il existe. */
    protected function currentFilename(string $seriesId): string|null
    {
        $today = $this->filename($seriesId, (new DateTime())->format(self::DATE_FORMAT));

        return is_file($this->cacheDirectory . DIRECTORY_SEPARATOR . $today) ? $today : null;
    }

    protected function filename(string $seriesId, string $date): string
    {
        return 'series_' . preg_replace('/[^A-Za-z0-9]/', '', $seriesId) . '_' . $date . '.html';
    }

    /** @return array<string> */
    protected function existingFilenames(string $seriesId): array
    {
        return array_filter(
            scandir($this->cacheDirectory) ?: [],
            fn(string $filename) => str_starts_with($filename, 'series_' . preg_replace('/[^A-Za-z0-9]/', '', $seriesId) . '_'),
        );
    }
}
