<?php
/**
 * Mysqldump File Doc Comment
 *
 * PHP version 5
 *
 * @category Library
 * @package  Ifsnop\Mysqldump
 * @author   Michael J. Calkins <clouddueling@github.com>
 * @author   Diego Torres <ifsnop@github.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://github.com/ifsnop/mysqldump-php
 *
 */

namespace Ifsnop\Mysqldump;

use Exception;
use PDO;
use PDOException;

/**
 * Mysqldump Class Doc Comment
 *
 * @category Library
 * @package  Ifsnop\Mysqldump
 * @author   Michael J. Calkins <clouddueling@github.com>
 * @author   Diego Torres <ifsnop@github.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://github.com/ifsnop/mysqldump-php
 *
 */
class Mysqldump
{

    // Same as mysqldump
    const MAXLINESIZE = 1000000;

    // Available compression methods as constants
    const GZIP = 'Gzip';
    const BZIP2 = 'Bzip2';
    const NONE = 'None';

    // Numerical Mysql types
    public $mysqlNumericalTypes = array(
        'bit',
        'tinyint',
        'smallint',
        'mediumint',
        'int',
        'integer',
        'bigint',
        'real',
        'double',
        'float',
        'decimal',
        'numeric'
    );

    // This can be set both on constructor or manually
    public $host;
    public $user;
    public $pass;
    public $db;
    public $fileName;

    // Internal stuff
    private $tables = array();
    private $views = array();
    private $triggers = array();
    private $dbHandler;
    private $dbType;
    private $compressManager;
    private $typeAdapter;
    private $dumpSettings = array();
    private $pdoSettings = array();
    private $version;
    private $tableColumnTypes = array();

    /**
     * Constructor of Mysqldump. Note that in the case of an SQLite database
     * connection, the filename must be in the $db parameter.
     *
     * @param string $db         Database name
     * @param string $user       SQL account username
     * @param string $pass       SQL account password
     * @param string $host       SQL server to connect to
     * @param string $type       SQL database type
     * @param array  $dumpSettings SQL database settings
     * @param array  $pdoSettings  PDO configured attributes
     *
     * @return null
     */
    public function __construct(
        $db = '',
        $user = '',
        $pass = '',
        $host = 'localhost',
        $type = 'mysql',
        $dumpSettings = array(),
        $pdoSettings = array()
    ) {
        $dumpSettingsDefault = array(
            'include-tables' => array(),
            'exclude-tables' => array(),
            'compress' => 'None',
            'no-data' => false,
            'add-drop-database' => false,
            'add-drop-table' => false,
            'single-transaction' => true,
            'lock-tables' => true,
            'add-locks' => true,
            'extended-insert' => true,
            'disable-foreign-keys-check' => false,
            'where' => '',
            'no-create-info' => false,
            'skip-triggers' => false,
            'add-drop-trigger' => true
        );

        $pdoSettingsDefault = array(PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
        );

        $this->db = $db;
        $this->user = $user;
        $this->pass = $pass;
        $this->host = $host;
        $this->dbType = strtolower($type);
        $this->pdoSettings = self::array_replace_recursive($pdoSettingsDefault, $pdoSettings);
        $this->dumpSettings = self::array_replace_recursive($dumpSettingsDefault, $dumpSettings);

        $diff = array_diff(array_keys($this->dumpSettings), array_keys($dumpSettingsDefault));
        if (count($diff)>0) {
            throw new Exception("Unexpected value in dumpSettings: (" . implode(",", $diff) . ")");
        }

        // Create a new compressManager to manage compressed output
        $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);
    }

    /**
     * Custom array_replace_recursive to be used if PHP < 5.3
     * Replaces elements from passed arrays into the first array recursively
     *
     * @param array $array1 The array in which elements are replaced
     * @param array $array2 The array from which elements will be extracted
     *
     * @return array Returns an array, or NULL if an error occurs.
     */
    public static function array_replace_recursive($array1, $array2)
    {
        if (function_exists('array_replace_recursive')) {
            return array_replace_recursive($array1, $array2);
        }

        foreach ($array2 as $key => $value) {
            if (is_array($value)) {
                $array1[$key] = self::array_replace_recursive($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }
        return $array1;
    }

    /**
     * Connect with PDO
     *
     * @return null
     */
    private function connect()
    {
        // Connecting with PDO
        try {
            switch ($this->dbType) {
                case 'sqlite':
                    $this->dbHandler = new PDO("sqlite:" . $this->db, null, null, $this->pdoSettings);
                    break;
                case 'mysql':
                case 'pgsql':
                case 'dblib':
                    $this->dbHandler = new PDO(
                        $this->dbType . ":host=" .
                        $this->host . ";dbname=" . $this->db,
                        $this->user,
                        $this->pass,
                        $this->pdoSettings
                    );
                    // Fix for always-unicode output
                    $this->dbHandler->exec("SET NAMES utf8");
                    // Store server version
                    $this->version = $this->dbHandler->getAttribute(PDO::ATTR_SERVER_VERSION);
                    break;
                default:
                    throw new Exception("Unsupported database type (" . $this->dbType . ")");
            }
        } catch (PDOException $e) {
            throw new Exception(
                "Connection to " . $this->dbType . " failed with message: " .
                $e->getMessage()
            );
        }

        $this->dbHandler->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler);
    }

    /**
     * Main call
     *
     * @param string $filename  Name of file to write sql dump to
     * @return null
     */
    public function start($filename = '')
    {
        // Output file can be redefined here
        if (!empty($filename)) {
            $this->fileName = $filename;
        }
        // We must set a name to continue
        if (empty($this->fileName)) {
            throw new Exception("Output file name is not set");
        }

        // Connect to database
        $this->connect();

        // Create output file
        $this->compressManager->open($this->fileName);

        // Write some basic info to output file
        $this->compressManager->write($this->getHeader());

        if ($this->dumpSettings['add-drop-database']) {
            $this->compressManager->write($this->typeAdapter->add_drop_database($this->db));
        }

        $this->getDatabaseStructure();

        // If there still are some tables/views in include-tables array,
        // that means that some tables or views weren't found.
        // Give proper error and exit.
        if (0 < count($this->dumpSettings['include-tables'])) {
            $name = implode(",", $this->dumpSettings['include-tables']);
            throw new Exception("Table or View (" . $name . ") not found in database");
        }

        // Disable checking foreign keys
        if ($this->dumpSettings['disable-foreign-keys-check']) {
            $this->compressManager->write(
                $this->typeAdapter->start_disable_foreign_keys_check()
            );
        }

        $this->exportTables();
        $this->exportViews();
        $this->exportTriggers();

        // Enable checking foreign keys if needed
        if ($this->dumpSettings['disable-foreign-keys-check']) {
            $this->compressManager->write(
                $this->typeAdapter->end_disable_foreign_keys_check()
            );
        }

        // Write some stats to output file
        $this->compressManager->write($this->getFooter());
        // Close output file
        $this->compressManager->close();
    }

    /**
     * Returns header for dump file
     *
     * @return string
     */
    private function getHeader()
    {
        // Some info about software, source and time
        $header = "-- mysqldump-php https://github.com/ifsnop/mysqldump-php" . PHP_EOL .
                "--" . PHP_EOL .
                "-- Host: {$this->host}\tDatabase: {$this->db}" . PHP_EOL .
                "-- ------------------------------------------------------" . PHP_EOL;

        if (!empty($this->version)) {
            $header .= "-- Server version \t" . $this->version . PHP_EOL;
        }

        $header .= "-- Date: " . date('r') . PHP_EOL . PHP_EOL;

        return $header;
    }

    /**
     * Returns footer for dump file
     *
     * @return string
     */
    private function getFooter()
    {
        $footer = "-- Dump completed on: " . date('r') . PHP_EOL;

        return $footer;
    }

    /**
     * Reads table and views names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructure()
    {
        // Listing all tables from database
        $this->tables = array();
        if (empty($this->dumpSettings['include-tables'])) {
            // include all tables for now, blacklisting happens later
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->db)) as $row) {
                array_push($this->tables, current($row));
            }
        } else {
            // include only the tables mentioned in include-tables
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->db)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-tables'], true)) {
                    array_push($this->tables, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }

        // Listing all views from database
        $this->views = array();
        if (empty($this->dumpSettings['include-tables'])) {
            // include all views for now, blacklisting happens later
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->db)) as $row) {
                array_push($this->views, current($row));
            }
        } else {
            // include only the tables mentioned in include-tables
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->db)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-tables'], true)) {
                    array_push($this->views, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }

        // Listing all triggers from database
        $this->triggers = array();
        if (false === $this->dumpSettings['skip-triggers']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_triggers($this->db)) as $row) {
                array_push($this->triggers, $row['Trigger']);
            }
        }
    }

    /**
     * Exports all the tables selected from database
     *
     * @return null
     */
    private function exportTables()
    {
        // Exporting tables one by one
        foreach ($this->tables as $table) {
            if (in_array($table, $this->dumpSettings['exclude-tables'], true)) {
                continue;
            }
            $this->getTableStructure($table);
            if (false === $this->dumpSettings['no-data']) {
                $this->listValues($table);
            }
        }
    }

    /**
     * Exports all the views found in database
     *
     * @return null
     */
    private function exportViews()
    {
        // Exporting views one by one
        foreach ($this->views as $view) {
            if (in_array($view, $this->dumpSettings['exclude-tables'], true)) {
                continue;
            }
            $this->getViewStructure($view);
        }
    }

    /**
     * Exports all the triggers found in database
     *
     * @return null
     */
    private function exportTriggers()
    {
        // Exporting views one by one
        foreach ($this->triggers as $trigger) {
            $this->getTriggerStructure($trigger);
        }
    }


    /**
     * Table structure extractor
     *
     * @todo move specific mysql code to typeAdapter
     * @param string $tableName  Name of table to export
     * @return null
     */
    private function getTableStructure($tableName)
    {
        $stmt = $this->typeAdapter->show_create_table($tableName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if (isset($r['Create Table'])) {
                if (!$this->dumpSettings['no-create-info']) {
                    $this->compressManager->write(
                        "--" . PHP_EOL .
                        "-- Table structure for table `$tableName`" . PHP_EOL .
                        "--" . PHP_EOL . PHP_EOL
                    );
                    if ($this->dumpSettings['add-drop-table']) {
                        $this->compressManager->write("/*!50001 DROP TABLE IF EXISTS `$tableName`*/;" . PHP_EOL . PHP_EOL);
                    }
                    $this->compressManager->write($r['Create Table'] . ";" . PHP_EOL . PHP_EOL);
                }

                break;
            }
            throw new Exception("Error getting table structure, unknown output");
        }

        $columnTypes = array();
        $columns = $this->dbHandler->query($this->typeAdapter->show_columns($tableName),
            PDO::FETCH_ASSOC
        );

        foreach($columns as $key => $col) {
            $types = $this->parseColumnType($col);
            $columnTypes[$col['Field']] = $types['is_numeric'];
        }
        $this->tableColumnTypes[$tableName] = $columnTypes;
        return;
    }

    /**
     * Decode column metadata and fill info structure.
     * type and is_numeric will always be available.
     *
     * @param array $colType Array returned from "SHOW COLUMNS FROM tableName"
     * @return array
     */
    private function parseColumnType($colType)
    {
        $colInfo = array();
        $colParts = explode(" ", $colType['Type']);

        if($fparen = strpos($colParts[0], "("))
        {
            $colInfo['type'] = substr($colParts[0], 0, $fparen);
            $colInfo['length']  = str_replace(")", "", substr($colParts[0], $fparen+1));
            $colInfo['attributes'] = isset($colParts[1]) ? $colParts[1] : NULL;
        }
        else
        {
            $colInfo['type'] = $colParts[0];
        }
        $colInfo['is_numeric'] = in_array($colInfo['type'], $this->mysqlNumericalTypes);

        return $colInfo;
    }

    /**
     * View structure extractor
     *
     * @todo move mysql specific code to typeAdapter
     * @param string $viewName  Name of view to export
     * @return null
     */
    private function getViewStructure($viewName)
    {
        $stmt = $this->typeAdapter->show_create_view($viewName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if (isset($r['Create View'])) {
                if (!$this->dumpSettings['no-create-info']) {
                    $this->compressManager->write(
                        "--" . PHP_EOL .
                        "-- Table structure for view `$viewName`" . PHP_EOL .
                        "--" . PHP_EOL . PHP_EOL
                    );
                    if ($this->dumpSettings['add-drop-table']) {
                        $this->compressManager->write("/*!50001 DROP TABLE IF EXISTS `$viewName`*/;" . PHP_EOL);
                        $this->compressManager->write("/*!50001 DROP VIEW IF EXISTS `$viewName`*/;" . PHP_EOL . PHP_EOL);
                    }
                    $this->compressManager->write($r['Create View'] . ";" . PHP_EOL . PHP_EOL);
                }
                return;
            }
            throw new Exception("Error getting view structure, unknown output");
        }
    }

    /**
     * Trigger structure extractor
     *
     * @param string $triggerName  Name of trigger to export
     * @return null
     */
    private function getTriggerStructure($triggerName)
    {
        $stmt = $this->typeAdapter->show_create_trigger($triggerName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if ($this->dumpSettings['add-drop-trigger']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_trigger($triggerName)
                );
            }
            $this->compressManager->write(
                $this->typeAdapter->create_trigger($r)
            );
            return;
        }

    }


    /**
     * Escape values with quotes when needed
     * @todo use is_number instead of ctype_digit and intval
     * @todo get column data type, use it to quote results
     *
     * @param string $tableName Name of table which contains rows
     * @param array $row Associative array of column names and values to be quoted
     *
     * @return string
     */
    private function escape($tableName, $row)
    {
        $ret = array();
        $columnTypes = $this->tableColumnTypes[$tableName];
        foreach ($row as $colName => $colValue) {
            if (is_null($colValue)) {
                $ret[] = "NULL";
            } elseif ($columnTypes[$colName]) {
                // if (ctype_digit($val) && ((string) intval($val) === $val)) {
                // Since "(string) intval($val) === $val" is slower, first check ctype_digit, then run comparison
                // We can't use ctype_digit alone, as this will trim off leading zeros on string values
                // but will quote negative integers (not a big deal IMHO)
                $ret[] = $colValue;
            } else {
                $ret[] = $this->dbHandler->quote($colValue);
            }
        }
        return $ret;
    }

    /**
     * Table rows extractor
     *
     * @param string $tableName  Name of table to export
     *
     * @return null
     */
    private function listValues($tableName)
    {
        $this->compressManager->write(
            "--" . PHP_EOL .
            "-- Dumping data for table `$tableName`" .  PHP_EOL .
            "--" . PHP_EOL . PHP_EOL
        );

        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->start_transaction());
        }

        if ($this->dumpSettings['lock-tables']) {
            $this->typeAdapter->lock_table($tableName);
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write($this->typeAdapter->start_add_lock_table($tableName));
        }

        $onlyOnce = true;
        $lineSize = 0;
        $stmt = "SELECT * FROM `$tableName`";
        if ($this->dumpSettings['where']) {
            $stmt .= " WHERE {$this->dumpSettings['where']}";
        }
        $resultSet = $this->dbHandler->query($stmt);
        $resultSet->setFetchMode(PDO::FETCH_ASSOC);
        foreach ($resultSet as $row) {
            $vals = $this->escape($tableName, $row);
            if ($onlyOnce || !$this->dumpSettings['extended-insert']) {
                $lineSize += $this->compressManager->write(
                    "INSERT INTO `$tableName` VALUES (" . implode(",", $vals) . ")"
                );
                $onlyOnce = false;
            } else {
                $lineSize += $this->compressManager->write(",(" . implode(",", $vals) . ")");
            }
            if (($lineSize > self::MAXLINESIZE) ||
                    !$this->dumpSettings['extended-insert']) {
                $onlyOnce = true;
                $lineSize = $this->compressManager->write(";" . PHP_EOL);
            }
        }
        $resultSet->closeCursor();

        if (!$onlyOnce) {
            $this->compressManager->write(";" . PHP_EOL);
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write($this->typeAdapter->end_add_lock_table($tableName));
        }

        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->commit_transaction());
        }

        if ($this->dumpSettings['lock-tables']) {
            $this->typeAdapter->unlock_table($tableName);
        }

        $this->compressManager->write(PHP_EOL);

    }
}

/**
 * Enum with all available compression methods
 *
 */
abstract class CompressMethod
{
    public static $enums = array(
        "None",
        "Gzip",
        "Bzip2"
    );

    /**
     * @param string $c
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

abstract class CompressManagerFactory
{
    /**
     * @param string $c
     * @return CompressBzip2|CompressGzip|CompressNone
     */
    public static function create($c)
    {
        $c = ucfirst(strtolower($c));
        if (! CompressMethod::isValid($c)) {
            throw new Exception("Compression method ($c) is not defined yet");
        }

        $method =  __NAMESPACE__ . "\\" . "Compress" . $c;

        return new $method;
    }
}

class CompressBzip2 extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (! function_exists("bzopen")) {
            throw new Exception("Compression is enabled, but bzip2 lib is not installed or configured properly");
        }
    }

    public function open($filename)
    {
        $this->fileHandler = bzopen($filename . ".bz2", "w");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        if (false === ($bytesWritten = bzwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return bzclose($this->fileHandler);
    }
}

class CompressGzip extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (! function_exists("gzopen")) {
            throw new Exception("Compression is enabled, but gzip lib is not installed or configured properly");
        }
    }

    public function open($filename)
    {
        $this->fileHandler = gzopen($filename . ".gz", "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        if (false === ($bytesWritten = gzwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return gzclose($this->fileHandler);
    }
}

class CompressNone extends CompressManagerFactory
{
    private $fileHandler = null;

    public function open($filename)
    {
        $this->fileHandler = fopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        if (false === ($bytesWritten = fwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return fclose($this->fileHandler);
    }
}

/**
 * Enum with all available TypeAdapter implementations
 *
 */
abstract class TypeAdapter
{
    public static $enums = array(
        "Sqlite",
        "Mysql"
    );

    /**
     * @param string $c
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

/**
 * TypeAdapter Factory
 *
 */
abstract class TypeAdapterFactory
{
    /**
     * @param string $c Type of database factory to create (Mysql, Sqlite,...)
     * @param PDO $dbHandler
     */
    public static function create($c, $dbHandler = null)
    {
        $c = ucfirst(strtolower($c));
        if (! TypeAdapter::isValid($c)) {
            throw new Exception("Database type support for ($c) not yet available");
        }
        $method =  __NAMESPACE__ . "\\" . "TypeAdapter" . $c;
        return new $method($dbHandler);
    }

    public function show_create_table($tableName)
    {
        return "SELECT tbl_name as 'Table', sql as 'Create Table' " .
            "FROM sqlite_master " .
            "WHERE type='table' AND tbl_name='$tableName'";
    }

    public function show_create_view($viewName)
    {
        return "SELECT tbl_name as 'View', sql as 'Create View' " .
            "FROM sqlite_master " .
            "WHERE type='view' AND tbl_name='$viewName'";
    }

    /**
     * function show_create_trigger Get trigger creation code from database
     * @todo make it do something
     */
    public function show_create_trigger($triggerName)
    {
        return "";
    }

    /**
     * function create_trigger Modify trigger code, add delimiters, etc
     * @todo make it do something
     */
    public function create_trigger($triggerName)
    {
        return "";
    }

    public function show_tables()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='table'";
    }

    public function show_views()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='view'";
    }

    public function show_triggers()
    {
        return "SELECT name FROM sqlite_master WHERE type='trigger'";
    }

    public function show_columns($tableName)
    {
        return "pragma table_info($tableName)";
    }

    public function start_transaction()
    {
        return "BEGIN EXCLUSIVE";
    }

    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        return "";
    }

    public function unlock_table()
    {
        return "";
    }

    public function start_add_lock_table()
    {
        return PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return PHP_EOL;
    }

    public function start_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function end_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function add_drop_database()
    {
        return PHP_EOL;
    }

    public function add_drop_trigger()
    {
        return PHP_EOL;
    }
}

class TypeAdapterPgsql extends TypeAdapterFactory
{
}

class TypeAdapterDblib extends TypeAdapterFactory
{
}

class TypeAdapterSqlite extends TypeAdapterFactory
{
}

class TypeAdapterMysql extends TypeAdapterFactory
{

    private $dbHandler = null;

    public function __construct ($dbHandler)
    {
        $this->dbHandler = $dbHandler;
    }

    public function show_create_table($tableName)
    {
        return "SHOW CREATE TABLE `$tableName`";
    }

    public function show_create_view($viewName)
    {
        return "SHOW CREATE VIEW `$viewName`";
    }

    public function show_create_trigger($triggerName)
    {
        return "SHOW CREATE TRIGGER `$triggerName`";
    }

    public function create_trigger($row)
    {
        $ret = "";
        if (isset($row['SQL Original Statement'])) {
            $triggerStmt = $row['SQL Original Statement'];
            $triggerStmtReplaced = str_replace("CREATE", "/*!50003 CREATE*/", $triggerStmt);
            if ( false === $triggerStmtReplaced ) {
                $triggerStmtReplaced = $triggerStmt;
            }
            $ret = "DELIMITER ;;" . PHP_EOL .
                $triggerStmtReplaced . ";;" . PHP_EOL .
                "DELIMITER ;" . PHP_EOL . PHP_EOL;
        } else {
            throw new Exception("Error getting trigger code, unknown output");
        }

        return $ret;
    }

    public function show_tables()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SELECT TABLE_NAME AS tbl_name " .
            "FROM INFORMATION_SCHEMA.TABLES " .
            "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='${args[0]}'";
    }

    public function show_views()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SELECT TABLE_NAME AS tbl_name " .
            "FROM INFORMATION_SCHEMA.TABLES " .
            "WHERE TABLE_TYPE='VIEW' AND TABLE_SCHEMA='${args[0]}'";
    }

    public function show_triggers()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SHOW TRIGGERS FROM `${args[0]}`;";
    }


    public function show_columns()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SHOW COLUMNS FROM `${args[0]}`;";
    }

    public function start_transaction()
    {
        return "SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ; " .
            "START TRANSACTION";
    }

    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();
        //$tableName = $args[0];
        //return "LOCK TABLES `$tableName` READ LOCAL";
        return $this->dbHandler->exec("LOCK TABLES `${args[0]}` READ LOCAL");

    }

    public function unlock_table()
    {
        return $this->dbHandler->exec("UNLOCK TABLES");
    }

    public function start_add_lock_table()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "LOCK TABLES `${args[0]}` WRITE;" . PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return "UNLOCK TABLES;" . PHP_EOL;
    }

    public function start_disable_foreign_keys_check()
    {
        return "-- Ignore checking of foreign keys" . PHP_EOL .
            "SET AUTOCOMMIT = 0;" . PHP_EOL .
            "SET FOREIGN_KEY_CHECKS = 0;" . PHP_EOL . PHP_EOL;
    }

    public function end_disable_foreign_keys_check()
    {
        return "-- Unignore checking of foreign keys" . PHP_EOL .
            "SET FOREIGN_KEY_CHECKS = 1;" . PHP_EOL .
            "COMMIT;" . PHP_EOL .
            "SET AUTOCOMMIT = 1;" . PHP_EOL . PHP_EOL;
    }

    public function add_drop_database()
    {
        if (func_num_args() != 1) {
             return "";
        }

        $args = func_get_args();

        $ret = "/*!40000 DROP DATABASE IF EXISTS `${args[0]}`*/;" . PHP_EOL;

        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
        $characterSet = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();

        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
        $collationDb = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();

        $ret .= "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `${args[0]}`".
            " /*!40100 DEFAULT CHARACTER SET " . $characterSet .
            " COLLATE " . $collationDb . "*/;" . PHP_EOL .
            "USE `${args[0]}`;" . PHP_EOL . PHP_EOL;

        return $ret;
    }

    public function add_drop_trigger($triggerName) {
        return "DROP TRIGGER IF EXISTS `$triggerName`;" . PHP_EOL;
    }
}
