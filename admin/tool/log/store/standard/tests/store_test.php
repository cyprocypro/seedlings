<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Standard log store tests.
 *
 * @package    logstore_standard
 * @copyright  2014 Petr Skoda {@link http://skodak.org/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/event.php');
require_once(__DIR__ . '/fixtures/restore_hack.php');

class logstore_standard_store_testcase extends advanced_testcase {
    public function test_log_writing() {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback(); // Logging waits till the transaction gets committed.

        $this->setAdminUser();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $module1 = $this->getDataGenerator()->create_module('resource', array('course' => $course1));
        $course2 = $this->getDataGenerator()->create_course();
        $module2 = $this->getDataGenerator()->create_module('resource', array('course' => $course2));

        // Test all plugins are disabled by this command.
        set_config('enabled_stores', '', 'tool_log');
        $manager = get_log_manager(true);
        $stores = $manager->get_readers();
        $this->assertCount(0, $stores);

        // Enable logging plugin.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        $manager = get_log_manager(true);

        $stores = $manager->get_readers();
        $this->assertCount(1, $stores);
        $this->assertEquals(array('logstore_standard'), array_keys($stores));
        /** @var \logstore_standard\log\store $store */
        $store = $stores['logstore_standard'];
        $this->assertInstanceOf('logstore_standard\log\store', $store);
        $this->assertInstanceOf('tool_log\log\writer', $store);
        $this->assertTrue($store->is_logging());

        $logs = $DB->get_records('logstore_standard_log', array(), 'id ASC');
        $this->assertCount(0, $logs);

        $this->setCurrentTimeStart();

        $this->setUser(0);
        $event1 = \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)));
        $event1->trigger();

        $logs = $DB->get_records('logstore_standard_log', array(), 'id ASC');
        $this->assertCount(1, $logs);

        $log1 = reset($logs);
        unset($log1->id);
        $log1->other = unserialize($log1->other);
        $log1 = (array)$log1;
        $data = $event1->get_data();
        $data['origin'] = 'cli';
        $data['ip'] = null;
        $data['realuserid'] = null;
        $this->assertEquals($data, $log1);

        $this->setAdminUser();
        \core\session\manager::loginas($user1->id, context_system::instance());
        $this->assertEquals(2, $DB->count_records('logstore_standard_log'));

        logstore_standard_restore::hack_executing(1);
        $event2 = \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module2->cmid), 'other' => array('sample' => 6, 'xx' => 9)));
        $event2->trigger();
        logstore_standard_restore::hack_executing(0);

        \core\session\manager::init_empty_session();
        $this->assertFalse(\core\session\manager::is_loggedinas());

        $logs = $DB->get_records('logstore_standard_log', array(), 'id ASC');
        $this->assertCount(3, $logs);
        array_shift($logs);
        $log2 = array_shift($logs);
        $this->assertSame('\core\event\user_loggedinas', $log2->eventname);
        $this->assertSame('cli', $log2->origin);

        $log3 = array_shift($logs);
        unset($log3->id);
        $log3->other = unserialize($log3->other);
        $log3 = (array)$log3;
        $data = $event2->get_data();
        $data['origin'] = 'restore';
        $data['ip'] = null;
        $data['realuserid'] = 2;
        $this->assertEquals($data, $log3);

        // Test table exists.
        $tablename = $store->get_internal_log_table_name();
        $this->assertTrue($DB->get_manager()->table_exists($tablename));

        // Test reading.
        $this->assertSame(3, $store->get_events_select_count('', array()));
        $events = $store->get_events_select('', array(), 'timecreated ASC', 0, 0); // Is actually sorted by "timecreated ASC, id ASC".
        $this->assertCount(3, $events);
        $resev1 = array_shift($events);
        array_shift($events);
        $resev2 = array_shift($events);
        $this->assertEquals($event1->get_data(), $resev1->get_data());
        $this->assertEquals($event2->get_data(), $resev2->get_data());

        // Test buffering.
        set_config('buffersize', 3, 'logstore_standard');
        $manager = get_log_manager(true);
        $stores = $manager->get_readers();
        /** @var \logstore_standard\log\store $store */
        $store = $stores['logstore_standard'];
        $DB->delete_records('logstore_standard_log');

        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(0, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(0, $DB->count_records('logstore_standard_log'));
        $store->flush();
        $this->assertEquals(2, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(2, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(2, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(5, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(5, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(5, $DB->count_records('logstore_standard_log'));
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(8, $DB->count_records('logstore_standard_log'));

        // Test guest logging setting.
        set_config('logguests', 0, 'logstore_standard');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $DB->delete_records('logstore_standard_log');
        get_log_manager(true);

        $this->setUser(null);
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(0, $DB->count_records('logstore_standard_log'));

        $this->setGuestUser();
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(0, $DB->count_records('logstore_standard_log'));

        $this->setUser($user1);
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(1, $DB->count_records('logstore_standard_log'));

        $this->setUser($user2);
        \logstore_standard\event\unittest_executed::create(
            array('context' => context_module::instance($module1->cmid), 'other' => array('sample' => 5, 'xx' => 10)))->trigger();
        $this->assertEquals(2, $DB->count_records('logstore_standard_log'));

        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
    }

    /**
     * Test logmanager::get_supported_reports returns all reports that require this store.
     */
    public function test_get_supported_reports() {
        $logmanager = get_log_manager();
        $allreports = \core_component::get_plugin_list('report');

        $supportedreports = array(
            'report_log' => '/report/log',
            'report_loglive' => '/report/loglive',
            'report_outline' => '/report/outline',
            'report_participation' => '/report/participation',
            'report_stats' => '/report/stats'
        );

        // Make sure all supported reports are installed.
        $expectedreports = array_keys(array_intersect_key($allreports, $supportedreports));
        $reports = $logmanager->get_supported_reports('logstore_standard');
        $reports = array_keys($reports);
        foreach ($expectedreports as $expectedreport) {
            $this->assertContains($expectedreport, $reports);
        }
    }
}
