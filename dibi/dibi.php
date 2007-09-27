<?php

/**
 * dibi - Database Abstraction Layer according to dgx
 * --------------------------------------------------
 *
 * Copyright (c) 2005-2007 David Grudl aka -dgx- (http://www.dgx.cz)
 *
 * for PHP 5.0.3 and newer
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file license.txt.
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2005-2007 David Grudl aka -dgx- (http://www.dgx.cz)
 * @license    New BSD License
 * @version    0.8e (Revision: $WCREV$, Date: $WCDATE$)
 * @category   Database
 * @package    Dibi
 * @link       http://dibi.texy.info/
 */


/**
 * This file is part of the "dibi" project (http://dibi.texy.info/)
 *
 * @version    $Revision$ $Date$
 */


if (version_compare(PHP_VERSION , '5.0.3', '<'))
    die('dibi needs PHP 5.0.3 or newer');


// libraries
require_once dirname(__FILE__).'/libs/driver.php';
require_once dirname(__FILE__).'/libs/resultset.php';
require_once dirname(__FILE__).'/libs/translator.php';
require_once dirname(__FILE__).'/libs/exception.php';





/**
 * Interface for user variable, used for generating SQL
 */
interface DibiVariableInterface
{
    /**
     * Format for SQL
     *
     * @param  object  destination DibiDriver
     * @param  string  optional modifier
     * @return string  SQL code
     */
    public function toSQL($driver, $modifier = NULL);
}





/**
 * Interface for database drivers
 *
 * This class is static container class for creating DB objects and
 * store connections info.
 *
 */
class dibi
{
    /**
     * Column type in relation to PHP native type
     */
    const
        FIELD_TEXT =       's', // as 'string'
        FIELD_BINARY =     'S',
        FIELD_BOOL =       'b',
        FIELD_INTEGER =    'i',
        FIELD_FLOAT =      'f',
        FIELD_DATE =       'd',
        FIELD_DATETIME =   't',
        FIELD_UNKNOWN =    '?',

        // special
        FIELD_COUNTER =    'c', // counter or autoincrement, is integer

        // dibi version
        VERSION =          '0.8e (Revision: $WCREV$, Date: $WCDATE$)';


    /**
     * Connection registry storage for DibiDriver objects
     * @var DibiDriver[]
     */
    private static $registry = array();

    /**
     * Current connection
     * @var DibiDriver
     */
    private static $connection;

    /**
     * Last SQL command @see dibi::query()
     * @var string
     */
    public static $sql;

    /**
     * File for logging SQL queries
     * @var string|NULL
     */
    public static $logFile;

    /**
     * Mode parameter used by fopen()
     * @var string
     */
    public static $logMode = 'a';

    /**
     * To log all queries or error queries (debug mode)
     * @var bool
     */
    public static $logAll = FALSE;

    /**
     * dibi::query() error mode
     * @var bool
     */
    public static $throwExceptions = TRUE;

    /**
     * Substitutions for identifiers
     * @var array
     */
    private static $substs = array();

    /**
     * @see addHandler
     * @var array
     */
    private static $handlers = array();

    /**
     * @var int
     */
    private static $timer;



    /**
     * Monostate class
     */
    final private function __construct()
    {}


    /**
     * Creates a new DibiDriver object and connects it to specified database
     *
     * @param  array|string connection parameters
     * @param  string       connection name
     * @return DibiDriver
     * @throws DibiException
     */
    public static function connect($config = 'driver=mysql', $name = 0)
    {
        // DSN string
        if (is_string($config)) {
            parse_str($config, $config);
        }

        // config['driver'] is required
        if (empty($config['driver'])) {
            throw new DibiException('Driver is not specified.');
        }

        // include dibi driver
        $class = "Dibi$config[driver]Driver";
        if (!class_exists($class)) {
            include_once dirname(__FILE__) . "/drivers/$config[driver].php";

            if (!class_exists($class)) {
                throw new DibiException("Unable to create instance of dibi driver class '$class'.");
            }
        }

        // create connection object and store in list
        /** like $connection = $class::connect($config); */
        self::$connection = self::$registry[$name] = new $class($config);

        if (self::$logAll) self::log("OK: connected to DB '$config[driver]'");

        return self::$connection;
    }



    /**
     * Returns TRUE when connection was established
     *
     * @return bool
     */
    public static function isConnected()
    {
        return (bool) self::$connection;
    }



    /**
     * Retrieve active connection
     *
     * @param  string   connection registy name
     * @return object   DibiDriver object.
     * @throws DibiException
     */
    public static function getConnection($name = NULL)
    {
        if ($name === NULL) {
            if (!self::$connection) {
                throw new DibiException('Dibi is not connected to database');
            }

            return self::$connection;
        }

        if (!isset(self::$registry[$name])) {
            throw new DibiException("There is no connection named '$name'.");
        }

        return self::$registry[$name];
    }



    /**
     * Change active connection
     *
     * @param  string   connection registy name
     * @return void
     * @throws DibiException
     */
    public static function activate($name)
    {
        self::$connection = self::getConnection($name);
    }



    /**
     * Generates and executes SQL query - Monostate for DibiDriver::query()
     *
     * @param  array|mixed    one or more arguments
     * @return int|DibiResult
     * @throws DibiException
     */
    public static function query($args)
    {
        // receive arguments
        if (!is_array($args)) $args = func_get_args();

        return self::getConnection()->query($args);
    }



    /**
     * Generates and prints SQL query
     *
     * @param  array|mixed  one or more arguments
     * @return bool
     */
    public static function test($args)
    {
        // receive arguments
        if (!is_array($args)) $args = func_get_args();

        // and generate SQL
        $trans = new DibiTranslator(self::getConnection());
        try {
            $sql = $trans->translate($args);
        } catch (DibiException $e) {
            return FALSE;
        }

        if ($sql === FALSE) return FALSE;

        self::dump($sql);

        return TRUE;
    }



    /**
     * Retrieves the ID generated for an AUTO_INCREMENT column by the previous INSERT query
     * Monostate for DibiDriver::insertId()
     *
     * @param  string     optional sequence name for DibiPostgreDriver
     * @return int|FALSE  int on success or FALSE on failure
     */
    public static function insertId($sequence=NULL)
    {
        return self::getConnection()->insertId($sequence);
    }



    /**
     * Gets the number of affected rows
     * Monostate for DibiDriver::affectedRows()
     *
     * @return int  number of rows or FALSE on error
     */
    public static function affectedRows()
    {
        return self::getConnection()->affectedRows();
    }



    /**
     * Executes the SQL query - Monostate for DibiDriver::nativeQuery()
     *
     * @param string        SQL statement.
     * @return object|bool  Result set object or TRUE on success, FALSE on failure
     */
    public static function nativeQuery($sql)
    {
        return self::getConnection()->nativeQuery($sql);
    }



    /**
     * Begins a transaction - Monostate for DibiDriver::begin()
     */
    public static function begin()
    {
        return self::getConnection()->begin();
    }



    /**
     * Commits statements in a transaction - Monostate for DibiDriver::commit()
     */
    public static function commit()
    {
        return self::getConnection()->commit();
    }



    /**
     * Rollback changes in a transaction - Monostate for DibiDriver::rollback()
     */
    public static function rollback()
    {
        return self::getConnection()->rollback();
    }



    private static function dumpHighlight($matches)
    {
        if (!empty($matches[1])) // comment
            return '<em style="color:gray">'.$matches[1].'</em>';

        if (!empty($matches[2])) // error
            return '<strong style="color:red">'.$matches[2].'</strong>';

        if (!empty($matches[3])) // most important keywords
            return '<strong style="color:blue">'.$matches[3].'</strong>';

        if (!empty($matches[4])) // other keywords
            return '<strong style="color:green">'.$matches[4].'</strong>';
    }



    /**
     * Prints out a syntax highlighted version of the SQL command
     *
     * @param string   SQL command
     * @param bool   return or print?
     * @return void
     */
    public static function dump($sql, $return = FALSE) {
        static $keywords2 = 'ALL|DISTINCT|AS|ON|INTO|AND|OR|AS';
        static $keywords1 = 'SELECT|UPDATE|INSERT|DELETE|FROM|WHERE|HAVING|GROUP\s+BY|ORDER\s+BY|LIMIT|SET|VALUES|LEFT\s+JOIN|INNER\s+JOIN';

        // insert new lines
        $sql = preg_replace("#\\b(?:$keywords1)\\b#", "\n\$0", $sql);

        $sql = trim($sql);
        // reduce spaces
        $sql = preg_replace('# {2,}#', ' ', $sql);

        $sql = wordwrap($sql, 100);
        $sql = htmlSpecialChars($sql);
        $sql = preg_replace("#\n{2,}#", "\n", $sql);

        // syntax highlight
        $sql = preg_replace_callback("#(/\\*.+?\\*/)|(\\*\\*.+?\\*\\*)|\\b($keywords1)\\b|\\b($keywords2)\\b#", array('dibi', 'dumpHighlight'), $sql);
        $sql = '<pre class="dump">' . $sql . "</pre>\n";

        // print & return
        if (!$return) echo $sql;
        return $sql;
    }



    /**
     * Displays complete result-set as HTML table
     *
     * @param object   DibiResult
     * @return void
     */
    public static function dumpResult(DibiResult $res)
    {
        echo '<table class="dump"><tr>';
        echo '<th>#row</th>';
        foreach ($res->getFields() as $field)
            echo '<th>' . $field . '</th>';
        echo '</tr>';

        foreach ($res as $row => $fields) {
            echo '<tr><th>', $row, '</th>';
            foreach ($fields as $field) {
                if (is_object($field)) $field = $field->__toString();
                echo '<td>', htmlSpecialChars($field), '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }



    /**
     * Create a new substitution pair for indentifiers
     *
     * @param string from
     * @param string to
     * @return void
     */
    public static function addSubst($expr, $subst)
    {
        self::$substs[':'.$expr.':'] = $subst;
    }



    /**
     * Remove substitution pair
     *
     * @param mixed from or TRUE
     * @return void
     */
    public static function removeSubst($expr)
    {
        if ($expr === TRUE) {
            self::$substs = array();
        } else {
            unset(self::$substs[':'.$expr.':']);
        }
    }



    /**
     * Returns substitution pairs
     *
     * @return array
     */
    public static function getSubst()
    {
        return self::$substs;
    }



    /**
     * Add new event handler
     *
     * @param string   event name
     * @param callback
     * @return void
     */
    public static function addHandler($event, $callback)
    {
        if (!is_callable($callback)) {
            throw new DibiException("Invalid callback");
        }

        self::$handlers[$event][] = $callback;
    }



    /**
     * Invoke registered handlers
     *
     * @param string   event name
     * @param array    arguments passed into handler
     * @return void
     */
    public static function invokeEvent($event, $args)
    {
        if (!isset(self::$handlers[$event])) return;

        foreach (self::$handlers[$event] as $handler) {
            call_user_func_array($handler, $args);
        }
    }



    public static function beforeQuery($driver, $sql)
    {
        self::$sql = $sql;
        self::$timer = -microtime(true);
    }



    /**
     * Error logging - EXPERIMENTAL
     */
    public static function afterQuery($driver, $sql, $res)
    {
        if ($res === FALSE) { // query error
            if (self::$logFile) { // log to file
                $info = $driver->errorInfo();
                if ($info['code']) {
                    $info['message'] = "[$info[code]] $info[message]";
                }

                self::log(
                    "ERROR: $info[message]"
                    . "\n-- SQL: " . self::$sql
                    . "\n-- driver: " . $driver->getConfig('driver')
                    . ";\n-- " . date('Y-m-d H:i:s ')
                );
            }

            if (self::$throwExceptions) {
                $info = $driver->errorInfo();
                throw new DibiException('Query error (driver ' . $driver->getConfig('driver') . ')', $info, self::$sql);
            } else {
                $info = $driver->errorInfo();
                if ($info['code']) {
                    $info['message'] = "[$info[code]] $info[message]";
                }

                trigger_error("self: $info[message]", E_USER_WARNING);
                return FALSE;
            }
        }

        if (self::$logFile && self::$logAll) { // log success
            self::$timer += microtime(true);
            $msg = $res instanceof DibiResult ? 'object('.get_class($res).') rows: '.$res->rowCount() : 'OK';

            self::log(
                "OK: " . self::$sql
                . ";\n-- result: $msg"
                . "\n-- takes: " . sprintf('%0.3f', self::$timer * 1000) . ' ms'
                . "\n-- driver: " . $driver->getConfig('driver')
                . "\n-- " . date('Y-m-d H:i:s ')
            );
        }

    }



    /**
     * Error logging - EXPERIMENTAL
     */
    public static function log($message)
    {
        if (self::$logFile == NULL || self::$logMode == NULL) return;

        $f = fopen(self::$logFile, self::$logMode);
        if (!$f) return;
        flock($f, LOCK_EX);
        fwrite($f, $message. "\n\n");
        fclose($f);
    }


} // class dibi



// this is experimental:
dibi::addHandler('beforeQuery', array('dibi', 'beforeQuery'));
dibi::addHandler('afterQuery', array('dibi', 'afterQuery'));