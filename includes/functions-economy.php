<?php
/**
 * economy.php
 *
 * Provides functions required only by currency.php and landtool.php
 *
 * Requires an OpenSimulator Money Server
 *    [DTL/NSL Money Server for OpenSim](http://www.nsl.tuis.ac.jp/xoops/modules/xpwiki/?OpenSim%2FMoneyServer)
 * or [Gloebit module](http://dev.gloebit.com/opensim/configuration-instructions/)
 *
 * @package     magicoli/opensim-helpers
 * @author      Gudule Lapointe <gudule@speculoos.world>
 * @link            https://github.com/magicoli/opensim-helpers
 * @license     AGPLv3
 *
 * Includes portions of code from original DTLS/NLS Money Server, by:
 *   Melanie Thielker and Teravus Ovares (http://opensimulator.org/)
 *   Fumi.Iseki for CMS/LMS '09 5/31
 **/

// Prevent direct access
if (!defined('OPENSIM_ENGINE')) {
    die('This file cannot be accessed directly.');
}
if (!php_has('xmlrpc')) {
    osDie('XML-RPC extension is not available. Please enable it in your PHP configuration.', 503);
}
if (!php_has('curl')) {
    osDie('cURL extension is not available. Please enable it in your PHP configuration.', 503);
}

// The economy has its own database when the grid says so (CURRENCY_DB_*), the default one otherwise
if (defined('CURRENCY_DB_HOST')) {
    $CurrencyDB = new OSPDO('mysql:host=' . CURRENCY_DB_HOST . ';dbname=' . CURRENCY_DB_NAME, CURRENCY_DB_USER, CURRENCY_DB_PASS);
} elseif (isset($OpenSimDB)) {
    $CurrencyDB = &$OpenSimDB;
} else {
    $CurrencyDB = &$robust_db;
}

function noserver_save_transaction($sourceId, $destId, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip)
{
    global $CurrencyDB;

    if (!is_numeric($amount)) {
        return;
    }
    if (!is_uuid($sourceId)) {
        $sourceId = NULL_KEY;
    }
    if (!is_uuid($destId)) {
        $destId = NULL_KEY;
    }

    $region = NULL_KEY;
    $client = $sourceId;
    if ($client == NULL_KEY) {
        $client = $destId;
    }

    $avt = opensim_get_avatar_session($client);
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

function noserver_get_balance($agentID)
{
    global $CurrencyDB;

    if (!is_uuid($agentID)) {
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

function currency_save_transaction($sourceId, $destId, $amount, $type, $flags, $description, &$deprecated = null)
{
    global $CurrencyDB;

    if (!is_numeric($amount)) {
        return;
    }
    if (!is_uuid($sourceId)) {
        $sourceId = NULL_KEY;
    }
    if (!is_uuid($destId)) {
        $destId = NULL_KEY;
    }

    $handle = 0;
    $secure = NULL_KEY;
    $client = $sourceId;
    $UUID = make_random_guid();
    $sourceID = $sourceId;
    $destID = $destId;
    if ($client == NULL_KEY) {
        $client = $destId;
    }

    $avt = opensim_get_avatar_session($client);
    if ($avt != null) {
        $region = $avt['regionID'];
        $secure = $avt['secureID'];

        $rgn = opensim_get_region_info($region);
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

function currency_set_currency_balance($agentID, $amount, &$deprecated = null)
{
    if (!is_uuid($agentID) or !is_numeric($amount)) {
        return false;
    }

    global $CurrencyDB;
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

function currency_update_simulator_balance($agentID, $amount = -1, $secureID = null)
{
    if (!is_uuid($agentID)) {
        return false;
    }

    if ($amount < 0) {
        $amount = currency_get_balance($agentID, $secureID);
        if ($amount < 0) {
            return false;
        }
    }

    // XML RPC to Region Server
    if (!is_uuid($secureID, true)) {
        return false;
    }

    $agentServer = opensim_get_server_info($agentID);
    if (!$agentServer) {
        return false;
    }
    $serverip = $agentServer['serverIP'];
    $httpport = $agentServer['serverHttpPort'];
    $serveruri = $agentServer['serverURI'];

    $avatarSession = opensim_get_avatar_session($agentID);
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
    $response = currency_xmlrpc_call($serverip, $httpport, $serveruri, $request);

    return $response;
}

function currency_move_money($agentID, $destID, $amount, $type, $flags, $desc, $prminvent = 0, $nxtowner = 0, $ip = '')
{
    if (!CURRENCY_USE_MONEYSERVER) {
        noserver_save_transaction($agentID, $destID, $amount, $type, $flags, $desc, $prminvent, $nxtowner, $ip);
        return true;
    }

    // Direct DB access for security
    // $url = preg_split("/[:\/]/", USER_SERVER_URI);
    // $userip = $url[3];
    currency_save_transaction($agentID, $destID, $amount, $type, $flags, $desc);

    // TODO: Shouldn't we execute both balance updates only if all of the four
    // conditions are met and none of them if any of the checks fails?
    if (is_uuid($agentID) and $agentID != NULL_KEY) {
        currency_set_currency_balance($agentID, -$amount);
    }
    if (is_uuid($destID) and $destID != NULL_KEY) {
        currency_set_currency_balance($destID, $amount);
    }

    return true;
}

function currency_add_money($agentID, $amount, $secureID = null)
{
    if (!is_uuid($agentID)) {
        return false;
    }

    if (!CURRENCY_USE_MONEYSERVER) {
        noserver_save_transaction(null, $agentID, $amount, 5010, 0, 'Add Money', 0, 0, '');
        $response = ['success' => true];
        return $response;
    }

    //
    // XML RPC to Region Server
    //
    if (!is_uuid($secureID, true)) {
        return false;
    }

    $agentServer = opensim_get_server_info($agentID);
    $serverip = $agentServer['serverIP'];
    $httpport = $agentServer['serverHttpPort'];
    $serveruri = $agentServer['serverURI'];
    if ($serverip == '') {
        return false;
    }

    $avatarSession = opensim_get_avatar_session($agentID);
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
    $response = currency_xmlrpc_call($serverip, $httpport, $serveruri, $request);

    return $response;
}

//
// Send the money to avatar for bonus
// by Milo
//
function currency_get_balance($agentID, $secureID = null)
{
    $cash = -1;
    if (!is_uuid($agentID)) {
        return (int) $cash;
    }

    if (!CURRENCY_USE_MONEYSERVER) {
        $cash = noserver_get_balance($agentID);
        return (int) $cash;
    }

    if (!is_uuid($secureID, true)) {
        return (int) $cash;
    }

    $agentServer = opensim_get_server_info($agentID);
    $serverip = $agentServer['serverIP'];
    $httpport = $agentServer['serverHttpPort'];
    $serveruri = $agentServer['serverURI'];
    if ($serverip == '') {
        return (int) $cash;
    }

    $avatarSession = opensim_get_avatar_session($agentID);
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
    $response = currency_xmlrpc_call($serverip, $httpport, $serveruri, $request);

    if ($response) {
        $cash = $response['balance'];
    }
    return (int) $cash;
}

function currency_get_confirm_value($ipAddress)
{
    return md5(trim((string) CURRENCY_SCRIPT_KEY) . '_' . $ipAddress);
}

function currency_process_transaction($avatarID, $cost, $ipAddress)
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

function currency_virtual_to_real($amount)
{
    $per = (float) CURRENCY_RATE_PER;
    $cost = $per > 0 ? (int) ((CURRENCY_RATE / $per) * 100 * $amount) : 0;

    return $cost;
}

// XML RPC
function currency_xmlrpc_call($host, $port, $uri, $request)
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
function opensim_set_current_region($agentID, $regionid, &$deprecated = null)
{
    if (!is_uuid($agentID) or !is_uuid($regionid)) {
        return false;
    }

    $result = opensim_db_for('PresenceService')->prepareAndExecute('UPDATE Presence SET RegionID = :region WHERE UserID = :user', [
        'region' => $regionid,
        'user' => $agentID,
    ]);

    return (bool) $result;
}

function opensim_get_server_info($userid, &$deprecated = null)
{
    if (!is_uuid($userid)) {
        return [];
    }

    // The last region of the user is in the database of the grid users, the region in the one of the grid service: two
    // databases that may not be the same, so no join
    $user = opensim_db_for('GridUserService')->prepareAndExecute('SELECT LastRegionID FROM GridUser WHERE UserID = :user', [
        'user' => $userid,
    ]);
    $region = $user ? $user->fetchColumn() : false;
    if (!$region) {
        return [];
    }
    $result = opensim_db_for('GridService')->prepareAndExecute(
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

function opensim_check_secure_session($agentID, $regionid, $secure, &$deprecated = null)
{
    if (!is_uuid($agentID) or !is_uuid($secure)) {
        return false;
    }

    $sql = 'SELECT UserID FROM Presence WHERE UserID = :user AND SecureSessionID = :secure';
    $values = ['user' => $agentID, 'secure' => $secure];
    if (is_uuid($regionid)) {
        $sql .= ' AND RegionID = :region';
        $values['region'] = $regionid;
    }

    $result = opensim_db_for('PresenceService')->prepareAndExecute($sql, $values);
    if (!$result || !($row = $result->fetch())) {
        return false;
    }

    return $row[0] == $agentID;
}

function opensim_check_region_secret($regionID, $secret, &$deprecated = null)
{
    if (!is_uuid($regionID)) {
        return false;
    }

    $result = opensim_db_for('GridService')->prepareAndExecute(
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
function opensim_get_region_info($regionID, &$deprecated = null)
{
    if (!is_uuid($regionID)) {
        return null;
    }

    // The regions: the database of the grid service
    $result = opensim_db_for('GridService')->prepareAndExecute(
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
function currency_has_key()
{
    return defined('CURRENCY_SCRIPT_KEY') && trim((string) CURRENCY_SCRIPT_KEY) !== '';
}

/** A random UUID (version 4), the identifier of a transaction. */
if (!function_exists('make_random_guid')) {
    function make_random_guid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
