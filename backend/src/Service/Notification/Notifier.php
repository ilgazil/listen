<?php

namespace App\Service\Notification;

use App\Entity\Book;
use Throwable;

interface Notifier
{
    public function available(Book $book): void;

    public function error(Throwable $exception): void;

    /** Signale un envoi interrompu, avec l'erreur survenue (message + fichier:ligne). */
    public function uploadCut(Book $book, string $error = '', string $source = ''): void;
}
