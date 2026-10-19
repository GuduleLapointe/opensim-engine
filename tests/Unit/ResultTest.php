<?php

use OpenSim\Result;

describe('answer of an operation', function () {
    test('is told a success or an error whatever form it takes, or neither', function (
        mixed $answer,
        bool $success,
        bool $error,
    ) {
        expect(Result::isSuccess($answer))->toBe($success)->and(Result::isError($answer))->toBe($error);
    })->with([
        'true' => [true, true, false],
        'false' => [false, false, true],
        'array with success' => [['success' => true], true, false],
        'array without it' => [['message' => 'ok'], false, false],
        'array with a failure' => [['success' => false], false, true],
        'array with an error code' => [['error_code' => 404], false, true],
        'array with success and an error' => [['success' => true, 'error' => 'late'], true, false],
        'word yes' => ['yes', true, false],
        'word no' => ['no', false, true],
        'word success' => ['Success', true, false],
        'word error' => ['error', false, true],
        'any other text' => ['something', true, false],
        'empty text' => ['', false, false],
        'one' => [1, true, false],
        'http ok' => [204, true, false],
        'http redirect' => [302, false, false],
        'http not found' => [404, false, true],
        'zero' => [0, false, true],
        'null' => [null, false, false],
        'exception' => [new RuntimeException('no'), false, true],
    ]);

    test('says yes or no to a value, and nothing to what is neither: no is not the contrary of yes', function (
        mixed $value,
        bool $true,
        bool $false,
    ) {
        expect(Result::isTrue($value))->toBe($true)->and(Result::isFalse($value))->toBe($false);
    })->with([
        'true' => [true, true, false],
        'false' => [false, false, true],
        'one' => [1, true, false],
        'zero' => [0, false, true],
        'text one' => ['1', true, false],
        'text zero' => ['0', false, true],
        'yes in any case' => [' YES ', true, false],
        'off' => ['off', false, true],
        'null' => [null, false, false],
        'empty text' => ['', false, false],
        'another text' => ['maybe', false, false],
        'another number' => [2, false, false],
        'empty array' => [[], false, false],
        'exception' => [new RuntimeException('no'), false, true],
    ]);

    test('is the array the projects make: success, message, time, then data or error code', function () {
        $ok = Result::success('Done', ['id' => 7]);
        $fail = Result::error('Nope', 404);

        expect($ok)
            ->toMatchArray(['success' => true, 'message' => 'Done', 'data' => ['id' => 7]])
            ->toHaveKey('timestamp')
            ->not->toHaveKey('error_code')
            ->and($fail)
            ->toMatchArray(['success' => false, 'message' => 'Nope', 'error_code' => 404])
            ->not->toHaveKey('data')
            ->and(Result::isSuccess($ok))
            ->toBeTrue()
            ->and(Result::isError($fail))
            ->toBeTrue();
    });
});
