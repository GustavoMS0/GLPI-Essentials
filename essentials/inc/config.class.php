<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - Plugin configuration
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

require_once __DIR__ . '/compat.class.php';

interface PluginEssentialsConfigConstants
{
    public const CONTEXT = 'plugin:essentials';

    public const OPTIONS = [
        'hide_category_code'      => 0,
        'pause_sla_approval'      => 0,
        'pause_sla_planned'       => 0,
        'ticket_sla_pause_banner' => 0,
        'ticket_quick_copy_link'  => 0,
    ];
}

trait PluginEssentialsConfigBody
{
    /**
     * Returns localized plugin name.
     *
     * @param int $nb
     * @return string
     */
    public static function getTypeName($nb = 0)
    {
        return __('Essentials', 'essentials');
    }

    /**
     * Returns all plugin options normalized to 0 or 1.
     *
     * @return array
     */
    public static function getAll(): array
    {
        $raw = [];
        if (class_exists('Config') && method_exists('Config', 'getConfigurationValues')) {
            $loaded = Config::getConfigurationValues(self::CONTEXT);
            if (is_array($loaded)) {
                $raw = $loaded;
            }
        }

        $result = [];
        foreach (self::OPTIONS as $key => $default) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = ((int) $raw[$key] === 1) ? 1 : 0;
            } else {
                $result[$key] = (int) $default;
            }
        }
        return $result;
    }

    /**
     * Checks whether a given option key is enabled (=== 1).
     *
     * @param string $key
     * @return bool
     */
    public static function isEnabled(string $key): bool
    {
        $all = self::getAll();
        return !empty($all[$key]);
    }

    /**
     * Persists boolean toggle values into GLPI configuration.
     *
     * @param array $input
     * @return bool
     */
    public static function save(array $input): bool
    {
        if (!class_exists('Config') || !method_exists('Config', 'setConfigurationValues')) {
            return false;
        }

        $values = [];
        foreach (array_keys(self::OPTIONS) as $key) {
            $values[$key] = (!empty($input[$key]) && (string) $input[$key] !== '0') ? '1' : '0';
        }

        Config::setConfigurationValues(self::CONTEXT, PluginEssentialsCompat::dbValues($values));
        return true;
    }

    /**
     * Initializes missing default configuration keys on install/upgrade
     * without overwriting existing administrator choices.
     *
     * @return void
     */
    public static function initDefaults(): void
    {
        if (!class_exists('Config')) {
            return;
        }

        $existing = Config::getConfigurationValues(self::CONTEXT);
        if (!is_array($existing)) {
            $existing = [];
        }

        $toSet = [];
        foreach (self::OPTIONS as $key => $default) {
            if (!array_key_exists($key, $existing)) {
                $toSet[$key] = (string) $default;
            }
        }

        if (!empty($toSet)) {
            Config::setConfigurationValues(self::CONTEXT, $toSet);
        }
    }

    /**
     * Removes all plugin configuration entries on uninstall.
     *
     * @return void
     */
    public static function deleteAll(): void
    {
        if (class_exists('Config') && method_exists('Config', 'deleteConfigurationValues')) {
            Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::OPTIONS));
        }
    }

    /**
     * Returns metadata for rendering the configuration page.
     *
     * @return array
     */
    public static function getOptionDefinitions(): array
    {
        return [
            'hide_category_code' => [
                'title'       => __('Ocultar código da categoria nos seletores', 'essentials'),
                'description' => __('Remove o sufixo de código (ex.: " - HW01") nos seletores de categoria quando a categoria possuir código cadastrado, preservando a hierarquia e hífens do nome.', 'essentials'),
                'group'       => __('Interface de Chamados', 'essentials'),
            ],
            'ticket_quick_copy_link' => [
                'title'       => __('Botão de cópia rápida no chamado', 'essentials'),
                'description' => __('Adiciona um botão para copiar com um clique o número, título e link direto do chamado para a área de transferência.', 'essentials'),
                'group'       => __('Interface de Chamados', 'essentials'),
            ],
            'pause_sla_approval' => [
                'title'       => __('Pausar SLA enquanto aguarda aprovação', 'essentials'),
                'description' => __('Pausa automaticamente a contagem do SLA de solução enquanto a validação do chamado estiver pendente.', 'essentials'),
                'group'       => __('Pausa Automática de SLA', 'essentials'),
            ],
            'pause_sla_planned' => [
                'title'       => __('Pausar SLA no status Planejado', 'essentials'),
                'description' => __('Pausa automaticamente a contagem do SLA de solução enquanto o chamado permanecer com status Planejado.', 'essentials'),
                'group'       => __('Pausa Automática de SLA', 'essentials'),
            ],
            'ticket_sla_pause_banner' => [
                'title'       => __('Exibir banner de SLA pausado no chamado', 'essentials'),
                'description' => __('Mostra um aviso visual no topo da ficha do chamado informando os motivos e o horário de início da pausa ativa pelo plugin.', 'essentials'),
                'group'       => __('Pausa Automática de SLA', 'essentials'),
            ],
        ];
    }
}

if (PluginEssentialsCompat::typedRightname()) {
    class PluginEssentialsConfig extends CommonGLPI implements PluginEssentialsConfigConstants
    {
        use PluginEssentialsConfigBody;

        public static string $rightname = 'config';
    }
} else {
    class PluginEssentialsConfig extends CommonGLPI implements PluginEssentialsConfigConstants
    {
        use PluginEssentialsConfigBody;

        public static $rightname = 'config';
    }
}
