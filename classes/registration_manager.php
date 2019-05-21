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
 * Tiles course format, registration manager class.
 *
 * @package format_tiles
 * @copyright 2018 David Watson {@link http://evolutioncode.uk}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_tiles;

defined('MOODLE_INTERNAL') || die();

/**
 * Class registration_manager
 * @package format_tiles
 */
class registration_manager {

    public function request_key($data) {
        try {
            $serverresponse = self::make_curl_request($data, 3);
            if (isset($serverresponse['errno']) && $serverresponse['errno']) {
                debugging('Connection error' . $serverresponse['errno'], DEBUG_DEVELOPER);
                return false;
            } else if (!isset($serverresponse['http_code'])
                || ($serverresponse['http_code'] != 200 && $serverresponse['http_code'] != 201)) { // Code 201 is "created".
                debugging(
                    'Unexpected HTTP code ' . $serverresponse['http_code'] . json_encode($serverresponse), DEBUG_DEVELOPER
                );
                return false;
            } else if (isset($serverresponse['exception'])) {
                debugging('Exception ' . $serverresponse['exception']);
                return false;
            } else if (!isset($serverresponse['status'])) {
                debugging(
                    'Server JSON response did not contain status field as expected, or status was not true', DEBUG_DEVELOPER
                );
                return false;
            } else if (!$serverresponse['status']) {
                debugging(
                    'Server JSON response status field was not true: '
                        . $serverresponse['status'] . ' ' . gettype($serverresponse['status']),
                    DEBUG_DEVELOPER
                );
                return false;
            } else if ($serverresponse['status'] && self::validate_key($serverresponse['key'])) {
                return $serverresponse;
            } else {
                debugging('Unknown curl request error ' . json_encode($serverresponse), DEBUG_DEVELOPER);
                return false;
            }
        } catch (Exception $ex) {
            debugging('Curl request error ' . $ex->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * @return bool
     */
    public static function is_registered() {
        return get_config('format_tiles', 'registered') !== false;
    }

    /**
     * @param [] $data
     * @throws \coding_exception
     */
    public static function schedule_registration_attempt($data) {
        global $USER;
        if (!self::is_registered()) {
            // Schedule an attempt to register later.
            \core\notification::warning(get_string('registrationdeferred', 'format_tiles'));
            $task = new \format_tiles\task\deferred_register();
            $task->set_custom_data($data);
            $task->set_component('format_tiles');
            $task->set_userid($USER->id);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * @param object $data the form data
     * @param int $timeout
     * @return mixed
     * @throws \coding_exception
     */
    private static function make_curl_request($data, $timeout) {
        $curl = new \curl();
        $curl->setopt( array(
                'CURLOPT_TIMEOUT' => $timeout,
                'CURLOPT_CONNECTTIMEOUT' => $timeout )
        );
        $curl->setopt(CURLOPT_URL, self::registration_server_url());
        $curl->setopt( CURLOPT_CUSTOMREQUEST, "POST");
        $curl->setopt( CURLOPT_RETURNTRANSFER, true);

        $curloutput = json_decode($curl->post(self::registration_server_url(), json_encode($data)), true);
        $curloutput['http_code'] = $curl->get_info()['http_code'];
        $curloutput['errno'] = $curl->get_errno();
        return $curloutput;
    }

    /**
     * @param [] $data
     * @return bool
     * @throws \coding_exception
     */
    public static function attempt_deferred_registration($data) {
        // As it's deferred user is not waiting, so we have a timeout of 30 seconds.
        if (self::is_registered()) {
            return true;
        }
        $result = self::make_curl_request($data, 30);
        if ($result) {
            return self::handle_server_response($result);
        } else {
            return false;
        }
    }

    /**
     * @return string
     */
    public static function registration_server_url() {
        return "https://api.evolutioncode.uk/registration";
    }

    /**
     * @return bool
     */
    public static function set_registered() {
        \core\notification::success(get_string('registeredthanks', 'format_tiles'));
        return set_config('registered', time(), 'format_tiles');
    }

    /**
     * @param $key
     * @return bool
     */
    public static function validate_key($key) {
        global $CFG;
        $utcyearmonth = gmdate( 'Yn'); // We use UTC not server's time zone.
        return $key === hash('sha256', $CFG->wwwroot .$utcyearmonth);
    }
}