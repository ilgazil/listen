<?php

namespace App\Service\Scraping\Lizzie;

use App\Entity\Book;
use DateTime;
use stdClass;

class LizzieItem
{
    protected stdClass $raw;

    public function __construct(stdClass $raw)
    {
        $this->raw = $raw;
    }

    public function getRaw(): stdClass
    {
        return $this->raw;
    }

    public function getScrapId(): string
    {
        return $this->raw->slug;
    }

    public function getTitle(): string
    {
        return $this->raw->title;
    }

    public function getAuthor(): string
    {
        return $this->raw->author;
    }

    public function getCover(): string
    {
        return $this->raw->imgSrc;
    }

    public function getRuntime(): string
    {
        return (new DateTime())->setTime(0, $this->raw->overallDuration)->format('H:i');
    }

    public function getNarrators(): string
    {
        return $this->raw->narrator;
    }

    public function toBook(): Book
    {
        return (new Book())
            ->setTitle($this->getTitle())
            ->setAuthor($this->getAuthor())
            ->setCover($this->getCover())
            ->setRuntime($this->getRuntime())
            ->setNarrators([$this->getNarrators()])
            ->setScraper(LizzieScraper::$NAME)
            ->setScrapId($this->getScrapId());
    }
}
