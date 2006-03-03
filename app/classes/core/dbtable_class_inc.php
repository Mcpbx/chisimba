<?php
// security check - must be included in all scripts
if (!$GLOBALS['kewl_entry_point_run']) {
    die("You cannot view this page directly");
}
// end security check
require_once "lib/logging.php";

/**
* Class to encapsulate operations on a database table.
* It is highly recommended that you create a derived version
* of this class for each table, rather than using it directly.
*
* @author Paul Scott based on methods by Sean Legassick
* @example ./examples/dbtable.eg.php The example
* @filesource
* @copyright (c) 2000-2006, GNU/GPL AVOIR UWC
* @package core
* @subpackage dbtable
* @version 0.1
* @since 03 March 2006
*
* $Id$
*/

class dbTable extends object
{
    /**
     * Whether or not to use prepared statements
     *
     * @access public
     * @var string
     */
    public $USE_PREPARED_STATEMENTS = true;

    /**
     * The current table name that we are working with
     *
     * @access public
     * @var string - default null
     */
    public $_tableName = null;

    /**
     * The global error callback for dbTable errors
     *
     * @access public
     * @var string
     */
    public $_errorCallback;

    /**
     * The database config object
     *
     * @access public
     * @var object
     */
    public $objDBConfig;

    /**
     * The db object
     *
     * @access private
     * @var object
     */
    private $_db = null;

    /**
     * Are we in a transaction?
     *
     * @access private
     * @var string
     */
    private $_inTransaction = false;

    /**
     * property to hold the last id inserted into the db
     *
     * @access private
     * @var string
     */
    private $_lastId = null;

    /**
     * Property to hold the portability object for queries against multiple RDBM's
     *
     * @access private
     * @var object
     */
    private $_portability;


    /**
    * Method to initialise the dbTable object.
    *
    * @access public
    * @param string $tableName The name of the table this object encapsulates
    * @param boolean $mirror Whether to mirror these operations (defaults to TRUE)
    * @param PEAR $ ::MDB2 $pearDb The PEAR::MDB2 object to use (defaults to use the global connection)
    * @param callback $errorCallback The name of a custom error callback function (defaults to the global)
    * @return void
    */
    public function init($tableName, $pearDb = null,
        $errorCallback = "globalPearErrorCallback")
    {
        $this->_tableName = $tableName;
        $this->_errorCallback = $errorCallback;
        if ($pearDb == null) {
            $this->_db = $this->objEngine->getDbObj();
        } else {
            $this->_db = $pearDb;
        }

        $this->objDBConfig=&$this->getObject('dbconfig','config');
        $this->_serverName = $this->objDBConfig->serverName();

        //get the portability object instantiated
        //we pass the dsn of the db object to the class via the engine->getDbObj() method
        require_once('portability_class_inc.php');
        $this->_portability = new portability($this->_db);

        //When creating tables it is neccessary to use the portability object.
        //Once that is done, all should be Ok
        /**
         * @example portability_example.php
         * @link portability_example.php
         */
    }

    /**
    * Method to evaluate if a particular value of a particular field exists in the database
    *
    * @access public
    * @param string $field the name of the field to search
    * @param mixed $value the value to search for
    * @return bool TRUE |FALSE if exists return TRUE, otherwise FALSE.
    */
    public function valueExists($field, $value)
    {
        $sql = "SELECT COUNT(*) AS fCount FROM $this->_tableName WHERE $field='$value'";
        $rs = $this->_execute($sql);
        if (!$rs) {
            $ret = false;
        } else {
            $line = $rs->fetchOne();
            if ($line['fCount'] > 0) {
                $ret = true;
            } else {
                $ret = false;
            }
        }
        return $ret;
    }

    /**
    * Method to get all items from the table.
    * Override in derived classes to implement access restrictions
    *
    * @access public
    * @param string $filter a SQL WHERE clause (optional)
    * @return array |FALSE Rows as an array of associative arrays, or FALSE on failure
    */
    public function getAll($filter = null)
    {
        $stmt = "SELECT * FROM {$this->_tableName} $filter";
        return $this->getArray($stmt);
    }

    /**
    * Method to fetch a row from the table.
    * Override in derived classes if necessary
    *
    * @access public
    * @return array |FALSE A row as an associative array, or FALSE on failure
    * @param string $pkfield the name of the primary key field
    * @param mixed $pkvalue the value of the primary key field for the record
    */
    public function getRow($pk_field, $pk_value)
    {
        $pk_value = addslashes($pk_value);
        $stmt = "SELECT * FROM {$this->_tableName} WHERE {$pk_field}='{$pk_value}'";

        return $this->_db->queryRow($stmt, array()); //, DB_FETCHMODE_ASSOC);
    }

    /**
    * Method to execute a query against the database and return an array.
    *
    * @access public
    * @param string $stmt the SQL query string
    * @return array |FALSE Rows as an array of associate arrays, or FALSE on failure
    */
    public function getArray($stmt)
    {
        $ret = $this->_db->queryAll($stmt, array()); //, MDB2_FETCHMODE_ASSOC);
        if (PEAR::isError($ret)) {
            $ret = false;
        }
        return $ret;
    }

    /**
    * Method to execute a query against the database and return an array.
    *
    * @access public
    * @param string $stmt the SQL query string
    * @param int $first The first record to return
    * @param int $count The number of records to return
    * @return array |FALSE Rows as an array of associate arrays, or FALSE on failure
    */
    public function getArrayWithLimit($stmt, $first, $count)
    {
        $rs = $this->_db->limitQuery($stmt, $first, $count);
        if (PEAR::isError($rs)) {
            $ret = false;
        } else {
            $ret = array();
            while ($row = $rs->fetchRow()) {
                $ret[] = $row;
            }
        }
        return $ret;
    }

    /**
    * Method to fetch all items from the table.
    * Override in derived classes to implement access restrictions
    *
    * @access public
    * @return db _result|FALSE a PEAR::DB_Result object, or FALSE
    * @param string $filter a SQL WHERE clause (optional)
    * @deprecated See getAll
    * @see getAll
    */
    public function fetchAll($filter = null)
    {
        $stmt = "SELECT * FROM {$this->_tableName} " . $filter;
        return $this->query($stmt);
    }

    /**
    * Method to return a count of records in the table.
    * Mainly to use in recordset paging
    *
    * @access public
    * @param string $filter a SQL WHERE clause (optional)
    * @return int number of records that matched
    */
    public function getRecordCount($filter = null)
    {
        $sql = "SELECT COUNT(*) AS rc FROM {$this->_tableName} " . $filter;
        $rs = $this->query($sql);
        //var_dump($rs);
       // $line = $rs->fetchOne();
       // return $line['rc'];
       return $rs[0]['rc'];
    }

    /**
    * Method to begin a transaction.
    * Use a transaction where you are performing multiple inserts and/or updates as part
    * of a single conceptual operation.
    *
    * @access public
    * @param void
    * @return void
    */
    public function beginTransaction()
    {
        if ($this->_db->supports('transactions')) {
            $this->_db->beginTransaction();
            $this->_inTransaction = true;
	    }
    }

    /**
    * Method to commit a transaction.
    *
    * @param void
    * @return set property _inTransaction
    * @access public
    */
    public function commitTransaction()
    {
        if ($this->db->in_transaction)
        {
            $this->_db->commit();
            $this->_inTransaction = false;
        }
    }

    /**
    * Method to rollback a transaction.
    *
    * @param void
    * @return void
    * @access public
    */
    public function rollbackTransaction()
    {
        $this->_db->rollback();
        $this->_inTransaction = false;
    }

    /**
    * Method to insert a new record into the table.
    *
    * @param array $fields The new record as an associative array containing field names as keys,
    *      field values as values. All non-NULL fields must be present
    * @param string $tablename The table to insert into, if not the default (optional)
    * @return string |FALSE Generated PK ID on success, FALSE on failure
    * @access public
    */
    public function insert($fields, $tablename = '')
    {
        log_debug("dbtable insert into $tablename");
        log_debug($fields);
        if (empty($tablename)) {
            $tablename = $this->_tableName;
        }
        $sql = "INSERT INTO {$tablename} SET ";
        $comma = "";
        if (empty($fields['id'])) {
            $id = $this->generateId();
            $fields['id'] = $id;
        } else {
            $id = $fields['id'];
        }
        foreach ($fields as $fieldName => $fieldValue) {
            if ($this->USE_PREPARED_STATEMENTS) {
                $sql .= "{$comma}{$fieldName}=?";
                $params[] = $fieldValue;
            } else {
                $sql .= "{$comma}{$fieldName}='{$fieldValue}'";
            }
            $comma = ', ';
        }
        $this->_lastId = $id;
        $ret = $this->_execute($sql, $params);
        log_debug("success $ret");

        return $ret ? $id : false;
    }

    /**
    * Method to update an existing record in the table.
    *
    * @param string $pkfield the name of the primary key field
    * @param mixed $pkvalue the value of the primary key field for the record to update
    * @param array $fields The record as an associative array containing field names as keys and field values as values.
    * @param string $tablename The name of the table to update, if not the default (optional)
    * @return TRUE |FALSE TRUE on success, FALSE on failure
    * @access public
    */
    public function update($pkfield, $pkvalue, $fields, $tablename = '')
    {
        if (empty($tablename)) {
            $tablename = $this->_tableName;
        }
        $sql = "UPDATE {$tablename} SET ";
        $comma = "";
        $params = array();
        foreach ($fields as $fieldName => $fieldValue) {
            if ($this->USE_PREPARED_STATEMENTS) {
                $sql .= "{$comma}{$fieldName}=?";
                $params[] = $fieldValue;
            } else {
                $sql .= "{$comma}{$fieldName}='{$fieldValue}'";
            }
            $comma = ',';
        }
        $sql .= " WHERE {$pkfield}='{$pkvalue}'";
        $ret = $this->_execute($sql, $params);

        return $ret;
    }

    /**
    * Method to delete a record from the table.
    *
    * @param string $pkfield the name of the primary key field
    * @param mixed $pkvalue the value of the primary key field for the record to delete
    * @return TRUE |FALSE TRUE on success, FALSE on failure
    * @access public
    */
    public function delete($pkfield, $pkvalue, $tablename = '')
    {
        if (empty($tablename)) {
            $tablename = $this->_tableName;
        }

        $sql = "DELETE FROM {$tablename} WHERE {$pkfield}='{$pkvalue}'";
        $ret = $this->_execute($sql);

        return $ret;
    }

    /**
    * Method to execute a SQL statement.
    *      Should not be used outside of methods of this class.
    *      Add methods here if you need functionality not available
    *      from them. Successful implementation of dynamic mirroring
    *      will depend on all database modifications being channeled
    *      through this class or its derived classes.
    *
    * @param string $stmt The SQL statement
    * @param array $params The parameters to the execute, defaults to empty
    * @return TRUE |FALSE TRUE on success, FALSE on failure
    * @access public
    * @todo create an alias for this for public use and mark it as private
    */
    public function _execute($stmt, $params = array())
    {
        $sh = $this->_db->prepare($stmt);
        return ($sh->execute($params));
    }

    /**
    * Method to execute a SQL statement on an array of records (each an array itself).
    *      Should not be used outside of methods of this class for
    *      the same reasons as _execute.
    *
    * @param string $stmt The SQL statement
    * @param array $data The array of records (each arrays themselves)
    * @return TRUE |FALSE TRUE on success, FALSE on failure
    * @access private
    */
    private function _executeMultiple($stmt, $data = array())
    {
        if (!$this->condConnect()) {
            return false;
        }
        $sh = $this->_db->prepare($stmt);
        $this->_db->loadModule('Extended');
        $this->_db->extended->executeMultiple($stmt, $data);
        return ($this->_db->executeMultiple($sh, $data));
    }

    /**
    * Method to fetch the primary key value of the last insert operation
    *
    * @return mixed Primary key value of last insert.
    * @param void
    * @access public
    */
    public function getLastInsertId()
    {
        return $this->_lastId;
    }

    /**
    * The error callback function, defers to configured error handler
    *
    * @param string $error
    * @return void
    * @access public
    */
    public function errorCallback($error)
    {
        call_user_func($this->_errorCallback, $error);
    }

    /**
    * Method to execute a query against the database
    *
    * @param string $stmt the SQL query string
    * @return db _result|FALSE a PEAR::DB_Result object, or FALSE on failure
    * @deprecated see execute()
    * @access public
    */
    public function query($stmt)
    {
        $ret = $this->_db->queryAll($stmt);
        if (PEAR::isError($ret)) {
            $ret = false;
        }
        return $ret;
    }

    /**
    * Method to construct a sql join statement.
    *
    * The assumption is that the From table is the current table.<br>
    * An example: The join condition is (tblFrom.fk1 = tblTo.pk1)
    * <code>join('INNER JOIN', 'tblTo', array('fk1'=>'pk1')</code>
    *
    * @param string $sqlJoinType the sql JOIN statement. (eg. 'INNER JOIN'|'LEFT JOIN')
    * @param string $tblJoinTo name of the table to join to.
    * @param array $join the foriegn and primary keys of the two tables. (eg. array('fkfield' => 'pkfield') )
    * @param string $tblJoinFrom name of the table being joined to.(optional)
    * @return string the sql JOIN statement.
    * @access public
    */
    public function join($sqlJoinType, $tblJoinTo, $join, $tblJoinFrom = null)
    {
        $tblJoinFrom = (is_null($tblJoinFrom)) ? $this->_tableName : $tblJoinFrom ;
        $strSQL = "{$sqlJoinType} {$tblJoinTo}";
        // Condtions of the join
        $insertON = true;
        foreach ($join as $key => $value) {
            $strSQL .= ($insertON) ? " ON ( " : " OR ( ";
            $insertON = false;
            $strSQL .= "{$tblJoinFrom}.{$key} = {$tblJoinTo}.{$value}";
            $strSQL .= " )";
        }
        return $strSQL;
    }

    /**
    * Method to generate a unique ID for use as a primary key value
    * To ensure uniqueness amongst a mirroring cluster '@servername' is
    * appended.
    *
    * @return string The generated ID
    * @param void
    * @access public
    */
    public function generateId()
    {
        $id = $this->_db->nextId($this->_tableName);
        $id = $this->_serverName . "_" . $id;
        return $id;
    }

/**
 *  Not sure if we include this crap or not...
 *  @todo fixme
 */
    /**
    * Method to check if table exists
    *
    * @param string $table The name of the table to check
    */
    public function tableExists($table) {
        //Get an array of tables
        $ar = $this->getListOf('tables');
        $numElements = count($ar);
        for($counter=0; $counter < $numElements; $counter++) {
           if($ar[$counter] == $table) {
               return TRUE;
           }
        }
        return FALSE;
    }

    /**
    * Wrapper for the getListOf method of PEAR MDB2
    *
    * @param string $What The item to get a list of as per PEAR MDB2
    */
    public function getListOf($what)
    {
        return $this->_db->getListOf($what);
    }

    /**
    * Method to return the last entry in a table or
    * last entry that matches $filter
    *
    * @param string $filter A SQL where clause
    * @param string $orderField The field to use as the
    * order field to mark the last entry, defaults to the
    * id field
    */
    public function getLastEntry($filter=NULL, $orderField="id")
    {
        $sql = "SELECT * FROM " . $this->_tableName . $filter  . " ORDER BY "
          . $orderField . " DESC ";
        return $this->getArrayWithLimit($sql, 0, 1);
    }

    /**
     * Select a different database
     *
     * @param string $name name of the database that should be selected
     * @return string name of the database previously connected to
     * @access public
     */
    public function setDatabaseTo($name)
    {
        $ret = $this->setDatabase($name);
        return $ret;
    }

} // end of dbTable class
?>