<?php

declare(strict_types=1);

$addon = rex_addon::get('client_installer');
$addon->setConfig('proxy_base_url', (string) $addon->getConfig('proxy_base_url', 'http://localhost:8088/installer'));
$addon->setConfig('api_token', (string) $addon->getConfig('api_token', ''));
$addon->setConfig('timeout', (int) $addon->getConfig('timeout', 20));
$addon->setConfig('blink_updates', (bool) $addon->getConfig('blink_updates', true));
$addon->setConfig('update_cache_ttl', (int) $addon->getConfig('update_cache_ttl', 300));
