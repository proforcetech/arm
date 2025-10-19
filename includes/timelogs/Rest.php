<?php
namespace ARM\TimeLogs;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Rest
{
    public static function boot(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('arm/v1', '/time-entries/start', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'start_entry'],
            'permission_callback' => [__CLASS__, 'permissions_check'],
            'args'                => [
                'job_id' => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
                'note' => [
                    'type'     => 'string',
                    'required' => false,
                ],
            ],
        ]);

        register_rest_route('arm/v1', '/time-entries/stop', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'stop_entry'],
            'permission_callback' => [__CLASS__, 'permissions_check'],
            'args'                => [
                'entry_id' => [
                    'type'     => 'integer',
                    'required' => false,
                    'minimum'  => 1,
                ],
                'job_id'   => [
                    'type'     => 'integer',
                    'required' => false,
                    'minimum'  => 1,
                ],
            ],
        ]);
    }

    public static function permissions_check(): bool
    {
        return is_user_logged_in();
    }

    public static function start_entry(WP_REST_Request $request)
    {
        $job_id = (int) $request->get_param('job_id');
        $note   = trim((string) $request->get_param('note'));
        $user_id = get_current_user_id();

        $location = $request->get_param('location');
        if ($location instanceof \stdClass) {
            $location = (array) $location;
        }
        if (!is_array($location)) {
            $location = [];
        }

        $result = Controller::start_entry($job_id, $user_id, 'technician', $note, $location);
        if ($result instanceof WP_Error) {
            return self::error_response($result);
        }

        return new WP_REST_Response($result, 200);
    }

    public static function stop_entry(WP_REST_Request $request)
    {
        $entry_id = (int) $request->get_param('entry_id');
        $job_id   = (int) $request->get_param('job_id');
        $user_id  = get_current_user_id();

        $location = $request->get_param('location');
        if ($location instanceof \stdClass) {
            $location = (array) $location;
        }
        if (!is_array($location)) {
            $location = [];
        }

        if ($entry_id > 0) {
            $result = Controller::close_entry($entry_id, $user_id, false, $location);
        } elseif ($job_id > 0) {
            $result = Controller::end_entry_by_job($job_id, $user_id, $location);
        } else {
            return self::error_response(new WP_Error('arm_time_missing_params', __('Missing entry or job reference.', 'arm-repair-estimates'), ['status' => 400]));
        }

        if ($result instanceof WP_Error) {
            return self::error_response($result);
        }

        return new WP_REST_Response($result, 200);
    }

    private static function error_response(WP_Error $error)
    {
        $status = (int) ($error->get_error_data()['status'] ?? 400);
        return new WP_REST_Response([
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], $status ?: 400);
    }
}
