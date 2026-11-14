<?php

namespace App\Service\Scraping;

use RuntimeException;

/**
 * Le site source bloque le scrap : mur 503 anti rate-limiting épuisé après
 * retries (page d'erreur trop petite, sans status HTTP d'échec côté cURL).
 * Le message est destiné à être affiché tel quel sur le formulaire.
 */
class AntiBotException extends RuntimeException
{
}
