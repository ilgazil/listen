<?php

namespace App\Service\Notification\Discord\Messages;

use App\Entity\Book;

/**
 * Signalement d'un envoi interrompu : vignette du livre (couverture, titre lié à la
 * fiche du scraper quand elle est connue) et détails de l'erreur survenue —
 * propriétés « Erreur » (message) et « Source » (fichier:ligne côté client).
 */
class UploadCutMessage
{
    private const MAX_LENGTH = 2000;

    public function __construct(
        protected Book $book,
        protected string $error = '',
        protected string $source = '',
    ) {
    }

    public function getContent(): mixed
    {
        $fields = [];

        if ($this->error !== '') {
            $fields[] = [
                'name' => 'Erreur',
                'value' => mb_substr($this->error, 0, self::MAX_LENGTH),
                'inline' => false,
            ];
        }

        if ($this->source !== '') {
            $fields[] = [
                'name' => 'Source',
                'value' => mb_substr($this->source, 0, self::MAX_LENGTH),
                'inline' => false,
            ];
        }

        $embed = [
            'type' => 'rich',
            'title' => $this->book->getTitle(),
            'color' => hexdec('e03131'),
            'fields' => $fields,
            'image' => [
                'url' => $this->book->getCover(),
                'height' => 0,
                'width' => 0,
            ],
        ];

        // Titre cliquable vers la fiche du scraper quand elle est définie.
        if ($this->sourceUrl() !== null) {
            $embed['url'] = $this->sourceUrl();
        }

        return [
            'embeds' => [
                $embed,
            ],
        ];
    }

    /** Fiche du livre chez le scraper (audible/lizzie), null si inconnue. */
    protected function sourceUrl(): ?string
    {
        if (trim((string) ($this->book->getScrapId() ?? '')) === '') {
            return null;
        }

        return match ($this->book->getScraper()) {
            'lizzie' => 'https://www.lizzie.audio/content/' . $this->book->getScrapId(),
            'audible' => 'https://www.audible.fr/pd/Book/' . $this->book->getScrapId(),
            default => null,
        };
    }
}
