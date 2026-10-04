<?php

namespace App\Controller;

use App\Entity\Book;
use App\Form\BookType;
use App\Service\Notification\Notifier;
use App\Service\Storage\FileStorageInterface;
use App\Service\Uploading\LocalFileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class ApiController extends AbstractController
{
    public function __construct(
        private readonly FileStorageInterface $unFichierApi,
        private readonly LocalFileUploader $uploader,
        private readonly EntityManagerInterface $entityManager,
        private readonly Notifier $notifier,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/upload', name: 'api_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        // L'upload + l'indexation 1Fichier sont longs et bloquants : ne pas tuer le script
        // (PHP_FASTCGI / max_execution_time de l'hébergeur) pendant l'opération.
        set_time_limit(0);

        try {
            return $this->doUpload($request);
        } catch (\Throwable $e) {
            $this->logger->error('upload failed - ' . $e->getMessage());

            return new JsonResponse(
                ['error' => 'Upload failed. ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function doUpload(Request $request): JsonResponse
    {
        $book = new Book();
        $form = $this->createForm(BookType::class, $book);
        $form->handleRequest($request);

        $reference = trim(($book->getScraper() ?? '') . '#' . ($book->getScrapId() ?? ''));

        if (!$form->isSubmitted() || !$form->isValid()) {
            $messages = [];
            foreach ($form->getErrors(true) as $error) {
                $messages[] = $error->getMessage();
            }
            $this->logger->debug($reference . ' : invalid form');

            return new JsonResponse(
                ['error' => 'Invalid form: ' . implode(' | ', $messages)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $book
            ->setNarrators($this->parseNarrators($form->get('narrators')->getData()))
            ->setRatings($this->parseRatings($form->get('ratings')->getData()));

        /** @var UploadedFile|null $file */
        $file = $form->get('file')->getData();

        if (!$file) {
            return new JsonResponse(['error' => 'A ZIP file is required.'], Response::HTTP_BAD_REQUEST);
        }

        $path = $this->uploader->getTargetDirectory() . DIRECTORY_SEPARATOR . $this->uploader->upload($file);

        try {
            $book->setId($this->unFichierApi->upload($path, $file->getClientMimeType(), $book->getFilename()));
        } catch (\Throwable $e) {
            $this->logger->error($reference . ' : 1Fichier upload failed - ' . $e->getMessage());
            $this->deleteLocalFile($path);

            return new JsonResponse(['error' => 'Upload to 1Fichier failed.'], Response::HTTP_BAD_GATEWAY);
        }

        $this->logger->debug($reference . ' : upload complete, id=' . $book->getId());
        $this->deleteLocalFile($path);

        try {
            $this->entityManager->persist($book);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error($reference . ' : persist failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Failed to store the book.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->debug($reference . ' : indexed in database');

        try {
            $this->notifier->available($book);
        } catch (\Throwable $e) {
            $this->logger->error($reference . ' : Discord notification failed - ' . $e->getMessage());
        }

        return new JsonResponse($this->serializeBook($book), Response::HTTP_CREATED);
    }

    #[Route('/api/books/{id}', name: 'api_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $book = $this->entityManager->getRepository(Book::class)->find($id);

        if (!$book) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $oldFilename = $book->getFilename();

        $error = $this->applyPayload($book, $data);

        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $newFilename = $book->getFilename();

        if ($oldFilename !== $newFilename) {
            try {
                $this->unFichierApi->rename($book->getId(), $newFilename);
            } catch (\Throwable $e) {
                $this->logger->error($book->getId() . ' : 1Fichier rename failed - ' . $e->getMessage());

                return new JsonResponse(['error' => 'Upload to 1Fichier failed.'], Response::HTTP_BAD_GATEWAY);
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error($book->getId() . ' : persist failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Failed to store the book.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($this->serializeBook($book), Response::HTTP_OK);
    }

    #[Route('/api/orphans', name: 'api_orphans', methods: ['GET'])]
    public function orphans(): JsonResponse
    {
        $existingIds = $this->entityManager->getRepository(Book::class)->findIds();

        try {
            $orphans = $this->unFichierApi->getOrphans($existingIds);
        } catch (\Throwable $e) {
            $this->logger->error('orphans listing failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Unable to list orphan files.'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($orphans, Response::HTTP_OK);
    }

    #[Route('/api/orphans/{id}', name: 'api_fix_orphan', methods: ['POST'])]
    public function fixOrphan(string $id, Request $request): JsonResponse
    {
        $book = $this->entityManager->getRepository(Book::class)->find($id);

        if ($book) {
            return new JsonResponse(['error' => 'This file is already registered.'], Response::HTTP_CONFLICT);
        }

        try {
            $isOrphan = $this->isOrphanFile($id);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Unable to list orphan files.'], Response::HTTP_BAD_GATEWAY);
        }

        if (!$isOrphan) {
            return new JsonResponse(['error' => 'Orphan file not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $book = (new Book())
            ->setId($id)
            ->setTitle('')
            ->setCover('')
            ->setAuthor('')
            ->setSaga('')
            ->setTome('')
            ->setRuntime('')
            ->setScraper('')
            ->setScrapId('')
            ->setNarrators([]);

        $error = $this->applyPayload($book, $data);

        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        if (trim((string) $book->getTitle()) === '') {
            return new JsonResponse(['error' => 'Le titre est requis.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->persist($book);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error($id . ' : persist failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Failed to store the book.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->notifier->available($book);

        return new JsonResponse($this->serializeBook($book), Response::HTTP_CREATED);
    }

    #[Route('/api/orphans/{id}', name: 'api_delete_orphan', methods: ['DELETE'])]
    public function deleteOrphan(string $id): Response
    {
        try {
            $isOrphan = $this->isOrphanFile($id);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Unable to list orphan files.'], Response::HTTP_BAD_GATEWAY);
        }

        if (!$isOrphan) {
            return new JsonResponse(['error' => 'Orphan file not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->unFichierApi->remove($id);
        } catch (\Throwable $e) {
            $this->logger->error($id . ' : orphan removal failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Removal from provider failed.'], Response::HTTP_BAD_GATEWAY);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/intruders', name: 'api_intruders', methods: ['GET'])]
    public function intruders(): JsonResponse
    {
        $books = $this->entityManager->getRepository(Book::class)->findAll();

        try {
            $storedIds = $this->unFichierApi->getStoredIds();
        } catch (\Throwable $e) {
            $this->logger->error('intruders listing failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Unable to list stored files.'], Response::HTTP_BAD_GATEWAY);
        }

        $intruders = array_values(array_filter(
            $books,
            static fn (Book $book): bool => $book->getId() !== null && !in_array($book->getId(), $storedIds, true),
        ));

        return new JsonResponse(array_map([$this, 'serializeBook'], $intruders), Response::HTTP_OK);
    }

    #[Route('/api/intruders/{id}', name: 'api_delete_intruder', methods: ['DELETE'])]
    public function deleteIntruder(string $id): Response
    {
        $book = $this->entityManager->getRepository(Book::class)->find($id);

        if (!$book) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $storedIds = $this->unFichierApi->getStoredIds();
        } catch (\Throwable $e) {
            $this->logger->error('intruders listing failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Unable to list stored files.'], Response::HTTP_BAD_GATEWAY);
        }

        if (in_array($id, $storedIds, true)) {
            return new JsonResponse(['error' => 'This file is still present on 1Fichier.'], Response::HTTP_CONFLICT);
        }

        try {
            $this->entityManager->remove($book);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error($id . ' : book removal failed - ' . $e->getMessage());

            return new JsonResponse(['error' => 'Failed to remove the book.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/report-upload-cut', name: 'api_report_upload_cut', methods: ['POST'])]
    public function reportUploadCut(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $book = (new Book())
            ->setTitle('')
            ->setCover('')
            ->setAuthor('')
            ->setSaga('')
            ->setTome('')
            ->setRuntime('')
            ->setScraper('')
            ->setScrapId('')
            ->setNarrators([]);

        $error = $this->applyPayload($book, $data);

        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        if (trim((string) $book->getTitle()) === '') {
            return new JsonResponse(['error' => 'Le titre est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $this->notifier->uploadCut($book);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/download/{id}', name: 'api_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        $book = $this->entityManager->getRepository(Book::class)->find($id);

        if (!$book) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        $url = $this->unFichierApi->download($book->getId());

        if (!$url) {
            $this->notifier->error(new \RuntimeException('Unable to retrieve download link for ' . $book->getId()));

            return new JsonResponse(['error' => 'Unable to retrieve link from host.'], Response::HTTP_BAD_GATEWAY);
        }

        return new RedirectResponse($url, Response::HTTP_FOUND);
    }

    private function isOrphanFile(string $id): bool
    {
        $existingIds = $this->entityManager->getRepository(Book::class)->findIds();

        try {
            $orphans = $this->unFichierApi->getOrphans($existingIds);
        } catch (\Throwable $e) {
            $this->logger->error('orphans listing failed - ' . $e->getMessage());

            throw $e;
        }

        return in_array($id, array_column($orphans, 'id'), true);
    }

    /**
     * Applique un payload JSON (mise à jour / création d'orphelin) sur un livre.
     *
     * @return string|null message d'erreur si le payload est invalide, sinon null
     */
    private function applyPayload(Book $book, array $data): ?string
    {
        foreach (['title', 'cover', 'author', 'saga', 'tome', 'runtime', 'scraper', 'scrap_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
                $book->{$setter}((string) $data[$field]);
            }
        }

        if (array_key_exists('narrators', $data)) {
            $narrators = $data['narrators'];

            if (!is_array($narrators)) {
                return 'Invalid narrators.';
            }

            $book->setNarrators(array_map('strval', $narrators));
        }

        if (array_key_exists('ratings', $data)) {
            $book->setRatings($data['ratings'] === null ? null : (float) $data['ratings']);
        }

        return null;
    }

    private function serializeBook(Book $book): array
    {
        return $this->serializer->normalize($book, null, [
            'groups' => ['book:read'],
            'iri' => false,
            'skip_null_values' => true,
            'api_platform_disable' => true,
        ]);
    }

    private function parseNarrators(string $csv): array
    {
        return preg_split('/\s*,\s*/', trim($csv), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function parseRatings(string $ratings): ?float
    {
        $ratings = trim($ratings);

        return $ratings === '' ? null : (float) $ratings;
    }

    private function deleteLocalFile(string $path): void
    {
        $real = realpath($path);

        if ($real !== false && is_file($real)) {
            unlink($real);
        }
    }
}
