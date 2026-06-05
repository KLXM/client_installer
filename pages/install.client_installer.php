<?php

declare(strict_types=1);

$addon = rex_addon::get('client_installer');

echo rex_view::title($addon->i18n('client_installer_title'));

rex_be_controller::includeCurrentPageSubPath();
