<?php

namespace App\Service\Notification\Discord\Messages;

use Throwable;

class ErrorMessage
{
    private const MAX_LENGTH = 2000;

    protected Throwable|string $error;

    public function __construct(Throwable|string $error)
    {
        $this->error = $error;
    }

    public function getContent(): array
    {
        $fields = [];

        if ($this->error instanceof Throwable) {
            $description = get_class($this->error) . ': ' . $this->error->getMessage();
            $fields = [
                [
                    'name' => 'Code',
                    'value' => (string) $this->error->getCode(),
                    'inline' => true,
                ],
                [
                    'name' => 'Fichier',
                    'value' => mb_substr($this->error->getFile() . ':' . $this->error->getLine(), 0, self::MAX_LENGTH),
                    'inline' => true,
                ],
            ];
        } else {
            $description = $this->error;
        }

        return [
            'embeds' => [
                [
                    'type' => 'rich',
                    'title' => 'Erreur runtime PHP',
                    'color' => hexdec('e03131'),
                    'description' => mb_substr($description, 0, self::MAX_LENGTH),
                    'fields' => $fields,
                ],
            ],
        ];
    }
}