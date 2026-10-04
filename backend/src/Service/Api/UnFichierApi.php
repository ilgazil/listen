<?php

namespace App\Service\Api;

use anlutro\cURL\cURL;
use App\Service\Storage\FileStorageInterface;
use Psr\Log\LoggerInterface;

class UnFichierApi implements FileStorageInterface
{
    private const MAX_INDEXING_ATTEMPTS = 30;
    private const INDEXING_ATTEMPT_COOLDOWN = 3;
    private const LISTING_PAGE_SIZE = 100;
    private const MAX_LISTING_PAGES = 100;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function download(string $id): string
    {
        $infos = json_decode(
            (new cURL())
                ->newJsonRequest(
                    'post',
                    'https://api.1fichier.com/v1/download/get_token.cgi',
                    [
                        'url' => 'https://1fichier.com/?' . $id,
                        'pretty' => '1',
                    ],
                )
                ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
                ->send()
                ->body,
        );

        if ($infos->status !== 'OK') {
            return '';
        }

        return $infos->url;
    }

    public function rename(string $id, string $name): void
    {
        $response = (new cURL())
            ->newJsonRequest(
                'post',
                'https://api.1fichier.com/v1/file/rename.cgi',
                [
                    'urls' => [
                        [
                            'url' => 'https://1fichier.com/?' . $id,
                            'filename' => $name,
                        ],
                    ],
                    'pretty' => '1',
                ],
            )
            ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
            ->send();

        $infos = json_decode($response->body);

        if (($infos->status ?? '') !== 'OK') {
            throw new \RuntimeException('1Fichier rename failed for ' . $id . '.');
        }
    }

    /**
     * Liste tous les fichiers du dossier de l'application, paginé sur l'API.
     *
     * @return array<object> fichier brut renvoyé par ls.cgi
     */
    protected function listFiles(): array
    {
        $files = [];
        $page = 1;

        do {
            $infos = json_decode(
                (new cURL())
                    ->newJsonRequest(
                        'post',
                        'https://api.1fichier.com/v1/file/ls.cgi',
                        [
                            'folder_id' => $_ENV['API_1FICHIER_APP_FOLDER_ID'],
                            'page' => $page,
                            'limit' => self::LISTING_PAGE_SIZE,
                            'pretty' => '1',
                        ],
                    )
                    ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
                    ->send()
                    ->body,
            );

            if (($infos->status ?? '') !== 'OK') {
                throw new \RuntimeException('1Fichier list failed.');
            }

            $records = $infos->items ?? [];
            $files = array_merge($files, $records);
            $page++;
        } while (count($records) === self::LISTING_PAGE_SIZE && $page <= self::MAX_LISTING_PAGES);

        return $files;
    }

    /**
     * Renvoie les fichiers du dossier de l'application dont le hash d'accès n'est
     * pas un identifiant de livre déjà enregistré en base.
     *
     * @param array<int, string> $existingIds les id (hash 1Fichier) des livres connus
     *
     * @return array<int, array{id: string, name: string, size: int, date: string}>
     */
    public function getOrphans(array $existingIds): array
    {
        $orphans = [];

        foreach ($this->listFiles() as $fileInfo) {
            $id = $this->extractAccessId($fileInfo->url ?? '');

            if ($id !== null && !in_array($id, $existingIds, true)) {
                $orphans[] = [
                    'id' => $id,
                    'name' => $fileInfo->filename ?? '',
                    'size' => (int) ($fileInfo->size ?? 0),
                    'date' => (string) ($fileInfo->date ?? ''),
                ];
            }
        }

        return $orphans;
    }

    /**
     * Renvoie les identifiants d'accès de tous les fichiers du dossier
     * applicatif.
     *
     * @return array<int, string> les hash d'accès 1Fichier des fichiers listés
     */
    public function getStoredIds(): array
    {
        $ids = [];

        foreach ($this->listFiles() as $fileInfo) {
            $id = $this->extractAccessId($fileInfo->url ?? '');

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function remove(string $id): void
    {
        $response = (new cURL())
            ->newJsonRequest(
                'post',
                'https://api.1fichier.com/v1/file/rm.cgi',
                [
                    'files' => [
                        [
                            'url' => 'https://1fichier.com/?' . $id,
                        ],
                    ],
                    'pretty' => '1',
                ],
            )
            ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
            ->send();

        $infos = json_decode($response->body);

        if (($infos->status ?? '') !== 'OK') {
            throw new \RuntimeException('1Fichier remove failed for ' . $id . '.');
        }
    }

    private function extractAccessId(string $url): ?string
    {
        preg_match_all('/\w+$/m', $url, $matches);

        return $matches[0][0] ?? null;
    }

    public function upload(string $path, string $mimeType, string $name): string
    {
        $endpoint = json_decode(
            (new cURL())
                ->jsonPost('https://api.1fichier.com/v1/upload/get_upload_server.cgi', ['pretty' => '1'])
                ->body,
        );

        $this->logger->debug('1Fichier ' . $name . ' upload : Got endpoint ' . $endpoint->id . ':' . $endpoint->url);

        (new cURL())
            ->newRawRequest(
                'post',
                'https://' . $endpoint->url . '/upload.cgi?id=' . $endpoint->id,
                [
                    'did' => $_ENV['API_1FICHIER_APP_FOLDER_ID'],
                    'file[]' => curl_file_create(
                        $path,
                        $mimeType,
                        $name,
                    ),
                ],
            )
            ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
            ->send();

        $this->logger->debug('1Fichier ' . $name . ' upload : complete');

        return $this->getDownloadId($name);
    }

    protected function getDownloadId($name): string
    {
        $id = '';
        $attempts = 0;

        do {
            $attempts++;

            if ($attempts > self::MAX_INDEXING_ATTEMPTS) {
                throw new \RuntimeException(
                    'File not indexed on 1Fichier after ' . self::MAX_INDEXING_ATTEMPTS . ' attempts.',
                );
            }

            $infos = json_decode(
                (new cURL())
                    ->newJsonRequest(
                        'post',
                        'https://api.1fichier.com/v1/file/ls.cgi',
                        [
                            'folder_id' => $_ENV['API_1FICHIER_APP_FOLDER_ID'],
                            'pretty' => '1'
                        ],
                    )
                    ->setHeader('Authorization', 'Bearer ' . $_ENV['API_1FICHIER_TOKEN'])
                    ->send()
                    ->body,
            );

            if (!empty($infos->items)) {
                foreach ($infos->items as $fileInfo) {
                    if ($fileInfo->filename === $name) {
                        $id = $this->extractAccessId($fileInfo->url ?? '') ?? '';
                    }
                }
            }

            if (!$id) {
                $this->logger->debug('1Fichier ' . $name . ' indexing : not found');

                sleep(self::INDEXING_ATTEMPT_COOLDOWN);
            }
        } while (!$id);

        $this->logger->debug('1Fichier ' . $name . ' indexing : found id ' . $id);

        return $id;
    }
}
