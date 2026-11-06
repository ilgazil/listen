<?php

namespace App\Service\Scraping\Audible;

use App\Service\Scraping\Cache;

class AudibleBookParser extends AudibleSearchParser
{
    use Cache;

    protected string $content;

    /**
     * Contenu complet de la fiche — le bloc titre (h1/h2), l'image produit, l'URL
     * canonique et les JSON « Détails Produit » sont hors du bloc héro (vide sur
     * le markup actuel).
     */
    protected string $page;

    public function __construct(string $content)
    {
        preg_match('/hero-overflow-visible.+<ul [^>]+bc-list(.*)<\/ul>/msU', $content, $matches);
        parent::__construct($matches[1] ?? '');

        $this->page = $content;
        $this->decodeProductData($content);
    }

    public function getCover(): string
    {
        // La fiche complète porte l'image produit (le bloc héro de l'ancien rendu
        // est vide sur le markup actuel).
        if (!$this->has('cover')) {
            $this->set('cover', $this->coverFrom($this->page));
        }

        return $this->get('cover');
    }

    public function getId(): string
    {
        if (!$this->has('id')) {
            // Fiche : l'URL canonique porte l'ASIN du produit — les attributs
            // data-asin des carrousels « les clients ont aussi écouté » référencent
            // d'autres livres. Les blocs productListItem historiques n'existent pas ici.
            if (preg_match('/rel="canonical"[^>]*href="[^"]*\/pd\/[^"\/]+\/(?<asin>[A-Za-z0-9]{10})/m', $this->page, $matches)
                || preg_match('/data-asin="(?<asin>[A-Za-z0-9]{10})"/m', $this->page, $matches)) {
                $this->set('id', $matches['asin']);
            } else {
                $this->set('id', parent::getId());
            }
        }

        return $this->get('id');
    }

    public function getTitle(): string
    {
        if (!$this->has('title')) {
            // Markup actuel : le h1 « slot="title" » du bloc titre porte le livre.
            // ENT_HTML5 ; double décodage, certains titres sont double-encodés
            // (&amp;#39; → &#39; → ').
            if (preg_match('/<h1\s+slot="title"[^>]*>(?<title>[^<]+)</msU', $this->page, $matches)
                || preg_match('/<h1\s[^>]*>(?<title>[^<]+)</msU', $this->page, $matches)) {
                $title = trim(html_entity_decode($matches['title'], ENT_QUOTES | ENT_HTML5));
                $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));

                $this->set('title', $title);
            } else {
                // Ancien rendu : le bloc héro (traité par le parent).
                $this->set('title', parent::getTitle());
            }
        }

        return $this->get('title');
    }

    public function computeSagaMetadata(): self
    {
        if ($this->get('saga')) {
            return $this;
        }

        // Markup actuel : sous-titre du bloc titre (« L'Assassin Royal 1 »). Le JSON
        // « Détails Produit » fournit le nom de série fiable (le sous-titre peut être
        // descriptif, ex. « A Full-Cast BBC Radio Dramatisation ») et complète un tome
        // absent (le part y est souvent vide).
        preg_match('/<h2\s+slot="subtitle"[^>]*>(?<subtitle>[^<]+)</msU', $this->page, $matches);
        [$saga, $tome] = $this->splitSagaAndTome(trim(html_entity_decode($matches['subtitle'] ?? '')));

        if ($series = $this->productSeries()) {
            $saga = $series['name'];

            if ('' === $tome) {
                $tome = $series['part'];
            }
        }

        if ('' === $saga) {
            // Ancien rendu : sous-titre du bloc héro.
            preg_match('/bc-size-medium[^>]*>\s*(?<subtitle>[^<]+)/mi', $this->content, $matches);

            [$saga, $tome] = $this->splitSagaAndTome(trim(html_entity_decode($matches['subtitle'] ?? '')));
        }

        $this->set('saga', $saga);
        $this->set('tome', str_replace(',', '.', $tome));

        return $this;
    }
}
