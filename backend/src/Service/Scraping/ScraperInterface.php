<?php

namespace App\Service\Scraping;

use App\Entity\Book;

interface ScraperInterface
{
    public function search(string $pattern): array;

    public function get(string $code): Book|null;

    /**
     * Fiche d'une URL produit du scraper (URL collée dans la recherche) :
     * null si l'URL n'appartient pas au scraper ou n'a pas donné de fiche.
     */
    public function fromUrl(string $url): Book|null;
}
