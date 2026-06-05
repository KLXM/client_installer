<?php

declare(strict_types=1);

use FriendsOfREDAXO\ClientInstaller\ClientInstaller;

$addon = rex_addon::get('client_installer');

if (rex::isBackend() && null !== rex::getUser() && rex::getUser()->isAdmin()) {
    rex_view::addCssFile($addon->getAssetsUrl('css/client_installer.css'));

    rex_extension::register('PAGES_PREPARED', static function () use ($addon): void {
        $page = rex_be_controller::getPageObject('install/client_installer');
        if (!$page instanceof rex_be_page) {
            return;
        }

        if (!(bool) $addon->getConfig('blink_updates', true)) {
            return;
        }

        try {
            $service = new ClientInstaller($addon);
            $info = $service->getUpdateInfo();
            $count = (int) ($info['count'] ?? 0);

            if ($count > 0) {
                $page->setTitle(
                    rex_i18n::msg('client_installer_title')
                    . ' <span class="ci-blink-dot" title="'
                    . rex_escape((string) $count)
                    . '">'
                    . rex_escape((string) $count)
                    . '</span>'
                );
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
        }
    });
}
