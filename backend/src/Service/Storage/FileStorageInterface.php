<?php

namespace App\Service\Storage;

/**
 * Abstraction du stockage des fichiers audio de la bibliothèque.
 *
 * Les implémentations (ex. 1Fichier) exposent uniquement un contrat de
 * fichiers : dépôt, téléchargement, renommage, listing des fichiers (orphelins,
 * identifiants stockés) et suppression. Le reste de l'application ne connaît
 * que cette interface.
 */
interface FileStorageInterface
{
    /**
     * Dépose un fichier sur le stockage.
     *
     * @return string identifiant de téléchargement du fichier
     */
    public function upload(string $path, string $mimeType, string $name): string;

    /**
     * Tente d'obtenir une URL de téléchargement temporaire.
     *
     * @return string URL temporaire, vide si le fournisseur échoue
     */
    public function download(string $id): string;

    /**
     * Renomme un fichier sur le stockage.
     */
    public function rename(string $id, string $name): void;

    /**
     * Liste les fichiers du stockage qui ne correspondent à aucun identifiant
     * de livre connu.
     *
     * @param array<int, string> $existingIds les identifiants des livres connus
     *
     * @return array<int, array{id: string, name: string, size: int, date: string}>
     */
    public function getOrphans(array $existingIds): array;

    /**
     * Renvoie les identifiants d'accès des fichiers présents sur le stockage.
     *
     * @return array<int, string> les identifiants des fichiers listés
     */
    public function getStoredIds(): array;

    /**
     * Supprime définitivement un fichier du stockage.
     */
    public function remove(string $id): void;
}