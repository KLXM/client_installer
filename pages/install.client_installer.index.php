<?php

declare(strict_types=1);

use FriendsOfREDAXO\ClientInstaller\ClientInstaller;
use FriendsOfREDAXO\ClientInstaller\ProxyApi;

/**
 * Entfernt unsichtbare/unerwartete Zeichen aus GitHub-Owner und Repo-Name.
 */
function ciSanitizeGitHubIdentifier(string $value): string
{
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $value);
    return is_string($clean) ? $clean : '';
}

$addon = rex_addon::get('client_installer');
$service = new ClientInstaller($addon);
$csrf = rex_csrf_token::factory('client_installer');
$message = '';

if (rex_request_method() === 'post') {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $action = rex_post('action', 'string', '');

        if ($action === 'refresh_updates') {
            $service->getUpdateInfo(true);
            echo rex_view::success($addon->i18n('client_installer_updates_refreshed'));
        }

        if ($action === 'install_addon') {
            $owner = ciSanitizeGitHubIdentifier(trim(rex_post('owner', 'string', '')));
            $repo = ciSanitizeGitHubIdentifier(trim(rex_post('repo', 'string', '')));

            if ($owner === '' || $repo === '') {
                echo rex_view::error('Owner oder Repository ungültig.');
                return;
            }

            $installApi = new ProxyApi($addon);

            try {
                $versions = $installApi->getVersions($owner, $repo);
                $ref = (string) ($versions[0]['tag_name'] ?? $versions[0]['name'] ?? '');
            } catch (Throwable $e) {
                echo rex_view::error('Neueste Version konnte nicht ermittelt werden: ' . $e->getMessage());
                return;
            }

            if ($ref === '') {
                echo rex_view::error('Keine installierbare Release-Version gefunden.');
                return;
            }

            $result = $service->installFromProxy($owner, $repo, $ref);
            if ($result['success']) {
                echo rex_view::success((string) $result['message']);

                $addonKey = (string) ($result['addonKey'] ?? '');
                if ($addonKey !== '') {
                    $installUrl = rex_url::currentBackendPage([
                        'page' => 'packages',
                        'package' => $addonKey,
                        'function' => 'install',
                        'rex-api-call' => 'package',
                        '_csrf_token' => rex_csrf_token::factory('rex_api_package')->getValue(),
                    ]);

                    $message = '<p><a class="btn btn-primary" href="' . rex_escape($installUrl) . '">' . rex_i18n::msg('package_install') . ': ' . rex_escape($addonKey) . '</a></p>';
                }
            } else {
                echo rex_view::error((string) $result['message']);
            }
        }
    }
}

$info = [
    'count' => 0,
    'updates' => [],
    'packages' => [],
];

try {
    $info = $service->getUpdateInfo();
} catch (Throwable $e) {
    echo rex_view::error($e->getMessage());
}

$packages = is_array($info['packages'] ?? null) ? $info['packages'] : [];
$proxyApi = new ProxyApi($addon);
$installButtonLabel = html_entity_decode(
    $addon->i18n('client_installer_btn_install'),
    ENT_QUOTES | ENT_HTML5,
    'UTF-8',
);

$updatesByRepo = [];
foreach (($info['updates'] ?? []) as $update) {
    $updatesByRepo[(string) $update['repo']] = $update;
}

$content = '';
$content .= '<p>' . rex_escape($addon->i18n('client_installer_intro')) . '</p>';
$content .= '<form method="post" style="margin-bottom:1rem;">'
    . $csrf->getHiddenField()
    . '<input type="hidden" name="action" value="refresh_updates">'
    . '<button class="btn btn-default" type="submit">' . rex_escape($addon->i18n('client_installer_refresh_updates')) . '</button>'
    . '</form>';

if ($message !== '') {
    $content .= $message;
}

$content .= '<table class="table table-striped">';
$content .= '<thead><tr>'
    . '<th>' . rex_escape($addon->i18n('client_installer_col_package')) . '</th>'
    . '<th>' . rex_escape($addon->i18n('client_installer_col_owner')) . '</th>'
    . '<th>' . rex_escape($addon->i18n('client_installer_col_installed')) . '</th>'
    . '<th>' . rex_escape($addon->i18n('client_installer_col_latest')) . '</th>'
    . '<th>' . rex_escape($addon->i18n('client_installer_col_action')) . '</th>'
    . '</tr></thead><tbody>';

foreach ($packages as $package) {
    $owner = ciSanitizeGitHubIdentifier(trim((string) ($package['owner_name'] ?? '')));
    $repo = ciSanitizeGitHubIdentifier(trim((string) ($package['repo_name'] ?? '')));
    if ($owner === '' || $repo === '') {
        continue;
    }

    $installedVersion = '-';
    if (rex_addon::exists($repo) && rex_addon::get($repo)->isInstalled()) {
        $installedVersion = (string) rex_addon::get($repo)->getVersion();
    }

    $latest = '-';
    $latestError = '';
    $defaultRef = '';
    try {
        $versions = $proxyApi->getVersions($owner, $repo);
        $latest = (string) ($versions[0]['tag_name'] ?? $versions[0]['name'] ?? '-');
        $defaultRef = $latest !== '-' ? $latest : '';
    } catch (Throwable $e) {
        $latest = 'kein Release';
        $latestError = $e->getMessage();
        $defaultRef = '';
    }
    $isUpdate = isset($updatesByRepo[$repo]);

    $content .= '<tr>';
    $content .= '<td>' . rex_escape($repo) . ($isUpdate ? ' <span class="label label-warning">Update</span>' : '') . '</td>';
    $content .= '<td>' . rex_escape($owner) . '</td>';
    $content .= '<td>' . rex_escape($installedVersion) . '</td>';
    $latestCell = rex_escape($latest);
    if ($latestError !== '') {
        $latestCell .= '<br><small class="text-danger">' . rex_escape($latestError) . '</small>';
    }
    $content .= '<td>' . $latestCell . '</td>';
    $content .= '<td>';
    $installButton = '<button type="submit" class="btn btn-primary">' . rex_escape($installButtonLabel) . '</button>';
    if ($defaultRef === '') {
        $installButton = '<button type="submit" class="btn btn-default" disabled="disabled">Kein Release</button>';
    }

    $content .= '<form method="post" class="form-inline" style="display:flex;gap:.4rem;align-items:center;">'
        . $csrf->getHiddenField()
        . '<input type="hidden" name="action" value="install_addon">'
        . '<input type="hidden" name="owner" value="' . rex_escape($owner) . '">'
        . '<input type="hidden" name="repo" value="' . rex_escape($repo) . '">'
        . $installButton
        . '</form>';
    $content .= '</td>';
    $content .= '</tr>';
}

$content .= '</tbody></table>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('client_installer_subpage_index'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
