<?php

/**
 * Standalone unit tests for Essentials GLPI plugin.
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 */

$testMode = getenv('ESSENTIALS_TEST_MODE');
if ($testMode === 'autoload_untyped' || $testMode === 'autoload_typed') {
    define('GLPI_ROOT', dirname(__DIR__));
    $typed = ($testMode === 'autoload_typed');
    spl_autoload_register(static function ($class) use ($typed) {
        if ($class === 'CommonGLPI') {
            if ($typed) {
                eval('class CommonGLPI { public static string $rightname = ""; }');
            } else {
                eval('class CommonGLPI { public static $rightname = ""; }');
            }
        }
    });
    require_once GLPI_ROOT . '/essentials/inc/compat.class.php';
    if (class_exists('CommonGLPI', false)) {
        fwrite(STDERR, "CommonGLPI should not be loaded prior to typedRightname()\n");
        exit(1);
    }
    $detected = PluginEssentialsCompat::typedRightname();
    if ($detected !== $typed) {
        fwrite(STDERR, "typedRightname autoload check mismatch\n");
        exit(1);
    }
    echo "Autoload check OK\n";
    exit(0);
}

require_once __DIR__ . '/bootstrap.php';

if ($testMode === 'ajax_403') {
    Session::$userId = 2;
    Session::$rights = [];
    $DB->tables['glpi_itilcategories'] = [
        ['id' => 1, 'name' => 'Hardware', 'completename' => 'Hardware', 'code' => 'HW01', 'entities_id' => 0],
    ];
    include GLPI_ROOT . '/essentials/ajax/categories.php';
    exit(0);
}

function essentials_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function essentials_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                'Assertion failed (%s): expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function essentials_run_subprocess(array $envOverrides, ?int &$exitCode = null): string
{
    $saved = [];
    foreach ($envOverrides as $key => $val) {
        $prev         = getenv($key);
        $saved[$key]  = $prev;
        if ($val === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $val);
        }
    }

    $output = [];
    $code   = 0;
    $cmd    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
    exec($cmd, $output, $code);

    foreach ($saved as $key => $prev) {
        if ($prev === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $prev);
        }
    }

    $exitCode = $code;
    return implode("\n", $output);
}

function essentials_reset_env(): void
{
    global $DB, $PLUGIN_HOOKS;

    $DB->reset();
    Config::reset();
    Ticket::resetFixtures();
    Entity::resetFixtures();
    SLA::resetFixtures();
    Calendar::resetFixtures();
    Log::reset();
    PluginEssentialsSlapause::setTestNow(null);
    PluginEssentialsUihooks::resetRenderGuards();
    $PLUGIN_HOOKS = [];
    Session::$userId = 2;
    Session::$rights = [
        'config'       => 3,
        'ticket'       => 7,
        'itilcategory' => 1,
    ];
    plugin_essentials_install();
}

function test_starts_pause_on_planned_or_waiting_validation_only_when_not_in_waiting(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_planned'  => 1,
        'pause_sla_approval' => 1,
    ]);
    PluginEssentialsSlapause::setTestNow('2026-11-16 09:00:00');

    // Ticket without time_to_resolve must NOT create any pause
    $ticketNoTtr = new Ticket();
    $ticketNoTtr->fields = [
        'id'                   => 101,
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => null,
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    PluginEssentialsSlapause::onTicketChange($ticketNoTtr);
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(101)), 'No pause when time_to_resolve is null');

    // Deleted ticket must NOT create any pause
    $ticketDeleted = new Ticket();
    $ticketDeleted->fields = [
        'id'                   => 102,
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 1,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    PluginEssentialsSlapause::onTicketChange($ticketDeleted);
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(102)), 'No pause when ticket is deleted');

    // Ticket in SOLVED / CLOSED must NOT create any pause
    foreach ([CommonITILObject::SOLVED, CommonITILObject::CLOSED] as $idx => $closedStatus) {
        $ticketClosed = new Ticket();
        $ticketClosed->fields = [
            'id'                   => 103 + $idx,
            'status'               => $closedStatus,
            'global_validation'    => CommonITILValidation::WAITING,
            'time_to_resolve'      => '2026-11-16 14:00:00',
            'is_deleted'           => 0,
            'slas_id_ttr'          => 0,
            'sla_waiting_duration' => 0,
        ];
        PluginEssentialsSlapause::onTicketChange($ticketClosed);
        essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(103 + $idx)), 'No pause when solved/closed');
    }

    // Ticket in WAITING (4) must NOT create any pause even with waiting validation
    $ticketWaiting = new Ticket();
    $ticketWaiting->fields = [
        'id'                   => 105,
        'status'               => CommonITILObject::WAITING,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    PluginEssentialsSlapause::onTicketChange($ticketWaiting);
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(105)), 'No pause while ticket is in native WAITING');

    // Ticket in PLANNED (3) starts 'planned' pause
    $ticketPlanned = new Ticket();
    $ticketPlanned->fields = [
        'id'                   => 106,
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    PluginEssentialsSlapause::onTicketChange($ticketPlanned);
    $pauses106 = PluginEssentialsSlapause::getActivePauses(106);
    essentials_assert_same(1, count($pauses106), 'One pause created for PLANNED');
    essentials_assert_same(PluginEssentialsSlapause::REASON_PLANNED, $pauses106[0]['reason'], 'Reason is planned');

    // Ticket in ASSIGNED (2) with TicketValidation change -> 'approval' pause
    Ticket::$store[107] = [
        'id'                   => 107,
        'status'               => CommonITILObject::ASSIGNED,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => '2026-11-16 15:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $validation = new TicketValidation();
    $validation->fields = ['id' => 1, 'tickets_id' => 107];
    PluginEssentialsSlapause::onValidationChange($validation);

    $pauses107 = PluginEssentialsSlapause::getActivePauses(107);
    essentials_assert_same(1, count($pauses107), 'One pause created via TicketValidation');
    essentials_assert_same(PluginEssentialsSlapause::REASON_APPROVAL, $pauses107[0]['reason'], 'Reason is approval');
}

function test_closes_pause_using_calendar_active_seconds_and_sla_computeDate(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_planned' => 1,
    ]);

    Calendar::$fixtures[5] = [
        'id'             => 5,
        'active_seconds' => 3600, // 1 hour active in business calendar even though wall-clock is 2h
    ];
    SLA::$fixtures[10] = [
        'id'           => 10,
        'calendars_id' => 5,
        'base_seconds' => 14400, // 4 hours from ticket date
    ];

    Ticket::$store[201] = [
        'id'                   => 201,
        'date'                 => '2026-11-16 08:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 12:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 10,
        'sla_waiting_duration' => 0,
    ];

    $ticket = new Ticket();
    $ticket->getFromDB(201);
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:00:00');
    essentials_assert_same(1, count(PluginEssentialsSlapause::getActivePauses(201)), 'Pause open at 10:00');

    // Exit PLANNED at 12:00:00 (2h wall-clock, 1h calendar active time)
    $ticket->fields['status'] = CommonITILObject::ASSIGNED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 12:00:00');

    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(201)), 'Pause closed after exiting PLANNED');
    essentials_assert_same(1, count(Calendar::$activeTimeCalls), 'Calendar::getActiveTimeBetween called once');
    essentials_assert_same(2, Calendar::$activeTimeCalls[0]['arg_count'], 'Calendar::getActiveTimeBetween called with 2 args');
    essentials_assert_same('2026-11-16 10:00:00', Calendar::$activeTimeCalls[0]['start'], 'Calendar start timestamp matches');
    essentials_assert_same('2026-11-16 12:00:00', Calendar::$activeTimeCalls[0]['end'], 'Calendar end timestamp matches');

    essentials_assert_same(1, count(SLA::$computeCalls), 'SLA::computeDate called once');
    essentials_assert_same('2026-11-16 08:00:00', SLA::$computeCalls[0]['start_date'], 'SLA::computeDate uses ticket opening date');
    essentials_assert_same(3600, SLA::$computeCalls[0]['additional_delay'], 'SLA::computeDate uses calendar active seconds');

    essentials_assert_same(3600, (int) $ticket->fields['sla_waiting_duration'], 'sla_waiting_duration updated with active pause seconds');
    essentials_assert_same('2026-11-16 13:00:00', $ticket->fields['time_to_resolve'], 'time_to_resolve recomputed via SLA::computeDate');
    essentials_assert_same(1, Ticket::$updateCallCount, 'Anti-reentrancy prevented duplicate Ticket::update');
    essentials_assert_same(1, count(Log::$entries), 'Log::history entry recorded');
    essentials_assert_same(Log::HISTORY_LOG_SIMPLE_MESSAGE, Log::$entries[0]['linked_action'], 'Log action is simple message');
}

function test_wall_clock_fallback_when_no_sla_calendar(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_approval' => 1,
    ]);

    Ticket::$store[301] = [
        'id'                   => 301,
        'date'                 => '2026-11-16 09:00:00',
        'status'               => CommonITILObject::ASSIGNED,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => '2026-11-16 15:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 600,
    ];

    $ticket = new Ticket();
    $ticket->getFromDB(301);
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:00:00');
    essentials_assert_same(1, count(PluginEssentialsSlapause::getActivePauses(301)), 'Approval pause open');

    $ticket->fields['global_validation'] = CommonITILValidation::ACCEPTED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:45:00'); // 2700s

    essentials_assert_same(0, count(Calendar::$activeTimeCalls), 'Calendar not called when slas_id_ttr is 0');
    essentials_assert_same(3300, (int) $ticket->fields['sla_waiting_duration'], '600 + 2700 wall-clock seconds');
    essentials_assert_same('2026-11-16 15:45:00', $ticket->fields['time_to_resolve'], 'Manual TTR shifted by 45 minutes');
}

function test_avoids_double_pause_when_ticket_stays_in_other_active_pause_reason(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_planned'  => 1,
        'pause_sla_approval' => 1,
    ]);

    // Case 1: planned starts at 10:00, approval starts at 10:30, approval exits at 11:00, planned exits at 12:00
    Ticket::$store[401] = [
        'id'                   => 401,
        'date'                 => '2026-11-16 08:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $ticket = new Ticket();
    $ticket->getFromDB(401);

    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:00:00');
    $ticket->fields['global_validation'] = CommonITILValidation::WAITING;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:30:00');
    essentials_assert_same(2, count(PluginEssentialsSlapause::getActivePauses(401)), 'Both pause reasons active');

    // Approval exits at 11:00 while PLANNED remains active
    $ticket->fields['global_validation'] = CommonITILValidation::ACCEPTED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 11:00:00');
    essentials_assert_same(1, count(PluginEssentialsSlapause::getActivePauses(401)), 'Planned remains active');
    essentials_assert_same(0, (int) $ticket->fields['sla_waiting_duration'], 'No compensation while other reason stays active');
    essentials_assert_same(0, Ticket::$updateCallCount, 'Ticket not updated yet');

    // Planned exits at 12:00
    $ticket->fields['status'] = CommonITILObject::ASSIGNED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 12:00:00');
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(401)), 'All pauses closed');
    essentials_assert_same(7200, (int) $ticket->fields['sla_waiting_duration'], 'Full 2h compensated once');
    essentials_assert_same('2026-11-16 16:00:00', $ticket->fields['time_to_resolve'], 'TTR advanced by 2h');

    // Case 2: reverse exit order (planned starts 10:00, approval starts 10:30, planned exits FIRST at 11:00, approval exits 12:00)
    Ticket::$updateCallCount = 0;
    Ticket::$store[402] = [
        'id'                   => 402,
        'date'                 => '2026-11-16 08:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $ticket2 = new Ticket();
    $ticket2->getFromDB(402);

    PluginEssentialsSlapause::syncTicket($ticket2, '2026-11-16 10:00:00');
    $ticket2->fields['global_validation'] = CommonITILValidation::WAITING;
    PluginEssentialsSlapause::syncTicket($ticket2, '2026-11-16 10:30:00');

    // Planned exits FIRST at 11:00 while approval remains active
    $ticket2->fields['status'] = CommonITILObject::ASSIGNED;
    PluginEssentialsSlapause::syncTicket($ticket2, '2026-11-16 11:00:00');
    $rem402 = PluginEssentialsSlapause::getActivePauses(402);
    essentials_assert_same(1, count($rem402), 'Approval remains active');
    essentials_assert_same('2026-11-16 10:00:00', $rem402[0]['paused_at'], 'Earliest paused_at preserved across overlapping pause');
    essentials_assert_same(0, (int) $ticket2->fields['sla_waiting_duration'], 'No early compensation on partial exit');

    // Approval exits at 12:00
    $ticket2->fields['global_validation'] = CommonITILValidation::ACCEPTED;
    PluginEssentialsSlapause::syncTicket($ticket2, '2026-11-16 12:00:00');
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(402)), 'All pauses closed');
    essentials_assert_same(7200, (int) $ticket2->fields['sla_waiting_duration'], 'Full 2h compensated without loss or double count');
    essentials_assert_same('2026-11-16 16:00:00', $ticket2->fields['time_to_resolve'], 'TTR advanced by 2h in reverse exit order');
}

function test_no_double_pause_when_entering_native_waiting(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_planned'  => 1,
        'pause_sla_approval' => 1,
    ]);

    Ticket::$store[501] = [
        'id'                   => 501,
        'date'                 => '2026-11-16 09:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 16:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $ticket = new Ticket();
    $ticket->getFromDB(501);

    // 10:00 enters PLANNED -> plugin pause starts
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:00:00');
    essentials_assert_same(1, count(PluginEssentialsSlapause::getActivePauses(501)), 'Plugin pause active while PLANNED');

    // 10:30 transitions straight into native WAITING (4) -> closes plugin pause up to 10:30 (1800s)
    $ticket->fields['status'] = CommonITILObject::WAITING;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:30:00');
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(501)), 'Plugin pause closed upon entering native WAITING');
    essentials_assert_same(1800, (int) $ticket->fields['sla_waiting_duration'], 'Plugin compensated [10:00, 10:30] before core WAITING');
    essentials_assert_same('2026-11-16 16:30:00', $ticket->fields['time_to_resolve'], 'TTR extended by 30m');

    // 11:00 while still in WAITING (4), validation becomes WAITING (2) -> plugin must NOT start pause
    $ticket->fields['global_validation'] = CommonITILValidation::WAITING;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 11:00:00');
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(501)), 'No plugin pause while in native WAITING');

    // 12:00 exits WAITING (4) -> ASSIGNED (2) with global_validation still WAITING (2) -> plugin starts fresh approval pause at 12:00
    $ticket->fields['status'] = CommonITILObject::ASSIGNED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 12:00:00');
    $pausesAfterWaiting = PluginEssentialsSlapause::getActivePauses(501);
    essentials_assert_same(1, count($pausesAfterWaiting), 'Fresh approval pause starts after leaving WAITING');
    essentials_assert_same(PluginEssentialsSlapause::REASON_APPROVAL, $pausesAfterWaiting[0]['reason'], 'Reason is approval');
    essentials_assert_same('2026-11-16 12:00:00', $pausesAfterWaiting[0]['paused_at'], 'Approval pause starts at exit from WAITING');
}

function test_typed_and_untyped_rightname_compat(): void
{
    $isTypedEnv = getenv('ESSENTIALS_TEST_TYPED_RIGHTNAME') === '1';
    $commonProp = new ReflectionProperty('CommonGLPI', 'rightname');
    $configProp = new ReflectionProperty('PluginEssentialsConfig', 'rightname');

    essentials_assert_same($isTypedEnv, PluginEssentialsCompat::typedRightname(), 'typedRightname matches CommonGLPI');
    essentials_assert_same($commonProp->hasType(), $configProp->hasType(), 'PluginEssentialsConfig::$rightname typing matches CommonGLPI');
    essentials_assert_same('config', PluginEssentialsConfig::$rightname, 'PluginEssentialsConfig::$rightname is config');

    essentials_assert_same('/glpi/plugins/essentials/ajax/categories.php', PluginEssentialsCompat::webPath('ajax/categories.php'), 'webPath resolves plugin path across GLPI versions');

    $marketplacePath = PluginEssentialsCompat::webPath('ajax/categories.php', 'essentials', '/var/www/glpi/marketplace/essentials');
    if (PluginEssentialsCompat::isGlpi11OrHigher()) {
        essentials_assert_same('/glpi/plugins/essentials/ajax/categories.php', $marketplacePath, 'GLPI 11/12 always routes plugins under /plugins/');
        essentials_assert_same('css/essentials.css', PluginEssentialsCompat::assetPath('css/essentials.css'), 'GLPI 11/12 omits public/ prefix');
        essentials_assert_same('A&#38;B', PluginEssentialsCompat::fromCore('A&#38;B'), 'GLPI 11/12 leaves core strings raw');
    } else {
        essentials_assert_same('/glpi/marketplace/essentials/ajax/categories.php', $marketplacePath, 'GLPI 10 preserves marketplace/ web segment when installed there');
        essentials_assert_same('public/css/essentials.css', PluginEssentialsCompat::assetPath('css/essentials.css'), 'GLPI 10 adds public/ prefix');
        essentials_assert_same('A&B <1> \\ Share', PluginEssentialsCompat::fromCore('A&#38;B &#60;1&#62; \\ Share'), 'GLPI 10 decodes HTML entities without stripping backslashes');
        $sanitized = PluginEssentialsCompat::coreInput(['name' => 'A&B']);
        essentials_assert_same('A&#38;B', $sanitized['name'], 'GLPI 10 coreInput sanitizes for CommonDBTM');
    }

    // Verify denyAccess() behavior per GLPI major version
    if (PluginEssentialsCompat::glpiMajor() >= 12) {
        $threwAccessDenied = false;
        try {
            PluginEssentialsCompat::denyAccess();
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
            $threwAccessDenied = true;
        }
        essentials_assert($threwAccessDenied, 'GLPI 12 denyAccess() throws AccessDeniedHttpException');
    } else {
        $threwRightError = false;
        try {
            PluginEssentialsCompat::denyAccess();
        } catch (RuntimeException $e) {
            $threwRightError = (strpos($e->getMessage(), 'Right error') !== false);
        }
        essentials_assert($threwRightError, 'GLPI 10/11 denyAccess() delegates to Html::displayRightError()');
    }

    // Spawn subprocesses simulating GLPI 11.0.0, GLPI 12.0.0, and lazy CommonGLPI autoloading
    if (getenv('ESSENTIALS_SUBPROCESS') !== '1' && defined('PHP_BINARY')) {
        $exit11 = 0;
        $out11  = essentials_run_subprocess([
            'ESSENTIALS_SUBPROCESS'           => '1',
            'ESSENTIALS_TEST_TYPED_RIGHTNAME' => '0',
            'ESSENTIALS_TEST_GLPI_VERSION'    => '11.0.0',
            'ESSENTIALS_TEST_MODE'            => null,
        ], $exit11);
        essentials_assert_same(0, $exit11, 'GLPI 11.0.0 subprocess failed: ' . $out11);

        $exit12 = 0;
        $out12  = essentials_run_subprocess([
            'ESSENTIALS_SUBPROCESS'           => '1',
            'ESSENTIALS_TEST_TYPED_RIGHTNAME' => '1',
            'ESSENTIALS_TEST_GLPI_VERSION'    => '12.0.0',
            'ESSENTIALS_TEST_MODE'            => null,
        ], $exit12);
        essentials_assert_same(0, $exit12, 'GLPI 12.0.0 subprocess failed: ' . $out12);

        $exitAutoUntyped = 0;
        $outAutoUntyped  = essentials_run_subprocess([
            'ESSENTIALS_TEST_MODE' => 'autoload_untyped',
        ], $exitAutoUntyped);
        essentials_assert_same(0, $exitAutoUntyped, 'Autoload untyped CommonGLPI check failed: ' . $outAutoUntyped);

        $exitAutoTyped = 0;
        $outAutoTyped  = essentials_run_subprocess([
            'ESSENTIALS_TEST_MODE' => 'autoload_typed',
        ], $exitAutoTyped);
        essentials_assert_same(0, $exitAutoTyped, 'Autoload typed CommonGLPI check failed: ' . $outAutoTyped);
    }
}

function test_use_ticket_calendar_and_direct_solve_or_close_compensation(): void
{
    essentials_reset_env();

    PluginEssentialsConfig::save([
        'pause_sla_planned'  => 1,
        'pause_sla_approval' => 1,
    ]);

    // 1. SLA with use_ticket_calendar = 1 delegates calendar lookup to Entity::getUsedConfig and calls setTicketCalendar()
    Entity::$configs[7]['calendars_id'] = 9;
    Calendar::$fixtures[9] = [
        'id'             => 9,
        'active_seconds' => 1800, // 30m active inside entity calendar for 2h wall-clock interval
    ];
    SLA::$fixtures[20] = [
        'id'                  => 20,
        'calendars_id'        => 0,
        'use_ticket_calendar' => 1,
        'base_seconds'        => 14400,
    ];

    Ticket::$store[601] = [
        'id'                   => 601,
        'entities_id'          => 7,
        'date'                 => '2026-11-16 08:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 12:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 20,
        'sla_waiting_duration' => 0,
    ];

    $ticket = new Ticket();
    $ticket->getFromDB(601);
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 10:00:00');
    essentials_assert_same(1, count(PluginEssentialsSlapause::getActivePauses(601)), 'Planned pause opened for entity calendar SLA');

    $ticket->fields['status'] = CommonITILObject::ASSIGNED;
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 12:00:00');

    essentials_assert_same(1, count(Calendar::$activeTimeCalls), 'Entity calendar getActiveTimeBetween invoked');
    essentials_assert_same(9, Calendar::$activeTimeCalls[0]['calendars_id'], 'Entity calendar #9 resolved via use_ticket_calendar');
    essentials_assert_same(1, count(SLA::$setTicketCalendarCalls), 'SLA::setTicketCalendar invoked');
    essentials_assert_same(9, SLA::$setTicketCalendarCalls[0]['calendars_id'], 'SLA::setTicketCalendar received entity calendar #9');
    essentials_assert_same(9, SLA::$computeCalls[0]['ticket_calendar_id'], 'Ticket calendar set before SLA::computeDate');
    essentials_assert_same(1800, (int) $ticket->fields['sla_waiting_duration'], 'sla_waiting_duration updated with entity calendar seconds');
    essentials_assert_same('2026-11-16 12:30:00', $ticket->fields['time_to_resolve'], 'TTR recomputed using entity calendar');

    // 2. Direct PLANNED (3) -> SOLVED (5) transition compensates elapsed pause before closing
    Ticket::$store[602] = [
        'id'                   => 602,
        'entities_id'          => 0,
        'date'                 => '2026-11-16 09:00:00',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $ticketSolve = new Ticket();
    $ticketSolve->getFromDB(602);
    PluginEssentialsSlapause::syncTicket($ticketSolve, '2026-11-16 10:00:00');

    $ticketSolve->fields['status'] = CommonITILObject::SOLVED;
    PluginEssentialsSlapause::syncTicket($ticketSolve, '2026-11-16 11:30:00'); // 5400s
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(602)), 'Pause deleted when moving directly to SOLVED');
    essentials_assert_same(5400, (int) $ticketSolve->fields['sla_waiting_duration'], 'Elapsed pause compensated on direct PLANNED -> SOLVED');
    essentials_assert_same('2026-11-16 15:30:00', $ticketSolve->fields['time_to_resolve'], 'TTR updated on direct PLANNED -> SOLVED');

    // 3. Direct pending approval -> CLOSED (6) transition compensates elapsed pause
    Ticket::$store[603] = [
        'id'                   => 603,
        'entities_id'          => 0,
        'date'                 => '2026-11-16 09:00:00',
        'status'               => CommonITILObject::ASSIGNED,
        'global_validation'    => CommonITILValidation::WAITING,
        'time_to_resolve'      => '2026-11-16 14:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    $ticketClose = new Ticket();
    $ticketClose->getFromDB(603);
    PluginEssentialsSlapause::syncTicket($ticketClose, '2026-11-16 10:00:00');

    $ticketClose->fields['status'] = CommonITILObject::CLOSED;
    PluginEssentialsSlapause::syncTicket($ticketClose, '2026-11-16 10:30:00'); // 1800s
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(603)), 'Pause deleted when moving directly to CLOSED');
    essentials_assert_same(1800, (int) $ticketClose->fields['sla_waiting_duration'], 'Elapsed pause compensated on direct approval -> CLOSED');
    essentials_assert_same('2026-11-16 14:30:00', $ticketClose->fields['time_to_resolve'], 'TTR updated on direct approval -> CLOSED');
}

function test_ajax_categories_schema_and_itil_rights(): void
{
    global $DB;

    essentials_reset_env();

    $DB->tables['glpi_itilcategories'] = [
        ['id' => 1, 'name' => 'Hardware', 'completename' => 'Hardware', 'code' => 'HW01', 'entities_id' => 0],
        ['id' => 2, 'name' => 'Rede', 'completename' => 'Rede', 'code' => 'A&#38;B \\ Share', 'entities_id' => 0],
        ['id' => 3, 'name' => 'ERP - Financeiro', 'completename' => 'ERP - Financeiro', 'code' => '', 'entities_id' => 0],
        ['id' => 4, 'name' => 'Sem codigo', 'completename' => 'Sem codigo', 'code' => null, 'entities_id' => 0],
    ];

    $expectedCode = PluginEssentialsCompat::isGlpi11OrHigher() ? 'A&#38;B \\ Share' : 'A&B \\ Share';

    // Problem READ permission must succeed without unknown column is_deleted errors
    Session::$rights = ['problem' => READ];
    ob_start();
    include GLPI_ROOT . '/essentials/ajax/categories.php';
    $problemJson = (string) ob_get_clean();
    $problemMap  = json_decode($problemJson, true);
    essentials_assert(is_array($problemMap), 'Problem READ returned valid JSON');
    essentials_assert_same('HW01', $problemMap['1'] ?? null, 'Category 1 returned');
    essentials_assert_same($expectedCode, $problemMap['2'] ?? null, 'Category 2 decoded cleanly');
    essentials_assert(!isset($problemMap['3']) && !isset($problemMap['4']), 'Empty/null codes excluded');

    // Change READ permission must also succeed
    Session::$rights = ['change' => READ];
    ob_start();
    include GLPI_ROOT . '/essentials/ajax/categories.php';
    $changeJson = (string) ob_get_clean();
    $changeMap  = json_decode($changeJson, true);
    essentials_assert_same('HW01', $changeMap['1'] ?? null, 'Change READ returned category map');

    // Unauthorized user gets 403 Forbidden payload (tested in subprocess due to exit)
    if (getenv('ESSENTIALS_SUBPROCESS') !== '1' && defined('PHP_BINARY')) {
        $exit403 = 0;
        $out403  = essentials_run_subprocess([
            'ESSENTIALS_TEST_MODE' => 'ajax_403',
        ], $exit403);
        essentials_assert_same(0, $exit403, 'ajax_403 subprocess exited cleanly: ' . $out403);
        essentials_assert(strpos($out403, '"Forbidden"') !== false, 'ajax/categories.php returns Forbidden JSON when lacking rights');
    }
}

function test_uihooks_and_setup_lifecycle(): void
{
    global $PLUGIN_HOOKS, $DB;

    essentials_reset_env();

    // Logged out -> no CSS/JS registered
    Session::$userId = 0;
    plugin_init_essentials();
    essentials_assert(!isset($PLUGIN_HOOKS['add_css']['essentials']), 'No CSS registered when logged out');
    essentials_assert(!isset($PLUGIN_HOOKS['add_javascript']['essentials']), 'No JS registered when logged out');

    // Logged in with all options disabled -> no CSS/JS registered
    $PLUGIN_HOOKS   = [];
    Session::$userId = 2;
    plugin_init_essentials();
    essentials_assert(!isset($PLUGIN_HOOKS['add_css']['essentials']), 'No CSS registered when all UI toggles are off');
    essentials_assert(!isset($PLUGIN_HOOKS['add_javascript']['essentials']), 'No JS registered when all UI toggles are off');

    // Enable UI toggles -> CSS & JS registered conditionally
    PluginEssentialsConfig::save([
        'hide_category_code'      => 1,
        'ticket_sla_pause_banner' => 1,
        'ticket_quick_copy_link'  => 1,
        'pause_sla_planned'       => 1,
    ]);
    $PLUGIN_HOOKS = [];
    plugin_init_essentials();
    essentials_assert(!empty($PLUGIN_HOOKS['add_css']['essentials']), 'CSS registered when UI feature enabled');
    essentials_assert(!empty($PLUGIN_HOOKS['add_javascript']['essentials']), 'JS registered when UI feature enabled');

    // Ticket in PLANNED with XSS payload in title -> safely escaped in banner + quick-copy + script JSON
    $ticket = new Ticket();
    $ticket->fields = [
        'id'                   => 777,
        'name'                 => '</script><script>alert(1)</script>',
        'status'               => CommonITILObject::PLANNED,
        'global_validation'    => CommonITILValidation::NONE,
        'time_to_resolve'      => '2026-11-17 10:00:00',
        'is_deleted'           => 0,
        'slas_id_ttr'          => 0,
        'sla_waiting_duration' => 0,
    ];
    PluginEssentialsSlapause::syncTicket($ticket, '2026-11-16 11:00:00');

    ob_start();
    PluginEssentialsUihooks::renderTicketEnhancements(['item' => $ticket]);
    // Second call (e.g. post_item_form + post_show_item) must NOT duplicate banner/button/config
    PluginEssentialsUihooks::renderTicketEnhancements(['item' => $ticket]);
    $html = (string) ob_get_clean();

    essentials_assert(strpos($html, 'glpi-essentials-sla-banner') !== false, 'SLA pause banner rendered');
    essentials_assert(strpos($html, 'glpi-essentials-copy-btn') !== false, 'Quick copy button rendered');
    essentials_assert(strpos($html, 'window.GLPI_ESSENTIALS_CFG = ') !== false, 'Runtime JS config rendered');
    essentials_assert(strpos($html, '<script>alert(1)</script>') === false, 'Raw XSS script tag is escaped');
    essentials_assert_same(1, substr_count($html, 'glpi-essentials-sla-banner'), 'Banner rendered once per request');
    essentials_assert_same(1, substr_count($html, 'glpi-essentials-copy-btn'), 'Quick copy button rendered once per request');

    // Purge cleanup
    PluginEssentialsSlapause::onTicketPurge($ticket);
    essentials_assert_same(0, count(PluginEssentialsSlapause::getActivePauses(777)), 'Ticket purge removes pause rows');

    // Uninstall cleanup
    plugin_essentials_uninstall();
    essentials_assert(!$DB->tableExists(PluginEssentialsSlapause::TABLE), 'Uninstall drops table');
    essentials_assert_same([], Config::getConfigurationValues(PluginEssentialsConfig::CONTEXT), 'Uninstall removes config values');
}

$tests = [
    'test_starts_pause_on_planned_or_waiting_validation_only_when_not_in_waiting',
    'test_closes_pause_using_calendar_active_seconds_and_sla_computeDate',
    'test_wall_clock_fallback_when_no_sla_calendar',
    'test_avoids_double_pause_when_ticket_stays_in_other_active_pause_reason',
    'test_no_double_pause_when_entering_native_waiting',
    'test_typed_and_untyped_rightname_compat',
    'test_use_ticket_calendar_and_direct_solve_or_close_compensation',
    'test_ajax_categories_schema_and_itil_rights',
    'test_uihooks_and_setup_lifecycle',
];

$passed = 0;
foreach ($tests as $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$fn}\n";
}

echo sprintf(
    "All %d PHP tests passed (GLPI_VERSION=%s, typedRightname=%s).\n",
    $passed,
    GLPI_VERSION,
    PluginEssentialsCompat::typedRightname() ? 'true' : 'false'
);
