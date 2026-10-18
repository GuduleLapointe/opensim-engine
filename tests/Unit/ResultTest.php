<?php

use OpenSim\Result;

describe('answer of an operation', function () {
    test('is told a success or an error whatever form it takes', function (mixed $answer, bool $success) {
        expect(Result::isSuccess($answer))
            ->toBe($success)
            ->and(Result::isError($answer))
            ->toBe(!$success);
    })->with([
        'true' => [true, true],
        'false' => [false, false],
        'array with success' => [['success' => true], true],
        'array without it' => [['message' => 'ok'], false],
        'array with a failure' => [['success' => false], false],
        'word yes' => ['yes', true],
        'word no' => ['no', false],
        'any other text' => ['something', true],
        'empty text' => ['', false],
        'one' => [1, true],
        'http ok' => [204, true],
        'http not found' => [404, false],
        'zero' => [0, false],
        'null' => [null, false],
        'exception' => [new RuntimeException('no'), false],
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
