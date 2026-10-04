<?php

namespace App\Service\Scraping\Audible;

use App\Entity\Book;
use App\Service\Scraping\Cache;

class AudibleSearchParser
{
    use Cache;

    protected string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function getId(): string
    {
        if (!$this->has('id')) {
            preg_match('/product-list-flyout-(.+)"/msU', $this->content, $matches);

            $this->set('id', $matches[1] ?? '');
        }

        return $this->get('id');
    }

    public function getCover(): string
    {
        preg_match('/bc-image-inset-border.+ src="(.+)".+\/>/msU', $this->content, $matches);

        $this->set('cover', trim($matches[1] ?? ''));

        return $this->get('cover');
    }

    public function getTitle(): string
    {
        if (!$this->has('title')) {
            preg_match('/<h3\s.+>.+<a[^>]+>(.+)<\/a/msU', $this->content, $matches);

            $this->set('title', trim(html_entity_decode($matches[1] ?? '')));
        }

        return $this->get('title');
    }

    public function computeSagaMetadata(): self
    {
        if (!$this->has('saga')) {
            $this->extractSagaFromSubtitle();

            if (!$this->get('saga')) {
                $this->extractSagaFromSeriesLabel();
            }
        }

        return $this;
    }

    protected function extractSagaFromSubtitle(): void
    {
        preg_match('/subtitle[^>]*>\s*<span[^>]*>\s*(?<subtitle>[^<]+)/mi', $this->content, $matches);

        $subtitle = trim(html_entity_decode($matches['subtitle'] ?? ''));

        if ('' === $subtitle) {
            return;
        }

        [$saga, $tome] = $this->splitSagaAndTome($subtitle);

        $this->set('saga', $saga);
        $this->set('tome', str_replace(',', '.', $tome));
    }

    protected function extractSagaFromSeriesLabel(): void
    {
        if (!preg_match('/seriesLabel[^>]*>\s*<span[^>]*>\s*Série\s*:.*?<a[^>]*>(?<saga>.*?)<\/a>(?<rest>[^<]*)/mis', $this->content, $matches)) {
            return;
        }

        $this->set('saga', trim(html_entity_decode($matches['saga'] ?? '')));

        if (preg_match('/(?:(?:Tome|Volume|Vol\.?|Livre|Book|Part)\s+)?(?<tome>\d+(?:[.,]\d+)?)\s*$/i', trim($matches['rest'] ?? ''), $tomeMatches)) {
            $this->set('tome', str_replace(',', '.', trim($tomeMatches['tome'])));
        }
    }

    protected function splitSagaAndTome(string $subtitle): array
    {
        if (preg_match('/^\s*(?:(?:Tome|Volume|Vol\.?|Livre|Book|Part)\s+)?(?<tome>\d+(?:[.,]\d+)?)\s*$/i', $subtitle, $matches)) {
            return ['', $matches['tome']];
        }

        if (preg_match('/^(?<saga>.+?)\s*,\s*(?:(?:Tome|Volume|Vol\.?|Livre|Book|Part)\s+)?(?<tome>\d+(?:[.,]\d+)?)\s*$/i', $subtitle, $matches)) {
            return [rtrim(trim($matches['saga']), ' ,:;.-'), $matches['tome']];
        }

        if (preg_match('/^(?<saga>.+?)\s+(?:(?:Tome|Volume|Vol\.?|Livre|Book|Part)\s+)?(?<tome>\d+(?:[.,]\d+)?)\s*$/i', $subtitle, $matches)) {
            return [rtrim(trim($matches['saga']), ' ,:;.-'), $matches['tome']];
        }

        return [trim($subtitle), ''];
    }

    public function getSaga(): string
    {
        return $this->computeSagaMetadata()->get('saga');
    }

    public function getTome(): string
    {
        return $this->computeSagaMetadata()->get('tome');
    }

    public function getAuthor(): string
    {
        if (!$this->has('author')) {
            preg_match('/authorLabel.+<a.+>(.+)</msU', $this->content, $matches);

            $this->set('author', trim(html_entity_decode($matches[1] ?? '')));
        }

        return $this->get('author');
    }

    public function getNarrators(): array
    {
        if (!$this->has('narrators')) {
            preg_match('/narratorLabel.+<\/span/msU', $this->content, $blockMatches);
            preg_match_all('/(?:<a[^>]+>(?<author>.*)<\/a>)/msU', $blockMatches[0] ?? '', $matches);

            $this->set('narrators', $matches['author'] ?? []);
        }

        return $this->get('narrators');
    }

    public function getRuntime(): string
    {
        if (!$this->has('runtime')) {
            preg_match('/runtimeLabel.*:\D*(?<hours>\d+)\D*h(?:\D+(?<minutes>\d+)\s+m.*)?</msU', $this->content, $matches);

            if (empty($matches)) {
                $this->set('runtime', '');
            } else {
                $this->set('runtime', $matches['hours'] . ':' . (sprintf('%02d', $matches['minutes'] ?? '00')));
            }
        }

        return $this->get('runtime');
    }

    public function getRatings(): float
    {
        if (!$this->has('ratings')) {
            preg_match('/ratingsLabel[^>]*>(?<block>.*?)<\/li/msi', $this->content, $blockMatches);

            preg_match('/bc-size-callout[^>]*>\s*(?<rating>\d+(?:[.,]\d+)?)\s*</m', $blockMatches['block'] ?? '', $ratingMatches);

            $this->set('ratings', isset($ratingMatches['rating']) ? (float) str_replace(',', '.', $ratingMatches['rating']) : 0.0);
        }

        return $this->get('ratings');
    }

    public function getBooks(): array
    {
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', $this->content, $matches);

        if (!$matches) {
            return [];
        }

        $books = array_map(function ($match) {
            $parser = new self($match);

            if (!$parser->getNarrators()) {
                return null;
            }

            return $parser->createBook();
        }, $matches[0]);

        return array_filter($books, fn($book) => !is_null($book));
    }

    public function createBook(): Book
    {
        return (new Book())
            ->setCover($this->getCover())
            ->setTitle($this->getTitle())
            ->setSaga($this->getSaga())
            ->setTome($this->getTome())
            ->setAuthor($this->getAuthor())
            ->setNarrators($this->getNarrators())
            ->setRuntime($this->getRuntime())
            ->setRatings($this->getRatings())
            ->setScraper(AudibleScraper::$NAME)
            ->setScrapId($this->getId())
        ;
    }
}
