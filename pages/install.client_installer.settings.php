<?php

declare(strict_types=1);

$addon = rex_addon::get('client_installer');
$csrf = rex_csrf_token::factory('client_installer_settings');

if (rex_request_method() === 'post') {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $addon->setConfig('proxy_base_url', rtrim(rex_post('proxy_base_url', 'string', ''), '/'));
        $addon->setConfig('api_token', trim(rex_post('api_token', 'string', '')));
        $addon->setConfig('timeout', max(5, rex_post('timeout', 'int', 20)));
        $addon->setConfig('blink_updates', rex_post('blink_updates', 'int', 0) === 1);
        $addon->setConfig('update_cache_ttl', max(60, rex_post('update_cache_ttl', 'int', 300)));

        echo rex_view::success($addon->i18n('client_installer_settings_saved'));
    }
}

$content = '<form method="post">'
    . $csrf->getHiddenField()
    . '<div class="form-group">'
    . '<label>' . rex_escape($addon->i18n('client_installer_proxy_base_url')) . '</label>'
    . '<input class="form-control" type="url" name="proxy_base_url" value="' . rex_escape((string) $addon->getConfig('proxy_base_url', '')) . '">'
    . '</div>'
    . '<div class="form-group">'
    . '<label>' . rex_escape($addon->i18n('client_installer_api_token')) . '</label>'
    . '<input class="form-control" type="text" name="api_token" value="' . rex_escape((string) $addon->getConfig('api_token', '')) . '">'
    . '</div>'
    . '<div class="form-group">'
    . '<label>' . rex_escape($addon->i18n('client_installer_timeout')) . '</label>'
    . '<input class="form-control" type="number" name="timeout" min="5" value="' . rex_escape((string) $addon->getConfig('timeout', 20)) . '">'
    . '</div>'
    . '<div class="form-group">'
    . '<label>' . rex_escape($addon->i18n('client_installer_update_cache_ttl')) . '</label>'
    . '<input class="form-control" type="number" name="update_cache_ttl" min="60" value="' . rex_escape((string) $addon->getConfig('update_cache_ttl', 300)) . '">'
    . '</div>'
    . '<div class="checkbox">'
    . '<label><input type="checkbox" name="blink_updates" value="1" ' . ((bool) $addon->getConfig('blink_updates', true) ? 'checked' : '') . '> '
    . rex_escape($addon->i18n('client_installer_blink_updates'))
    . '</label>'
    . '</div>'
    . '<button class="btn btn-primary" type="submit">' . rex_escape($addon->i18n('client_installer_save')) . '</button>'
    . '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('client_installer_subpage_settings'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
