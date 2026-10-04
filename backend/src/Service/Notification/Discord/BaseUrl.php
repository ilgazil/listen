<?php

namespace App\Service\Notification\Discord;

/**
 * URL publique de l'application (schéma + hôte + préfixe de chemin), sans
 * slash final. Permet de construire des liens absolus sans concaténation
 * fragile ni URL en dur.
 */
final class BaseUrl
{
    private function __construct(private readonly string $base)
    {
    }

    public static function from(string $base): self
    {
        return new self(rtrim($base, '/'));
    }

    public function path(string $path): string
    {
        return $this->base . '/' . ltrim($path, '/');
    }
}