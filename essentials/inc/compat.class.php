<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - GLPI 10 / 11 / 12 compatibility helpers
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

class PluginEssentialsCompat
{
    /**
     * Returns the current GLPI version string.
     *
     * @return string
     */
    public static function glpiVersion(): string
    {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '10.0.0';
    }

    /**
     * Returns the major GLPI version number (10, 11, 12...).
     *
     * @return int
     */
    public static function glpiMajor(): int
    {
        if (preg_match('/^(\d+)/', self::glpiVersion(), $matches) === 1) {
            return (int) $matches[1];
        }
        return 10;
    }

    /**
     * Returns true when running on GLPI 11.0.0-dev or newer.
     *
     * @return bool
     */
    public static function isGlpi11OrHigher(): bool
    {
        return version_compare(self::glpiVersion(), '11.0.0-dev', '>=');
    }

    /**
     * Checks whether CommonGLPI::$rightname is typed (GLPI 12+).
     *
     * @return bool
     */
    public static function typedRightname(): bool
    {
        if (!class_exists('CommonGLPI')) {
            return false;
        }
        try {
            $prop = new ReflectionProperty('CommonGLPI', 'rightname');
            return method_exists($prop, 'hasType') && $prop->hasType();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    /**
     * Returns GLPI root_doc without trailing slash.
     *
     * @return string
     */
    public static function rootDoc(): string
    {
        global $CFG_GLPI;

        if (is_array($CFG_GLPI) && isset($CFG_GLPI['root_doc'])) {
            return rtrim((string) $CFG_GLPI['root_doc'], '/');
        }
        return '';
    }

    /**
     * Builds a plugin web path directly from root_doc across GLPI 10, 11, and 12.
     * On GLPI 11+, all plugins are routed under /plugins/<key> regardless of directory.
     *
     * @param string $relative
     * @param string $pluginKey
     * @param string|null $pluginDir
     * @return string
     */
    public static function webPath(string $relative = '', string $pluginKey = 'essentials', ?string $pluginDir = null): string
    {
        $baseSegment = 'plugins';
        if (!self::isGlpi11OrHigher()) {
            $dir          = ($pluginDir !== null && $pluginDir !== '') ? $pluginDir : dirname(__DIR__);
            $pluginParent = basename(dirname(rtrim($dir, '/\\')));
            if ($pluginParent === 'marketplace') {
                $baseSegment = 'marketplace';
            }
        }
        $base = self::rootDoc() . '/' . $baseSegment . '/' . trim($pluginKey, '/');
        $rel  = ltrim($relative, '/');

        return $rel !== '' ? ($base . '/' . $rel) : $base;
    }

    /**
     * Resolves static asset path for $PLUGIN_HOOKS['add_css'] / ['add_javascript']:
     * prefixes 'public/' on GLPI < 11.0.0-dev and '' on >= 11.0.0-dev.
     *
     * @param string $relative
     * @return string
     */
    public static function assetPath(string $relative): string
    {
        $rel = ltrim($relative, '/');
        if (strpos($rel, 'public/') === 0) {
            $rel = substr($rel, 7);
        }
        $prefix = self::isGlpi11OrHigher() ? '' : 'public/';
        return $prefix . $rel;
    }

    /**
     * Decodes HTML entities from DB-sourced GLPI core strings on GLPI < 11.0.0-dev
     * without stripping literal backslashes.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function fromCore($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::fromCore($v);
            }
            return $out;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (!self::isGlpi11OrHigher()) {
            if (
                class_exists('Glpi\\Toolbox\\Sanitizer')
                && method_exists('Glpi\\Toolbox\\Sanitizer', 'decodeHtmlSpecialChars')
            ) {
                return \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($value);
            }
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $value;
    }

    /**
     * Prepares clean values for direct DB calls ($DB->insert / $DB->update).
     *
     * @param array $values
     * @return array
     */
    public static function dbValues(array $values): array
    {
        if (
            !self::isGlpi11OrHigher()
            && class_exists('Glpi\\Toolbox\\Sanitizer')
            && method_exists('Glpi\\Toolbox\\Sanitizer', 'dbUnescape')
        ) {
            $out = [];
            foreach ($values as $k => $v) {
                $out[$k] = is_string($v) ? \Glpi\Toolbox\Sanitizer::dbUnescape($v) : $v;
            }
            return $out;
        }
        return $values;
    }

    /**
     * Prepares an array to pass into GLPI core CommonDBTM::add()/update()
     * across GLPI 10, 11, and 12.
     *
     * @param array $values
     * @return array
     */
    public static function coreInput(array $values): array
    {
        if (
            !self::isGlpi11OrHigher()
            && class_exists('Glpi\\Toolbox\\Sanitizer')
            && method_exists('Glpi\\Toolbox\\Sanitizer', 'sanitize')
        ) {
            return \Glpi\Toolbox\Sanitizer::sanitize($values);
        }
        return $values;
    }

    /**
     * Verifies POST method, payload presence, and same-origin headers when supplied.
     * Relies on GLPI core's single-use CSRF validation in includes.php for forms
     * closed via Html::closeForm() so the token is not validated twice.
     *
     * @return void
     */
    public static function requireSafePost(): void
    {
        global $CFG_GLPI;

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== 'POST' || empty($_POST)) {
            self::denyAccess();
        }

        $allowedHosts = [];
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $headerKey) {
            if (!empty($_SERVER[$headerKey])) {
                $raw = (string) $_SERVER[$headerKey];
                $first = trim(explode(',', $raw)[0]);
                $parsed = parse_url('http://' . $first, PHP_URL_HOST);
                if (is_string($parsed) && $parsed !== '') {
                    $allowedHosts[strtolower($parsed)] = true;
                }
            }
        }
        if (is_array($CFG_GLPI) && !empty($CFG_GLPI['url_base'])) {
            $baseHost = parse_url((string) $CFG_GLPI['url_base'], PHP_URL_HOST);
            if (is_string($baseHost) && $baseHost !== '') {
                $allowedHosts[strtolower($baseHost)] = true;
            }
        }

        if (!empty($allowedHosts)) {
            $sourceUrl = '';
            if (!empty($_SERVER['HTTP_ORIGIN'])) {
                $sourceUrl = (string) $_SERVER['HTTP_ORIGIN'];
            } elseif (!empty($_SERVER['HTTP_REFERER'])) {
                $sourceUrl = (string) $_SERVER['HTTP_REFERER'];
            }
            if ($sourceUrl !== '') {
                $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
                if (!is_string($sourceHost) || !isset($allowedHosts[strtolower($sourceHost)])) {
                    self::denyAccess();
                }
            }
        }
    }

    /**
     * Denies access across GLPI 10/11 (Html::displayRightError) and GLPI 12 (AccessDeniedHttpException).
     *
     * @return void
     */
    public static function denyAccess(): void
    {
        if (self::glpiMajor() >= 12 && class_exists('Glpi\\Exception\\Http\\AccessDeniedHttpException')) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        if (class_exists('Html') && method_exists('Html', 'displayRightError')) {
            Html::displayRightError();
        } elseif (class_exists('Glpi\\Exception\\Http\\AccessDeniedHttpException')) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        } else {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo 'Forbidden';
        }
        exit;
    }
}
