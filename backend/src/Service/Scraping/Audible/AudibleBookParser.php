<?php

namespace App\Service\Scraping\Audible;

use App\Service\Scraping\Cache;

class AudibleBookParser extends AudibleSearchParser
{
    use Cache;

    protected string $content;

    public function __construct(string $content)
    {
        preg_match('/bc-image-inset-border.+ src="(.+)".+\/>/msU', $content, $matches);
        $this->set('cover', trim($matches[1] ?? ''));

        preg_match('/hero-overflow-visible.+<ul [^>]+bc-list(.*)<\/ul>/msU', $content, $matches);
        parent::__construct($matches[1] ?? '');
    }

    public function getCover(): string
    {
        return $this->get('cover');
    }

    public function getTitle(): string
    {
        if (!$this->has('title')) {
            preg_match('/<h1\s.+>(.+)<\/h1/msU', $this->content, $matches);

            $this->set('title', trim(html_entity_decode($matches[1] ?? '')));
        }

        return $this->get('title');
    }

    public function computeSagaMetadata(): self
    {
        if (!$this->get('saga')) {
            preg_match('/bc-size-medium[^>]*>\s*(?<subtitle>[^<]+)/mi', $this->content, $matches);

            [$saga, $tome] = $this->splitSagaAndTome(trim(html_entity_decode($matches['subtitle'] ?? '')));

            $this->set('saga', $saga);
            $this->set('tome', str_replace(',', '.', $tome));
        }

        return $this;
    }
}
