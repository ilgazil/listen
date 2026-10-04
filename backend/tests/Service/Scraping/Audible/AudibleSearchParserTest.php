<?php

namespace App\Tests\Service\Scraping\Audible;

use App\Service\Scraping\Audible\AudibleSearchParser;
use PHPUnit\Framework\TestCase;

class AudibleSearchParserTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../Fixtures/audible-search.html';

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