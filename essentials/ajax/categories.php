<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - AJAX endpoint returning ITIL category code map
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../inc/compat.class.php';

Session::checkLoginUser();

$canRead = Session::haveRight('ticket', READ)
    || (defined('CREATE') && Session::haveRight('ticket', CREATE))
    || Session::haveRight('problem', READ)
    || Session::haveRight('change', READ)
    || Session::haveRight('itilcategory', READ)
    || Session::haveRight('config', READ);

if (!$canRead) {
    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

global $DB;

$where = [
    ['code' => ['<>', '']],
    ['NOT' => ['code' => null]],
];

if (function_exists('getEntitiesRestrictCriteria')) {
    $entityCriteria = getEntitiesRestrictCriteria('glpi_itilcategories', '', '', true);
    if (is_array($entityCriteria) && !empty($entityCriteria)) {
        $where = array_merge($where, $entityCriteria);
    }
}

$map = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'code'],
    'FROM'   => 'glpi_itilcategories',
    'WHERE'  => $where,
]);

foreach ($iterator as $row) {
    $id   = (int) ($row['id'] ?? 0);
    $code = trim((string) PluginEssentialsCompat::fromCore((string) ($row['code'] ?? '')));
    if ($id > 0 && $code !== '') {
        $map[(string) $id] = $code;
    }
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
}
echo json_encode($map, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE);
