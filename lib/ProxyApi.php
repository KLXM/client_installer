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
        $data = $this->requestJson('api/v1/versions', [
            'owner' => $owner,
            'repo' => $repo,
        ]);
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
        $token = trim((string) $this->addon->getConfig('api_token', ''));

        foreach ($this->buildBaseUrls() as $baseUrl) {
            $url = $baseUrl . '/index.php?route=api/v1/download&' . $query;

            if (function_exists('curl_init')) {
                $headers = [
                    'Accept: application/zip,application/octet-stream',
                    'User-Agent: REDAXO-ClientInstaller',
                    'X-Repo-Owner: ' . $owner,
                    'X-Repo-Name: ' . $repo,
                    'X-Repo-Ref: ' . $ref,
                ];

                if ($token !== '') {
                    $headers[] = 'Authorization: Bearer ' . $token;
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => (int) $this->addon->getConfig('timeout', 20),
                ]);

                $body = curl_exec($ch);
                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if (!is_string($body)) {
                    $lastError = 'Proxy nicht erreichbar: ' . ($curlError !== '' ? $curlError : 'Unbekannter cURL-Fehler');
                    continue;
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    $bodySnippet = trim(substr($body, 0, 180));
                    $lastError = 'Proxy-Download fehlgeschlagen (HTTP ' . (string) $statusCode . ')';
                    if ($bodySnippet !== '') {
                        $lastError .= ': ' . $bodySnippet;
                    } else {
                        $lastError .= '.';
                    }
                    continue;
                }

                $target = $this->addon->getCachePath('downloads/' . md5($owner . '/' . $repo . '/' . $ref) . '.zip');
                rex_dir::create(dirname($target));
                if (file_put_contents($target, $body) === false) {
                    $lastError = 'Download konnte nicht in den Cache geschrieben werden.';
                    continue;
                }

                return $target;
            }

            try {
                $socket = rex_socket::factoryUrl($url);
                $socket->setTimeout((int) $this->addon->getConfig('timeout', 20));
                $this->addAuthHeader($socket);
                $socket->addHeader('X-Repo-Owner', $owner);
                $socket->addHeader('X-Repo-Name', $repo);
                $socket->addHeader('X-Repo-Ref', $ref);
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
    private function requestJson(string $route, array $queryParams = []): array
    {
        $lastError = 'Proxy liefert kein gültiges JSON.';
        $transport = 'unknown';
        $token = trim((string) $this->addon->getConfig('api_token', ''));
        $tokenFingerprint = $token !== '' ? substr(sha1($token), 0, 10) : 'no-token';

        foreach ($this->buildBaseUrls() as $baseUrl) {
            $query = http_build_query(array_merge([
                'route' => $route,
            ], $queryParams));
            $url = $baseUrl . '/index.php?' . $query;
            $host = (string) parse_url($baseUrl, PHP_URL_HOST);
            $resolvedIp = $host !== '' ? gethostbyname($host) : '';

            if (function_exists('curl_init')) {
                $transport = 'curl';
                $headers = [
                    'Accept: application/json',
                    'User-Agent: REDAXO-ClientInstaller',
                ];

                $ownerHeader = isset($queryParams['owner']) ? trim((string) $queryParams['owner']) : '';
                $repoHeader = isset($queryParams['repo']) ? trim((string) $queryParams['repo']) : '';
                if ($ownerHeader !== '') {
                    $headers[] = 'X-Repo-Owner: ' . $ownerHeader;
                }
                if ($repoHeader !== '') {
                    $headers[] = 'X-Repo-Name: ' . $repoHeader;
                }

                if ($token !== '') {
                    $headers[] = 'Authorization: Bearer ' . $token;
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => (int) $this->addon->getConfig('timeout', 20),
                ]);

                $body = curl_exec($ch);
                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if (!is_string($body)) {
                    $lastError = 'Proxy nicht erreichbar: ' . ($curlError !== '' ? $curlError : 'Unbekannter cURL-Fehler');
                    continue;
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    $bodySnippet = trim(substr($body, 0, 180));
                    $lastError = 'Proxy-Antwort fehlerhaft (HTTP ' . (string) $statusCode . ')';
                    if ($bodySnippet !== '') {
                        $lastError .= ': ' . $bodySnippet;
                    } else {
                        $lastError .= '.';
                    }
                    $lastError .= ' URL: ' . $url;
                    $lastError .= ' transport=' . $transport;
                    $lastError .= ' token=' . $tokenFingerprint;
                    if ($resolvedIp !== '') {
                        $lastError .= ' ip=' . $resolvedIp;
                    }
                    continue;
                }

                $data = json_decode($body, true);
                if (!is_array($data)) {
                    $lastError = 'Proxy liefert kein gültiges JSON.';
                    continue;
                }

                return $data;
            }

            try {
                $transport = 'socket';
                $socket = rex_socket::factoryUrl($url);
                $socket->setTimeout((int) $this->addon->getConfig('timeout', 20));
                $this->addAuthHeader($socket);
                $ownerHeader = isset($queryParams['owner']) ? trim((string) $queryParams['owner']) : '';
                $repoHeader = isset($queryParams['repo']) ? trim((string) $queryParams['repo']) : '';
                if ($ownerHeader !== '') {
                    $socket->addHeader('X-Repo-Owner', $ownerHeader);
                }
                if ($repoHeader !== '') {
                    $socket->addHeader('X-Repo-Name', $repoHeader);
                }
                $response = $socket->doGet();
                if (!$response->isOk()) {
                    $statusCode = $response->getStatusCode();
                    $bodySnippet = trim(substr((string) $response->getBody(), 0, 180));
                    $lastError = 'Proxy-Antwort fehlerhaft (HTTP ' . ($statusCode !== null ? (string) $statusCode : '?') . ')';
                    if ($bodySnippet !== '') {
                        $lastError .= ': ' . $bodySnippet;
                    } else {
                        $lastError .= '.';
                    }
                    $lastError .= ' URL: ' . $url;
                    $lastError .= ' transport=' . $transport;
                    $lastError .= ' token=' . $tokenFingerprint;
                    if ($resolvedIp !== '') {
                        $lastError .= ' ip=' . $resolvedIp;
                    }
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
