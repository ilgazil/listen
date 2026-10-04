<?php

namespace App\Service\Notification\Discord\Messages;

use App\Entity\Book;

class AvailableBookMessage
{
    protected Book $book;
    protected ?string $url;

    public function __construct(Book $book, ?string $url = null)
    {
        $this->book = $book;
        // Lien « ouvrir le livre » : URL du téléchargement ou de la page des
        // fichiers orphelins selon le flux. Null => embed sans lien cliquable.
        $this->url = $url;
    }

    public function getContent(): mixed
    {
        $fields = [];

        if ($this->book->getSaga()) {
            $field = [
                'name' => 'Saga',
                'value' => $this->book->getSaga(),
                'inline' => true,
            ];

            if ($this->book->getTome()) {
                $field['value'] .= ', ' . $this->book->getTome();
            }

            $fields[] = $field;
        }

        $embed = [
            'type' => 'rich',
            'title' => $this->book->getTitle(),
            'color' => hexdec('f59e0b'),
            'fields' => array_merge($fields, [
                [
                    'name' => 'Durée',
                    'value' => "{$this->book->getRuntime()}",
                    'inline' => true,
                ],
                [
                    'name' => 'Évaluation',
                    'value' => "{$this->book->getRatings()}",
                    'inline' => true,
                ],
            ]),
            'image' => [
                'url' => $this->book->getCover(),
                'height' => 0,
                'width' => 0
            ],
        ];

        if ($this->url !== null) {
            $embed['url'] = $this->url;
        }

        return [
            'embeds' => [
                $embed,
            ],
        ];
    }
}
