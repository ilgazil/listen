<?php

namespace App\Command;

use anlutro\cURL\cURL;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-1fichier',
    description: 'Migrate the 1Fichier storage to another account (download, re-upload, update book ids).',
)]
class MigrateUnFichierCommand extends Command
{
    private const LISTING_PAGE_SIZE = 100;
    private const MAX_LISTING_PAGES = 100;
    /** Patience d'attente d'indexation : sondage toutes les 30 s pendant ~10 min. */
    private const MAX_INDEXING_ATTEMPTS = 20;
    private const INDEXING_ATTEMPT_COOLDOWN = 30;

    public function __construct(
        protected EntityManagerInterface $entityManager,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        // Options décrites en français : la sortie --help fait partie de l'interface.
        $this
            ->addOption('new-token', null, InputOption::VALUE_REQUIRED, 'Clé API (Bearer) du compte de destination ; sinon demandée interactivement.')
            ->addOption('new-folder', null, InputOption::VALUE_REQUIRED, "Id d'un dossier existant du nouveau compte (alternative à --new-folder-name).")
            ->addOption('new-folder-name', null, InputOption::VALUE_REQUIRED, "Nom du dossier de destination, créé à la racine du nouveau compte s'il n'existe pas (alternative à --new-folder).")
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Sans confirmation interactive.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceToken = (string) ($_ENV['API_1FICHIER_TOKEN'] ?? '');

        if ($sourceToken === '') {
            $io->error('API_1FICHIER_TOKEN manquant : configure la clé API du compte SOURCE dans .env.local.');

            return Command::FAILURE;
        }

        $appFolderId = (int) ($_ENV['API_1FICHIER_APP_FOLDER_ID'] ?? 0);

        if ($appFolderId === 0) {
            $io->error("API_1FICHIER_APP_FOLDER_ID manquant : impossible de délimiter le dossier de l'application.");

            return Command::FAILURE;
        }

        // Dossier de destination : soit l'id d'un dossier existant, soit un nom
        // (le dossier sera créé à la racine du nouveau compte).
        $destinationFolder = trim((string) $input->getOption('new-folder'));
        $destinationFolderName = trim((string) $input->getOption('new-folder-name'));

        if ($destinationFolder !== '' && $destinationFolderName !== '') {
            $io->error("Options --new-folder et --new-folder-name exclusives : donner l'id OU le nom du dossier de destination.");

            return Command::FAILURE;
        }

        if ($destinationFolder === '' && $destinationFolderName === '') {
            $io->error('Dossier de destination requis : --new-folder=<id> ou --new-folder-name=<nom>.');

            return Command::FAILURE;
        }

        if ($destinationFolder !== '' && !ctype_digit($destinationFolder)) {
            $io->error(sprintf('Option --new-folder invalide : "%s" (id numérique attendu).', $destinationFolder));

            return Command::FAILURE;
        }

        $destinationFolderId = $destinationFolder !== '' ? (int) $destinationFolder : null;

        $destinationToken = (string) $input->getOption('new-token');

        if ($destinationToken === '') {
            $destinationToken = (string) $io->askHidden('Nouvelle clé API 1Fichier (Bearer)');

            if ($destinationToken === '') {
                $io->error('Clé API du compte de destination requise.');

                return Command::FAILURE;
            }
        }

        // Clé fausse ou dossier cible inexistant : détecté avant tout téléchargement.
        if ($destinationFolderId !== null) {
            try {
                $this->validateDestination($destinationToken, $destinationFolderId);
            } catch (\Throwable $e) {
                $io->error(sprintf('Dossier destination %d inaccessible : %s', $destinationFolderId, $e->getMessage()));

                return Command::FAILURE;
            }
        } else {
            // Dossier donné par son nom : créé à la racine du nouveau compte
            // s'il n'existe pas déjà (un dossier homonyme est réutilisé).
            try {
                // Le listing des dossiers de la racine valide la clé en même temps.
                [$destinationFolderId, $created] = $this->resolveDestinationFolder($destinationToken, $destinationFolderName);

                $io->writeln(sprintf(
                    '  Dossier destination « %s » : %s (id %d)',
                    $destinationFolderName,
                    $created ? 'créé' : 'déjà présent',
                    $destinationFolderId,
                ));
            } catch (\Throwable $e) {
                $io->error('Résolution du dossier destination impossible : ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        // Fichiers du dossier applicatif (source, lecture seule — le compte
        // source ne sera jamais modifié) et fichiers déjà présents côté
        // destination : un fichier (même nom + taille) déjà migré est sauté,
        // ce qui rend une relance après échec sans risque de doublon.
        try {
            $sourceFiles = $this->listFolder($appFolderId, $sourceToken);
            $destinationFiles = $this->listFolder($destinationFolderId, $destinationToken);
        } catch (\Throwable $e) {
            $io->error('Listing 1Fichier impossible : ' . $e->getMessage());

            return Command::FAILURE;
        }

        // Fichiers déjà présents sur la destination (même nom + taille) : on
        // garde leur hash de côté pour remapper les livres sans ré-upload
        // (reprise après une migration partielle ou un échec en cours de route).
        $present = [];

        foreach ($destinationFiles as $file) {
            $present[(string) ($file->filename ?? '') . '|' . (string) ($file->size ?? '')] = $this->extractAccessId((string) ($file->url ?? ''));
        }

        $todo = [];
        $skipped = 0;
        $booksUpdated = 0;
        $failures = 0;

        foreach ($sourceFiles as $file) {
            $filename = (string) ($file->filename ?? '?');
            $sourceId = $this->extractAccessId((string) ($file->url ?? ''));
            $key = (string) ($file->filename ?? '') . '|' . (string) ($file->size ?? '');

            if (!isset($present[$key])) {
                $todo[] = $file;
                continue;
            }

            $skipped++;

            // Le fichier est déjà sur la destination : reste à remapper le
            // livre, dont le hash d'accès a changé de compte.
            $destinationId = $present[$key];

            if ($sourceId === null || $destinationId === null || $destinationId === $sourceId) {
                $io->writeln(sprintf('  = %s (déjà présent sur la destination)', $filename));
                continue;
            }

            try {
                $updated = $this->updateBookId($sourceId, $destinationId);
            } catch (\Throwable $e) {
                $failures++;
                $io->writeln(sprintf('  <fg=red>✗</> %s — mise à jour du livre impossible : %s', $filename, $e->getMessage()));
                continue;
            }

            $booksUpdated += $updated ? 1 : 0;
            $io->writeln(sprintf('  = %s (déjà présent — livre %s)', $filename, $updated ? 'remappé' : 'à jour'));
        }

        if (count($todo) === 0) {
            $io->success(sprintf('Rien à migrer : les %d fichier(s) source sont déjà sur la destination (%d livre(s) remappé(s)).', $skipped, $booksUpdated));
            $this->printDestinationFolderId($io, $destinationFolderId);

            return Command::SUCCESS;
        }

        if (!$input->getOption('yes') && !$io->confirm(sprintf('Migrer %d fichier(s) vers le nouveau compte ? Le compte source reste intact.', count($todo)), false)) {
            $io->note('Migration abandonnée (aucune action).');

            return Command::SUCCESS;
        }

        $migrated = 0;

        foreach ($todo as $file) {
            $oldId = $this->extractAccessId((string) ($file->url ?? ''));
            $filename = (string) ($file->filename ?? 'sans-nom');
            $size = (int) ($file->size ?? 0);

            if ($oldId === null) {
                $io->warning(sprintf('Fichier sans identifiant 1Fichier, ignoré : %s', $filename));
                continue;
            }

            try {
                [$newId, $bookUpdated] = $this->migrateOne($oldId, $filename, $size, $sourceToken, $destinationToken, $destinationFolderId);

                $migrated++;
                $booksUpdated += $bookUpdated ? 1 : 0;

                $io->writeln(sprintf('  <fg=green>✓</> %s → %s', $filename, $newId));
            } catch (\Throwable $e) {
                $failures++;

                $io->writeln(sprintf('  <fg=red>✗</> %s — %s', $filename, $e->getMessage()));
            }
        }

        $io->text(sprintf(
            '%d fichier(s) déjà présents, %d migré(s) — %d livre(s) remappé(s) en base, %d échec(s).',
            $skipped,
            $migrated,
            $booksUpdated,
            $failures,
        ));

        if ($migrated > 0) {
            $io->note([
                "Le compte source est intact : rien n'y a été supprimé.",
                sprintf('Bascule dans backend/.env.local : API_1FICHIER_TOKEN=<nouvelle clé> + API_1FICHIER_APP_FOLDER_ID=%d', $destinationFolderId),
                'Puis vérifie /api/orphans (fichiers non référencés) et /api/intruders (livres sans fichier).',
            ]);
        }

        // Restitution de l'id du dossier du nouveau compte, pour mettre à jour
        // les paramètres du site.
        $this->printDestinationFolderId($io, $destinationFolderId);

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Migre un fichier : téléchargement depuis le compte source, ré-upload vers
     * le compte de destination, mise à jour de l'identifiant du livre en base
     * (le hash d'accès change de compte ; false = fichier orphelin, aucun
     * livre ne référence l'ancien hash).
     *
     * @return array{0: string, 1: bool} [nouveau hash d'accès, livre mis à jour]
     */
    protected function migrateOne(
        string $oldId,
        string $filename,
        int $size,
        string $sourceToken,
        string $destinationToken,
        ?int $destinationFolderId,
    ): array {
        // 1. Jeton de téléchargement côté source.
        $url = $this->getDownloadToken($oldId, $sourceToken);

        // 2. Téléchargement en streaming vers un fichier temporaire.
        $tempPath = $this->tempPath($oldId);
        $downloadedSize = $this->downloadTo($url, $tempPath);

        // 3. Taille téléchargée = taille annoncée par le listing source.
        if ($size > 0 && $downloadedSize !== $size) {
            throw new \RuntimeException(
                sprintf('Download size mismatch: %d bytes (expected %d)', $downloadedSize, $size),
            );
        }

        try {
            // 4. Ré-upload vers la destination + attente d'indexation.
            [$newId, $uploadedSize] = $this->uploadFile($tempPath, $filename, $destinationFolderId, $destinationToken);
        } finally {
            @unlink($tempPath);
        }

        // 5. Taille indexée = taille téléchargée.
        if ($uploadedSize > 0 && $uploadedSize !== $downloadedSize) {
            throw new \RuntimeException(
                sprintf('Uploaded size mismatch: %d bytes (expected %d)', $uploadedSize, $downloadedSize),
            );
        }

        // 6. Sur un autre compte le hash doit changer ; sinon l'upload est
        //    reparti sur le compte source (mauvaise clé).
        if ($newId === $oldId) {
            throw new \RuntimeException(sprintf('Destination returned the same access hash (%s), suspicious.', $newId));
        }

        return [$newId, $this->updateBookId($oldId, $newId)];
    }

    /** Vérifie la clé de destination et l'existence du dossier cible (0 = racine). */
    protected function validateDestination(string $token, int $folderId): void
    {
        $infos = $this->apiPost(
            'https://api.1fichier.com/v1/file/ls.cgi',
            [
                'folder_id' => $folderId,
                'page' => 1,
                'limit' => 1,
                'pretty' => '1',
            ],
            $token,
        );

        if (($infos->status ?? '') !== 'OK') {
            throw new \RuntimeException('listing refused');
        }
    }

    /**
     * Résout le dossier de destination par son nom : réutilise le dossier
     * homonyme à la racine du nouveau compte, sinon le crée (folder/mkdir.cgi).
     * Le listing des dossiers valide aussi la clé du nouveau compte.
     *
     * @return array{0: int, 1: bool} [id du dossier, créé ?]
     */
    protected function resolveDestinationFolder(string $token, string $name): array
    {
        $existing = $this->findFolderByName($token, $name);

        if ($existing !== null) {
            return [$existing, false];
        }

        $created = $this->apiPost(
            'https://api.1fichier.com/v1/folder/mkdir.cgi',
            [
                'name' => $name,
                'folder_id' => 0,
                'pretty' => '1',
            ],
            $token,
        );

        // L'API refuse la création d'un dossier homonyme : on relit une fois le
        // listing (le dossier a pu être créé entre-temps ou échapper à la
        // première lecture), sinon on échoue avec la réponse de l'API.
        if (($created->status ?? '') !== 'OK' || empty($created->folder_id)) {
            $existing = $this->findFolderByName($token, $name);

            if ($existing !== null) {
                return [$existing, false];
            }

            throw new \RuntimeException('folder creation refused: ' . mb_substr((string) json_encode($created), 0, 200));
        }

        return [(int) $created->folder_id, true];
    }

    /**
     * Cherche un dossier par son nom à la racine du compte (endpoint dédié
     * folder/ls.cgi : sous-dossiers dans « sub_folders », sans champ « status »).
     * Renvoie l'id du dossier trouvé, sinon null.
     */
    protected function findFolderByName(string $token, string $name): ?int
    {
        $infos = $this->apiPost(
            'https://api.1fichier.com/v1/folder/ls.cgi',
            [
                'folder_id' => 0,
                'pretty' => '1',
            ],
            $token,
        );

        if (!isset($infos->sub_folders) || !is_array($infos->sub_folders)) {
            throw new \RuntimeException('destination folder listing refused: ' . mb_substr((string) json_encode($infos), 0, 200));
        }

        foreach ($infos->sub_folders as $folder) {
            if (($folder->name ?? '') === $name) {
                return (int) $folder->id;
            }
        }

        return null;
    }

    /** Restitue l'id du dossier destination, pour la bascule des paramètres du site. */
    protected function printDestinationFolderId(SymfonyStyle $io, int $destinationFolderId): void
    {
        $io->success(sprintf('Dossier destination : id %d — API_1FICHIER_APP_FOLDER_ID=%d', $destinationFolderId, $destinationFolderId));
    }

    /**
     * Listing paginé d'un dossier 1Fichier.
     *
     * @return array<int, object> fichiers bruts renvoyés par ls.cgi
     */
    protected function listFolder(int $folderId, string $token): array
    {
        $items = [];
        $page = 1;

        do {
            $infos = $this->apiPost(
                'https://api.1fichier.com/v1/file/ls.cgi',
                [
                    'folder_id' => $folderId,
                    'page' => $page,
                    'limit' => self::LISTING_PAGE_SIZE,
                    'pretty' => '1',
                ],
                $token,
            );

            if (($infos->status ?? '') !== 'OK') {
                throw new \RuntimeException('1Fichier list failed for folder ' . $folderId);
            }

            foreach ($infos->items ?? [] as $item) {
                $items[] = $item;
            }

            $page++;
        } while (count($infos->items ?? []) === self::LISTING_PAGE_SIZE && $page <= self::MAX_LISTING_PAGES);

        return $items;
    }

    /** URL de téléchargement temporaire sur le compte source. */
    protected function getDownloadToken(string $fileId, string $token): string
    {
        $infos = $this->apiPost(
            'https://api.1fichier.com/v1/download/get_token.cgi',
            [
                'url' => 'https://1fichier.com/?' . $fileId,
                'pretty' => '1',
            ],
            $token,
        );

        if (($infos->status ?? '') !== 'OK' || empty($infos->url)) {
            throw new \RuntimeException('Download token refused for ' . $fileId);
        }

        return (string) $infos->url;
    }

    /**
     * Téléchargement en streaming (fichiers volumineux : rien n'est chargé en
     * mémoire). Renvoie la taille écrite.
     */
    protected function downloadTo(string $url, string $destination): int
    {
        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open destination file: ' . $destination);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Pas de timeout global (gros fichiers) ; une connexion bloquée est
            // coupée si le débit passe sous 1 o/s pendant 60 s.
            CURLOPT_TIMEOUT => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
        ]);
        curl_exec($curl);

        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($error !== '' || $statusCode >= 400) {
            @unlink($destination);

            throw new \RuntimeException('Download failed: [' . $statusCode . ']' . ($error !== '' ? ' ' . $error : ''));
        }

        clearstatcache();

        return (int) filesize($destination);
    }

    /**
     * Ré-uploade un fichier vers le compte de destination puis attend son
     * indexation (le listing destination porte la taille réelle).
     *
     * @return array{0: string, 1: int} [nouveau hash d'accès, taille indexée]
     */
    protected function uploadFile(string $path, string $filename, ?int $folderId, string $token): array
    {
        $response = (new cURL())
            ->jsonPost('https://api.1fichier.com/v1/upload/get_upload_server.cgi', ['pretty' => '1']);

        $endpoint = json_decode((string) $response->body);

        // 1Fichier renvoie parfois des erreurs en clair au lieu du JSON attendu.
        if (!isset($endpoint->id, $endpoint->url)) {
            throw new \RuntimeException('Upload server request failed: ' . $this->snippet($response->body, $response->statusCode));
        }

        $payload = [
            'file[]' => curl_file_create($path, null, $filename),
        ];

        if ($folderId !== null) {
            $payload['did'] = $folderId;
        }

        $upload = (new cURL())
            ->newRawRequest(
                'post',
                'https://' . $endpoint->url . '/upload.cgi?id=' . $endpoint->id,
                $payload,
            )
            ->setHeader('Authorization', 'Bearer ' . $token)
            ->send();

        // L'accusé de réception porte la vraie cause (ex. « Error 503 »).
        if (($upload->statusCode ?? 0) >= 400 || preg_match('/Error\s*\d+/i', (string) $upload->body)) {
            throw new \RuntimeException('File upload failed: ' . $this->snippet($upload->body, $upload->statusCode));
        }

        $body = trim((string) $upload->body);
        $decoded = json_decode($body);

        // L'accusé de réception est une URL brute (ou un JSON {url: …}) : il
        // porte déjà le hash du nouveau fichier — inutile d'attendre
        // l'indexation (les listings de gros dossiers sont servis avec du
        // retard, et l'API limite le listing d'un dossier). La taille réelle
        // a déjà été vérifiée au téléchargement ; 0 = taille non vérifiée ici.
        $newId = $this->extractAccessId(is_object($decoded) ? (string) ($decoded->url ?? '') : $body);

        if ($newId !== null) {
            return [$newId, 0];
        }

        // Accusé de réception sans URL exploitable : dernier recours, attendre
        // l'indexation en relisant le listing du dossier destination.
        return $this->waitForIndex($filename, $folderId, $token);
    }

    /**
     * Dernier recours quand l'accusé de réception n'a pas d'URL : attendre
     * l'indexation du fichier en relisant le listing du dossier destination.
     * L'indexation peut être lente et le listing d'un dossier est servi avec du
     * retard — on sonde toutes les 30 s, ~10 minutes maximum.
     *
     * @return array{0: string, 1: int} [hash d'accès, taille indexée]
     */
    protected function waitForIndex(string $filename, ?int $folderId, string $token): array
    {
        for ($attempt = 1; $attempt <= self::MAX_INDEXING_ATTEMPTS; $attempt++) {
            $items = $this->listFolder($folderId ?? 0, $token);

            foreach ($items as $item) {
                if (($item->filename ?? '') !== $filename) {
                    continue;
                }

                $itemId = $this->extractAccessId((string) ($item->url ?? ''));

                if ($itemId !== null) {
                    return [$itemId, (int) ($item->size ?? 0)];
                }
            }

            if ($attempt < self::MAX_INDEXING_ATTEMPTS) {
                sleep(self::INDEXING_ATTEMPT_COOLDOWN);
            }
        }

        throw new \RuntimeException(
            sprintf('File not indexed after %d attempts on destination account: %s', self::MAX_INDEXING_ATTEMPTS, $filename),
        );
    }

    /** POST JSON authentifié → objet décodé ; lève avec un extrait du corps si la réponse est inexploitable. */
    protected function apiPost(string $url, array $payload, string $token): object
    {
        $response = (new cURL())
            ->newJsonRequest('post', $url, $payload)
            ->setHeader('Authorization', 'Bearer ' . $token)
            ->send();

        $body = trim((string) $response->body);
        $infos = json_decode($body);

        // 1Fichier renvoie parfois des erreurs en clair (ex. « Error 503 ») :
        // extraire un maximum de contexte avant d'échouer.
        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException($url . ': ' . $this->snippet($body, $response->statusCode));
        }

        return $infos;
    }

    /** Extrait court du corps d'une réponse 1Fichier, pour les messages d'erreur. */
    protected function snippet(?string $body, ?int $statusCode): string
    {
        $body = trim((string) $body);

        if ($body === '') {
            $body = '(empty body)';
        }

        return '[' . ($statusCode ?? '?') . '] ' . mb_substr($body, 0, 200);
    }

    /** Met à jour l'identifiant 1Fichier d'un livre (hash d'accès changé de compte). */
    protected function updateBookId(string $oldId, string $newId): bool
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE book SET id = :new WHERE id = :old',
            ['old' => $oldId, 'new' => $newId],
        );

        return $affected > 0;
    }

    /** Fichier temporaire de passage (téléchargé puis ré-uploade) ; var/ est gitignoré. */
    protected function tempPath(string $fileId): string
    {
        $directory = $this->projectDir() . '/var/migration';

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create migration directory: ' . $directory);
        }

        return $directory . '/' . $fileId . '.zip';
    }

    /** Racine du projet (src/Command → remonte de 2 niveaux). */
    protected function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Hash d'accès depuis une URL de fichier 1Fichier (https://1fichier.com/?xxxx). */
    protected function extractAccessId(string $url): ?string
    {
        preg_match_all('/\w+$/m', $url, $matches);

        return $matches[0][0] ?? null;
    }
}
