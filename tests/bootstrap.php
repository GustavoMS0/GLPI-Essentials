<?php

/**
 * Standalone GLPI 10 / 11 / 12 test bootstrap for Essentials.
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 */

namespace Glpi\Toolbox {
    class Sanitizer
    {
        public static function sanitize($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::sanitize($v);
                }
                return $out;
            }
            if (!is_string($value)) {
                return $value;
            }
            $encoded = str_replace(
                ['&', '<', '>', '"', "'"],
                ['&#38;', '&#60;', '&#62;', '&#34;', '&#39;'],
                $value
            );
            return addslashes($encoded);
        }

        public static function unsanitize($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::unsanitize($v);
                }
                return $out;
            }
            if (!is_string($value)) {
                return $value;
            }
            $unescaped = stripslashes($value);
            return html_entity_decode($unescaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        public static function decodeHtmlSpecialChars($value)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = self::decodeHtmlSpecialChars($v);
                }
                return $out;
            }
            if (!is_string($value)) {
                return $value;
            }
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        public static function dbUnescape($value)
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }
}

namespace Glpi\Exception\Http {
    class AccessDeniedHttpException extends \RuntimeException
    {
    }
}

namespace {
    define('GLPI_ROOT', dirname(__DIR__));

    $testGlpiVersion = getenv('ESSENTIALS_TEST_GLPI_VERSION');
    define('GLPI_VERSION', ($testGlpiVersion !== false && $testGlpiVersion !== '') ? $testGlpiVersion : '10.0.18');

    if (!defined('READ')) {
        define('READ', 1);
    }
    if (!defined('UPDATE')) {
        define('UPDATE', 2);
    }
    if (!defined('CREATE')) {
        define('CREATE', 4);
    }
    if (!defined('INFO')) {
        define('INFO', 0);
    }

    global $CFG_GLPI, $PLUGIN_HOOKS, $DB;
    $CFG_GLPI = [
        'root_doc' => '/glpi',
        'url_base' => 'https://glpi.example.org/glpi',
    ];
    $PLUGIN_HOOKS = [];

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string
        {
            return $text;
        }
    }

    if (!function_exists('getEntitiesRestrictCriteria')) {
        function getEntitiesRestrictCriteria(
            string $table = '',
            string $field = '',
            $value = '',
            bool $is_recursive = false
        ): array {
            return ['entities_id' => [0]];
        }
    }

    $useTypedRightname = getenv('ESSENTIALS_TEST_TYPED_RIGHTNAME') === '1';
    if ($useTypedRightname) {
        eval('class CommonGLPI { public static string $rightname = ""; }');
    } else {
        eval('class CommonGLPI { public static $rightname = ""; }');
    }

    class CommonDBTM extends CommonGLPI
    {
        /** @var array */
        public $fields = [];

        public function canViewItem(): bool
        {
            return true;
        }
    }

    class CommonITILObject extends CommonDBTM
    {
        public const INCOMING = 1;
        public const ASSIGNED = 2;
        public const PLANNED  = 3;
        public const WAITING  = 4;
        public const SOLVED   = 5;
        public const CLOSED   = 6;
    }

    class CommonITILValidation extends CommonDBTM
    {
        public const NONE     = 1;
        public const WAITING  = 2;
        public const ACCEPTED = 3;
        public const REFUSED  = 4;
    }

    class Ticket extends CommonITILObject
    {
        /** @var array<int, array> */
        public static $store = [];

        /** @var int */
        public static $updateCallCount = 0;

        public static function resetFixtures(): void
        {
            self::$store           = [];
            self::$updateCallCount = 0;
        }

        public function getFromDB($id): bool
        {
            $id = (int) $id;
            if (!isset(self::$store[$id])) {
                return false;
            }
            $this->fields = self::$store[$id];
            return true;
        }

        public function update(array $input, $history = 1): bool
        {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                return false;
            }
            self::$updateCallCount++;
            foreach ($input as $k => $v) {
                $this->fields[$k] = $v;
            }
            if (isset(self::$store[$id])) {
                self::$store[$id] = array_merge(self::$store[$id], $this->fields);
            } else {
                self::$store[$id] = $this->fields;
            }

            if (class_exists('PluginEssentialsSlapause')) {
                PluginEssentialsSlapause::onTicketChange($this);
            }
            return true;
        }
    }

    class TicketValidation extends CommonDBTM
    {
    }

    class Entity extends CommonDBTM
    {
        /** @var array<int, array<string, mixed>> */
        public static $configs = [];

        public static function resetFixtures(): void
        {
            self::$configs = [];
        }

        public static function getUsedConfig(string $name, int $entitiesId)
        {
            return self::$configs[$entitiesId][$name] ?? 0;
        }
    }

    class SLA extends CommonDBTM
    {
        /** @var array<int, array> */
        public static $fixtures = [];

        /** @var array */
        public static $computeCalls = [];

        /** @var array */
        public static $setTicketCalendarCalls = [];

        /** @var int */
        public $ticketCalendarId = 0;

        public static function resetFixtures(): void
        {
            self::$fixtures               = [];
            self::$computeCalls           = [];
            self::$setTicketCalendarCalls = [];
        }

        public function getFromDB($id): bool
        {
            $id = (int) $id;
            if (!isset(self::$fixtures[$id])) {
                return false;
            }
            $this->fields           = self::$fixtures[$id];
            $this->ticketCalendarId = 0;
            return true;
        }

        public function setTicketCalendar(int $calendarsId): void
        {
            $this->ticketCalendarId       = $calendarsId;
            self::$setTicketCalendarCalls[] = [
                'slas_id'      => (int) ($this->fields['id'] ?? 0),
                'calendars_id' => $calendarsId,
            ];
        }

        public function computeDate(string $startDate, int $additionalDelay = 0): string
        {
            $id = (int) ($this->fields['id'] ?? 0);
            self::$computeCalls[] = [
                'slas_id'            => $id,
                'start_date'         => $startDate,
                'additional_delay'   => $additionalDelay,
                'ticket_calendar_id' => $this->ticketCalendarId,
            ];

            if (isset($this->fields['compute_cb']) && is_callable($this->fields['compute_cb'])) {
                return (string) call_user_func($this->fields['compute_cb'], $startDate, $additionalDelay);
            }

            $baseSeconds = (int) ($this->fields['base_seconds'] ?? 14400);
            $ts          = strtotime($startDate);
            return date('Y-m-d H:i:s', $ts + $baseSeconds + $additionalDelay);
        }
    }

    class Calendar extends CommonDBTM
    {
        /** @var array<int, array> */
        public static $fixtures = [];

        /** @var array */
        public static $activeTimeCalls = [];

        public static function resetFixtures(): void
        {
            self::$fixtures        = [];
            self::$activeTimeCalls = [];
        }

        public function getFromDB($id): bool
        {
            $id = (int) $id;
            if (!isset(self::$fixtures[$id])) {
                return false;
            }
            $this->fields = self::$fixtures[$id];
            return true;
        }

        public function getActiveTimeBetween(string $start, string $end): int
        {
            if (func_num_args() !== 2) {
                throw new RuntimeException('Calendar::getActiveTimeBetween must be called with exactly 2 arguments.');
            }
            self::$activeTimeCalls[] = [
                'calendars_id' => (int) ($this->fields['id'] ?? 0),
                'start'        => $start,
                'end'          => $end,
                'arg_count'    => func_num_args(),
            ];

            if (isset($this->fields['active_cb']) && is_callable($this->fields['active_cb'])) {
                return (int) call_user_func($this->fields['active_cb'], $start, $end);
            }

            return (int) ($this->fields['active_seconds'] ?? max(0, strtotime($end) - strtotime($start)));
        }
    }

    class Config
    {
        /** @var array<string, array<string, mixed>> */
        private static $values = [];

        public static function reset(): void
        {
            self::$values = [];
        }

        public static function getConfigurationValues(string $context): array
        {
            return self::$values[$context] ?? [];
        }

        public static function setConfigurationValues(string $context, array $values): void
        {
            if (!isset(self::$values[$context])) {
                self::$values[$context] = [];
            }
            foreach ($values as $k => $v) {
                self::$values[$context][$k] = $v;
            }
        }

        public static function deleteConfigurationValues(string $context, array $keys): void
        {
            if (!isset(self::$values[$context])) {
                return;
            }
            foreach ($keys as $k) {
                unset(self::$values[$context][$k]);
            }
        }
    }

    class Session
    {
        /** @var int */
        public static $userId = 2;

        /** @var array<string, int> */
        public static $rights = [
            'config'       => 3,
            'ticket'       => 7,
            'itilcategory' => 1,
        ];

        /** @var array */
        public static $messages = [];

        public static function getLoginUserID(bool $forceHuman = true)
        {
            return self::$userId > 0 ? self::$userId : false;
        }

        public static function haveRight(string $module, int $right): bool
        {
            $mask = (int) (self::$rights[$module] ?? 0);
            return ($mask & $right) === $right;
        }

        public static function checkRight(string $module, int $right): void
        {
            if (!self::haveRight($module, $right)) {
                throw new RuntimeException('Missing right: ' . $module);
            }
        }

        public static function checkLoginUser(): void
        {
            if (self::$userId <= 0) {
                throw new RuntimeException('Not logged in');
            }
        }

        public static function addMessageAfterRedirect(string $msg, bool $check_once = false, int $message_type = INFO): void
        {
            self::$messages[] = [$msg, $message_type];
        }
    }

    class Html
    {
        public static function convDateTime(?string $time): string
        {
            return (string) $time;
        }

        public static function displayRightError(): void
        {
            throw new RuntimeException('Right error');
        }
    }

    class Log
    {
        public const HISTORY_LOG_SIMPLE_MESSAGE = 20;

        /** @var array */
        public static $entries = [];

        public static function reset(): void
        {
            self::$entries = [];
        }

        public static function history(
            int $items_id,
            string $itemtype,
            array $changes,
            string $itemtype_link = '',
            int $linked_action = 0
        ): void {
            self::$entries[] = [
                'items_id'      => $items_id,
                'itemtype'      => $itemtype,
                'changes'       => $changes,
                'itemtype_link' => $itemtype_link,
                'linked_action' => $linked_action,
            ];
        }
    }

    class DBConnection
    {
        public static function getDefaultCharset(): string
        {
            return 'utf8mb4';
        }

        public static function getDefaultCollation(): string
        {
            return 'utf8mb4_unicode_ci';
        }

        public static function getDefaultPrimaryKeySignOption(): string
        {
            return 'unsigned';
        }
    }

    class EssentialsTestDB
    {
        /** @var array<string, array<int, array>> */
        public $tables = [];

        /** @var array<string, array<int, string>> */
        public $schemas = [
            'glpi_itilcategories' => ['id', 'name', 'completename', 'code', 'entities_id'],
        ];

        /** @var array<string, int> */
        private $autoInc = [];

        public function reset(): void
        {
            $this->tables  = [];
            $this->autoInc = [];
        }

        public function tableExists(string $table): bool
        {
            return array_key_exists($table, $this->tables);
        }

        public function doQuery(string $sql): bool
        {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m) === 1) {
                $table = $m[1];
                if (!isset($this->tables[$table])) {
                    $this->tables[$table]  = [];
                    $this->autoInc[$table] = 1;
                }
                return true;
            }
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m) === 1) {
                $table = $m[1];
                unset($this->tables[$table], $this->autoInc[$table]);
                return true;
            }
            throw new RuntimeException('Unexpected doQuery SQL: ' . $sql);
        }

        public function insert(string $table, array $values)
        {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Table does not exist: ' . $table);
            }
            if ($table === 'glpi_plugin_essentials_sla_pauses') {
                foreach ($this->tables[$table] as $existing) {
                    if (
                        (int) $existing['tickets_id'] === (int) ($values['tickets_id'] ?? 0)
                        && (string) $existing['reason'] === (string) ($values['reason'] ?? '')
                    ) {
                        throw new RuntimeException('Duplicate key uniq_ticket_reason');
                    }
                }
            }
            $id = $this->autoInc[$table]++;
            $row = array_merge(['id' => $id], $values);
            $this->tables[$table][] = $row;
            return $id;
        }

        public function update(string $table, array $values, array $where): bool
        {
            if (!$this->tableExists($table)) {
                return false;
            }
            foreach ($this->tables[$table] as $idx => $row) {
                if ($this->matchesWhere($row, $where)) {
                    foreach ($values as $k => $v) {
                        $this->tables[$table][$idx][$k] = $v;
                    }
                }
            }
            return true;
        }

        public function delete(string $table, array $where): bool
        {
            if (!$this->tableExists($table)) {
                return false;
            }
            $kept = [];
            foreach ($this->tables[$table] as $row) {
                if (!$this->matchesWhere($row, $where)) {
                    $kept[] = $row;
                }
            }
            $this->tables[$table] = $kept;
            return true;
        }

        public function request(array $criteria): ArrayIterator
        {
            $table = (string) ($criteria['FROM'] ?? '');
            if (!$this->tableExists($table)) {
                return new ArrayIterator([]);
            }
            $where = $criteria['WHERE'] ?? [];
            $this->assertValidColumns($table, $criteria['SELECT'] ?? [], $where);
            $rows  = [];
            foreach ($this->tables[$table] as $row) {
                if ($this->matchesWhere($row, $where)) {
                    $rows[] = $row;
                }
            }
            if (!empty($criteria['ORDER']) && is_string($criteria['ORDER'])) {
                $parts = preg_split('/\s+/', trim($criteria['ORDER']));
                $col   = $parts[0] ?? 'id';
                $dir   = strtoupper($parts[1] ?? 'ASC');
                usort($rows, static function ($a, $b) use ($col, $dir) {
                    $va = $a[$col] ?? '';
                    $vb = $b[$col] ?? '';
                    $cmp = $va <=> $vb;
                    return $dir === 'DESC' ? -$cmp : $cmp;
                });
            }
            if (!empty($criteria['SELECT']) && is_array($criteria['SELECT'])) {
                $selectCols = $criteria['SELECT'];
                $projected  = [];
                foreach ($rows as $row) {
                    $item = [];
                    foreach ($selectCols as $col) {
                        $item[$col] = $row[$col] ?? null;
                    }
                    $projected[] = $item;
                }
                $rows = $projected;
            }
            return new ArrayIterator($rows);
        }

        private function assertValidColumns(string $table, array $select, array $where): void
        {
            if (!isset($this->schemas[$table])) {
                return;
            }
            $allowed = $this->schemas[$table];
            foreach ($select as $col) {
                if (is_string($col) && !in_array($col, $allowed, true)) {
                    throw new RuntimeException('Unknown column ' . $col . ' in ' . $table);
                }
            }
            $this->assertValidWhereColumns($table, $allowed, $where);
        }

        private function assertValidWhereColumns(string $table, array $allowed, array $where): void
        {
            foreach ($where as $k => $v) {
                if ($k === 'NOT' && is_array($v)) {
                    $this->assertValidWhereColumns($table, $allowed, $v);
                    continue;
                }
                if (is_int($k) && is_array($v)) {
                    $this->assertValidWhereColumns($table, $allowed, $v);
                    continue;
                }
                if (is_string($k) && !in_array($k, $allowed, true)) {
                    throw new RuntimeException('Unknown column ' . $k . ' in ' . $table);
                }
            }
        }

        private function matchesWhere(array $row, array $where): bool
        {
            foreach ($where as $k => $v) {
                if ($k === 'NOT' && is_array($v)) {
                    if ($this->matchesWhere($row, $v)) {
                        return false;
                    }
                    continue;
                }
                if (is_int($k) && is_array($v)) {
                    if (!$this->matchesWhere($row, $v)) {
                        return false;
                    }
                    continue;
                }
                $actual = $row[$k] ?? null;
                if (is_array($v) && count($v) === 2 && is_string($v[0]) && in_array($v[0], ['<>', '!='], true)) {
                    if ($actual == $v[1]) {
                        return false;
                    }
                    continue;
                }
                if (is_array($v)) {
                    if (!in_array($actual, $v)) {
                        return false;
                    }
                    continue;
                }
                if ($v === null) {
                    if ($actual !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $actual !== (string) $v) {
                    return false;
                }
            }
            return true;
        }
    }

    $DB = new EssentialsTestDB();

    require_once GLPI_ROOT . '/essentials/setup.php';
    require_once GLPI_ROOT . '/essentials/hook.php';
    require_once GLPI_ROOT . '/essentials/inc/compat.class.php';
    require_once GLPI_ROOT . '/essentials/inc/config.class.php';
    require_once GLPI_ROOT . '/essentials/inc/slapause.class.php';
    require_once GLPI_ROOT . '/essentials/inc/uihooks.class.php';
}
