<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - GLPI plugin setup and metadata
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

define('PLUGIN_ESSENTIALS_VERSION', '1.0.0');
define('PLUGIN_ESSENTIALS_MIN_GLPI', '10.0.0');
define('PLUGIN_ESSENTIALS_MAX_GLPI', '12.0.99');
define('PLUGIN_ESSENTIALS_MIN_PHP', '7.4');

/**
 * Registers plugin hooks and conditional assets.
 *
 * @return void
 */
function plugin_init_essentials(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['essentials'] = true;
    $PLUGIN_HOOKS['config_page']['essentials']    = 'front/config.form.php';

    $PLUGIN_HOOKS['item_add']['essentials'] = [
        'Ticket'           => ['PluginEssentialsSlapause', 'onTicketChange'],
        'TicketValidation' => ['PluginEssentialsSlapause', 'onValidationChange'],
    ];
    $PLUGIN_HOOKS['item_update']['essentials'] = [
        'Ticket'           => ['PluginEssentialsSlapause', 'onTicketChange'],
        'TicketValidation' => ['PluginEssentialsSlapause', 'onValidationChange'],
    ];
    $PLUGIN_HOOKS['item_purge']['essentials'] = [
        'Ticket'           => ['PluginEssentialsSlapause', 'onTicketPurge'],
        'TicketValidation' => ['PluginEssentialsSlapause', 'onValidationChange'],
    ];
    $PLUGIN_HOOKS['item_delete']['essentials'] = [
        'TicketValidation' => ['PluginEssentialsSlapause', 'onValidationChange'],
    ];

    $PLUGIN_HOOKS['post_show_item']['essentials'] = ['PluginEssentialsUihooks', 'renderTicketEnhancements'];
    $PLUGIN_HOOKS['post_item_form']['essentials'] = ['PluginEssentialsUihooks', 'renderTicketEnhancements'];

    if (class_exists('Session') && (int) Session::getLoginUserID(true) > 0) {
        require_once __DIR__ . '/inc/compat.class.php';
        require_once __DIR__ . '/inc/config.class.php';

        $cfg             = PluginEssentialsConfig::getAll();
        $hasCategoryHide = !empty($cfg['hide_category_code']);
        $hasQuickCopy    = !empty($cfg['ticket_quick_copy_link']);
        $hasBanner       = !empty($cfg['ticket_sla_pause_banner']);

        if ($hasCategoryHide || $hasQuickCopy || $hasBanner) {
            $PLUGIN_HOOKS['add_css']['essentials'][] = PluginEssentialsCompat::assetPath('css/essentials.css');
        }
        if ($hasCategoryHide || $hasQuickCopy) {
            $PLUGIN_HOOKS['add_javascript']['essentials'][] = PluginEssentialsCompat::assetPath('js/essentials.js');
        }
    }
}

/**
 * Returns plugin metadata and version compatibility requirements.
 *
 * @return array
 */
function plugin_version_essentials(): array
{
    return [
        'name'         => 'Essentials',
        'version'      => PLUGIN_ESSENTIALS_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-Essentials',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ESSENTIALS_MIN_GLPI,
                'max' => PLUGIN_ESSENTIALS_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_ESSENTIALS_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Checks runtime PHP and GLPI prerequisites before activation.
 *
 * @return bool
 */
function plugin_essentials_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_ESSENTIALS_MIN_PHP, '<')) {
        echo 'Essentials requires PHP ' . PLUGIN_ESSENTIALS_MIN_PHP . ' or higher.';
        return false;
    }

    if (defined('GLPI_VERSION')) {
        $minOk = version_compare(GLPI_VERSION, PLUGIN_ESSENTIALS_MIN_GLPI, '>=');
        $maxOk = version_compare(GLPI_VERSION, PLUGIN_ESSENTIALS_MAX_GLPI, '<=');
        if (!$minOk || !$maxOk) {
            echo 'Essentials requires GLPI between ' . PLUGIN_ESSENTIALS_MIN_GLPI . ' and ' . PLUGIN_ESSENTIALS_MAX_GLPI . '.';
            return false;
        }
    }

    return true;
}

/**
 * Checks plugin configuration state before activation.
 *
 * @param bool $verbose
 * @return bool
 */
function plugin_essentials_check_config($verbose = false): bool
{
    return true;
}
