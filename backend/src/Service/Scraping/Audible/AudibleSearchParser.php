<?php

namespace App\Service\Scraping\Audible;

use App\Entity\Book;
use App\Service\Scraping\Cache;

class AudibleSearchParser
{
    use Cache;

    protected string $content;

    /**
     * Données structurées « Détails Produit » du markup actuel (rangées série et
     * fiche produit) : blocs JSON décodés — auteurs, narrateurs, durée, note, série.
     * Absents de l'ancien rendu (blocs productListItem) où les libellés HTML restent
     * la source.
     */
    protected array $productData = [];

    public function __construct(string $content)
    {
        $this->content = $content;
        $this->decodeProductData($content);
    }

    /**
     * Décodage des blocs JSON « Détails Produit » embarqués dans le markup actuel.
     * Appelé par le constructeur (bloc rangée) et complété par AudibleBookParser
     * (fiche complète, JSON hors du bloc héro).
     *
     * Sans flag U : les quantifieurs paresseux explicites ne doivent pas être inversés.
     */
    protected function decodeProductData(string $content): void
    {
        if (!preg_match_all('/<script type="application\/json">(?<json>.*?)<\/script>/ms', $content, $matches)) {
            return;
        }

        foreach ($matches['json'] as $json) {
            $data = json_decode(trim($json), true);

            if (is_array($data) && !array_is_list($data)) {
                $this->productData = array_merge($this->productData, $data);
            }
        }
    }

    /** Noms d'une liste du JSON « Détails Produit » (auteurs, narrateurs…). */
    protected function productNames(string $key): array
    {
        $names = [];

        foreach ($this->productData[$key] ?? [] as $entry) {
            $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : trim((string) $entry);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Série du JSON « Détails Produit » (fiche) : nom fiable + tome (« part »,
     * souvent vide — le tome se lit aussi sur le sous-titre du bloc titre).
     *
     * @return array{name: string, part: string}|null
     */
    protected function productSeries(): ?array
    {
        foreach ($this->productData['series'] ?? [] as $series) {
            if (!is_array($series)) {
                continue;
            }

            $name = trim((string) ($series['name'] ?? ''));

            if ($name !== '') {
                return ['name' => $name, 'part' => trim((string) ($series['part'] ?? ''))];
            }
        }

        return null;
    }

    public function getId(): string
    {
        if (!$this->has('id')) {
            // ASIN du bloc (pages recherche et série), sinon le flyout (pages de
            // recherche), sinon l'attribut data-asin (rangées « adbl-product-row »
            // du markup actuel).
            if (preg_match('/product-list-item-(?<asin>[A-Za-z0-9]{10})/m', $this->content, $matches)
                || preg_match('/product-list-flyout-(.+)"/msU', $this->content, $matches)) {
                $this->set('id', $matches['asin'] ?? $matches[1]);
            } elseif (preg_match('/data-asin="(?<asin>[A-Za-z0-9]{10})"/m', $this->content, $matches)) {
                $this->set('id', $matches['asin']);
            } else {
                $this->set('id', '');
            }
        }

        return $this->get('id');
    }

    public function getCover(): string
    {
        if (!$this->has('cover')) {
            $this->set('cover', $this->coverFrom($this->content));
        }

        return $this->get('cover');
    }

    /**
     * Couverture d'un bloc : image produit du markup actuel (rangée série, fiche),
     * sinon l'image « bc-image-inset-border » de l'ancien rendu. La vignette est
     * réécrite en 500 px (les pages actuelles servent des miniatures SL63/SL240).
     */
    protected function coverFrom(string $html): string
    {
        if (!preg_match('/<adbl-product-image[^>]*>\s*<img[^>]+src="(?<src>[^"]+)"/msU', $html, $matches)
            && !preg_match('/bc-image-inset-border.+ src="(.+)".+\/>/msU', $html, $matches)) {
            return '';
        }

        $cover = trim((string) ($matches['src'] ?? $matches[1] ?? ''));

        return (string) preg_replace('/\._SL\d+_\./', '._SL500_.', $cover);
    }

    public function getTitle(): string
    {
        if (!$this->has('title')) {
            // L'aria-label du bloc est le titre fiable (pages recherche et série) — le
            // rendu du <h3> varie (produits sans fiche standard, ex. tome indisponible).
            // ENT_HTML5 pour &apos; ; double décodage, les labels sont double-encodés
            // (&amp;#39; → &#39; → ').
            preg_match('/productListItem[^>]*aria-label=[\'"](?<title>[^\'"]*)[\'"]/m', $this->content, $ariaMatches);
            $title = trim(html_entity_decode($ariaMatches['title'] ?? '', ENT_QUOTES | ENT_HTML5));
            $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));

            if ('' === $title) {
                // Rangées « adbl-product-row » du markup actuel (page série) : le titre
                // est dans un h3 « slot » — le regex historique se noie dans les ancres
                // du bloc (boutons d'aperçu, buy-box).
                preg_match('/<h3\s+slot="title"[^>]*>(?:\s*<a[^>]*>)?(?<title>[^<]+)</msU', $this->content, $matches);
                $title = trim(html_entity_decode($matches['title'] ?? '', ENT_QUOTES | ENT_HTML5));
            }

            if ('' === $title) {
                preg_match('/<h3\s.+>.+<a[^>]+>(.+)<\/a/msU', $this->content, $matches);
                $title = trim(html_entity_decode($matches[1] ?? ''));
            }

            $this->set('title', $title);
        }

        return $this->get('title');
    }

    /**
     * Liens « Série : … » de la page, par nom de saga — chemin absolu de la page série
     * (sans les paramètres de suivi Audible).
     *
     * @return array<string, string>
     */
    public function getSeriesUrls(): array
    {
        if (!$this->has('seriesUrls')) {
            preg_match_all(
                '/seriesLabel[^>]*>\s*<span[^>]*>\s*Série\s*:.*?<a[^>]+href="(?<href>\/series\/[^"]+)"[^>]*>(?<name>.*?)<\/a>/mis',
                $this->content,
                $matches,
                PREG_SET_ORDER,
            );

            $urls = [];

            foreach ($matches as $match) {
                $name = trim(html_entity_decode($match['name']));

                if ($name !== '' && !isset($urls[$name])) {
                    $urls[$name] = explode('?', $match['href'])[0];
                }
            }

            $this->set('seriesUrls', $urls);
        }

        return $this->get('seriesUrls');
    }

    public function computeSagaMetadata(): self
    {
        if (!$this->has('saga')) {
            $this->extractSagaFromSubtitle();
            $this->extractSagaFromRow();
            $this->extractSagaFromSeriesLabel();
        }

        return $this;
    }

    /**
     * Rangées « adbl-product-row » du markup actuel (page série) : sous-titre
     * « Saga 1 » du bloc titre.
     */
    protected function extractSagaFromRow(): void
    {
        if ($this->get('saga') || $this->get('tome')) {
            return;
        }

        if (!preg_match('/<h4\s+slot="subtitle"[^>]*>(?<subtitle>[^<]+)</msU', $this->content, $matches)) {
            return;
        }

        [$saga, $tome] = $this->splitSagaAndTome(trim(html_entity_decode($matches['subtitle'])));

        $this->set('saga', $saga);
        $this->set('tome', str_replace(',', '.', $tome));
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
            // Markup actuel : JSON « Détails Produit » (les rangées série/fiche
            // n'exposent plus l'auteur en lien) — sinon le lien « De : » (ancien rendu).
            $authors = $this->productNames('authors');

            $this->set('author', $authors ? implode(', ', $authors) : $this->authorFromLabel());
        }

        return $this->get('author');
    }

    /** Ancien rendu : premier lien du bloc « authorLabel ». */
    protected function authorFromLabel(): string
    {
        preg_match('/authorLabel.+<a.+>(.+)</msU', $this->content, $matches);

        return trim(html_entity_decode($matches[1] ?? ''));
    }

    public function getNarrators(): array
    {
        if (!$this->has('narrators')) {
            // Markup actuel : JSON « Détails Produit » — sinon les liens du bloc
            // « narratorLabel » (ancien rendu).
            $narrators = $this->productNames('narrators');

            $this->set('narrators', $narrators ?: $this->narratorsFromLabel());
        }

        return $this->get('narrators');
    }

    /** Ancien rendu : tous les liens du bloc « narratorLabel ». */
    protected function narratorsFromLabel(): array
    {
        preg_match('/narratorLabel.+<\/span/msU', $this->content, $blockMatches);
        preg_match_all('/(?:<a[^>]+>(?<author>.*)<\/a>)/msU', $blockMatches[0] ?? '', $matches);

        return $matches['author'] ?? [];
    }

    public function getRuntime(): string
    {
        if (!$this->has('runtime')) {
            // Markup actuel : durée du JSON (« 20 h et 52 min ») — sinon le libellé
            // « Durée : » du bloc (ancien rendu).
            $duration = trim((string) ($this->productData['duration'] ?? ''));

            $this->set('runtime', $this->runtimeFromDuration($duration) ?: $this->runtimeFromLabel());
        }

        return $this->get('runtime');
    }

    /** « 17 h et 48 min » → 17:48 (format H:i de l'entité, minutes seules admises). */
    protected function runtimeFromDuration(string $duration): string
    {
        preg_match('/(?<hours>\d+)\s*h/i', $duration, $hours);
        preg_match('/(?<minutes>\d+)\s*min/i', $duration, $minutes);

        if (empty($hours) && empty($minutes)) {
            return '';
        }

        return ($hours['hours'] ?? '0') . ':' . sprintf('%02d', $minutes['minutes'] ?? 0);
    }

    /** Ancien rendu : libellé « Durée : » du bloc héro. */
    protected function runtimeFromLabel(): string
    {
        preg_match('/runtimeLabel.*:\D*(?<hours>\d+)\D*h(?:\D+(?<minutes>\d+)\s+m.*)?</msU', $this->content, $matches);

        if (empty($matches)) {
            return '';
        }

        return $matches['hours'] . ':' . (sprintf('%02d', $matches['minutes'] ?? '00'));
    }

    public function getRatings(): float
    {
        if (!$this->has('ratings')) {
            // Markup actuel : note du JSON, arrondie à une décimale (4.8115 → 4.8,
            // comme l'affichage) — sinon la valeur affichée du bloc (ancien rendu).
            $value = $this->productData['rating']['value'] ?? null;

            if (is_numeric($value)) {
                $this->set('ratings', round((float) $value, 1));
            } else {
                $this->set('ratings', $this->ratingsFromLabel());
            }
        }

        return $this->get('ratings');
    }

    /** Ancien rendu : valeur affichée du bloc « ratingsLabel ». */
    protected function ratingsFromLabel(): float
    {
        preg_match('/ratingsLabel[^>]*>(?<block>.*?)<\/li/msi', $this->content, $blockMatches);

        preg_match('/bc-size-callout[^>]*>\s*(?<rating>\d+(?:[.,]\d+)?)\s*</m', $blockMatches['block'] ?? '', $ratingMatches);

        return isset($ratingMatches['rating']) ? (float) str_replace(',', '.', $ratingMatches['rating']) : 0.0;
    }

    public function getBooks(bool $requireNarrator = true): array
    {
        // Ancien rendu : blocs productListItem (recherche, ancienne page série).
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', $this->content, $matches);

        // Markup actuel : rangées adbl-product-row (page série) — les carrousels
        // « autres sagas » (adbl-product-grid-item) ne sont pas des rangées produit.
        // Sans flag U : paresseux explicite, une rangée par bloc.
        if (!$matches[0]) {
            preg_match_all('/<adbl-product-row.*?<\/adbl-product-row>/ms', $this->content, $matches);
        }

        if (!$matches[0]) {
            return [];
        }

        $books = array_map(function ($match) use ($requireNarrator) {
            $parser = new self($match);

            // Sur une page série, un livre sans narrateur parsé reste un livre de la
            // saga (ex. tome sans fiche standard) : le filtre n'est appliqué qu'en recherche.
            if ($requireNarrator && !$parser->getNarrators()) {
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
