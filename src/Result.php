<?php

namespace OpenSim;

/**
 * The answer of an operation, whatever form it takes (a boolean, an exception, a structured array with `success`...): what to ask of it without knowing which, and the structured array the projects make.
 */
final class Result
{
    /** What says yes, in any case. */
    private const YES = ['true', 'yes', 'y', 'on', '1'];

    /** What says no, in any case. */
    private const NO = ['false', 'no', 'n', 'off', '0'];

    /** The words of an answer that went well, and of one that did not. */
    private const GOOD = ['success', 'ok', 'done'];
    private const BAD = ['error', 'fail', 'failed', 'failure'];

    /**
     * Whether a value says yes: true, 1, "1", "true", "yes", "on". Null, and anything that says nothing, is not a yes.
     */
    public static function isTrue(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value == 1,
            is_string($value) => in_array(strtolower(trim($value)), self::YES, true),
            default => false,
        };
    }

    /**
     * Whether a value says no: false, 0, "0", "false", "no", "off", an exception. It is not the contrary of isTrue(): null, and
     * anything that says nothing, is neither a yes nor a no.
     */
    public static function isFalse(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => !$value,
            is_int($value), is_float($value) => $value == 0,
            is_string($value) => in_array(strtolower(trim($value)), self::NO, true),
            $value instanceof \Throwable => true,
            default => false,
        };
    }

    /**
     * Whether an answer is a success, whatever form it takes: a yes, an array with `success` true, a word of success, an HTTP status
     * of the 2xx, or any other text (an answer that is not an error is told). A text that is empty, null, an array that says nothing
     * of its success: neither a success nor an error.
     */
    public static function isSuccess(mixed $response): bool
    {
        return match (true) {
            self::isTrue($response) => true,
            is_array($response) => ($response['success'] ?? null) === true,
            is_int($response), is_float($response) => $response >= 200 && $response < 300,
            is_string($response) => trim($response) !== '' && !self::isFalse($response) && !self::isError($response),
            default => false,
        };
    }

    /**
     * Whether an answer is an error, whatever form it takes: a no, an exception, an array with `success` false (or an error or an error
     * code and no success), a word of error, an HTTP status of the 4xx and 5xx. It is not the contrary of isSuccess(): an answer that
     * says nothing (null, an empty text, an array with nothing of success or error) is neither.
     */
    public static function isError(mixed $response): bool
    {
        return match (true) {
            self::isFalse($response) => true,
            is_array($response) => ($response['success'] ?? null) === false ||
                (!isset($response['success']) && (!empty($response['error']) || !empty($response['error_code']))),
            is_int($response), is_float($response) => $response >= 400,
            is_string($response) => in_array(strtolower(trim($response)), self::BAD, true),
            default => false,
        };
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
