<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - Install and uninstall hooks
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Essentials.
 *
 * Essentials is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Essentials is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Direct access not allowed.");
}

/**
 * Installs plugin database schema and initializes default configuration.
 *
 * @return bool
 */
function plugin_essentials_install(): bool
{
    require_once __DIR__ . '/inc/compat.class.php';
    require_once __DIR__ . '/inc/config.class.php';
    require_once __DIR__ . '/inc/slapause.class.php';

    PluginEssentialsSlapause::installSchema();
    PluginEssentialsConfig::initDefaults();

    return true;
}

/**
 * Removes plugin database schema and configuration values.
 *
 * @return bool
 */
function plugin_essentials_uninstall(): bool
{
    require_once __DIR__ . '/inc/compat.class.php';
    require_once __DIR__ . '/inc/config.class.php';
    require_once __DIR__ . '/inc/slapause.class.php';

    PluginEssentialsSlapause::uninstallSchema();
    PluginEssentialsConfig::deleteAll();

    return true;
}
