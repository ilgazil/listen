<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\BookRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Transliterator;

#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(paginationEnabled: false),
    ],
)]
class Book
{
    #[ORM\Id]
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['book:read'])]
    private ?string $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['book:read'])]
    private ?string $scraper = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['book:read'])]
    private ?string $scrap_id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['book:read'])]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    #[Groups(['book:read'])]
    private ?string $cover = null;

    #[ORM\Column(length: 255)]
    #[Groups(['book:read'])]
    private ?string $author = null;

    #[ORM\Column(length: 255)]
    private ?string $narrators = null;

    #[ORM\Column(length: 15, nullable: true)]
    #[Groups(['book:read'])]
    private ?string $runtime = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['book:read'])]
    private ?float $ratings = null;

    #[ORM\Column(length: 255)]
    #[Groups(['book:read'])]
    private ?string $saga = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['book:read'])]
    private ?string $tome = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getScraper(): ?string
    {
        return $this->scraper;
    }

    public function setScraper(?string $scraper): static
    {
        $this->scraper = $scraper;

        return $this;
    }

    public function getScrapId(): ?string
    {
        return $this->scrap_id;
    }

    public function setScrapId(?string $scrap_id): static
    {
        $this->scrap_id = $scrap_id;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function setCover(string $cover): static
    {
        $this->cover = $cover;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(string $author): static
    {
        $this->author = $author;

        return $this;
    }

    #[Groups(['book:read'])]
    public function getNarrators(): array
    {
        return array_map(
            'trim',
            array_filter(explode(',', $this->narrators ?? '')),
        );
    }

    public function setNarrators(array $narrators): static
    {
        $this->narrators = implode(',', array_map('trim', $narrators));

        return $this;
    }

    public function getRuntime(): ?string
    {
        return $this->runtime;
    }

    public function setRuntime(?string $runtime): static
    {
        $this->runtime = $runtime;

        return $this;
    }

    public function getRatings(): ?float
    {
        return $this->ratings;
    }

    public function setRatings(?float $ratings): static
    {
        $this->ratings = $ratings;

        return $this;
    }

    public function getSaga(): ?string
    {
        return $this->saga;
    }

    public function setSaga(string $saga): static
    {
        $this->saga = $saga;

        return $this;
    }

    public function getTome(): ?string
    {
        return $this->tome;
    }

    public function setTome(?string $tome): static
    {
        $this->tome = $tome;

        return $this;
    }

    #[Ignore]
    public function getFilename(): string
    {
        $baseName = implode(' - ', array_filter([
            $this->author ?? '',
            $this->saga ?? '',
            $this->tome ?? '',
            $this->title ?? '',
        ]));

        $unaccentedName = Transliterator
            ::createFromRules(
                ':: Any-Latin; :: Latin-ASCII; :: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;',
                Transliterator::FORWARD,
            )
            ->transliterate($baseName);

        $normalizedName = preg_replace(
            ['/[^\w\s]/', '/\s+/', '/\s/'],
            [' ', ' ', '-'],
            $unaccentedName,
        );

        return strtolower($normalizedName . '.zip');
    }
}
