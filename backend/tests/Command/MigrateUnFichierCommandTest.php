<?php

namespace App\Tests\Command;

use App\Command\MigrateUnFichierCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateUnFichierCommandTest extends TestCase
{
    private string $previousToken;

    private string $previousFolder;

    protected function setUp(): void
    {
        // Credentials du compte source : substitués pour l'occasion
        // (.env.local absent en test).
        $this->previousToken = (string) ($_ENV['API_1FICHIER_TOKEN'] ?? '');
        $this->previousFolder = (string) ($_ENV['API_1FICHIER_APP_FOLDER_ID'] ?? '');

        $_ENV['API_1FICHIER_TOKEN'] = 'source-token';
        $_ENV['API_1FICHIER_APP_FOLDER_ID'] = '42';
    }

    protected function tearDown(): void
    {
        if ($this->previousToken === '') {
            unset($_ENV['API_1FICHIER_TOKEN']);
        } else {
            $_ENV['API_1FICHIER_TOKEN'] = $this->previousToken;
        }

        if ($this->previousFolder === '') {
            unset($_ENV['API_1FICHIER_APP_FOLDER_ID']);
        } else {
            $_ENV['API_1FICHIER_APP_FOLDER_ID'] = $this->previousFolder;
        }
    }

    public function testMigratesFilesAndUpdatesBookIds(): void
    {
        $updates = [];
        $uploads = [];

        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [42 => $this->bookFiles(), 123 => []],
            [123, true],
            static function (string $path, string $filename, ?int $folderId, string $token) use (&$uploads): array {
                $uploads[] = [$filename, $folderId];

                return ['new-' . $filename, 100];
            },
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder-name' => 'listen', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            [
                ['old' => 'aaaa1111', 'new' => 'new-livre-a.zip'],
                ['old' => 'bbbb2222', 'new' => 'new-livre-b.zip'],
            ],
            $updates,
        );
        // Les uploads partent dans le dossier créé sur le nouveau compte.
        self::assertSame([['livre-a.zip', 123], ['livre-b.zip', 123]], $uploads);
        self::assertStringContainsString('créé (id 123)', $tester->getDisplay());
        // L'id du dossier est restitué en fin de traitement, pour la bascule.
        self::assertStringContainsString('API_1FICHIER_APP_FOLDER_ID=123', $tester->getDisplay());
    }

    public function testDestinationFolderGivenByNameIsReusedWhenItExists(): void
    {
        $updates = [];
        $uploads = [];

        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [42 => $this->bookFiles(), 77 => []],
            [77, false],
            static function (string $path, string $filename, ?int $folderId, string $token) use (&$uploads): array {
                $uploads[] = [$filename, $folderId];

                return ['new-' . $filename, 100];
            },
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder-name' => 'listen', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([['livre-a.zip', 77], ['livre-b.zip', 77]], $uploads);
        self::assertStringContainsString('déjà présent (id 77)', $tester->getDisplay());
        self::assertStringContainsString('API_1FICHIER_APP_FOLDER_ID=77', $tester->getDisplay());
    }

    public function testDestinationFolderIdIsMandatoryAndExclusive(): void
    {
        $updates = [];
        $command = $this->createCommand($this->createEntityManager($updates), [], [123, true], null);
        $tester = new CommandTester($command);

        $tester->execute(['--new-token' => 't', '--new-folder' => '5', '--new-folder-name' => 'x', '--yes' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('exclusives', $tester->getDisplay());

        $tester->execute(['--new-token' => 't', '--yes' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('requis', $tester->getDisplay());

        $tester->execute(['--new-token' => 't', '--new-folder' => 'abc', '--yes' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('numérique', $tester->getDisplay());
    }

    public function testUploadFailureKeepsGoingAndLeavesDatabaseUntouched(): void
    {
        $updates = [];
        $uploads = [];

        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [42 => $this->bookFiles(), 99 => []],
            [123, true],
            static function (string $path, string $filename, ?int $folderId, string $token) use (&$uploads): array {
                $uploads[] = $filename;

                if ($filename === 'livre-a.zip') {
                    throw new \RuntimeException('File upload failed: [503] Error 503');
                }

                return ['new-' . $filename, 100];
            },
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder' => '99', '--yes' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Le premier fichier a échoué (aucune écriture DB) puis le suivant a été traité.
        self::assertSame(['livre-a.zip', 'livre-b.zip'], $uploads);
        self::assertSame([['old' => 'bbbb2222', 'new' => 'new-livre-b.zip']], $updates);
        self::assertStringContainsString('Error 503', $tester->getDisplay());
    }

    public function testUploadedSizeMismatchLeavesDatabaseUntouched(): void
    {
        $updates = [];

        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [42 => $this->bookFiles(), 99 => []],
            [123, true],
            static fn (): array => ['new-hash', 99],
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder' => '99', '--yes' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $updates);
        self::assertStringContainsString('mismatch', $tester->getDisplay());
    }

    public function testFilesAlreadyPresentOnDestinationAreSkippedAndRemapped(): void
    {
        $updates = [];
        $uploads = [];

        // Relance : livre-a.zip est déjà sur la destination (même nom + taille).
        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [
                42 => $this->bookFiles(),
                99 => [
                    (object) ['url' => 'https://1fichier.com/?deja0000', 'filename' => 'livre-a.zip', 'size' => 100, 'date' => 9],
                ],
            ],
            [123, true],
            static function (string $path, string $filename, ?int $folderId, string $token) use (&$uploads): array {
                $uploads[] = $filename;

                return ['new-' . $filename, 100];
            },
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder' => '99', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['livre-b.zip'], $uploads);
        // Le livre du fichier déjà présent est remappé vers le hash de destination.
        self::assertSame(
            [
                ['old' => 'aaaa1111', 'new' => 'deja0000'],
                ['old' => 'bbbb2222', 'new' => 'new-livre-b.zip'],
            ],
            $updates,
        );
        self::assertStringContainsString('déjà présent', $tester->getDisplay());
    }

    public function testRelaunchRemapsAllBooksWithoutReupload(): void
    {
        $updates = [];
        $uploads = [];

        // Reprise complète : les 311 fichiers sont déjà sur la destination
        // (échec d'indexation en cours de route), seul le remap reste à faire.
        $command = $this->createCommand(
            $this->createEntityManager($updates),
            [
                42 => $this->bookFiles(),
                99 => [
                    (object) ['url' => 'https://1fichier.com/?deja0000', 'filename' => 'livre-a.zip', 'size' => 100, 'date' => 9],
                    (object) ['url' => 'https://1fichier.com/?deja9999', 'filename' => 'livre-b.zip', 'size' => 100, 'date' => 10],
                ],
            ],
            [123, true],
            static function (string $path, string $filename, ?int $folderId, string $token) use (&$uploads): array {
                $uploads[] = $filename;

                return ['new-' . $filename, 100];
            },
        );

        $tester = new CommandTester($command);
        $tester->execute(['--new-token' => 'destination-token', '--new-folder' => '99', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $uploads);
        self::assertSame(
            [
                ['old' => 'aaaa1111', 'new' => 'deja0000'],
                ['old' => 'bbbb2222', 'new' => 'deja9999'],
            ],
            $updates,
        );
        self::assertStringContainsString('Rien à migrer', $tester->getDisplay());
        self::assertStringContainsString('API_1FICHIER_APP_FOLDER_ID=99', $tester->getDisplay());
    }

    /**
     * Commande instrumentée : listings 1Fichier simulés par dossier,
     * résolution du dossier destination paramétrable, uploads paramétrables,
     * téléchargements sans disque.
     *
     * @param array<int, array<int, object>> $folders contenus des dossiers (id → fichiers)
     * @param array{0: int, 1: bool}         $resolvedFolder retour de resolveDestinationFolder
     */
    private function createCommand(
        EntityManagerInterface $entityManager,
        array $folders,
        array $resolvedFolder,
        ?\Closure $upload,
    ): MigrateUnFichierCommand {
        return new class($entityManager, $folders, $resolvedFolder, $upload) extends MigrateUnFichierCommand {
            public function __construct(
                EntityManagerInterface $entityManager,
                private readonly array $folders,
                private readonly array $resolvedFolder,
                private readonly ?\Closure $upload,
                ?string $name = null,
            ) {
                parent::__construct($entityManager, $name);
            }

            protected function validateDestination(string $token, int $folderId): void
            {
            }

            protected function resolveDestinationFolder(string $token, string $name): array
            {
                return $this->resolvedFolder;
            }

            protected function listFolder(int $folderId, string $token): array
            {
                return $this->folders[$folderId] ?? [];
            }

            protected function getDownloadToken(string $fileId, string $token): string
            {
                return 'https://dl.1fichier.test/' . $fileId;
            }

            protected function downloadTo(string $url, string $destination): int
            {
                // Tous les fichiers de test pèsent 100 octets (taille du listing).
                return 100;
            }

            protected function uploadFile(string $path, string $filename, ?int $folderId, string $token): array
            {
                return ($this->upload)($path, $filename, $folderId, $token);
            }

            protected function tempPath(string $fileId): string
            {
                // Simulé : aucun fichier temporaire réel n'est écrit.
                return sys_get_temp_dir() . '/migrate-1fichier-test-' . $fileId . '.zip';
            }
        };
    }

    /** Connexion DBAL simulée qui enregistre les UPDATE book (old → new). */
    private function createEntityManager(array &$updates): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$updates): int {
                $updates[] = $params;

                return 1;
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    /** Deux fichiers du dossier applicatif, 100 octets chacun (taille des uploads simulés). */
    private function bookFiles(): array
    {
        return [
            (object) ['url' => 'https://1fichier.com/?aaaa1111', 'filename' => 'livre-a.zip', 'size' => 100, 'date' => 1],
            (object) ['url' => 'https://1fichier.com/?bbbb2222', 'filename' => 'livre-b.zip', 'size' => 100, 'date' => 2],
        ];
    }
}
