<?php

namespace OpenSim\Economy;

use PDO;
use OpenSim\Data\OSPDO;

/**
 * The currency of a grid served by the helpers: the balances and the transactions in the database of the money server (or of the helpers when there is none), and the calls to the simulators.
 */
final class Currency
{
    /** @var ?OSPDO the database of the currency, once chosen */
    private static ?OSPDO $currencyDb = null;

    /**
     * The database of the currency: its own when the grid says so (CURRENCY_DB_*), the default one of the helpers otherwise.
     * A consumer may give the handle itself.
     */
    public static function currencyDb(?OSPDO $db = null)
    {
        if ($db !== null) {
            self::$currencyDb = $db;
        }
        if (self::$currencyDb !== null) {
            return self::$currencyDb;
        }
        if (defined('CURRENCY_DB_HOST')) {
            return self::$currencyDb = new OSPDO('mysql:host=' . CURRENCY_DB_HOST . ';dbname=' . CURRENCY_DB_NAME, CURRENCY_DB_USER, CURRENCY_DB_PASS);
        }

        return self::db();
    }

    /** Whether the PHP has what the currency calls need (XML-RPC and cURL), the message of what is missing otherwise. */
    public static function missing(): ?string
    {
        if (!\OpenSim\Support\Platform::has('xmlrpc')) {
            return 'XML-RPC extension is not available. Please enable it in your PHP configuration.';
        }
        if (!\OpenSim\Support\Platform::has('curl')) {
            return 'cURL extension is not available. Please enable it in your PHP configuration.';
        }

        return null;
    }

    /**
     * The database of an OpenSim service (PresenceService, GridService...): the one the consumer says, with its function db_for()
     * when it has one (a service may have a database of its own), else the default handle of the engine.
     *
     * @param  string|null $service the section of the service in the Robust config
     * @return object|null
     */
    public static function db($service = null)
    {
        if (function_exists('db_for')) {
            return db_for($service);
        }

        return $GLOBALS['OpenSimDB'] ?? ($GLOBALS['robust_db'] ?? null);
    }

    /**
     * The session of an avatar who is in the grid, from the presence service: where it is, and its session IDs.
     *
     * @param  string $agentID
     * @return array|null empty when the avatar is not in the grid, null for something that is not an avatar
     */
    public static function avatarSession($agentID, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return null;
        }

        // Who is where: the database of the presence service
        $result = self::db('PresenceService')->prepareAndExecute(
            'SELECT RegionID,SessionID,SecureSessionID FROM Presence WHERE UserID = :user',
            ['user' => $agentID],
        );
        if ($result && ($row = $result->fetch())) {
            [$RegionID, $SessionID, $SecureSessionID] = $row;
        } else {
            return [];
        }

        $av_session['regionID'] = $RegionID;
        $av_session['sessionID'] = $SessionID;
        $av_session['secureID'] = $SecureSessionID;

        return $av_session;
    }

    public static function userAlert($agentID, $message, $secureID = null)
    {
        $agentServer = self::serverInfo($agentID);
        if (!$agentServer) {
            return false;
        }
        $serverip = $agentServer['serverIP'];
        $httpport = $agentServer['serverHttpPort'];
        $serveruri = $agentServer['serverURI'];

        $avatarSession = self::avatarSession($agentID);
        if (!$avatarSession) {
            return false;
        }
        $sessionID = $avatarSession['sessionID'];
        if ($secureID == null) {
            $secureID = $avatarSession['secureID'];
        }

        $request = xmlrpc_encode_request('UserAlert', [
            [
                'clientUUID' => $agentID,
                'clientSessionID' => $sessionID,
                'clientSecureSessionID' => $secureID,
                'Description' => $message,
            ],
        ]);
        $response = self::xmlrpcCall($serverip, $httpport, $serveruti, $request);

        return $response;
    }

    public static function recordLocal($sourceId, $destId, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip)
    {
        $CurrencyDB = self::currencyDb();

        if (!is_numeric($amount)) {
            return;
        }
        if (!\OpenSim\Text\Uuid::isValid($sourceId)) {
            $sourceId = NULL_KEY;
        }
        if (!\OpenSim\Text\Uuid::isValid($destId)) {
            $destId = NULL_KEY;
        }

        $region = NULL_KEY;
        $client = $sourceId;
        if ($client == NULL_KEY) {
            $client = $destId;
        }

        $avt = self::avatarSession($client);
        if ($avt != null) {
            $region = $avt['regionID'];
        }

        $CurrencyDB->insert(CURRENCY_TRANSACTION_TBL, [
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

    public static function balanceLocal($agentID)
    {
        $CurrencyDB = self::currencyDb();

        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return -1;
        }

        $sent_sum = 0;
        $received_sum = 0;

        $credits = $CurrencyDB->prepareAndExecute(
            'SELECT SUM(amount) FROM ' . CURRENCY_TRANSACTION_TBL . ' WHERE destId = :destId',
            ['destId' => $agentID],
        );
        if ($credits) {
            [$received_sum] = $credits->fetch();
        }

        $debits = $CurrencyDB->prepareAndExecute(
            'SELECT SUM(amount) FROM ' . CURRENCY_TRANSACTION_TBL . ' WHERE sourceId = :sourceId',
            ['sourceId' => $agentID],
        );
        if ($debits) {
            [$sent_sum] = $debits->fetch();
        }

        $cash = (int) $received_sum - (int) $sent_sum;
        return $cash;
    }

    public static function saveTransaction($sourceId, $destId, $amount, $type, $flags, $description, &$deprecated = null)
    {
        $CurrencyDB = self::currencyDb();

        if (!is_numeric($amount)) {
            return;
        }
        if (!\OpenSim\Text\Uuid::isValid($sourceId)) {
            $sourceId = NULL_KEY;
        }
        if (!\OpenSim\Text\Uuid::isValid($destId)) {
            $destId = NULL_KEY;
        }

        $handle = 0;
        $secure = NULL_KEY;
        $client = $sourceId;
        $UUID = self::randomGuid();
        $sourceID = $sourceId;
        $destID = $destId;
        if ($client == NULL_KEY) {
            $client = $destId;
        }

        $avt = self::avatarSession($client);
        if ($avt != null) {
            $region = $avt['regionID'];
            $secure = $avt['secureID'];

            $rgn = self::regionInfo($region);
            if ($rgn != null) {
                $handle = $rgn['regionHandle'];
            }
        }

        $CurrencyDB->insert(CURRENCY_TRANSACTION_TBL, [
            'UUID' => $UUID,
            'sender' => $sourceID,
            'receiver' => $destID,
            'amount' => $amount,
            'objectUUID' => NULL_KEY,
            'regionHandle' => $handle,
            'type' => $type,
            'time' => time(),
            'secure' => $secure,
            'status' => $flags,
            'description' => $description,
        ]);
    }

    public static function setBalance($agentID, $amount, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID) or !is_numeric($amount)) {
            return false;
        }

        $CurrencyDB = self::currencyDb();
        $balances_table = CURRENCY_MONEY_TBL;

        $CurrencyDB->query("LOCK TABLES $balances_table WRITE");
        try {
            $currentbalance = $CurrencyDB->prepareAndExecute("SELECT balance FROM $balances_table WHERE user = :user", [
                'user' => $agentID,
            ]);
            $row = $currentbalance ? $currentbalance->fetch() : false;
            if ($row) {
                $balance = (int) $row[0] + (int) $amount;
                $result = $CurrencyDB->prepareAndExecute("UPDATE $balances_table SET balance = :balance WHERE user = :user", [
                    'balance' => $balance,
                    'user' => $agentID,
                ]);
            } else {
                $result = false;
            }
        } finally {
            $CurrencyDB->query('UNLOCK TABLES');
        }
        return $result;
    }

    public static function updateSimulatorBalance($agentID, $amount = -1, $secureID = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return false;
        }

        if ($amount < 0) {
            $amount = self::balance($agentID, $secureID);
            if ($amount < 0) {
                return false;
            }
        }

        // XML RPC to Region Server
        if (!\OpenSim\Text\Uuid::isValid($secureID, true)) {
            return false;
        }

        $agentServer = self::serverInfo($agentID);
        if (!$agentServer) {
            return false;
        }
        $serverip = $agentServer['serverIP'];
        $httpport = $agentServer['serverHttpPort'];
        $serveruri = $agentServer['serverURI'];

        $avatarSession = self::avatarSession($agentID);
        if (!$avatarSession) {
            return false;
        }
        $sessionID = $avatarSession['sessionID'];
        if ($secureID == null) {
            $secureID = $avatarSession['secureID'];
        }

        $request = xmlrpc_encode_request('UpdateBalance', [
            [
                'clientUUID' => $agentID,
                'clientSessionID' => $sessionID,
                'clientSecureSessionID' => $secureID,
                'Balance' => $amount,
            ],
        ]);
        $response = self::xmlrpcCall($serverip, $httpport, $serveruri, $request);

        return $response;
    }

    public static function moveMoney($agentID, $destID, $amount, $type, $flags, $desc, $prminvent = 0, $nxtowner = 0, $ip = '')
    {
        if (!CURRENCY_USE_MONEYSERVER) {
            self::recordLocal($agentID, $destID, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip);
            return true;
        }

        // Direct DB access for security
        // $url = preg_split("/[:\/]/", USER_SERVER_URI);
        // $userip = $url[3];
        self::saveTransaction($agentID, $destID, $amount, $type, $flags, $desc);

        // TODO: Shouldn't we execute both balance updates only if all of the four
        // conditions are met and none of them if any of the checks fails?
        if (\OpenSim\Text\Uuid::isValid($agentID) and $agentID != NULL_KEY) {
            self::setBalance($agentID, -$amount);
        }
        if (\OpenSim\Text\Uuid::isValid($destID) and $destID != NULL_KEY) {
            self::setBalance($destID, $amount);
        }

        return true;
    }

    public static function addMoney($agentID, $amount, $secureID = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return false;
        }

        if (!CURRENCY_USE_MONEYSERVER) {
            self::recordLocal(null, $agentID, $amount, 5010, 0, 'Add Money', 0, 0, '');
            $response = ['success' => true];
            return $response;
        }

        //
        // XML RPC to Region Server
        //
        if (!\OpenSim\Text\Uuid::isValid($secureID, true)) {
            return false;
        }

        $agentServer = self::serverInfo($agentID);
        $serverip = $agentServer['serverIP'];
        $httpport = $agentServer['serverHttpPort'];
        $serveruri = $agentServer['serverURI'];
        if ($serverip == '') {
            return false;
        }

        $avatarSession = self::avatarSession($agentID);
        $sessionID = $avatarSession['sessionID'];
        // if ($sessionID=="")  return false;
        if ($secureID == null) {
            $secureID = $avatarSession['secureID'];
        }

        $request = xmlrpc_encode_request('AddBankerMoney', [
            [
                'clientUUID' => $agentID,
                'clientSessionID' => $sessionID,
                'clientSecureSessionID' => $secureID,
                'amount' => $amount,
            ],
        ]);
        $response = self::xmlrpcCall($serverip, $httpport, $serveruri, $request);

        return $response;
    }

    public static function sendMoney($agentID, $amount, $secretCode = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return false;
        }

        if (!CURRENCY_USE_MONEYSERVER) {
            self::recordLocal(null, $agentID, $amount, 5003, 0, 'Send Money', 0, 0, '');
            $response = ['success' => true];
            return $response;
        }

        //
        // XML RPC to Region Server
        //
        $agentServer = self::serverInfo($agentID);
        $serverip = $agentServer['serverIP'];
        $httpport = $agentServer['serverHttpPort'];
        $serveruri = $agentServer['serverURI'];
        if ($serverip == '') {
            return false;
        }
        $serverip = gethostbyname($serverip);

        if ($secretCode != null) {
            $secretCode = md5($secretCode . '_' . $serverip);
        } else {
            $secretCode = self::confirmValue($serverip);
        }

        $request = xmlrpc_encode_request('SendMoneyBalance', [
            [
                'clientUUID' => $agentID,
                'secretAccessCode' => $secretCode,
                'amount' => $amount,
            ],
        ]);
        $response = self::xmlrpcCall($serverip, $httpport, $serveruri, $request);

        return $response;
    }

    public static function balance($agentID, $secureID = null)
    {
        $cash = -1;
        if (!\OpenSim\Text\Uuid::isValid($agentID)) {
            return (int) $cash;
        }

        if (!CURRENCY_USE_MONEYSERVER) {
            $cash = self::balanceLocal($agentID);
            return (int) $cash;
        }

        if (!\OpenSim\Text\Uuid::isValid($secureID, true)) {
            return (int) $cash;
        }

        $agentServer = self::serverInfo($agentID);
        $serverip = $agentServer['serverIP'];
        $httpport = $agentServer['serverHttpPort'];
        $serveruri = $agentServer['serverURI'];
        if ($serverip == '') {
            return (int) $cash;
        }

        $avatarSession = self::avatarSession($agentID);
        $sessionID = $avatarSession['sessionID'];
        if ($sessionID == '') {
            return (int) $cash;
        }
        if ($secureID == null) {
            $secureID = $avatarSession['secureID'];
        }

        $request = xmlrpc_encode_request('GetBalance', [
            [
                'clientUUID' => $agentID,
                'clientSessionID' => $sessionID,
                'clientSecureSessionID' => $secureID,
            ],
        ]);
        $response = self::xmlrpcCall($serverip, $httpport, $serveruri, $request);

        if ($response) {
            $cash = $response['balance'];
        }
        return (int) $cash;
    }

    public static function confirmValue($ipAddress)
    {
        return md5(trim((string) CURRENCY_SCRIPT_KEY) . '_' . $ipAddress);
    }

    public static function processTransaction($avatarID, $cost, $ipAddress)
    {
        // Do external processing here! (credit card, paypal, any money system)
        // Return False if it fails!
        // Remember, $amount is stored without decimal places, however it's assumed
        // that the transaction amount is in Cents and has two decimal places
        // 5 dollars will be 500
        // 15 dollars will be 1500

        // if ($avatarID==CURRENCY_BANKER) return true;
        // return false;

        return true;
    }

    public static function virtualToReal($amount)
    {
        $per = (float) CURRENCY_RATE_PER;
        $cost = $per > 0 ? (int) ((CURRENCY_RATE / $per) * 100 * $amount) : 0;

        return $cost;
    }

    public static function xmlrpcCall($host, $port, $uri, $request)
    {
        $url = '';
        if ($uri != '') {
            $dec = explode(':', $uri);
            if (!strncasecmp($dec[0], 'http', 4)) {
                $url = "$dec[0]:$dec[1]";
            }
        }
        if ($url == '') {
            $url = "http://$host";
        }
        $url = "$url:$port/";

        // TODO: use file_get_contents() instead of over complicate curl procedure
        $header[] = 'Content-type: text/xml';
        $header[] = 'Content-length: ' . strlen($request);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $data = curl_exec($ch);

        $ret = false;
        if ($data) {
            $ret = xmlrpc_decode($data);
        }

        return $ret;
    }

    /**
     * Following functions are not specific to money and could be useful in other
     * helpers. However, they are not: they are currently only used in currency
     * scripts, so I leave them here, hence the opensim_ prefix.
     */
    public static function setCurrentRegion($agentID, $regionid, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID) or !\OpenSim\Text\Uuid::isValid($regionid)) {
            return false;
        }

        $result = self::db('PresenceService')->prepareAndExecute('UPDATE Presence SET RegionID = :region WHERE UserID = :user', [
            'region' => $regionid,
            'user' => $agentID,
        ]);

        return (bool) $result;
    }

    public static function serverInfo($userid, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($userid)) {
            return [];
        }

        // The last region of the user is in the database of the grid users, the region in the one of the grid service: two
        // databases that may not be the same, so no join
        $user = self::db('GridUserService')->prepareAndExecute('SELECT LastRegionID FROM GridUser WHERE UserID = :user', [
            'user' => $userid,
        ]);
        $region = $user ? $user->fetchColumn() : false;
        if (!$region) {
            return [];
        }
        $result = self::db('GridService')->prepareAndExecute(
            'SELECT serverIP,serverHttpPort,serverURI,regionSecret FROM regions WHERE uuid = :uuid',
            ['uuid' => $region],
        );
        if ($result && ($row = $result->fetch())) {
            [$serverip, $httpport, $serveruri, $secret] = $row;
        } else {
            return [];
        }

        $serverinfo['serverIP'] = $serverip;
        $serverinfo['serverHttpPort'] = $httpport;
        $serverinfo['serverURI'] = $serveruri;
        $serverinfo['regionSecret'] = $secret;
        return $serverinfo;
    }

    public static function checkSecureSession($agentID, $regionid, $secure, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($agentID) or !\OpenSim\Text\Uuid::isValid($secure)) {
            return false;
        }

        $sql = 'SELECT UserID FROM Presence WHERE UserID = :user AND SecureSessionID = :secure';
        $values = ['user' => $agentID, 'secure' => $secure];
        if (\OpenSim\Text\Uuid::isValid($regionid)) {
            $sql .= ' AND RegionID = :region';
            $values['region'] = $regionid;
        }

        $result = self::db('PresenceService')->prepareAndExecute($sql, $values);
        if (!$result || !($row = $result->fetch())) {
            return false;
        }

        return $row[0] == $agentID;
    }

    public static function checkRegionSecret($regionID, $secret, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($regionID)) {
            return false;
        }

        $result = self::db('GridService')->prepareAndExecute(
            'SELECT UUID FROM regions WHERE UUID=:uuid AND regionSecret=:regionSecret',
            [
                'uuid' => $regionID,
                'regionSecret' => $secret,
            ],
        );
        if ($result) {
            [$UUID] = $result->fetch();
            if ($UUID == $regionID) {
                return true;
            }
        }

        return false;
    }

    /** What the database of the grid knows of one of its regions (by UUID): name, handle and address. */
    public static function regionInfo($regionID, &$deprecated = null)
    {
        if (!\OpenSim\Text\Uuid::isValid($regionID)) {
            return null;
        }

        // The regions: the database of the grid service
        $result = self::db('GridService')->prepareAndExecute(
            'SELECT regionName, regionHandle, serverIP, serverHttpPort, serverURI FROM regions WHERE uuid = :uuid',
            ['uuid' => $regionID],
        );
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return null;
        }

        return [
            'regionName' => $row['regionName'],
            'regionHandle' => $row['regionHandle'],
            'serverIP' => $row['serverIP'],
            'serverHttpPort' => $row['serverHttpPort'],
            'serverURI' => $row['serverURI'],
        ];
    }

    /**
     * Whether the grid has the key that ties a quote of currency to its purchase (the script key of the MoneyServer, in its
     * MoneyServer.ini). There is no default: a key everybody knows protects nothing.
     */
    public static function hasKey()
    {
        return defined('CURRENCY_SCRIPT_KEY') && trim((string) CURRENCY_SCRIPT_KEY) !== '';
    }

        public static function randomGuid()
        {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        }
}
