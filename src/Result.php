<?php

namespace OpenSim;

/**
 * The answer of an operation, whatever form it takes (a boolean, an exception, a structured array with `success`...): what to ask of it without knowing which, and the structured array the projects make.
 */
final class Result
{
    public static function isSuccess($response)
    {
        if ($response === true || $response === false) {
            return $response;
        }
        if (is_array($response)) {
            return !empty($response['success']) && $response['success'] === true;
        } elseif (is_string($response)) {
            if (in_array(strtolower($response), ['true', 'success', 'ok', 'yes', '1'], true)) {
                return true;
            }
            if (in_array(strtolower($response), ['false', 'error', 'fail', 'no', '0'], true)) {
                return false;
            }
            return !empty($response);
        } elseif (is_numeric($response)) {
            return $response == 1 || ($response >= 200 && $response < 300);
        }
        return false;
    }

    public static function isError($response)
    {
        return !self::isSuccess($response);
    }

    /**
     * Create a standardized response array for consistent project-wide usage
     *
     * @param bool $success Whether the operation was successful
     * @param string $message Human-readable message
     * @param mixed $data Optional data payload (for success responses)
     * @param string $error_code Optional error code for programmatic handling
     * @return array Standardized response structure
     */
    public static function build($success, $message, $data = null, $error_code = null)
    {
        $response = [
            'success' => (bool) $success,
            'message' => $message,
            'timestamp' => time(),
        ];

        if ($success) {
            if ($data !== null) {
                $response['data'] = $data;
            }
        } else {
            if ($error_code !== null) {
                $response['error_code'] = $error_code;
            }
        }

        return $response;
    }

    /**
     * Create a success response
     *
     * @param string $message Success message
     * @param mixed $data Optional data payload
     * @return array Success response
     */
    public static function success($message, $data = null)
    {
        return self::build(true, $message, $data);
    }

    /**
     * Create an error response
     *
     * @param string $message Error message
     * @param int $error_code HTTP response code (also used as exit code)
     * @return array Error response
     */
    public static function error($message, $error_code = 500)
    {
        return self::build(false, $message, null, $error_code);
    }
}
