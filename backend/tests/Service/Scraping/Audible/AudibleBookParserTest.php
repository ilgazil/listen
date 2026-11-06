<?php

namespace App\Tests\Service\Scraping\Audible;

use App\Service\Scraping\Audible\AudibleBookParser;
use PHPUnit\Framework\TestCase;

class AudibleBookParserTest extends TestCase
{
    private const string BOOK_FIXTURE = __DIR__ . '/../../../Fixtures/audible-book.html';
    private const string CAST_FIXTURE = __DIR__ . '/../../../Fixtures/audible-book-cast.html';

    public function testParsesCurrentMarkup(): void
    {
        // Markup actuel de la fiche : JSON « Détails Produit » (contributions +
        // métadonnées), bloc titre h1/h2 « slot », image produit, URL canonique.
        $json = <<<'JSON'
{"authors":[{"name":"Robin Hobb"}],"narrators":[{"name":"Sylvain Agaësse"}],"duration":"17 h et 48 min","rating":{"value":4.8115,"count":4559},"series":[{"part":"","name":"L'Assassin Royal","url":"/series/LAssassin-Royal-Livres-Audio/B01NBMNLMA"}]}
JSON;

        $html = '<html><head>'
            . '<link rel="canonical" href="https://www.audible.fr/pd/Lapprenti-assassin-Livre-Audio/B01M2WSG6T" />'
            . '</head><body>'
            . '<adbl-product-image slot="image"><img src="https://m.media-amazon.com/images/I/41omuHGjzfL._SL63_.jpg" alt="Couverture" /></adbl-product-image>'
            . '<adbl-title-lockup slot="title-lockup">'
            . '<h1 slot="title">L&#39;apprenti assassin</h1>'
            . '<h2 slot="subtitle">L&#39;Assassin Royal 1</h2>'
            . '</adbl-title-lockup>'
            . '<adbl-product-metadata slot="metadata" aria-label="Détails Produit">'
            . '<script type="application/json">' . $json . '</script>'
            . '</adbl-product-metadata>'
            . '</body></html>';

        $book = (new AudibleBookParser($html))->createBook();

        self::assertSame('B01M2WSG6T', $book->getScrapId());
        self::assertSame("L'apprenti assassin", $book->getTitle());
        self::assertSame('Robin Hobb', $book->getAuthor());
        self::assertSame(['Sylvain Agaësse'], $book->getNarrators());
        self::assertSame('17:48', $book->getRuntime());
        self::assertSame(4.8, $book->getRatings());
        self::assertSame("L'Assassin Royal", $book->getSaga());
        self::assertSame('1', $book->getTome());

        // Vignette SL63 réécrite en 500 px.
        self::assertSame('https://m.media-amazon.com/images/I/41omuHGjzfL._SL500_.jpg', $book->getCover());
    }

    public function testSagaFromProductDataOverridesDescriptiveSubtitle(): void
    {
        // Sous-titre descriptif sans tome : le JSON « Détails Produit » fournit le
        // nom de série fiable, et aucun tome n'est inventé.
        $html = '<html><body>'
            . '<adbl-title-lockup><h1 slot="title">Titre</h1><h2 slot="subtitle">A Full-Cast BBC Radio Dramatisation</h2></adbl-title-lockup>'
            . '<adbl-product-metadata><script type="application/json">{"narrators":[{"name":"N"}],'
            . '"series":[{"part":"","name":"BBC Radio’s The Lord of the Rings"}]}</script></adbl-product-metadata>'
            . '</body></html>';

        $book = (new AudibleBookParser($html))->createBook();

        self::assertSame('BBC Radio’s The Lord of the Rings', $book->getSaga());
        self::assertSame('', $book->getTome());
    }

    public function testTomeFromSubtitleWinsOverEmptySeriesPart(): void
    {
        // Tome du sous-titre (« Saga 2 ») conservé quand le part du JSON est vide.
        $html = '<html><body>'
            . '<adbl-title-lockup><h1 slot="title">Titre</h1><h2 slot="subtitle">Le seigneur des anneaux 2</h2></adbl-title-lockup>'
            . '<adbl-product-metadata><script type="application/json">{"series":[{"part":"","name":"Le seigneur des anneaux"}]}</script>'
            . '</adbl-product-metadata>'
            . '</body></html>';

        $book = (new AudibleBookParser($html))->createBook();

        self::assertSame('Le seigneur des anneaux', $book->getSaga());
        self::assertSame('2', $book->getTome());
    }

    public function testParsesLegacyHeroMarkup(): void
    {
        // Ancien rendu : bloc héro + libellés HTML, image bc-image-inset-border,
        // ASIN du bloc product-list-item.
        $html = '<html><body>'
            . '<img class="bc-image-inset-border js-only-element" src="https://m.media-amazon.com/images/I/41omuHGjzfL._SL63_.jpg" alt="" />'
            . '<div class="hero-overflow-visible bc-container"><ul class="bc-list bc-list-nobullets">'
            . '<li class="bc-list-item product-list-item-B07OLDTE01"></li>'
            . '<li class="bc-list-item authorLabel"><span>De :</span><a href="/author/x">Auteur Ancien</a></li>'
            . '<li class="bc-list-item
	narratorLabel" ><span>Lu par :
	<a href="/search?searchNarrator=N1">Narrateur 1</a>,
	<a href="/search?searchNarrator=N2">Narrateur 2</a></span></li>'
            . '<li class="bc-list-item runtimeLabel">Durée : 10 h et 30 min</li>'
            . '<li class="bc-list-item ratingsLabel"><span class="bc-text bc-size-callout">4,5</span></li>'
            . '<li class="bc-list-item
	subtitle" ><span class="bc-text bc-size-medium">Saga Ancienne 2</span></li>'
            . '</ul></div>'
            . '</body></html>';

        $book = (new AudibleBookParser($html))->createBook();

        self::assertSame('B07OLDTE01', $book->getScrapId());
        self::assertSame('Auteur Ancien', $book->getAuthor());
        self::assertSame(['Narrateur 1', 'Narrateur 2'], $book->getNarrators());
        self::assertSame('10:30', $book->getRuntime());
        self::assertSame(4.5, $book->getRatings());
        self::assertSame('Saga Ancienne', $book->getSaga());
        self::assertSame('2', $book->getTome());

        // Vignette SL63 réécrite en 500 px (même traitement que le markup actuel).
        self::assertSame('https://m.media-amazon.com/images/I/41omuHGjzfL._SL500_.jpg', $book->getCover());
    }

    public function testParsesBookFixture(): void
    {
        if (!is_file(self::BOOK_FIXTURE)) {
            self::markTestSkipped('fixture audible-book.html non présente (gitignorée).');
        }

        $book = (new AudibleBookParser((string) file_get_contents(self::BOOK_FIXTURE)))->createBook();

        self::assertSame('B01M2WSG6T', $book->getScrapId());
        self::assertSame("L'apprenti assassin", $book->getTitle());
        self::assertSame('Robin Hobb', $book->getAuthor());
        self::assertSame(['Sylvain Agaësse'], $book->getNarrators());
        self::assertSame('17:48', $book->getRuntime());
        self::assertSame(4.8, $book->getRatings());
        self::assertSame("L'Assassin Royal", $book->getSaga());
        self::assertSame('1', $book->getTome());
        self::assertStringContainsString('_SL500_', $book->getCover());
    }

    public function testParsesMultiNarratorBookFixture(): void
    {
        if (!is_file(self::CAST_FIXTURE)) {
            self::markTestSkipped('fixture audible-book-cast.html non présente (gitignorée).');
        }

        $book = (new AudibleBookParser((string) file_get_contents(self::CAST_FIXTURE)))->createBook();

        self::assertSame('B0092ATSRK', $book->getScrapId());
        self::assertSame('The Lord of the Rings: The Fellowship of the Ring', $book->getTitle());
        self::assertSame('J. R. R. Tolkien', $book->getAuthor());
        self::assertCount(9, $book->getNarrators());
        self::assertContains('Ian Holm', $book->getNarrators());
        self::assertSame('4:35', $book->getRuntime());
        self::assertSame(4.0, $book->getRatings());

        // Sous-titre descriptif : la saga vient du JSON « Détails Produit ».
        self::assertSame('BBC Radio’s The Lord of the Rings', $book->getSaga());
        self::assertSame('', $book->getTome());
    }
}
