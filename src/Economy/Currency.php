<?php

declare(strict_types=1);

namespace OpenSim\Economy;

use OpenSim\Data\OSPDO;
use OpenSim\Network\Http;
use OpenSim\Services\XmlRpc;
use OpenSim\Text\Uuid;
use PDO;

/**
 * The currency of a grid served by the helpers: the balances and the transactions in the database of the money server (or of the
 * helpers when there is none), and the calls to the simulators.
 *
 *     $currency = new Currency($settings, $services, $money);
 *
 * @see CurrencySettings
 */
final class Currency
{
    /**
     * @param \Closure(string):?OSPDO $services the database of a service of Robust (PresenceService, GridUserService, GridService)
     * @param ?OSPDO $money the database of the currency: the money server's, else the one of the helpers
     * @param ?\Closure(string,string):?string $post the answer of a simulator to an XML-RPC request (address, body), null when it does not answer (default: curl)
     */
    public function __construct(
        public readonly CurrencySettings $settings,
        private readonly \Closure $services,
        private readonly ?OSPDO $money = null,
        private readonly ?\Closure $post = null,
    ) {}

    /** The database of a service of OpenSimulator. */
    public function db(string $service): ?OSPDO
    {
        return ($this->services)($service);
    }

    /**
     * The session of an avatar who is in the grid, from the presence service: where it is, and its session IDs.
     *
     * @return array{regionID:string,sessionID:string,secureID:string}|array{}|null empty when the avatar is not in the grid, null for something that is not an avatar
     */
    public function avatarSession(mixed $agentID): ?array
    {
        if (!Uuid::isValid($agentID)) {
            return null;
        }
        // Who is where: the database of the presence service
        $result = $this->db('PresenceService')?->prepareAndExecute(
            'SELECT RegionID,SessionID,SecureSessionID FROM Presence WHERE UserID = :user',
            ['user' => $agentID],
        );
        $row = $result ? $result->fetch(PDO::FETCH_NUM) : false;
        if (!$row) {
            return [];
        }

        return ['regionID' => $row[0], 'sessionID' => $row[1], 'secureID' => $row[2]];
    }

    /** Tell an avatar something, on the simulator it is on. */
    public function userAlert(string $agentID, string $message, ?string $secureID = null): mixed
    {
        $agentServer = $this->serverInfo($agentID);
        if (!$agentServer) {
            return false;
        }
        $avatarSession = $this->avatarSession($agentID);
        if (!$avatarSession) {
            return false;
        }
        $secureID ??= $avatarSession['secureID'];

        return $this->call($agentServer, 'UserAlert', [
            'clientUUID' => $agentID,
            'clientSessionID' => $avatarSession['sessionID'],
            'clientSecureSessionID' => $secureID,
            'Description' => $message,
        ]);
    }

    /** Record a transfer in the table of the transactions of a grid without a money server. */
    public function recordLocal($sourceId, $destId, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip): void
    {
        if ($this->money === null || !is_numeric($amount)) {
            return;
        }
        $sourceId = Uuid::isValid($sourceId) ? $sourceId : Uuid::NULL_KEYS[0];
        $destId = Uuid::isValid($destId) ? $destId : Uuid::NULL_KEYS[0];
        $client = Uuid::isNullKey($sourceId) ? $destId : $sourceId;
        $region = Uuid::NULL_KEYS[0];
        $session = $this->avatarSession($client);
        if ($session) {
            $region = $session['regionID'];
        }

        $this->money->insert($this->settings->transactionTable, [
            'sourceId' => $sourceId,
            'destId' => $destId,
            'amount' => $amount,
            'flags' => $flags,
            'aggregatePermInventory' => $prminvent,
            'aggregatePermNextOwner' => $nxtowner,
            'description' => $desc,
            'transactionType' => $type,
            'timeOccurred' => time(),
            'RegionGenerated' => $region,
            'ipGenerated' => $ip,
        ]);
    }

    /** The balance of an avatar, from the transactions kept: what it received less what it sent; -1 for what is not an avatar. */
    public function balanceLocal(mixed $agentID): int
    {
        if ($this->money === null || !Uuid::isValid($agentID)) {
            return -1;
        }
        $table = $this->settings->transactionTable;
        $received = (int) $this->money->get_var("SELECT SUM(amount) FROM $table WHERE destId = :destId", [
            'destId' => $agentID,
        ]);
        $sent = (int) $this->money->get_var("SELECT SUM(amount) FROM $table WHERE sourceId = :sourceId", [
            'sourceId' => $agentID,
        ]);

        return $received - $sent;
    }

    /** Record a transfer in the tables of the money server. */
    public function saveTransaction($sourceId, $destId, $amount, $type, $flags, $description): void
    {
        if ($this->money === null || !is_numeric($amount)) {
            return;
        }
        $null = Uuid::NULL_KEYS[0];
        $sourceId = Uuid::isValid($sourceId) ? $sourceId : $null;
        $destId = Uuid::isValid($destId) ? $destId : $null;
        $handle = 0;
        $secure = $null;
        $session = $this->avatarSession($sourceId === $null ? $destId : $sourceId);
        if ($session) {
            $secure = $session['secureID'];
            $region = $this->regionInfo($session['regionID']);
            $region !== null && ($handle = $region['regionHandle']);
        }

        $this->money->insert($this->settings->transactionTable, [
            'UUID' => self::randomGuid(),
            'sender' => $sourceId,
            'receiver' => $destId,
            'amount' => $amount,
            'objectUUID' => $null,
            'regionHandle' => $handle,
            'type' => $type,
            'time' => time(),
            'secure' => $secure,
            'status' => $flags,
            'description' => $description,
        ]);
    }

    /**
     * Add (or take, when negative) an amount to the balance of an avatar who has one.
     *
     * @return \PDOStatement|false false for what is not an avatar or has no balance
     */
    public function setBalance(mixed $agentID, mixed $amount): \PDOStatement|false
    {
        if ($this->money === null || !Uuid::isValid($agentID) || !is_numeric($amount)) {
            return false;
        }
        $table = $this->settings->moneyTable;
        if ($this->money->get_var("SELECT balance FROM $table WHERE user = :user", ['user' => $agentID]) === false) {
            return false;
        }

        // The addition is done by the database: two transfers at once do not lose one
        return $this->money->prepareAndExecute("UPDATE $table SET balance = balance + :amount WHERE user = :user", [
            'amount' => (int) $amount,
            'user' => $agentID,
        ]);
    }

    /** Tell the simulator an avatar is on its new balance. */
    public function updateSimulatorBalance(mixed $agentID, int $amount = -1, ?string $secureID = null): mixed
    {
        if (!Uuid::isValid($agentID)) {
            return false;
        }
        if ($amount < 0) {
            $amount = $this->balance($agentID, $secureID);
            if ($amount < 0) {
                return false;
            }
        }
        if (!Uuid::isValid($secureID, true)) {
            return false;
        }
        $agentServer = $this->serverInfo($agentID);
        $avatarSession = $this->avatarSession($agentID);
        if (!$agentServer || !$avatarSession) {
            return false;
        }

        return $this->call($agentServer, 'UpdateBalance', [
            'clientUUID' => $agentID,
            'clientSessionID' => $avatarSession['sessionID'],
            'clientSecureSessionID' => $secureID ?? $avatarSession['secureID'],
            'Balance' => $amount,
        ]);
    }

    /** Move money from an avatar to another (the object or the region that sells is the null key). */
    public function moveMoney(
        $agentID,
        $destID,
        $amount,
        $type,
        $flags,
        $desc,
        $prminvent = 0,
        $nxtowner = 0,
        $ip = '',
    ): bool {
        if (!$this->settings->useMoneyServer) {
            $this->recordLocal($agentID, $destID, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip);

            return true;
        }
        // Direct access to the database, for security
        $this->saveTransaction($agentID, $destID, $amount, $type, $flags, $desc);
        if (Uuid::isValid($agentID) && !Uuid::isNullKey($agentID)) {
            $this->setBalance($agentID, -$amount);
        }
        if (Uuid::isValid($destID) && !Uuid::isNullKey($destID)) {
            $this->setBalance($destID, $amount);
        }

        return true;
    }

    /** Give an avatar money they bought. */
    public function addMoney(mixed $agentID, $amount, ?string $secureID = null): mixed
    {
        if (!Uuid::isValid($agentID)) {
            return false;
        }
        if (!$this->settings->useMoneyServer) {
            $this->recordLocal(null, $agentID, $amount, 5010, 0, 'Add Money', 0, 0, '');

            return ['success' => true];
        }
        if (!Uuid::isValid($secureID, true)) {
            return false;
        }
        $agentServer = $this->serverInfo($agentID);
        if (($agentServer['serverIP'] ?? '') === '') {
            return false;
        }
        $avatarSession = $this->avatarSession($agentID);

        return $this->call($agentServer, 'AddBankerMoney', [
            'clientUUID' => $agentID,
            'clientSessionID' => $avatarSession['sessionID'] ?? '',
            'clientSecureSessionID' => $secureID ?? ($avatarSession['secureID'] ?? ''),
            'amount' => $amount,
        ]);
    }

    /** Send money to an avatar, through the money server of the simulator they are on. */
    public function sendMoney(mixed $agentID, $amount, ?string $secretCode = null): mixed
    {
        if (!Uuid::isValid($agentID)) {
            return false;
        }
        if (!$this->settings->useMoneyServer) {
            $this->recordLocal(null, $agentID, $amount, 5003, 0, 'Send Money', 0, 0, '');

            return ['success' => true];
        }
        $agentServer = $this->serverInfo($agentID);
        $ip = $agentServer['serverIP'] ?? '';
        if ($ip === '') {
            return false;
        }
        $ip = gethostbyname($ip);
        $secretCode = $secretCode !== null ? md5($secretCode . '_' . $ip) : $this->confirmValue($ip);

        return $this->call(['serverIP' => $ip] + $agentServer, 'SendMoneyBalance', [
            'clientUUID' => $agentID,
            'secretAccessCode' => $secretCode,
            'amount' => $amount,
        ]);
    }

    /** The balance of an avatar; -1 for what is not an avatar, or when the simulator does not say. */
    public function balance(mixed $agentID, ?string $secureID = null): int
    {
        if (!Uuid::isValid($agentID)) {
            return -1;
        }
        if (!$this->settings->useMoneyServer) {
            return $this->balanceLocal($agentID);
        }
        if (!Uuid::isValid($secureID, true)) {
            return -1;
        }
        $agentServer = $this->serverInfo($agentID);
        if (($agentServer['serverIP'] ?? '') === '') {
            return -1;
        }
        $avatarSession = $this->avatarSession($agentID);
        if (($avatarSession['sessionID'] ?? '') === '') {
            return -1;
        }
        $response = $this->call($agentServer, 'GetBalance', [
            'clientUUID' => $agentID,
            'clientSessionID' => $avatarSession['sessionID'],
            'clientSecureSessionID' => $secureID ?? $avatarSession['secureID'],
        ]);

        return is_array($response) && isset($response['balance']) ? (int) $response['balance'] : -1;
    }

    /** What ties a quote of currency to its purchase: the key of the grid and the address of whoever asked. */
    public function confirmValue(string $ipAddress): string
    {
        return md5($this->settings->scriptKey . '_' . $ipAddress);
    }

    /**
     * Take the payment of a purchase of currency. Nothing is taken here (credit card, paypal, any money system would be): the purchase
     * is accepted.
     */
    public function processTransaction($avatarID, $cost, string $ipAddress): bool
    {
        return true;
    }

    /** What the real currency costs for an amount of the virtual one, in cents. */
    public function virtualToReal($amount): int
    {
        return $this->settings->ratePer > 0
            ? (int) (($this->settings->rate / $this->settings->ratePer) * 100 * $amount)
            : 0;
    }

    /**
     * The estimate of what an amount costs: the pack of Gloebit that covers it, else the rate.
     *
     * @return array{estimatedCost:int,currencyBuy:int}
     */
    public function quote($amount): array
    {
        if ($this->settings->provider !== 'gloebit') {
            return ['estimatedCost' => $this->virtualToReal($amount), 'currencyBuy' => $amount];
        }
        $cost = 1;
        $bought = $amount;
        $threshold = $this->settings->gloebitThreshold > 0 ? $this->settings->gloebitThreshold : 1;
        foreach ($this->settings->gloebitPacks as $pack => $price) {
            // The pack that covers the amount (the biggest one if none does)
            $cost = $price;
            $bought = $pack;
            if ($pack >= $amount / $threshold) {
                break;
            }
        }

        return ['estimatedCost' => $cost, 'currencyBuy' => $bought];
    }

    /**
     * What the viewer is told when a purchase of currency cannot be done here: the message and the address it shows. The module of
     * the currency of the grid decides: Gloebit has the user finish the transaction on its web site, Podex sends to its terminals in
     * world, any other gets the help page of the helpers.
     *
     * @return array{errorMessage:string,errorURI:?string}
     */
    public function refusal(string $agentID): array
    {
        switch ($this->settings->provider) {
            case 'podex':
                return [
                    'errorMessage' => $this->settings->podexMessage . ' ' . $this->settings->podexUrl,
                    'errorURI' => null,
                ];
            case 'gloebit':
                $base = $this->settings->gloebitSandbox
                    ? 'https://sandbox.gloebit.com/purchase'
                    : 'https://www.gloebit.com/purchase';
                $server = $this->serverInfo($agentID);
                $inform = !empty($server['serverIP'])
                    ? "http://{$server['serverIP']}:{$server['serverHttpPort']}/gloebit/buy_complete?agentId=$agentID"
                    : '';

                return [
                    'errorMessage' => 'Click OK to finish the transaction on Gloebit website.',
                    'errorURI' => "$base?reset&r=&inform=$inform",
                ];
        }

        return [
            'errorMessage' => 'Unable to process the transaction. The gateway denied your charge. Open help page?',
            'errorURI' => $this->settings->helperUrl,
        ];
    }

    /** Put an avatar's current region in the presence service. */
    public function setCurrentRegion(mixed $agentID, mixed $regionID): bool
    {
        if (!Uuid::isValid($agentID) || !Uuid::isValid($regionID)) {
            return false;
        }

        return (bool) $this->db('PresenceService')?->prepareAndExecute(
            'UPDATE Presence SET RegionID = :region WHERE UserID = :user',
            ['region' => $regionID, 'user' => $agentID],
        );
    }

    /**
     * The simulator an avatar was last on: where to call it.
     *
     * @return array{serverIP:string,serverHttpPort:mixed,serverURI:string,regionSecret:string}|array{}
     */
    public function serverInfo(mixed $userID): array
    {
        if (!Uuid::isValid($userID)) {
            return [];
        }
        // The last region of the user is in the database of the grid users, the region in the one of the grid service: two
        // databases that may not be the same, so no join
        $user = $this->db('GridUserService')?->prepareAndExecute(
            'SELECT LastRegionID FROM GridUser WHERE UserID = :user',
            [
                'user' => $userID,
            ],
        );
        $region = $user ? $user->fetchColumn() : false;
        if (!$region) {
            return [];
        }
        $result = $this->db('GridService')?->prepareAndExecute(
            'SELECT serverIP,serverHttpPort,serverURI,regionSecret FROM regions WHERE uuid = :uuid',
            ['uuid' => $region],
        );
        $row = $result ? $result->fetch(PDO::FETCH_NUM) : false;
        if (!$row) {
            return [];
        }

        return ['serverIP' => $row[0], 'serverHttpPort' => $row[1], 'serverURI' => $row[2], 'regionSecret' => $row[3]];
    }

    /** Whether the secure session is the one of an avatar who is in the grid (on the region, when it is given). */
    public function checkSecureSession(mixed $agentID, mixed $regionID, mixed $secure): bool
    {
        if (!Uuid::isValid($agentID) || !Uuid::isValid($secure)) {
            return false;
        }
        $sql = 'SELECT UserID FROM Presence WHERE UserID = :user AND SecureSessionID = :secure';
        $values = ['user' => $agentID, 'secure' => $secure];
        if (Uuid::isValid($regionID)) {
            $sql .= ' AND RegionID = :region';
            $values['region'] = $regionID;
        }
        $result = $this->db('PresenceService')?->prepareAndExecute($sql, $values);
        $row = $result ? $result->fetch(PDO::FETCH_NUM) : false;

        return $row && $row[0] == $agentID;
    }

    /** Whether the secret is the one a region of the grid gave. */
    public function checkRegionSecret(mixed $regionID, mixed $secret): bool
    {
        if (!Uuid::isValid($regionID)) {
            return false;
        }
        $result = $this->db('GridService')?->prepareAndExecute(
            'SELECT UUID FROM regions WHERE UUID = :uuid AND regionSecret = :regionSecret',
            ['uuid' => $regionID, 'regionSecret' => $secret],
        );
        $row = $result ? $result->fetch(PDO::FETCH_NUM) : false;

        return $row && $row[0] == $regionID;
    }

    /**
     * What the database of the grid knows of one of its regions (by UUID): name, handle and address.
     *
     * @return ?array{regionName:string,regionHandle:string,serverIP:string,serverHttpPort:mixed,serverURI:string}
     */
    public function regionInfo(mixed $regionID): ?array
    {
        if (!Uuid::isValid($regionID)) {
            return null;
        }
        $result = $this->db('GridService')?->prepareAndExecute(
            'SELECT regionName, regionHandle, serverIP, serverHttpPort, serverURI FROM regions WHERE uuid = :uuid',
            ['uuid' => $regionID],
        );
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : false;

        return $row
            ? array_intersect_key(
                $row,
                array_flip(['regionName', 'regionHandle', 'serverIP', 'serverHttpPort', 'serverURI']),
            )
            : null;
    }

    /** A UUID for a transaction. */
    public static function randomGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Call a method of the money server of a simulator.
     *
     * @param array<string,mixed> $server the simulator (serverIP, serverHttpPort, serverURI)
     * @param array<string,mixed> $struct
     * @return mixed what the simulator answers, false when it does not
     */
    private function call(array $server, string $method, array $struct): mixed
    {
        $uri = (string) ($server['serverURI'] ?? '');
        $scheme = $uri !== '' ? explode(':', $uri) : [];
        $base =
            isset($scheme[0], $scheme[1]) && !strncasecmp($scheme[0], 'http', 4)
                ? "$scheme[0]:$scheme[1]"
                : 'http://' . ($server['serverIP'] ?? '');
        $url = "$base:" . ($server['serverHttpPort'] ?? '') . '/';
        $body = XmlRpc::request($method, [$struct]);
        $answer =
            $this->post !== null ? ($this->post)($url, $body) : Http::post($url, $body, ['Content-type: text/xml'], 3);

        return is_string($answer) && $answer !== '' ? XmlRpc::answerOf($answer) : false;
    }
}
