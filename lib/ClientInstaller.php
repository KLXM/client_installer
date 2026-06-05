<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\ClientInstaller;

use rex_addon;
use rex_dir;
use rex_file;
use rex_functional_exception;
use rex_finder;
use rex_install_archive;
use rex_logger;
use rex_package_manager;
use rex_path;

final class ClientInstaller
{
    private rex_addon $addon;
    private ProxyApi $api;

    public function __construct(rex_addon $addon)
    {
        $this->addon = $addon;
        $this->api = new ProxyApi($addon);
    }

    /**
     * @return array{count:int,updates:array<int,array<string,string>>,packages:array<int,array<string,mixed>>}
     */
    public function getUpdateInfo(bool $force = false): array
    {
        $cacheFile = $this->addon->getDataPath('updates.cache.php');
        $ttl = (int) $this->addon->getConfig('update_cache_ttl', 300);

        if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = rex_file::getCache($cacheFile);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $packages = $this->api->getPackages();
        $updates = [];

        foreach ($packages as $package) {
            $ownerRaw = trim((string) ($package['owner_name'] ?? ''));
            $repoRaw = trim((string) ($package['repo_name'] ?? ''));
            $owner = preg_replace('/[^A-Za-z0-9._-]/', '', $ownerRaw);
            $repo = preg_replace('/[^A-Za-z0-9._-]/', '', $repoRaw);
            $owner = is_string($owner) ? $owner : '';
            $repo = is_string($repo) ? $repo : '';
            if ($owner === '' || $repo === '') {
                continue;
            }

            if (!rex_addon::exists($repo)) {
                continue;
            }

            $installed = rex_addon::get($repo);
            if (!$installed->isInstalled()) {
                continue;
            }

            try {
                $versions = $this->api->getVersions($owner, $repo);
            } catch (rex_functional_exception) {
                continue;
            }

            if ([] === $versions) {
                continue;
            }

            $latestTag = (string) ($versions[0]['tag_name'] ?? $versions[0]['name'] ?? '');
            if ($latestTag === '') {
                continue;
            }

            $currentVersion = ltrim((string) $installed->getVersion(), 'vV');
            $latestVersion = ltrim($latestTag, 'vV');

            if (version_compare($latestVersion, $currentVersion, '>')) {
                $updates[] = [
                    'owner' => $owner,
                    'repo' => $repo,
                    'installed' => (string) $installed->getVersion(),
                    'latest' => $latestTag,
                ];
            }
        }

        $result = [
            'count' => count($updates),
            'updates' => $updates,
            'packages' => $packages,
        ];

        rex_file::putCache($cacheFile, $result);

        return $result;
    }

    /**
     * @return array{success:bool,message:string,addonKey:?string}
     */
    public function installFromProxy(string $owner, string $repo, string $ref): array
    {
        try {
            $archive = $this->api->downloadArchive($owner, $repo, $ref);
            $extractPath = $this->addon->getCachePath('extract/' . md5($owner . '_' . $repo . '_' . $ref));
            rex_dir::delete($extractPath);
            rex_dir::create($extractPath);

            if (!rex_install_archive::extract($archive, $extractPath)) {
                return [
                    'success' => false,
                    'message' => 'Archiv konnte nicht entpackt werden.',
                    'addonKey' => null,
                ];
            }

            $packageFile = $this->findPackageYml($extractPath);
            if (null === $packageFile) {
                return [
                    'success' => false,
                    'message' => 'Kein package.yml im Archiv gefunden.',
                    'addonKey' => null,
                ];
            }

            /** @var array{package?:string} $config */
            $config = rex_file::getConfig($packageFile);
            $addonKey = (string) ($config['package'] ?? '');
            if ($addonKey === '') {
                return [
                    'success' => false,
                    'message' => 'package.yml enthält keinen AddOn-Key.',
                    'addonKey' => null,
                ];
            }

            $addonRoot = dirname($packageFile);
            if (!rex_dir::copy($addonRoot, rex_path::addon($addonKey))) {
                return [
                    'success' => false,
                    'message' => 'AddOn konnte nicht in den AddOn-Ordner kopiert werden.',
                    'addonKey' => null,
                ];
            }

            rex_package_manager::synchronizeWithFileSystem();

            return [
                'success' => true,
                'message' => 'AddOn heruntergeladen: ' . $addonKey,
                'addonKey' => $addonKey,
            ];
        } catch (rex_functional_exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'addonKey' => null,
            ];
        } catch (\Throwable $e) {
            rex_logger::logException($e);
            return [
                'success' => false,
                'message' => 'Unerwarteter Fehler bei der Installation (' . $e::class . '): ' . $e->getMessage(),
                'addonKey' => null,
            ];
        }
    }

    private function findPackageYml(string $extractPath): ?string
    {
        $iterator = rex_finder::factory($extractPath)->recursive()->filesOnly();
        foreach ($iterator as $file) {
            if ($file->getFilename() === 'package.yml') {
                return $file->getPathname();
            }
        }

        return null;
    }
}
