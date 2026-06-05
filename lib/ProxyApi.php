<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\ClientInstaller;

use rex_addon;
use rex_dir;
use rex_functional_exception;
use rex_socket;
use rex_socket_exception;

final class ProxyApi
{
    private rex_addon $addon;

    public function __construct(rex_addon $addon)
    {
        $this->addon = $addon;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPackages(): array
    {
        $data = $this->requestJson('api/v1/packages');
        $packages = $data['packages'] ?? [];

        return is_array($packages) ? $packages : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getVersions(string $owner, string $repo): array
    {
        $query = http_build_query([
            'owner' => $owner,
            'repo' => $repo,
        ]);

        $data = $this->requestJson('api/v1/versions&' . $query);
        $versions = $data['versions'] ?? [];

        return is_array($versions) ? $versions : [];
    }

    public function downloadArchive(string $owner, string $repo, string $ref): string
    {
        $query = http_build_query([
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $ref,
        ]);

        $lastError = 'Proxy-Download fehlgeschlagen.';

        foreach ($this->buildBaseUrls() as $baseUrl) {
            $url = $baseUrl . '/index.php?route=api/v1/download&' . $query;

            try {
                $socket = rex_socket::factoryUrl($url);
                $socket->setTimeout((int) $this->addon->getConfig('timeout', 20));
                $this->addAuthHeader($socket);
                $response = $socket->doGet();

                if (!$response->isOk()) {
                    $statusCode = $response->getStatusCode();
                    $lastError = 'Proxy-Download fehlgeschlagen (HTTP ' . ($statusCode !== null ? (string) $statusCode : '?') . ').';
                    continue;
                }

                $target = $this->addon->getCachePath('downloads/' . md5($owner . '/' . $repo . '/' . $ref) . '.zip');
                rex_dir::create(dirname($target));
                $response->writeBodyTo($target);

                return $target;
            } catch (rex_socket_exception $e) {
                $lastError = 'Proxy nicht erreichbar: ' . $e->getMessage();
            }
        }

        throw new rex_functional_exception($lastError);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $routeAndQuery): array
    {
        $lastError = 'Proxy liefert kein gültiges JSON.';

        foreach ($this->buildBaseUrls() as $baseUrl) {
            $url = $baseUrl . '/index.php?route=' . $routeAndQuery;

            try {
                $socket = rex_socket::factoryUrl($url);
                $socket->setTimeout((int) $this->addon->getConfig('timeout', 20));
                $this->addAuthHeader($socket);
                $response = $socket->doGet();
                if (!$response->isOk()) {
                    $statusCode = $response->getStatusCode();
                    $lastError = 'Proxy-Antwort fehlerhaft (HTTP ' . ($statusCode !== null ? (string) $statusCode : '?') . ').';
                    continue;
                }

                $data = json_decode($response->getBody(), true);
                if (!is_array($data)) {
                    $lastError = 'Proxy liefert kein gültiges JSON.';
                    continue;
                }

                return $data;
            } catch (rex_socket_exception $e) {
                $lastError = 'Proxy nicht erreichbar: ' . $e->getMessage();
            }
        }

        throw new rex_functional_exception($lastError);
    }

    private function addAuthHeader(rex_socket $socket): void
    {
        $token = trim((string) $this->addon->getConfig('api_token', ''));
        if ($token !== '') {
            $socket->addHeader('Authorization', 'Bearer ' . $token);
        }
    }

    /**
     * @return list<string>
     */
    private function buildBaseUrls(): array
    {
        $baseUrl = trim((string) $this->addon->getConfig('proxy_base_url', ''));
        if ($baseUrl === '') {
            throw new rex_functional_exception('Proxy-URL ist nicht konfiguriert.');
        }

        $baseUrl = rtrim($baseUrl, '/');
        $urls = [$baseUrl];

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        if ($path !== '' && rtrim($path, '/') === '/installer') {
            $fallback = preg_replace('@/installer$@', '', $baseUrl);
            if (is_string($fallback) && $fallback !== '' && !in_array($fallback, $urls, true)) {
                $urls[] = $fallback;
            }
        }

        return $urls;
    }
}
