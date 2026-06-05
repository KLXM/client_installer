<?php

declare(strict_types=1);

$addon = rex_addon::get('client_installer');
if (null === $addon->getConfig('update_cache_ttl')) {
    $addon->setConfig('update_cache_ttl', 300);
}
