<?php

namespace App\Tests;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de déploiement : le lock doit rester installable sur le PHP de
 * production (Infomaniak), version épinglée dans `composer.json`
 * (`config.platform.php`).
 *
 * C'est un lock résolu avec un PHP plus récent que la prod qui génère un
 * `vendor/composer/platform_check.php` refusant de démarrer l'application en
 * production (« Composer detected issues in your platform »). Le pin force la
 * résolution à rester compatible ; ce test vérifie qu'il n'a pas sauté.
 */
class ComposerPlatformTest extends TestCase
{
    public function testPlatformIsPinnedToProductionPhp(): void
    {
        $config = $this->composerJson();

        self::assertArrayHasKey(
            'php',
            $config['config']['platform'] ?? [],
            'Le pin config.platform.php a disparu de composer.json : une mise à jour résoudrait des dépendances incompatibles avec la prod. Rétablir (ex. « composer config platform.php 8.5.0 ») et bumper uniquement quand le PHP web de prod monte.',
        );
    }

    public function testLockedRuntimePackagesSupportPinnedPlatform(): void
    {
        $pin = $this->pinnedPhp();

        $lock = $this->composerLock();

        foreach ($lock['packages'] as $package) {
            $php = $package['require']['php'] ?? null;

            if ($php === null) {
                continue;
            }

            self::assertTrue(
                Semver::satisfies($pin, $php),
                sprintf(
                    '%s %s exige PHP « %s », incompatible avec la plateforme épinglée (%s) : le déploiement casserait sur ce paquet.',
                    $package['name'],
                    $package['version'],
                    $php,
                    $pin,
                ),
            );
        }
    }

    public function testPinnedPlatformSatisfiesProjectRequirement(): void
    {
        // Le pin doit rester cohérent avec le « require.php » du projet : sinon
        // composer lui-même refuse de fonctionner sur la plateforme cible.
        $pin = $this->pinnedPhp();
        $require = $this->composerJson()['require']['php'] ?? '';

        self::assertTrue(
            Semver::satisfies($pin, $require),
            sprintf('Le pin plateforme %s ne satisfait plus « require.php: %s » de composer.json.', $pin, $require),
        );
    }

    public function testCooldownPolicyWithholdsFreshReleases(): void
    {
        $cooldown = $this->composerJson()['config']['policy']['cooldown'] ?? [];

        self::assertArrayHasKey(
            'period',
            $cooldown,
            'La politique cooldown a disparu de composer.json : un composer update réinstallerait des versions publiées depuis quelques heures (risque supply chain — miroir du min-release-age du frontend/.npmrc). Rétablir config.policy.cooldown.period (« 5 days », Composer >= 2.11).',
        );
    }

    private function pinnedPhp(): string
    {
        $config = $this->composerJson();

        return (string) $config['config']['platform']['php'];
    }

    private function composerJson(): array
    {
        return (array) json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
    }

    private function composerLock(): array
    {
        return (array) json_decode((string) file_get_contents(__DIR__ . '/../composer.lock'), true);
    }
}
