<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - Ticket UI hooks (SLA pause banner, quick copy & JS config)
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
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/slapause.class.php';

class PluginEssentialsUihooks
{
    /** @var bool */
    private static $configBootstrapped = false;

    /** @var array<int, bool> */
    private static $renderedBanner = [];

    /** @var array<int, bool> */
    private static $renderedCopy = [];

    /**
     * Resets per-request static render guards (used by unit tests).
     *
     * @return void
     */
    public static function resetRenderGuards(): void
    {
        self::$configBootstrapped = false;
        self::$renderedBanner     = [];
        self::$renderedCopy       = [];
    }

    /**
     * Escapes text safely for HTML output.
     *
     * @param string $value
     * @return string
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Builds ticket URL without relying on deprecated helpers.
     *
     * @param int $ticketId
     * @return string
     */
    public static function buildTicketUrl(int $ticketId): string
    {
        global $CFG_GLPI;

        if ($ticketId <= 0) {
            return '';
        }

        $base = '';
        if (is_array($CFG_GLPI) && !empty($CFG_GLPI['url_base'])) {
            $base = rtrim((string) $CFG_GLPI['url_base'], '/');
        } else {
            $base = PluginEssentialsCompat::rootDoc();
        }

        return $base . '/front/ticket.form.php?id=' . $ticketId;
    }

    /**
     * Hook callback for post_show_item / post_item_form.
     *
     * @param mixed $params
     * @return void
     */
    public static function renderTicketEnhancements($params = []): void
    {
        if (!is_array($params) || !isset($params['item']) || !is_object($params['item'])) {
            return;
        }
        if (class_exists('Session') && (int) Session::getLoginUserID(true) <= 0) {
            return;
        }

        $item       = $params['item'];
        $isTicket   = ($item instanceof Ticket);
        $isItilItem = $isTicket || (class_exists('CommonITILObject') && ($item instanceof CommonITILObject));
        if (!$isItilItem) {
            return;
        }

        $cfg = PluginEssentialsConfig::getAll();
        $hasCategoryHide = !empty($cfg['hide_category_code']);
        $hasQuickCopy    = !empty($cfg['ticket_quick_copy_link']);
        $hasPauseBanner  = !empty($cfg['ticket_sla_pause_banner']);

        if (!$hasCategoryHide && !$hasQuickCopy && !$hasPauseBanner) {
            return;
        }
        if (!$isTicket && !$hasCategoryHide) {
            return;
        }

        $ticketId = $isTicket ? (int) ($item->fields['id'] ?? 0) : 0;
        if ($isTicket && $ticketId > 0 && method_exists($item, 'canViewItem') && !$item->canViewItem()) {
            return;
        }

        $ticketTitle = ($isTicket && $ticketId > 0)
            ? (string) PluginEssentialsCompat::fromCore((string) ($item->fields['name'] ?? ''))
            : '';
        $ticketUrl = ($isTicket && $ticketId > 0)
            ? self::buildTicketUrl($ticketId)
            : '';

        if (($hasCategoryHide || $hasQuickCopy) && !self::$configBootstrapped) {
            self::$configBootstrapped = true;

            $jsConfig = [
                'hide_category_code' => $hasCategoryHide,
                'categories_url'     => PluginEssentialsCompat::webPath('ajax/categories.php'),
                'quick_copy'         => $hasQuickCopy && $ticketId > 0,
                'ticket_id'          => $ticketId,
                'ticket_title'       => $ticketTitle,
                'ticket_url'         => $ticketUrl,
            ];

            $json = json_encode(
                $jsConfig,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
            );
            if (is_string($json)) {
                echo '<script>'
                    . 'window.GLPI_ESSENTIALS_CFG = ' . $json . ';'
                    . 'if (typeof window.glpiEssentialsRefresh === "function") { window.glpiEssentialsRefresh(); }'
                    . '</script>';
            }
        }

        if (!$isTicket || $ticketId <= 0) {
            return;
        }

        $status = (int) ($item->fields['status'] ?? 0);
        if (
            $hasPauseBanner
            && !isset(self::$renderedBanner[$ticketId])
            && !in_array($status, [
                PluginEssentialsSlapause::STATUS_WAITING,
                PluginEssentialsSlapause::STATUS_SOLVED,
                PluginEssentialsSlapause::STATUS_CLOSED,
            ], true)
        ) {
            $pauses = PluginEssentialsSlapause::getActivePauses($ticketId);
            if (!empty($pauses)) {
                self::$renderedBanner[$ticketId] = true;
                echo self::renderPauseBannerHtml($pauses);
            }
        }

        if ($hasQuickCopy && !isset(self::$renderedCopy[$ticketId])) {
            self::$renderedCopy[$ticketId] = true;

            $copyText = '#' . $ticketId
                . ($ticketTitle !== '' ? ' - ' . $ticketTitle : '')
                . "\n"
                . $ticketUrl;
            $defaultLabel = __('Copiar resumo e link', 'essentials');
            $copiedLabel  = __('Copiado!', 'essentials');

            echo '<div class="glpi-essentials-toolbar">'
                . '<button type="button"'
                . ' class="btn btn-sm btn-outline-secondary glpi-essentials-copy-btn"'
                . ' data-copy-text="' . self::esc($copyText) . '"'
                . ' data-default-label="' . self::esc($defaultLabel) . '"'
                . ' data-copied-label="' . self::esc($copiedLabel) . '">'
                . '<i class="ti ti-copy me-1" aria-hidden="true"></i>'
                . '<span class="glpi-essentials-copy-label">' . self::esc($defaultLabel) . '</span>'
                . '</button>'
                . '</div>';
        }
    }

    /**
     * Renders the SLA pause banner HTML.
     *
     * @param array $pauses
     * @return string
     */
    private static function renderPauseBannerHtml(array $pauses): string
    {
        $reasonLabels = [
            PluginEssentialsSlapause::REASON_APPROVAL => __('Aprovação pendente', 'essentials'),
            PluginEssentialsSlapause::REASON_PLANNED  => __('Chamado planejado', 'essentials'),
        ];

        $badges      = [];
        $earliestRaw = '';
        $earliestTs  = false;

        foreach ($pauses as $pause) {
            $reason = (string) ($pause['reason'] ?? '');
            $label  = isset($reasonLabels[$reason]) ? $reasonLabels[$reason] : $reason;
            if ($label !== '') {
                $badges[] = '<span class="glpi-essentials-sla-badge">' . self::esc($label) . '</span>';
            }

            $pausedAt = (string) ($pause['paused_at'] ?? '');
            $ts       = strtotime($pausedAt);
            if ($pausedAt !== '' && ($earliestTs === false || ($ts !== false && $ts < $earliestTs))) {
                $earliestRaw = $pausedAt;
                $earliestTs  = $ts;
            }
        }

        $displaySince = $earliestRaw;
        if ($earliestRaw !== '' && class_exists('Html') && method_exists('Html', 'convDateTime')) {
            $displaySince = (string) Html::convDateTime($earliestRaw);
        }

        $title = __('SLA de solução pausado pelo plugin Essentials', 'essentials');
        $since = $displaySince !== ''
            ? sprintf(__('Pausado desde: %s', 'essentials'), $displaySince)
            : '';

        return '<div class="glpi-essentials-sla-banner" role="status" aria-live="polite">'
            . '<div class="glpi-essentials-sla-icon" aria-hidden="true">'
            . '<i class="ti ti-clock-pause"></i>'
            . '</div>'
            . '<div class="glpi-essentials-sla-body">'
            . '<div class="glpi-essentials-sla-title">' . self::esc($title) . '</div>'
            . '<div class="glpi-essentials-sla-meta">'
            . implode(' ', $badges)
            . ($since !== '' ? '<span class="glpi-essentials-sla-since">' . self::esc($since) . '</span>' : '')
            . '</div>'
            . '</div>'
            . '</div>';
    }
}
