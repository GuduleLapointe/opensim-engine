<?php

declare(strict_types=1);

/**
 * The currency of a grid: what the viewers and the simulators ask (quote, buy, balance, transfers, land), the money kept, the
 * calls to the simulators.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Handler;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Economy\Currency;
use OpenSim\Economy\CurrencySettings;
use OpenSim\Services\CurrencyServer;
use OpenSim\Services\XmlRpc;
use OpenSim\Testing\Machine;

const MONEY_AGENT = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
const MONEY_OTHER = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
const MONEY_REGION = 'cccccccc-3333-4333-8333-cccccccccccc';
const MONEY_SESSION = 'dddddddd-4444-4444-8444-dddddddddddd';
const MONEY_SECURE = 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee';
const MONEY_SECRET = 'region-secret';

/** The databases of the grid and of the money: an avatar on a region of a simulator, another one who is not there. */
function money_database(): OpenSim\Data\OSPDO
{
    $db = test_pdo();
    foreach (
        [
            'CREATE TABLE Presence (UserID CHAR(36), RegionID CHAR(36), SessionID CHAR(36), SecureSessionID CHAR(36))',
            'CREATE TABLE GridUser (UserID CHAR(36), LastRegionID CHAR(36))',
            'CREATE TABLE regions (uuid CHAR(36), regionName VARCHAR(255), regionHandle VARCHAR(255), serverIP VARCHAR(255), serverHttpPort INT, serverURI VARCHAR(255), regionSecret VARCHAR(255))',
            'CREATE TABLE transactions (sourceId CHAR(36), destId CHAR(36), amount INT, flags INT, aggregatePermInventory INT, aggregatePermNextOwner INT, description VARCHAR(255), transactionType INT, timeOccurred INT, RegionGenerated CHAR(36), ipGenerated VARCHAR(45))',
            'CREATE TABLE moneytx (UUID CHAR(36), sender CHAR(36), receiver CHAR(36), amount INT, objectUUID CHAR(36), regionHandle VARCHAR(255), type INT, time INT, secure CHAR(36), status INT, description VARCHAR(255))',
            'CREATE TABLE balances (user CHAR(36), balance INT)',
            "INSERT INTO Presence VALUES ('" .
            MONEY_AGENT .
            "', '" .
            MONEY_REGION .
            "', '" .
            MONEY_SESSION .
            "', '" .
            MONEY_SECURE .
            "')",
            "INSERT INTO GridUser VALUES ('" . MONEY_AGENT . "', '" . MONEY_REGION . "')",
            "INSERT INTO regions VALUES ('" .
            MONEY_REGION .
            "', 'Welcome', '1099511628032000', '10.0.0.5', 9000, 'http://sim.example.org:9000', '" .
            MONEY_SECRET .
            "')",
            "INSERT INTO balances VALUES ('" . MONEY_AGENT . "', 100), ('" . MONEY_OTHER . "', 5)",
        ]
        as $sql
    ) {
        $db->exec($sql);
    }

    return $db;
}

/** A currency over the database, the calls to the simulators being kept. */
function money_currency(OpenSim\Data\OSPDO $db, array &$calls, array $settings = []): Currency
{
    return new Currency(
        new CurrencySettings(
            ...$settings + ['scriptKey' => 'secret-key', 'helperUrl' => 'https://web.example.org/helpers/currency.php'],
        ),
        static fn(string $service) => $db,
        $db,
        static function (string $url, string $body) use (&$calls): ?string {
            $calls[] = [$url, XmlRpc::parse($body)];

            return (new PhpXmlRpc\Response((new PhpXmlRpc\Encoder())->encode(['balance' => 42])))->serialize();
        },
    );
}

/** The answer of a method of the server to a request. */
function money_call(XmlRpc $rpc, string $method, array $request): array
{
    $answer = $rpc->call(XmlRpc::request($method, [$request]));
    $xml = preg_replace('/^<\?xml[^>]*>/', '', $answer->body()) ?? '';
    $parsed = XmlRpc::parse(
        str_replace(
            ['<params>', '</params>'],
            ['<methodCall><methodName>x</methodName><params>', '</params></methodCall>'],
            $xml,
        ),
    );

    return ['status' => $answer->status, 'data' => $parsed['params'][0] ?? null, 'body' => $answer->body()];
}

afterEach(fn() => Connections::using(null));

describe('The settings of the currency', function () {
    test('are what the files of the grid say: the module, the key, the environment of Gloebit, the rate', function () {
        $dir = test_tmp() . '/money-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents(
            "$dir/OpenSim.ini",
            "[Economy]\n    economymodule = Gloebit\n[Gloebit]\n    GLBEnvironment = sandbox\n",
        );
        file_put_contents("$dir/MoneyServer.ini", "[MoneyServer]\n    MoneyScriptAccessKey = \"abc\"\n");
        file_put_contents("$dir/Helpers.ini", "[Helpers]\n    CurrencyRate = 5\n    CurrencyRatePer = 500\n");

        $settings = CurrencySettings::fromConfig(
            GridConfig::fromFolder($dir, ['Helpers' => 'Helpers.ini']),
            'https://web/currency.php',
        );
        $beta = CurrencySettings::fromConfig(new GridConfig());

        expect($settings->provider)
            ->toBe('gloebit')
            ->and($settings->useMoneyServer)
            ->toBeTrue()
            ->and($settings->scriptKey)
            ->toBe('abc')
            ->and($settings->hasKey())
            ->toBeTrue()
            ->and($settings->gloebitSandbox)
            ->toBeTrue()
            ->and([$settings->rate, $settings->ratePer, $settings->helperUrl])
            ->toBe([5.0, 500.0, 'https://web/currency.php'])
            ->and([$beta->provider, $beta->useMoneyServer, $beta->hasKey(), $beta->rate, $beta->ratePer])
            ->toBe(['', false, false, 4.0, 1000.0]);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The currency', function () {
    test('estimates a cost at the rate, or with the pack of Gloebit that covers the amount', function () {
        $calls = [];
        $db = money_database();
        $rate = money_currency($db, $calls);
        $gloebit = money_currency($db, $calls, ['provider' => 'gloebit']);

        expect($rate->virtualToReal(1000))
            ->toBe(400)
            ->and($rate->quote(250))
            ->toBe(['estimatedCost' => 100, 'currencyBuy' => 250])
            ->and($gloebit->quote(300))
            ->toBe(['estimatedCost' => 199, 'currencyBuy' => 400])
            ->and($gloebit->quote(401))
            ->toBe(['estimatedCost' => 199, 'currencyBuy' => 400])
            ->and($gloebit->quote(600))
            ->toBe(['estimatedCost' => 499, 'currencyBuy' => 1050])
            ->and($gloebit->quote(99999))
            ->toBe(['estimatedCost' => 4999, 'currencyBuy' => 11500]);
    });

    test('knows who is where, and refuses a session that is not the one of the avatar', function () {
        $calls = [];
        $currency = money_currency(money_database(), $calls);

        expect($currency->avatarSession(MONEY_AGENT))
            ->toBe(['regionID' => MONEY_REGION, 'sessionID' => MONEY_SESSION, 'secureID' => MONEY_SECURE])
            ->and($currency->avatarSession(MONEY_OTHER))
            ->toBe([])
            ->and($currency->avatarSession('nobody'))
            ->toBeNull()
            ->and($currency->checkSecureSession(MONEY_AGENT, null, MONEY_SECURE))
            ->toBeTrue()
            ->and($currency->checkSecureSession(MONEY_AGENT, MONEY_REGION, MONEY_SECURE))
            ->toBeTrue()
            ->and($currency->checkSecureSession(MONEY_AGENT, MONEY_OTHER, MONEY_SECURE))
            ->toBeFalse()
            ->and($currency->checkSecureSession(MONEY_AGENT, null, MONEY_SESSION))
            ->toBeFalse()
            ->and($currency->checkRegionSecret(MONEY_REGION, MONEY_SECRET))
            ->toBeTrue()
            ->and($currency->checkRegionSecret(MONEY_REGION, 'other'))
            ->toBeFalse()
            ->and($currency->regionInfo(MONEY_REGION)['regionName'])
            ->toBe('Welcome')
            ->and($currency->serverInfo(MONEY_AGENT))
            ->toMatchArray([
                'serverIP' => '10.0.0.5',
                'serverHttpPort' => 9000,
                'serverURI' => 'http://sim.example.org:9000',
            ])
            ->and($currency->serverInfo(MONEY_OTHER))
            ->toBe([]);
    });

    test('keeps the money of a grid without a money server in its transactions', function () {
        $calls = [];
        $db = money_database();
        $currency = money_currency($db, $calls);

        $currency->addMoney(MONEY_AGENT, 300);
        $currency->moveMoney(MONEY_AGENT, MONEY_OTHER, 120, 5001, 0, 'Gift', ip: '10.0.0.9');

        expect($currency->balanceLocal(MONEY_AGENT))
            ->toBe(180)
            ->and($currency->balance(MONEY_OTHER))
            ->toBe(120)
            ->and($currency->balance('nobody'))
            ->toBe(-1)
            ->and(
                $db->get_row(
                    "SELECT description, ipGenerated, RegionGenerated FROM transactions WHERE destId = '" .
                        MONEY_OTHER .
                        "'",
                    [],
                    PDO::FETCH_ASSOC,
                ),
            )
            ->toBe(['description' => 'Gift', 'ipGenerated' => '10.0.0.9', 'RegionGenerated' => MONEY_REGION])
            ->and($calls)
            ->toBe([]);
    });

    test(
        'moves the money of a grid with a money server in its tables, and tells the simulators the new balances',
        function () {
            $calls = [];
            $db = money_database();
            $currency = money_currency($db, $calls, ['useMoneyServer' => true, 'transactionTable' => 'moneytx']);

            $currency->moveMoney(MONEY_AGENT, MONEY_OTHER, 30, 5001, 0, 'Gift');
            $currency->updateSimulatorBalance(MONEY_AGENT, 70, MONEY_SECURE);

            expect($db->get_var("SELECT balance FROM balances WHERE user = '" . MONEY_AGENT . "'"))
                ->toBe(70)
                ->and($db->get_var("SELECT balance FROM balances WHERE user = '" . MONEY_OTHER . "'"))
                ->toBe(35)
                ->and(
                    $db->get_row(
                        'SELECT sender, receiver, amount, type, description FROM moneytx',
                        [],
                        PDO::FETCH_ASSOC,
                    ),
                )
                ->toBe([
                    'sender' => MONEY_AGENT,
                    'receiver' => MONEY_OTHER,
                    'amount' => 30,
                    'type' => 5001,
                    'description' => 'Gift',
                ])
                ->and($currency->setBalance('nobody', 5))
                ->toBeFalse()
                ->and($currency->setBalance('ffffffff-6666-4666-8666-ffffffffffff', 5))
                ->toBeFalse()
                ->and($calls[0][0])
                ->toBe('http://sim.example.org:9000/')
                ->and($calls[0][1]['method'])
                ->toBe('UpdateBalance')
                ->and($calls[0][1]['params'][0])
                ->toMatchArray(['clientUUID' => MONEY_AGENT, 'clientSessionID' => MONEY_SESSION, 'Balance' => 70]);
        },
    );

    test('asks the simulator for the balance when a money server keeps it', function () {
        $calls = [];
        $currency = money_currency(money_database(), $calls, ['useMoneyServer' => true]);

        expect($currency->balance(MONEY_AGENT, MONEY_SECURE))
            ->toBe(42)
            ->and($calls[0][1]['method'])
            ->toBe('GetBalance')
            ->and($currency->balance(MONEY_AGENT, 'not a session'))
            ->toBe(-1)
            ->and($currency->balance(MONEY_OTHER, MONEY_SECURE))
            ->toBe(-1);
    });

    test('tells the viewer what to do when a purchase cannot be done here, as the module wants', function () {
        $calls = [];
        $db = money_database();

        expect(money_currency($db, $calls)->refusal(MONEY_AGENT))
            ->toBe([
                'errorMessage' => 'Unable to process the transaction. The gateway denied your charge. Open help page?',
                'errorURI' => 'https://web.example.org/helpers/currency.php',
            ])
            ->and(money_currency($db, $calls, ['provider' => 'podex'])->refusal(MONEY_AGENT))
            ->toBe([
                'errorMessage' =>
                    'Please use our terminals in-world to proceed. Click OK to teleport to terminals region. secondlife://Podex Exchange/128/128/21',
                'errorURI' => null,
            ])
            ->and(
                money_currency($db, $calls, ['provider' => 'gloebit', 'gloebitSandbox' => true])->refusal(MONEY_AGENT),
            )
            ->toBe([
                'errorMessage' => 'Click OK to finish the transaction on Gloebit website.',
                'errorURI' =>
                    'https://sandbox.gloebit.com/purchase?reset&r=&inform=http://10.0.0.5:9000/gloebit/buy_complete?agentId=' .
                    MONEY_AGENT,
            ]);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The calls to the currency', function () {
    /** The server of the calls, over a currency, for a caller. */
    function money_rpc(OpenSim\Data\OSPDO $db, array &$calls, array $settings = [], string $ip = '10.0.0.9'): array
    {
        $server = new CurrencyServer(money_currency($db, $calls, $settings), $ip);

        return [new XmlRpc($server->currencyMethods()), new XmlRpc($server->landMethods()), $server];
    }

    test('quote the cost of an amount to an avatar who proves who they are, tied to their address', function () {
        $calls = [];
        $db = money_database();
        [$rpc] = money_rpc($db, $calls);

        $quote = money_call($rpc, 'getCurrencyQuote', [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SECURE,
            'currencyBuy' => '250',
        ])['data'];
        $forged = money_call($rpc, 'getCurrencyQuote', [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SESSION,
            'currencyBuy' => '250',
        ])['data'];

        expect($quote)
            ->toBe([
                'success' => true,
                'currency' => ['estimatedCost' => 100, 'currencyBuy' => '250'],
                'confirm' => md5('secret-key_10.0.0.9'),
            ])
            ->and($forged)
            ->toBe([
                'success' => false,
                'errorMessage' => "Unable to Authenticate\n\nClick URL for more info.",
                'errorURI' => 'https://web.example.org/helpers/currency.php',
            ]);
    });

    test('sell currency to an avatar who confirms the quote they were given, and refuse the others', function () {
        $calls = [];
        $db = money_database();
        [$rpc] = money_rpc($db, $calls);
        $confirm = md5('secret-key_10.0.0.9');
        $bought = [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SECURE,
            'currencyBuy' => '250',
            'confirm' => $confirm,
        ];

        $wrong = money_call($rpc, 'buyCurrency', ['confirm' => 'nope'] + $bought)['data'];
        $forged = money_call($rpc, 'buyCurrency', ['secureSessionId' => MONEY_SESSION] + $bought)['data'];
        $done = money_call($rpc, 'buyCurrency', $bought)['data'];

        expect($wrong['errorMessage'])
            ->toBe("\n\nMissmatch Confirm Value!!")
            ->and($forged['errorMessage'])
            ->toBe("\n\nMissmatch Secure Session ID!!")
            ->and($done)
            ->toBe(['success' => true])
            ->and(money_currency($db, $calls)->balanceLocal(MONEY_AGENT))
            ->toBe(250);

        // A module that keeps its money elsewhere has its own words for a purchase that is not done here
        [$rpc] = money_rpc($db, $calls, ['useMoneyServer' => true, 'provider' => 'podex']);
        $refused = money_call($rpc, 'buyCurrency', $bought)['data'];

        expect($refused['success'])->toBeFalse()->and($refused['errorMessage'])->toContain('terminals in-world');
    });

    test('give a region the balance of an avatar who is there, and nothing to the others', function () {
        $calls = [];
        [$rpc] = money_rpc($db = money_database(), $calls);

        $known = money_call($rpc, 'simulatorUserBalanceRequest', [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SECURE,
        ])['data'];
        $forged = money_call($rpc, 'simulatorUserBalanceRequest', [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SESSION,
        ])['data'];

        expect($known)
            ->toBe(['success' => true, 'agentId' => MONEY_AGENT, 'funds' => 0])
            ->and($forged)
            ->toBe([
                'success' => false,
                'errorMessage' => 'Could not authenticate your avatar. Money operations may be unavailable',
                'errorURI' => ' ',
            ]);
    });

    test(
        'move the money a region asks to move, when the region and the avatar are who they say and the funds are there',
        function () {
            $calls = [];
            $db = money_database();
            $currency = money_currency($db, $calls);
            $currency->addMoney(MONEY_AGENT, 200);
            [$rpc] = money_rpc($db, $calls);
            $move = [
                'agentId' => MONEY_AGENT,
                'destId' => MONEY_OTHER,
                'secureSessionId' => MONEY_SECURE,
                'regionId' => MONEY_REGION,
                'secret' => MONEY_SECRET,
                'cash' => 50,
                'transactionType' => 5001,
                'flags' => 0,
                'description' => 'Pay',
                'aggregatePermInventory' => 0,
                'aggregatePermNextOwner' => 0,
            ];

            $done = money_call($rpc, 'regionMoveMoney', $move)['data'];
            $broke = money_call($rpc, 'regionMoveMoney', ['cash' => 5000] + $move)['data'];
            $foreign = money_call($rpc, 'regionMoveMoney', ['secret' => 'other'] + $move)['data'];
            $forged = money_call($rpc, 'regionMoveMoney', ['secureSessionId' => MONEY_SESSION] + $move)['data'];

            expect($done)
                ->toBe([
                    'success' => true,
                    'agentId' => MONEY_AGENT,
                    'funds' => 200,
                    'funds2' => 200,
                    'currencySecret' => ' ',
                ])
                ->and($currency->balanceLocal(MONEY_AGENT))
                ->toBe(150)
                ->and($currency->balanceLocal(MONEY_OTHER))
                ->toBe(50)
                ->and($broke['errorMessage'])
                ->toBe('You do not have sufficient funds for this purchase')
                ->and($foreign['errorMessage'])
                ->toBe('This region is not authorized to manage your money.')
                ->and($forged['errorMessage'])
                ->toBe('Unable to authenticate avatar. Money operations may be unavailable');
        },
    );

    test('let a region claim the avatar who is on it now', function () {
        $calls = [];
        [$rpc] = money_rpc($db = money_database(), $calls);
        $db->exec("UPDATE Presence SET RegionID = '" . MONEY_OTHER . "'");
        $claim = [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SECURE,
            'regionId' => MONEY_REGION,
            'secret' => MONEY_SECRET,
        ];

        $done = money_call($rpc, 'simulatorClaimUserRequest', $claim)['data'];
        $foreign = money_call($rpc, 'simulatorClaimUserRequest', ['secret' => 'other'] + $claim)['data'];

        expect($done)
            ->toBe(['success' => true, 'agentId' => MONEY_AGENT, 'funds' => 0, 'currencySecret' => ' '])
            ->and($db->get_var('SELECT RegionID FROM Presence'))
            ->toBe(MONEY_REGION)
            ->and($foreign['success'])
            ->toBeFalse();
    });

    test('estimate and sell the currency a land purchase needs', function () {
        $calls = [];
        $db = money_database();
        [, $land] = money_rpc($db, $calls);
        $confirm = md5('secret-key_10.0.0.9');
        $buy = [
            'agentId' => MONEY_AGENT,
            'secureSessionId' => MONEY_SECURE,
            'currencyBuy' => '500',
            'estimatedCost' => '0',
            'billableArea' => '256',
            'confirm' => $confirm,
        ];

        $prepared = money_call($land, 'preflightBuyLandPrep', $buy)['data'];
        $bought = money_call($land, 'buyLandPrep', $buy)['data'];
        $invalid = money_call($land, 'buyLandPrep', ['currencyBuy' => '-5'] + $buy)['data'];
        $forged = money_call($land, 'preflightBuyLandPrep', ['secureSessionId' => MONEY_SESSION] + $buy)['data'];

        expect($prepared['success'])
            ->toBeTrue()
            ->and($prepared['currency'])
            ->toBe(['estimatedCost' => 200])
            ->and($prepared['membership']['levels']['levels']['description'])
            ->toBe('some level')
            ->and($prepared['confirm'])
            ->toBe($confirm)
            ->and($bought)
            ->toBe(['success' => true])
            ->and(money_currency($db, $calls)->balanceLocal(MONEY_AGENT))
            ->toBe(500)
            ->and($invalid['errorMessage'])
            ->toBe("\n\nInvalid amount")
            ->and($forged['success'])
            ->toBeFalse();
    });

    test('are not served without the key of the grid', function () {
        $calls = [];
        $db = money_database();
        $rpc = static fn() => new CurrencyServer(money_currency($db, $calls, ['scriptKey' => '']), '10.0.0.9');

        expect(fn() => $rpc()->currencyMethods())->toThrow(OpenSim\Services\Failure::class, 'MoneyScriptAccessKey');
    });

    test('are served by the handler at the addresses the viewers fix, for the address of who calls', function () {
        $db = money_database();
        $dir = test_tmp() . '/money-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/MoneyServer.ini", "[MoneyServer]\n    MoneyScriptAccessKey = \"secret-key\"\n");
        Connections::using(static fn() => $db);
        $factory = new Psr17Factory();
        $handler = new Handler(
            new Api((new Machine())->environment, config: static fn() => GridConfig::fromFolder($dir)),
            $factory,
            $factory,
            '/api',
            grid: 'demo',
            authenticator: new OpenSim\Security\OpenAccess(),
        );
        $ask = static fn(string $path, string $body, string $ip = '10.0.0.9') => $handler->handle(
            $factory
                ->createServerRequest('POST', $path, ['REMOTE_ADDR' => $ip])
                ->withBody($factory->createStream($body)),
        );

        $quote = $ask(
            '/api/currency.php',
            XmlRpc::request('getCurrencyQuote', [
                ['agentId' => MONEY_AGENT, 'secureSessionId' => MONEY_SECURE, 'currencyBuy' => '1000'],
            ]),
        );
        $land = $ask(
            '/api/landtool.php',
            XmlRpc::request('preflightBuyLandPrep', [
                ['agentId' => MONEY_AGENT, 'secureSessionId' => MONEY_SECURE, 'currencyBuy' => '1000'],
            ]),
        );
        file_put_contents("$dir/MoneyServer.ini", "[MoneyServer]\n");
        $keyless = $ask('/api/currency.php', XmlRpc::request('getCurrencyQuote', [[]]));

        expect((string) $quote->getBody())
            ->toContain('<name>estimatedCost</name>')
            ->toContain(md5('secret-key_10.0.0.9'))
            ->and((string) $land->getBody())
            ->toContain('<name>membership</name>')
            ->and($keyless->getStatusCode())
            ->toBe(503)
            ->and($handler->handle($factory->createServerRequest('GET', '/api/currency.php'))->getHeaderLine('Allow'))
            ->toBe('POST');
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');
