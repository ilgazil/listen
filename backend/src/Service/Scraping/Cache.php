<?php

namespace App\Service\Scraping;

trait Cache
{
    protected array $cache;

    protected function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    protected function set(string $key, mixed $value): self
    {
        $this->cache[$key] = $value;

        return $this;
    }

    protected function get(string $key): mixed
    {
        return $this->cache[$key] ?? '';
    }
}
