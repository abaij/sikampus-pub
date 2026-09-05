<?php

namespace App\Services\Update;

/**
 * Perintah yang dipakai menjalankan git/composer/npm saat pembaruan jalur Git.
 *
 * Dibuat bisa dikonfigurasi karena server produksi lazim punya lebih dari satu versi PHP
 * (Ubuntu + PPA ondrej): `composer` di PATH bisa memakai PHP yang berbeda dari yang melayani
 * web, dan gejalanya menyesatkan — composer install berhenti dengan "your php version does not
 * satisfy that requirement" padahal PHP yang benar terpasang di server.
 *
 * Nilainya boleh lebih dari satu kata, mis. UPDATE_COMPOSER_COMMAND="/usr/bin/php8.4 /usr/local/bin/composer",
 * supaya composer bisa dipaksa memakai interpreter tertentu tanpa perlu wrapper script.
 */
class DeployCommand
{
    /**
     * @return list<string>
     */
    public static function git(): array
    {
        return static::split(env('UPDATE_GIT_BINARY', 'git'), 'git');
    }

    /**
     * @return list<string>
     */
    public static function composer(): array
    {
        return static::split(env('UPDATE_COMPOSER_COMMAND', 'composer'), 'composer');
    }

    /**
     * @return list<string>
     */
    public static function npm(): array
    {
        return static::split(env('UPDATE_NPM_COMMAND', 'npm'), 'npm');
    }

    /**
     * @return list<string>
     */
    private static function split(?string $configured, string $fallback): array
    {
        $parts = preg_split('/\s+/', trim((string) $configured), -1, PREG_SPLIT_NO_EMPTY);

        return $parts ?: [$fallback];
    }
}
