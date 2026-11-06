<?php

namespace App\Tests\Service\Scraping\Audible;

use App\Service\Scraping\Audible\AudibleSearchParser;
use PHPUnit\Framework\TestCase;

class AudibleSearchParserTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../Fixtures/audible-search.html';
    private const string SERIES_FIXTURE = __DIR__ . '/../../../Fixtures/audible-series.html';
    private const string BOURBON_FIXTURE = __DIR__ . '/../../../Fixtures/audible-bourbon-search.html';
    private const string TOWERS_SERIES_FIXTURE = __DIR__ . '/../../../Fixtures/audible-series-towers.html';

    public function testSagaFromSubtitleWithCommaTome(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Les Chroniques lunaires, Tome 2'));

        self::assertSame('Les Chroniques lunaires', $parser->getSaga());
        self::assertSame('2', $parser->getTome());
    }

    public function testSagaFromSubtitleVolume(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Alice in Wonderland, Volume 1'));

        self::assertSame('Alice in Wonderland', $parser->getSaga());
        self::assertSame('1', $parser->getTome());
    }

    public function testSagaFromSubtitleBook(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Scarlett and Browne, Book 3'));

        self::assertSame('Scarlett and Browne', $parser->getSaga());
        self::assertSame('3', $parser->getTome());
    }

    public function testSagaFromSubtitleLivre(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Passion torréfiée, Livre 1'));

        self::assertSame('Passion torréfiée', $parser->getSaga());
        self::assertSame('1', $parser->getTome());
    }

    public function testSagaFromSubtitleVolAbbreviation(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Terror at the Gates, Vol. 1'));

        self::assertSame('Terror at the Gates', $parser->getSaga());
        self::assertSame('1', $parser->getTome());
    }

    public function testSagaFromSubtitleTrailingNumberWithoutMarker(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Phobos 1'));

        self::assertSame('Phobos', $parser->getSaga());
        self::assertSame('1', $parser->getTome());
    }

    public function testSagaFromSubtitleDecolonizesTome(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Les Chemins de poussière, Tome 2,5'));

        self::assertSame('Les Chemins de poussière', $parser->getSaga());
        self::assertSame('2.5', $parser->getTome());
    }

    public function testSubtitleOnlyContainsVolume(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'Volume 1'));

        self::assertSame('', $parser->getSaga());
        self::assertSame('1', $parser->getTome());
    }

    public function testSubtitleWithoutSagaOrTome(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'A Novel'));

        self::assertSame('A Novel', $parser->getSaga());
        self::assertSame('', $parser->getTome());
    }

    public function testSagaFromSeriesLabelFallback(): void
    {
        $parser = new AudibleSearchParser($this->fragment(series: 'Sound Therapy', seriesRest: ''));

        self::assertSame('Sound Therapy', $parser->getSaga());
        self::assertSame('', $parser->getTome());
    }

    public function testSagaFromSeriesLabelWithVolume(): void
    {
        $parser = new AudibleSearchParser($this->fragment(series: 'Les Chroniques lunaires', seriesRest: ', Volume 2'));

        self::assertSame('Les Chroniques lunaires', $parser->getSaga());
        self::assertSame('2', $parser->getTome());
    }

    public function testSagaPrefersSubtitleOverSeriesLabel(): void
    {
        $parser = new AudibleSearchParser(
            $this->fragment(subtitle: 'Les Chroniques lunaires, Tome 2', series: 'Les Chroniques lunaires', seriesRest: ', Volume 2')
        );

        self::assertSame('Les Chroniques lunaires', $parser->getSaga());
        self::assertSame('2', $parser->getTome());
    }

    public function testRatingsWithFrenchDecimalComma(): void
    {
        $parser = new AudibleSearchParser($this->fragment(rating: '5,0'));

        self::assertSame(5.0, $parser->getRatings());
    }

    public function testRatingsOtherValue(): void
    {
        $parser = new AudibleSearchParser($this->fragment(rating: '4,3'));

        self::assertSame(4.3, $parser->getRatings());
    }

    public function testRatingsAbsent(): void
    {
        $parser = new AudibleSearchParser($this->fragment(rating: 'Pas de notations'));

        self::assertSame(0.0, $parser->getRatings());
    }

    public function testRatingsWithoutBlock(): void
    {
        $parser = new AudibleSearchParser($this->fragment(subtitle: 'A Novel'));

        self::assertSame(0.0, $parser->getRatings());
    }

    public function testParsesFixture(): void
    {
        if (!is_file(self::FIXTURE)) {
            self::markTestSkipped('fixture audible-search.html non présente (gitignorée).');
        }

        $parser = new AudibleSearchParser((string) file_get_contents(self::FIXTURE));
        $books = $parser->getBooks();

        self::assertCount(20, $books);

        $scarlet = $books[0];
        self::assertSame('Scarlet', $scarlet->getTitle());
        self::assertSame('Les Chroniques lunaires', $scarlet->getSaga());
        self::assertSame('2', $scarlet->getTome());
        self::assertSame(5.0, $scarlet->getRatings());

        $alice = $books[1];
        self::assertSame('Alice in Wonderland', $alice->getSaga());
        self::assertSame('1', $alice->getTome());
        self::assertSame(4.3, $alice->getRatings());

        $browne = $books[8];
        self::assertSame('Scarlett and Browne', $browne->getSaga());
        self::assertSame('3', $browne->getTome());
    }

    public function testSeriesFixtureListsFullSaga(): void
    {
        if (!is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixture audible-series.html non présente (gitignorée).');
        }

        $parser = new AudibleSearchParser((string) file_get_contents(self::SERIES_FIXTURE));

        // La page série liste la saga complète : le tome sans narrateur parsé est inclus.
        $books = $parser->getBooks(false);

        self::assertCount(11, $books);

        $tomes = array_map(fn($book) => $book->getTome(), $books);

        self::assertContains('8', $tomes);

        $tome8 = $books[array_search('8', $tomes)];
        self::assertSame("Que le diable l'emporte", $tome8->getTitle());
        self::assertSame('B07WNK1W8H', $tome8->getScrapId());
        self::assertSame('Bourbon Kid', $tome8->getSaga());
    }

    public function testSearchFixtureExposesSeriesUrls(): void
    {
        if (!is_file(self::BOURBON_FIXTURE)) {
            self::markTestSkipped('fixture audible-bourbon-search.html non présente (gitignorée).');
        }

        $parser = new AudibleSearchParser((string) file_get_contents(self::BOURBON_FIXTURE));

        $seriesUrls = $parser->getSeriesUrls();

        self::assertSame(
            '/series/Bourbon-Kid-Livres-Audio/B07F77M8JL',
            $seriesUrls['Bourbon Kid'],
        );
    }

    public function testSeriesUrlsEmptyWithoutSeriesLinks(): void
    {
        $parser = new AudibleSearchParser('<li class="productListItem">x</li>');

        self::assertSame([], $parser->getSeriesUrls());
    }

    /**
     * Rangée « adbl-product-row » du markup actuel (page série) : JSON « Détails
     * Produit » (contributions, durée, note) + bloc titre h3/h4 « slot ».
     */
    private function row(string $title, string $subtitle, string $asin, string $json): string
    {
        return <<<HTML
<adbl-product-row variant="catalog" series-header="Volume 1 de la série" placement="base">
  <a href="/pd/Livre-Audio/$asin" slot="image">
    <adbl-product-image><img src="https://m.media-amazon.com/images/I/couv._SL240_.jpg" alt="Couverture" /></adbl-product-image>
  </a>
  <h3 slot="title"><a href="/pd/Livre-Audio/$asin">$title</a></h3>
  <h4 slot="subtitle">$subtitle</h4>
  <adbl-sample-button variant="simple" placement="list" size="sm" label="Aperçu" data-asin="$asin" icon="play"></adbl-sample-button>
  <div slot="buy-box">
    <adbl-product-metadata slot="metadata" aria-label="Détails Produit">
      <script type="application/json">$json</script>
    </adbl-product-metadata>
  </div>
</adbl-product-row>
HTML;
    }

    public function testParsesSeriesRowFromCurrentMarkup(): void
    {
        $json = <<<'JSON'
{"authors":[{"name":"J. R. R. Tolkien"}],"narrators":[{"name":"Thierry Janssen"}],"duration":"20 h et 52 min","language":"Français","rating":{"value":4.8,"count":4631}}
JSON;

        $parser = new AudibleSearchParser(
            $this->row('La fraternité de l&#039;anneau', 'Le seigneur des anneaux 1', 'B0791DND3Z', $json),
        );

        $book = $parser->createBook();

        self::assertSame('B0791DND3Z', $book->getScrapId());
        self::assertSame('La fraternité de l\'anneau', $book->getTitle());
        self::assertSame('Le seigneur des anneaux', $book->getSaga());
        self::assertSame('1', $book->getTome());
        self::assertSame('J. R. R. Tolkien', $book->getAuthor());
        self::assertSame(['Thierry Janssen'], $book->getNarrators());
        self::assertSame('20:52', $book->getRuntime());
        self::assertSame(4.8, $book->getRatings());

        // Vignette SL240 réécrite en 500 px.
        self::assertSame('https://m.media-amazon.com/images/I/couv._SL500_.jpg', $book->getCover());
    }

    public function testSplitsMultipleRowsFromCurrentMarkup(): void
    {
        $rowOne = $this->row('Livre un', 'Saga essai 1', 'B000000001', '{"narrators":[{"name":"N1"}]}');
        $rowTwo = $this->row('Livre deux', 'Saga essai 2', 'B000000002', '{"narrators":[{"name":"N2"}]}');

        $parser = new AudibleSearchParser('<div class="adbl-impression-container" data-widget="productList">' . $rowOne . $rowTwo . '</div>');

        $books = $parser->getBooks();

        self::assertCount(2, $books);
        self::assertSame('Livre un', $books[0]->getTitle());
        self::assertSame('B000000001', $books[0]->getScrapId());
        self::assertSame('Livre deux', $books[1]->getTitle());
        self::assertSame('B000000002', $books[1]->getScrapId());
    }

    public function testParsesSeriesFixtureWithCurrentMarkup(): void
    {
        if (!is_file(self::TOWERS_SERIES_FIXTURE)) {
            self::markTestSkipped('fixture audible-series-towers.html non présente (gitignorée).');
        }

        $parser = new AudibleSearchParser((string) file_get_contents(self::TOWERS_SERIES_FIXTURE));

        // La page série liste la saga complète : le filtrage narrateur est désactivé.
        $books = $parser->getBooks(false);

        self::assertCount(3, $books);
        self::assertSame(['1', '2', '3'], array_map(fn ($book) => $book->getTome(), $books));
        self::assertSame('B0791DND3Z', $books[0]->getScrapId());

        $towers = $books[1];

        self::assertSame('Les deux tours', $towers->getTitle());
        self::assertSame('Le seigneur des anneaux', $towers->getSaga());
        self::assertSame('J. R. R. Tolkien', $towers->getAuthor());
        self::assertSame(['Thierry Janssen'], $towers->getNarrators());
        self::assertSame('18:09', $towers->getRuntime());
        self::assertSame(4.9, $towers->getRatings());
        self::assertStringContainsString('_SL500_', $towers->getCover());
    }

    private function fragment(string $subtitle = '', string $series = '', string $seriesRest = '', string $rating = ''): string
    {
        $html = '';

        if ($subtitle !== '') {
            $html .= '<li class="bc-list-item' . "\n\t" . 'subtitle" >';
            $html .= '<span class="bc-text' . "\n\n" . '    bc-size-base' . "\n\n" . '    bc-color-secondary"  >' . $subtitle . '</span>';
            $html .= '</li>';
        }

        if ($rating !== '') {
            $html .= '<li class="bc-list-item' . "\n\t" . 'ratingsLabel" tabindex=\'-1\'>';
            $html .= '<span class="bc-text' . "\n\n" . '    bc-size-callout" style="vertical-align: text-top"' . "\n" . '  aria-hidden=\'true\' aria-hidden=\'true\'>' . $rating . '</span>';
            $html .= '<div aria-label="5,0 out of 5 stars" role="img" class="bc-review-stars"></div>';
            $html .= '<span class="bc-text bc-size-callout bc-color-secondary">' . ($rating !== 'Pas de notations' ? '6 notations' : 'Pas de notations') . '</span>';
            $html .= '</li>';
        }

        if ($series !== '') {
            $html .= '<li class="bc-list-item' . "\n\t" . 'seriesLabel" >';
            $html .= '<span class="bc-text' . "\n\n" . '    bc-size-small' . "\n\n" . '    bc-color-secondary"  >Série :' . "\n\n" . '  <!-- comment -->' . "\n\n";
            $html .= '<a class="bc-link' . "\n\n" . '    bc-color-link" tabindex="0" href="#' . '">' . $series . '</a>' . $seriesRest;
            $html .= '</span></li>';
        }

        return $html;
    }
}