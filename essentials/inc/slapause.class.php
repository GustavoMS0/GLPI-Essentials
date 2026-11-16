<?php

/**
 * ------------------------------------------------------------------------
 * Essentials - Automatic SLA pause for Waiting Approval & Planned status
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

class PluginEssentialsSlapause
{
    public const TABLE = 'glpi_plugin_essentials_sla_pauses';

    public const REASON_APPROVAL = 'approval';
    public const REASON_PLANNED  = 'planned';

    public const STATUS_PLANNED = 3;
    public const STATUS_WAITING = 4;
    public const STATUS_SOLVED  = 5;
    public const STATUS_CLOSED  = 6;

    public const VALIDATION_WAITING = 2;

    /** @var array<int, bool> */
    private static $updatingTickets = [];

    /** @var string|null */
    private static $testNow = null;

    /**
     * Allows deterministic time injection in unit tests.
     *
     * @param string|null $now
     * @return void
     */
    public static function setTestNow(?string $now): void
    {
        self::$testNow = $now;
    }

    /**
     * Returns current timestamp string consistent with GLPI session time.
     *
     * @return string
     */
    public static function currentNow(): string
    {
        if (self::$testNow !== null && self::$testNow !== '') {
            return self::$testNow;
        }
        if (isset($_SESSION['glpi_currenttime']) && is_string($_SESSION['glpi_currenttime']) && $_SESSION['glpi_currenttime'] !== '') {
            return $_SESSION['glpi_currenttime'];
        }
        return date('Y-m-d H:i:s');
    }

    /**
     * Creates plugin database schema during install/upgrade.
     *
     * @return void
     */
    public static function installSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

        $charset = class_exists('DBConnection') && method_exists('DBConnection', 'getDefaultCharset')
            ? DBConnection::getDefaultCharset()
            : 'utf8mb4';
        $collation = class_exists('DBConnection') && method_exists('DBConnection', 'getDefaultCollation')
            ? DBConnection::getDefaultCollation()
            : 'utf8mb4_unicode_ci';
        $sign = class_exists('DBConnection') && method_exists('DBConnection', 'getDefaultPrimaryKeySignOption')
            ? DBConnection::getDefaultPrimaryKeySignOption()
            : 'unsigned';

        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`id` int ' . $sign . ' NOT NULL AUTO_INCREMENT,'
            . '`tickets_id` int ' . $sign . ' NOT NULL DEFAULT 0,'
            . '`reason` varchar(32) NOT NULL DEFAULT \'\','
            . '`paused_at` timestamp NULL DEFAULT NULL,'
            . '`slas_id` int ' . $sign . ' NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uniq_ticket_reason` (`tickets_id`, `reason`),'
            . 'KEY `idx_tickets_id` (`tickets_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collation . ' ROW_FORMAT=DYNAMIC';

        self::execDdl($DB, $sql);
    }

    /**
     * Drops plugin database schema during uninstall.
     *
     * @return void
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            self::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }
    }

    /**
     * Executes DDL compatibly with GLPI 10.0.0–10.0.10 (query) and 10.0.11+ (doQuery).
     *
     * @param mixed $db
     */
    private static function execDdl($db, string $sql): void
    {
        if (method_exists($db, 'doQuery')) {
            $db->doQuery($sql);
            return;
        }
        if (method_exists($db, 'query')) {
            $db->query($sql);
        }
    }

    /**
     * Hook handler for Ticket item_add / item_update.
     *
     * @param mixed $item
     * @return void
     */
    public static function onTicketChange($item): void
    {
        if (!($item instanceof Ticket)) {
            return;
        }
        $ticketId = (int) ($item->fields['id'] ?? 0);
        if ($ticketId <= 0 || !empty(self::$updatingTickets[$ticketId])) {
            return;
        }
        self::syncTicket($item);
    }

    /**
     * Hook handler for TicketValidation item_add / item_update / item_delete / item_purge.
     *
     * @param mixed $item
     * @return void
     */
    public static function onValidationChange($item): void
    {
        if (!($item instanceof TicketValidation)) {
            return;
        }
        $ticketsId = (int) ($item->fields['tickets_id'] ?? 0);
        if ($ticketsId <= 0 || !empty(self::$updatingTickets[$ticketsId])) {
            return;
        }
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketsId)) {
            self::syncTicket($ticket);
        }
    }

    /**
     * Hook handler for Ticket item_purge.
     *
     * @param mixed $item
     * @return void
     */
    public static function onTicketPurge($item): void
    {
        if (!($item instanceof Ticket)) {
            return;
        }
        $ticketId = (int) ($item->fields['id'] ?? 0);
        if ($ticketId > 0) {
            self::deleteByTicket($ticketId);
        }
    }

    /**
     * Deletes all SLA pause rows associated with a ticket.
     *
     * @param int $ticketId
     * @return void
     */
    public static function deleteByTicket(int $ticketId): void
    {
        global $DB;

        if ($ticketId <= 0) {
            return;
        }
        $DB->delete(self::TABLE, ['tickets_id' => $ticketId]);
    }

    /**
     * Returns all open pause records for a ticket.
     *
     * @param int $ticketId
     * @return array
     */
    public static function getActivePauses(int $ticketId): array
    {
        global $DB;

        if ($ticketId <= 0 || !$DB->tableExists(self::TABLE)) {
            return [];
        }

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => 'paused_at ASC',
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Synchronizes pause state for a Ticket based on current fields and config.
     *
     * @param Ticket $ticket
     * @param string $now
     * @return void
     */
    public static function syncTicket(Ticket $ticket, string $now = ''): void
    {
        global $DB;

        $ticketId = (int) ($ticket->fields['id'] ?? 0);
        if ($ticketId <= 0 || !empty(self::$updatingTickets[$ticketId]) || !$DB->tableExists(self::TABLE)) {
            return;
        }

        $nowStr           = $now !== '' ? $now : self::currentNow();
        $status           = (int) ($ticket->fields['status'] ?? 0);
        $globalValidation = (int) ($ticket->fields['global_validation'] ?? 0);
        $isDeleted        = ((int) ($ticket->fields['is_deleted'] ?? 0)) === 1;
        $timeToResolve    = trim((string) ($ticket->fields['time_to_resolve'] ?? ''));
        $hasTtr           = ($timeToResolve !== '' && $timeToResolve !== '0000-00-00 00:00:00');
        $isSolvedOrClosed = in_array($status, [self::STATUS_SOLVED, self::STATUS_CLOSED], true);
        $isWaiting        = ($status === self::STATUS_WAITING);

        // Plugin pauses may only accrue outside WAITING/SOLVED/CLOSED and while TTR is set
        $canAccrueNew       = ($hasTtr && !$isDeleted && !$isSolvedOrClosed && !$isWaiting);
        // Closing an existing open pause on WAITING/SOLVED/CLOSED still compensates elapsed time up to now
        $canCompensateClose = ($hasTtr && !$isDeleted);

        $desired = [];
        if ($canAccrueNew) {
            $cfg = PluginEssentialsConfig::getAll();
            if (!empty($cfg['pause_sla_planned']) && $status === self::STATUS_PLANNED) {
                $desired[self::REASON_PLANNED] = true;
            }
            if (!empty($cfg['pause_sla_approval']) && $globalValidation === self::VALIDATION_WAITING) {
                $desired[self::REASON_APPROVAL] = true;
            }
        }

        $existingRows = self::getActivePauses($ticketId);
        if (empty($existingRows) && empty($desired)) {
            return;
        }

        $existingByReason = [];
        $toClose          = [];
        $remaining        = [];

        foreach ($existingRows as $row) {
            $reason = (string) ($row['reason'] ?? '');
            $existingByReason[$reason] = $row;
            if (isset($desired[$reason])) {
                $remaining[] = $row;
            } else {
                $toClose[] = $row;
            }
        }

        if (!empty($toClose)) {
            foreach ($toClose as $closeRow) {
                $DB->delete(self::TABLE, ['id' => (int) $closeRow['id']]);
            }

            if ($canCompensateClose) {
                $earliestClosed = self::findEarliestRow($toClose);
                $earliestPausedAt = (string) ($earliestClosed['paused_at'] ?? '');
                $earliestSlasId   = (int) ($earliestClosed['slas_id'] ?? 0);

                if (!empty($remaining)) {
                    // Carry earliest pause timestamp forward onto remaining active row(s)
                    // so continuous overlapping pauses neither double-count nor lose prior time.
                    if ($earliestPausedAt !== '') {
                        $earliestClosedTs = strtotime($earliestPausedAt);
                        foreach ($remaining as $remRow) {
                            $remTs = strtotime((string) ($remRow['paused_at'] ?? ''));
                            if ($earliestClosedTs !== false && ($remTs === false || $earliestClosedTs < $remTs)) {
                                $DB->update(
                                    self::TABLE,
                                    PluginEssentialsCompat::dbValues([
                                        'paused_at' => $earliestPausedAt,
                                        'slas_id'   => $earliestSlasId > 0 ? $earliestSlasId : (int) ($remRow['slas_id'] ?? 0),
                                    ]),
                                    ['id' => (int) $remRow['id']]
                                );
                            }
                        }
                    }
                } elseif ($earliestPausedAt !== '') {
                    $slasId = $earliestSlasId > 0
                        ? $earliestSlasId
                        : (int) ($ticket->fields['slas_id_ttr'] ?? 0);
                    $entitiesId = (int) ($ticket->fields['entities_id'] ?? 0);
                    $sla        = null;
                    if ($slasId > 0 && class_exists('SLA')) {
                        $candidate = new SLA();
                        if ($candidate->getFromDB($slasId)) {
                            $sla = $candidate;
                        }
                    }
                    $seconds = self::computeActivePauseSeconds($earliestPausedAt, $nowStr, $sla, $entitiesId);
                    if ($seconds > 0) {
                        self::applyPauseCompensation($ticket, $seconds);
                    }
                }
            }
        }

        if ($canAccrueNew) {
            $ticketSlasId = (int) ($ticket->fields['slas_id_ttr'] ?? 0);
            foreach (array_keys($desired) as $reason) {
                if (!isset($existingByReason[$reason])) {
                    $DB->insert(
                        self::TABLE,
                        PluginEssentialsCompat::dbValues([
                            'tickets_id' => $ticketId,
                            'reason'     => $reason,
                            'paused_at'  => $nowStr,
                            'slas_id'    => $ticketSlasId,
                        ])
                    );
                }
            }
        }
    }

    /**
     * Resolves the calendar ID for an SLA, falling back to the ticket entity calendar
     * when use_ticket_calendar is enabled.
     *
     * @param SLA|null $sla
     * @param int $entitiesId
     * @return int
     */
    private static function resolveCalendarId(?SLA $sla, int $entitiesId): int
    {
        if ($sla === null) {
            return 0;
        }
        $slaCalId = (int) ($sla->fields['calendars_id'] ?? 0);
        if ($slaCalId > 0) {
            return $slaCalId;
        }
        if (
            !empty($sla->fields['use_ticket_calendar'])
            && class_exists('Entity')
            && method_exists('Entity', 'getUsedConfig')
        ) {
            $entityCalId = (int) Entity::getUsedConfig('calendars_id', $entitiesId);
            if ($entityCalId > 0) {
                return $entityCalId;
            }
        }
        return 0;
    }

    /**
     * Computes active elapsed seconds between $from and $to using the SLA Calendar
     * (or ticket entity calendar when use_ticket_calendar is enabled), falling back
     * to wall-clock seconds when no calendar is linked.
     *
     * @param string $from
     * @param string $to
     * @param SLA|null $sla
     * @param int $entitiesId
     * @return int
     */
    public static function computeActivePauseSeconds(
        string $from,
        string $to,
        ?SLA $sla = null,
        int $entitiesId = 0
    ): int {
        $fromTs = strtotime($from);
        $toTs   = strtotime($to);
        if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
            return 0;
        }

        $calId = self::resolveCalendarId($sla, $entitiesId);
        if ($calId > 0 && class_exists('Calendar')) {
            $cal = new Calendar();
            if ($cal->getFromDB($calId)) {
                $active = (int) $cal->getActiveTimeBetween($from, $to);
                return max(0, $active);
            }
        }

        return max(0, $toTs - $fromTs);
    }

    /**
     * Compensates Ticket SLA waiting duration and time_to_resolve cleanly.
     *
     * @param Ticket $ticket
     * @param int $seconds
     * @return void
     */
    public static function applyPauseCompensation(Ticket $ticket, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $ticketId = (int) ($ticket->fields['id'] ?? 0);
        if ($ticketId <= 0 || !empty(self::$updatingTickets[$ticketId])) {
            return;
        }

        $currentTtr = trim((string) ($ticket->fields['time_to_resolve'] ?? ''));
        if ($currentTtr === '' || $currentTtr === '0000-00-00 00:00:00') {
            return;
        }

        $currentWaiting = (int) ($ticket->fields['sla_waiting_duration'] ?? 0);
        $newWaiting     = $currentWaiting + $seconds;
        $slasId         = (int) ($ticket->fields['slas_id_ttr'] ?? 0);
        $entitiesId     = (int) ($ticket->fields['entities_id'] ?? 0);
        $ticketDate     = trim((string) ($ticket->fields['date'] ?? ''));
        $newTtr         = '';

        if ($slasId > 0 && $ticketDate !== '' && class_exists('SLA')) {
            $sla = new SLA();
            if ($sla->getFromDB($slasId)) {
                if (!empty($sla->fields['use_ticket_calendar'])) {
                    $ticketCalId = (class_exists('Entity') && method_exists('Entity', 'getUsedConfig'))
                        ? (int) Entity::getUsedConfig('calendars_id', $entitiesId)
                        : 0;
                    if ($ticketCalId > 0 && method_exists($sla, 'setTicketCalendar')) {
                        $sla->setTicketCalendar($ticketCalId);
                    }
                }
                $computed = $sla->computeDate($ticketDate, $newWaiting);
                if (is_string($computed) && $computed !== '') {
                    $newTtr = $computed;
                }
            }
        }

        if ($newTtr === '') {
            $ttrTs = strtotime($currentTtr);
            if ($ttrTs === false) {
                return;
            }
            $newTtr = date('Y-m-d H:i:s', $ttrTs + $seconds);
        }

        self::$updatingTickets[$ticketId] = true;
        try {
            $updateInput = PluginEssentialsCompat::coreInput([
                'id'                   => $ticketId,
                'sla_waiting_duration' => $newWaiting,
                'time_to_resolve'      => $newTtr,
            ]);

            if ($ticket->update($updateInput)) {
                $ticket->fields['sla_waiting_duration'] = $newWaiting;
                $ticket->fields['time_to_resolve']      = $newTtr;

                if (class_exists('Log') && method_exists('Log', 'history')) {
                    $simpleAction = defined('Log::HISTORY_LOG_SIMPLE_MESSAGE')
                        ? Log::HISTORY_LOG_SIMPLE_MESSAGE
                        : 20;
                    $message = sprintf(
                        __('SLA compensado pelo plugin Essentials: +%s (novo prazo: %s).', 'essentials'),
                        self::formatDuration($seconds),
                        $newTtr
                    );
                    Log::history($ticketId, 'Ticket', [0, '', $message], '', $simpleAction);
                }
            }
        } finally {
            unset(self::$updatingTickets[$ticketId]);
        }
    }

    /**
     * Returns the row with the earliest paused_at timestamp.
     *
     * @param array $rows
     * @return array
     */
    private static function findEarliestRow(array $rows): array
    {
        $earliest   = $rows[0];
        $earliestTs = strtotime((string) ($earliest['paused_at'] ?? ''));

        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['paused_at'] ?? ''));
            if ($ts !== false && ($earliestTs === false || $ts < $earliestTs)) {
                $earliest   = $row;
                $earliestTs = $ts;
            }
        }
        return $earliest;
    }

    /**
     * Formats seconds into a concise human-readable duration.
     *
     * @param int $seconds
     * @return string
     */
    private static function formatDuration(int $seconds): string
    {
        $hours   = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = $secs . 's';
        }
        return implode(' ', $parts);
    }
}
