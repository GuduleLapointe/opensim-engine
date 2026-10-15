<?php
/**
 * W4OS Database Engine
 *
 * Core database functionality that can be used by both WordPress and helpers.
 * This will contain all the database connection and query logic.
 *
 * This class extends PDO and provides additional methods for common operations.
 *
 * Accepts mixed arguments:
 * - DSN string as PDO takes it (e.g. 'mysql:host=localhost;dbname=mydb'), with the username, the password and the driver
 *   options (e.g. PDO::ATTR_TIMEOUT) as the other arguments
 * - Array with credentials, the driver options being the fourth argument:
 *   - 'user' => 'username'
 *   - 'pass' => 'password'
 *   - 'name' => 'database_name' (optional: without it, a connection to the server)
 *   - 'host' => 'localhost'
 *   - 'port' => 3306 (optional, defaults to 3306)
 *   - 'driver' => 'mysql' (optional, any PDO driver: for sqlite, 'name' is the file or ':memory:')
 *   - 'socket' => path of the local socket (optional, instead of host and port)
 *   - 'log' => false to keep a failed connection out of the error log (optional)
 * - Single string with service URI (e.g. 'http://localhost:8002')
 *
 * A connection that fails does not throw: $connected is false and $error says why.
 *
 * @package magicoli/opensim-helpers
 * @since 3.0.0
 */

class OSPDO extends PDO
{
    /** Seconds to wait for a server that does not answer, unless the driver options say otherwise. */
    public const TIMEOUT = 5;

    public $connected = false;
    public $db_name;

    /** @var Throwable|null why the connection failed */
    public $error = null;

    public function __construct($dsn, $username = null, $password = null, $driver_options = null)
    {
        // TODO: move this UI-related code in the closest UI calling class/function
        if (class_exists('Engine_Settings') && Engine_Settings::get('show_errors')) {
            $this->show_errors();
        }

        $log = true;
        $dbname = null;
        // First handle the different attributes formatting
        if (is_array($dsn)) {
            // If args are passed as an array, extract them.
            $credentials = array_merge(
                [
                    'user' => null,
                    'pass' => null,
                    'name' => null,
                    'host' => null,
                    'port' => null,
                    'driver' => 'mysql',
                    'socket' => null,
                    'log' => true,
                ],
                $dsn,
            );
            $username = $credentials['user'] ?? $username;
            $password = $credentials['pass'] ?? $password;
            $dbname = $credentials['name'] ?? ($credentials['database'] ?? null);
            $log = (bool) $credentials['log'];
            $dsn = self::dsn(
                $credentials['driver'],
                (string) $credentials['host'] . (empty($credentials['port']) ? '' : ':' . $credentials['port']),
                $dbname,
                $credentials['socket'],
            );
        } elseif (func_num_args() === 1 && is_string($dsn) && !self::is_dsn($dsn)) {
            // If a single string is passed, assume it's service URI.
            $url_parts = parse_url($dsn);
            $serviceURI = ($url_parts['host'] ?? '') . (empty($url_parts['port']) ? '' : ':' . $url_parts['port']);
            $credentials = class_exists('W4OS3') ? W4OS3::get_credentials($serviceURI) : [];

            if (!($credentials['db']['enabled'] ?? false)) {
                $this->error = new Exception("No database enabled for the service $serviceURI");
                return;
            }

            $username = $credentials['db']['user'];
            $password = $credentials['db']['pass'];
            $dbname = $credentials['db']['name'] ?? ($credentials['db']['database'] ?? null);
            if (empty($dbname)) {
                $this->error = new Exception("Database name is empty, cannot connect to the database of $serviceURI");
                error_log($this->error->getMessage());
                return;
            }
            $dsn = self::dsn(
                'mysql',
                $credentials['db']['host'] . (empty($credentials['db']['port']) ? '' : ':' . $credentials['db']['port']),
                $dbname,
            );
        } elseif (preg_match('/(?:^|[:;])dbname=([^;]*)/', $dsn, $m)) {
            $dbname = $m[1];
        } elseif (strpos($dsn, 'sqlite:') === 0) {
            $dbname = substr($dsn, 7);
        }

        $driver_options = (array) $driver_options + [PDO::ATTR_TIMEOUT => self::TIMEOUT];

        try {
            parent::__construct($dsn, $username, $password, $driver_options);
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Check the database is really connected
            $this->query('SELECT 1');
            $this->connected = true;
            $this->db_name = $dbname;
        } catch (PDOException $e) {
            $this->error = $e;
            $this->connected = false;
            if ($log) {
                error_log("Could not connect to database $dsn as $username: " . $e->getMessage());
            }
        }
    }

    /**
     * The DSN of a driver.
     *
     * @param  string      $driver sqlite uses the name as its file; the others take host, socket and database name
     * @param  string      $host   may carry its port (host:port)
     * @param  string|null $name   database name, none for a connection to the server
     * @param  string|null $socket path of a local socket, instead of the host
     * @return string
     */
    public static function dsn($driver, $host, $name = null, $socket = null)
    {
        if ($driver === 'sqlite') {
            return 'sqlite:' . $name;
        }

        $parts = [];
        if (!empty($socket)) {
            $parts[] = 'unix_socket=' . $socket;
        } elseif ($host !== '') {
            if (substr_count($host, ':') === 1 && preg_match('/^(.+):(\d+)$/', $host, $m)) {
                $parts[] = 'host=' . $m[1];
                $parts[] = 'port=' . $m[2];
            } else {
                $parts[] = 'host=' . $host;
            }
        }
        if (!empty($name)) {
            $parts[] = 'dbname=' . $name;
        }

        return $driver . ':' . implode(';', $parts);
    }

    /** Whether a string is a DSN (driver:...), not the URI of a service (http://host:port, host:port). */
    private static function is_dsn($string)
    {
        return preg_match('/^[a-z0-9]+:(?!\/\/|\d+$)/i', $string) === 1;
    }

    /**
     * Prepare SQL query, execute with params and log error if any
     *
     * @param  string $query
     * @param  array  $params     substitute markers passed to execute()
     * @param  array  $options    options passed to prepare()
     * @return PDOstatement if success, false on error
     */
    public function prepareAndExecute($query, $params = null, $options = [])
    {
        $trace = debug_backtrace()[0];
        $trace = $trace['file'] . ':' . $trace['line'];

        try {
            $statement = $this->prepare($query, $options);
            $result = $statement->execute($params);
        } catch (PDOException $e) {
            error_log(
                'Error ' .
                    $e->getCode() .
                    ' ' .
                    $e->getMessage() .
                    ' query ' .
                    print_r($query, true) .
                    ' params ' .
                    print_r($params, true) .
                    ' ' .
                    $trace,
            );
            return false;
        }

        if ($result) {
            return $statement;
        }

        error_log('Error ' . $statement->errorCode() . ' ' . $statement->errorInfo()[2] . ' ' . $trace);
        return false;
    }

    public function insert($table, $values)
    {
        $markers = [];
        foreach ($values as $field => $value) {
            $markers[] = ':' . $field;
        }
        $markers = implode(',', $markers);
        $fields = implode(',', array_keys($values));
        $sql = "INSERT INTO $table ($fields) VALUES ($markers)";
        $statement = $this->prepare($sql);
        return $statement->execute($values);
    }

    /**
     * Get single value using PDO standard
     */
    public function get_var($query, $params = [])
    {
        $statement = $this->prepareAndExecute($query, $params);
        if ($statement) {
            $result = $statement->fetchColumn();
            $statement->closeCursor();
            return $result;
        }
        return false;
    }

    /**
     * Get single column as array using PDO standard
     */
    public function get_col($query, $params = [], $column_offset = 0)
    {
        // TODO: if parent get_col() exists return its output and ignore this method

        $statement = $this->prepareAndExecute($query, $params);
        if ($statement) {
            $results = [];
            while ($row = $statement->fetchColumn($column_offset)) {
                $results[] = $row;
            }
            $statement->closeCursor();
            return $results;
        }
        return [];
    }

    /**
     * Get single row using PDO standard
     */
    public function get_row($query, $params = [], $output = PDO::FETCH_OBJ)
    {
        $statement = $this->prepareAndExecute($query, $params);
        if ($statement) {
            $result = $statement->fetch($output);
            $statement->closeCursor();
            return $result;
        }
        return null;
    }

    /**
     * Get multiple rows using PDO standard
     */
    public function get_results($query, $params = [], $output = PDO::FETCH_OBJ)
    {
        $statement = $this->prepareAndExecute($query, $params);
        if ($statement) {
            $results = $statement->fetchAll($output);
            $statement->closeCursor();
            return $results;
        }
        return [];
    }

    /**
     * Check if table exists
     */
    public function table_exists($table)
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $result = $this->query("SELECT 1 FROM $table LIMIT 1");
            return $result !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if multiple tables exist
     */
    public function tables_exist($tables)
    {
        if (is_string($tables)) {
            $tables = [$tables];
        }

        foreach ($tables as $table) {
            if (!$this->table_exists($table)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert .NET connection string to array format
     *
     * @param string $connectionstring .NET connection string
     * @return array Array with keys: saveformat, type, host, port, name, user, pass
     */
    public static function connectionstring_to_array($connectionstring, $provider = 'mysql')
    {
        if (is_array($connectionstring)) {
            // If already an array, just return it
            return $connectionstring;
        }
        $creds = \OpenSim\Config\ConnectionString::pairs($connectionstring);
        if (preg_match('/:[0-9]+$/', $creds['Data Source'] ?? '')) {
            $host = explode(':', $creds['Data Source']);
            $creds['Data Source'] = $host[0];
            $creds['Port'] = empty($host[1]) || $host[1] == 3306 ? null : $host[1];
        }
        switch ($provider) {
            // TODO: test pgsql before enabling

            // case 'OpenSim.Data.PGSQL.dll':
            // case 'pgsql':
            // case 'postgres':
            // case 'postgresql':
            // case 'posql':
            //     $type = 'pgsql';
            //     // PostgreSQL specific handling if needed
            //     break;

            case 'OpenSim.Data.MySQL.dll':
            case 'mysql':
            default:
                $type = 'mysql';
        }

        $result = [
            'type' => $type,
            'host' => $creds['Data Source'] ?? '',
            'port' => $creds['Port'] ?? null,
            'name' => $creds['Database'] ?? '',
            'user' => $creds['User ID'] ?? '',
            'pass' => $creds['Password'] ?? '',
            'saveformat' => 'connection_string',
            'ConnectionString' => $connectionstring, // Preserve original for reference
        ];
        return $result;
    }

    // Version from Helpers, probably not better than the one above
    // public static function connectionstring_to_array( $connectionstring ) {
    // 	$parts = explode( ';', $connectionstring );
    // 	$creds = array();
    // 	foreach ( $parts as $part ) {
    // 		$pair              = explode( '=', $part );
    // 		$creds[ $pair[0] ] = $pair[1] ?? '';
    // 	}
    //     if( preg_match( '/:[0-9]+$/', $creds['Data Source'] ) ) {
    //         $host = explode( ':', $creds['Data Source'] );
    //         $creds['Data Source'] = $host[0];
    //         $creds['Port'] = empty( $host[1] || $host[1] == 3306 ) ? null : $creds['Port'];
    //     }
    //     $result = array(
    //         'host' => $creds['Data Source'],
    //         'port' => $creds['Port'] ?? null,
    //         'name' => $creds['Database'],
    //         'user' => $creds['User ID'],
    //         'pass' => $creds['Password'],
    //     );
    // 	return $result;
    // }

    /**
     * Convert database credentials array back to .NET connection string format
     *
     * @param array $creds Database credentials array
     * @return string Connection string in .NET format
     */
    public static function array_to_connectionstring($creds)
    {
        if (empty($creds) || !is_array($creds)) {
            return '';
        }

        // If we have the original string and nothing critical changed, use it
        if (
            !empty($creds['original_string']) &&
            !empty($creds['saveformat']) &&
            $creds['saveformat'] === 'connection_string'
        ) {
            return $creds['original_string'];
        }

        $parts = [];

        // Build Data Source (host:port or just host)
        if (!empty($creds['host'])) {
            $data_source = $creds['host'];
            if (!empty($creds['port']) && $creds['port'] != 3306) {
                $data_source .= ':' . $creds['port'];
            }
            $parts[] = 'Data Source=' . $data_source;
        }

        if (!empty($creds['name'])) {
            $parts[] = 'Database=' . $creds['name'];
        }

        if (!empty($creds['user'])) {
            $parts[] = 'User ID=' . $creds['user'];
        }

        if (!empty($creds['pass'])) {
            // TODO: escape password if needed
            $parts[] = 'Password=' . $creds['pass'];
        }

        // Add common OpenSim-specific options
        $parts[] = 'Old Guids=true';

        return implode(';', $parts) . ';';
    }

    public function is_connected()
    {
        return $this->connected;
    }

    public static function join_query_conditions($conditions, $glue = 'AND')
    {
        if (empty($conditions)) {
            return '';
        }

        $glue = strtoupper(trim($glue));
        if (!in_array($glue, ['AND', 'OR'])) {
            // Stop execution if glue is not AND or OR, query result
            // would be unpredictable and could lead to SQL injection
            throw new Exception('Invalid glue for query conditions: ' . $glue);
        }

        if (is_array($conditions)) {
            $conditions = array_filter($conditions);
            if (empty($conditions)) {
                return '';
            }
            return implode(" $glue ", $conditions);
        }

        return $conditions;
    }
}

/**
 * OpenSim_Database class, alias of OSPDO
 */
class OpenSim_Database extends OSPDO
{
    public function __construct($dsn, $username = null, $password = null, $driver_options = null)
    {
        parent::__construct($dsn, $username, $password, $driver_options);
    }
}
