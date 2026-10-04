<?php

namespace App\Service\Notification;

use App\Entity\Book;
use Throwable;

interface Notifier
{
    public function available(Book $book): void;

    public function error(Throwable $exception): void;

    public function uploadCut(Book $book): void;
}
