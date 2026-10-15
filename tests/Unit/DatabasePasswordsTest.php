<?php

use OpenSim\Provisioning\DatabaseService;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\SimPlan;

describe('password of a database account, in a session', function () {
    test('plans made before it was entered take it, the other accounts keep theirs', function () {
        // The quick setup plans the simulator, with a password made at random, before the database of the grid is settled
        $sim = new SimPlan();
        [$sim->dbHost, $sim->dbUser, $sim->dbPass] = ['db.example.org', 'quick_user', 'made-at-random'];
        $other = new GridPlan();
        [$other->dbHost, $other->dbUser, $other->dbPass] = ['db.example.org', 'other_user', 'its-own'];

        DatabaseService::remember('db.example.org', 'quick_user', 'typed-by-the-person');
        DatabaseService::applyRemembered($sim);
        DatabaseService::applyRemembered($other);

        expect($sim->dbPass)->toBe('typed-by-the-person')
            ->and($other->dbPass)->toBe('its-own');
    });

    test('a plan whose account was never entered keeps its password', function () {
        $plan = new GridPlan();
        [$plan->dbHost, $plan->dbUser, $plan->dbPass] = ['db.example.org', 'never_entered_user', 'made-at-random'];

        DatabaseService::applyRemembered($plan);

        expect($plan->dbPass)->toBe('made-at-random');
    });
});
