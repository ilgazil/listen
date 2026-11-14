<?php

namespace App\Service\Scraping\Audible;

use App\Entity\Book;
use App\Service\Scraping\AntiBotException;
use App\Service\Scraping\Fetcher;
use App\Service\Scraping\ScraperInterface;
use App\Service\Scraping\SearchRanker;
use Throwable;

class AudibleScraper implements ScraperInterface
{
    public static string $NAME = 'audible';

    /**
     * Enrichissement série : déclenché dès qu'une saga dont le **nom matche la
     * recherche** est présente dans les résultats (le score exige tous les mots du
     * pattern dans le nom de saga). La page série liste la saga complète, là où la
     * recherche n'expose que ~20 produits.
     */
    protected const MIN_SERIES_SCORE = 60;

    /** Nb max de pages série scrapées par recherche (les meilleures d'abord). */
    protected const MAX_SERIES_ENRICHMENTS = 2;

    /** Audible renvoie souvent une 503 sur les pages série : on retente. */
    protected const MAX_SERIES_ATTEMPTS = 3;
    protected const SERIES_RETRY_DELAY = 1;

    /** Idem pour la page de recherche (une 503 transitoire ne doit pas vider les résultats). */
    protected const MAX_SEARCH_ATTEMPTS = 2;
    protected const SEARCH_RETRY_DELAY = 1;

    /** Une vraie page Audible fait ~1 Mo ; la page d'erreur 503, ~2 Ko. */
    protected const MIN_REAL_PAGE_BYTES = 10000;

    protected string $endpoint = 'https://www.audible.fr/';

    protected array $baseUrlQuery = [
        'ipRedirectOverride' => 'true',
        'overrideBaseCountry' => 'true',
    ];

    public function __construct(protected AudibleSeriesCache $seriesCache)
    {
    }

    public function search(string $pattern): array
    {
        $pattern = SearchRanker::normalize($pattern);
        $words = SearchRanker::words($pattern);

        // La recherche Audible est un ET strict sur tous les mots : un blob
        // « auteur saga titre » (terme dérivé d'un nom de fichier) ne renvoie rien.
        // On relâche progressivement : sans le premier mot (l'auteur), puis sur la
        // queue du titre (2 derniers mots).
        $variants = [$pattern];

        if (count($words) > 2) {
            $variants[] = implode(' ', array_slice($words, 1));

            $tail = implode(' ', array_slice($words, -2));

            if (!in_array($tail, $variants, true)) {
                $variants[] = $tail;
            }
        }

        foreach ($variants as $variant) {
            $url = (new \anlutro\cURL\cURL())->buildUrl(
                $this->endpoint . 'search',
                ['keywords' => $variant] + $this->baseUrlQuery,
            );

            $parser = new AudibleSearchParser($this->fetchWithRetry($url, self::MAX_SEARCH_ATTEMPTS, self::SEARCH_RETRY_DELAY));
            $books = $parser->getBooks();

            if ($books) {
                return $this->withSeriesBooks($pattern, $books, $parser);
            }
        }

        return [];
    }

    /**
     * Complète les sagas tronquées : une recherche sur le nom d'une saga n'expose que
     * ~20 produits et peut occulter des tomes (ex. « bourbon kid » → 10 tomes sur 11).
     * Dès qu'une saga dont le nom matche la recherche est présente sur la page (lien
     * « Série : … »), sa page série — qui liste la saga entière — est scrapée et les
     * livres fusionnés par ASIN.
     *
     * @param array<Book> $books
     * @return array<Book>
     */
    protected function withSeriesBooks(string $pattern, array $books, AudibleSearchParser $parser): array
    {
        try {
            $seriesPaths = $this->detectMatchingSeries($pattern, $parser->getSeriesUrls());

            if (!$seriesPaths) {
                return $books;
            }

            $seriesBooks = [];

            foreach ($seriesPaths as $seriesPath) {
                $seriesBooks = array_merge(
                    $seriesBooks,
                    (new AudibleSearchParser($this->fetchSeriesPage($seriesPath)))->getBooks(false),
                );
            }

            // Dédup par ASIN : la version avec narrateurs prime, sinon la première vue.
            $byAsin = [];

            foreach (array_merge($books, $seriesBooks) as $book) {
                $asin = $book->getScrapId();

                if ($asin !== '' && (!isset($byAsin[$asin]) || (!$byAsin[$asin]->getNarrators() && $book->getNarrators()))) {
                    $byAsin[$asin] = $book;
                }
            }

            return array_values($byAsin);
        } catch (Throwable) {
            // L'enrichissement série est un bonus : en cas d'échec (réseau, anti-bot,
            // markup changé), la recherche seule est renvoyée.
            return $books;
        }
    }

    /**
     * Page série, avec retries (Audible renvoie régulièrement sa 503 anti
     * rate-limiting) et cache quotidien — une page obtenue n'est plus redemandée.
     */
    protected function fetchSeriesPage(string $seriesPath): string
    {
        $seriesId = basename((string) parse_url($seriesPath, PHP_URL_PATH));

        $cached = $this->seriesCache->get($seriesId);

        if ($cached !== null) {
            return $cached;
        }

        $html = $this->fetchWithRetry(
            $this->endpoint . ltrim($seriesPath, '/'),
            self::MAX_SERIES_ATTEMPTS,
            self::SERIES_RETRY_DELAY,
        );

        // La page obtenue est mise en cache ; une page d'erreur (503 épuisée) ne
        // l'est pas — la prochaine recherche retentera. Le markup actuel des pages
        // série n'utilise plus productListItem (rangées adbl-product-row).
        if (str_contains($html, 'productListItem') || str_contains($html, 'adbl-product-row')) {
            $this->seriesCache->set($seriesId, $html);
        }

        return $html;
    }

    /**
     * GET avec retries : Audible sert parfois sa page d'erreur 503 (anti
     * rate-limiting) **sans status HTTP d'échec côté cURL**. On la reconnaît à sa
     * taille (une vraie page, même sans résultat, fait ~1 Mo).
     */
    protected function fetchWithRetry(string $url, int $maxAttempts, int $delay): string
    {
        for ($attempt = 1; ; $attempt++) {
            $html = $this->fetch($url);

            if (strlen($html) > self::MIN_REAL_PAGE_BYTES || $attempt >= $maxAttempts) {
                return $html;
            }

            sleep($delay);
        }
    }

    /**
     * Pages série des sagas dont le nom matche la recherche, dans les deux sens :
     * tous les mots du pattern dans le nom de la saga (« bourbon kid » → « Bourbon
     * Kid »), ou le nom de la saga contenu dans la recherche (« anonyme bourbon kid
     * cimetière du diable » → « Bourbon Kid »). Les meilleures d'abord.
     *
     * @param array<string, string> $seriesUrls nom de saga → chemin de la page série
     * @return array<string> chemins des pages série à scraper
     */
    protected function detectMatchingSeries(string $pattern, array $seriesUrls): array
    {
        $scores = [];

        foreach ($seriesUrls as $saga => $seriesUrl) {
            $score = max(
                SearchRanker::score($pattern, $saga),
                SearchRanker::score($saga, $pattern),
            );

            if ($score >= self::MIN_SERIES_SCORE) {
                $scores[$saga] = $score;
            }
        }

        arsort($scores);

        return array_slice(array_map(fn(string $saga) => $seriesUrls[$saga], array_keys($scores)), 0, self::MAX_SERIES_ENRICHMENTS);
    }

    public function get(string $code): Book|null
    {
        try {
            $body = $this->fetchProductPage($code);
        } catch (AntiBotException) {
            // Mur 503 épuisé : pas de livre fantôme à partir d'une page d'erreur
            // (effacement des champs au re-scrap). Le contrat de get() reste null ;
            // fromUrl() laisse l'exception remonter pour l'erreur explicite du form.
            return null;
        }

        return $this->parseProductPage($body);
    }

    /**
     * Fiche depuis une URL passée à GET /api/scrap/url : l'ASIN est extrait de
     * l'URL produit (/pd/…/{ASIN} — slug éventuel devant l'ASIN) et la fiche est
     * fetchée comme pour get() (retries, ipRedirectOverride). Null si l'URL n'est
     * pas une fiche Audible (autre hôte, page recherche/série…). Jette
     * AntiBotException quand le mur anti-bot épuise les retries : le contrôleur
     * la transforme en erreur explicite pour le formulaire.
     */
    public function fromUrl(string $url): Book|null
    {
        // Délimiteur « # » : les dièses des classes de caractères sont échappés
        // (sans quoi ils termineraient le motif).
        if (preg_match('#audible\.[a-z.]+/pd/(?:[^/?\#]+/)*([A-Za-z0-9]{10})(?:[/?\#]|$)#i', $url, $matches) !== 1) {
            return null;
        }

        return $this->parseProductPage($this->fetchProductPage(strtoupper($matches[1])));
    }

    /**
     * Tags (genres) de la fiche d'un livre : ils n'apparaissent que sur la fiche
     * produit (ni la recherche, ni la page série ne les exposent). Retries comme
     * pour les pages série — la fiche reçoit aussi la 503 anti rate-limiting.
     */
    public function tags(string $code): array
    {
        $html = $this->fetchWithRetry(
            $this->productUrl($code),
            self::MAX_SERIES_ATTEMPTS,
            self::SERIES_RETRY_DELAY,
        );

        // Mur 503 épuisé : la page reçue est une page d'erreur, pas une fiche —
        // jeter plutôt que retourner une liste vide, indiscernable d'une fiche
        // réelle sans genres (le command la compterait en « fiche sans tags » et
        // ne retraiterait jamais le livre). Le contrôleur frontend attrape et
        // sert son contrat 200 + tags: [].
        if (strlen($html) <= self::MIN_REAL_PAGE_BYTES) {
            throw new AntiBotException(sprintf('Audible wall for %s: page too small (%d bytes).', $code, strlen($html)));
        }

        return (new AudibleBookParser($html))->getTags();
    }

    /**
     * Fiche fetchée avec retries (503 anti rate-limiting, page d'erreur ~2 Ko
     * sans status HTTP d'échec côté cURL). Une page trop petite est un mur, pas
     * une fiche : parser la page d'erreur produirait un livre vide (effacement
     * des champs au re-scrap). Jette AntiBotException quand le mur épuise les
     * retries — le message est affiché tel quel sur le formulaire.
     */
    private function fetchProductPage(string $code): string
    {
        $body = $this->fetchWithRetry(
            $this->productUrl($code),
            self::MAX_SERIES_ATTEMPTS,
            self::SERIES_RETRY_DELAY,
        );

        if (strlen($body) <= self::MIN_REAL_PAGE_BYTES) {
            throw new AntiBotException('Le scrap de la fiche a été bloqué par l\'anti-bot d\'Audible : réessaie dans quelques minutes.');
        }

        return $body;
    }

    /**
     * Livre parsé depuis une page fiche réelle. Page « titre indisponible »
     * (livre retiré du catalogue : ni URL canonique ni data-asin) → null, sinon
     * le re-scrap proposerait d'écraser les champs du livre par la page d'erreur.
     */
    private function parseProductPage(string $body): Book|null
    {
        $book = (new AudibleBookParser($body))->createBook();

        if ($book->getScrapId() === '') {
            return null;
        }

        return $book;
    }

    protected function fetch(string $url): string
    {
        return Fetcher::get($url);
    }

    /**
     * URL de la fiche produit. `ipRedirectOverride` : Audible juge parfois l'IP
     * « non FR » et redirige pd/* vers audible.de — une page d'accueil de ~95 Ko,
     * au-dessus du seuil de page réelle, donc indiscernable d'une fiche sans
     * genres (0 tag) ni d'une fiche sans ASIN (get()). Le paramètre, mécanisme
     * d'Audible elle-même, garantit la fiche FR quelle que soit la perception de
     * l'IP (le slug produit se rétablit dans la chaîne de redirection).
     */
    private function productUrl(string $code): string
    {
        return $this->endpoint . 'pd/Book/' . $code . '?ipRedirectOverride=true';
    }
}
