<?php //-->
/*
 * This file is part of the Eden package.
 * (c) 2011-2012 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

require_once dirname(__FILE__).'/sql/database.php';
require_once dirname(__FILE__).'/sqlite/error.php';
require_once dirname(__FILE__).'/sqlite/alter.php';
require_once dirname(__FILE__).'/sqlite/create.php';
require_once dirname(__FILE__).'/sqlite/utility.php';

/**
 * Abstractly defines a layout of available methods to
 * connect to and query a SQLite database. This class also 
 * lays out query building methods that auto renders a 
 * valid query the specific database will understand without 
 * actually needing to know the query language. Extending
 * all SQL classes, comes coupled with loosely defined
 * searching, collections and models.
 *
 * @package    Eden
 * @category   sql
 * @author     Christian Blanquera cblanquera@openovate.com
 */
class Eden_Sqlite extends Eden_Sql_Database {
	/* Constants
	-------------------------------*/
	/* Public Properties
	-------------------------------*/
	/* Protected Properties
	-------------------------------*/
	protected $_file = NULL;
	
	protected $_model 		= self::MODEL;
	protected $_collection 	= self::COLLECTION;
	
	/* Private Properties
	-------------------------------*/
	/* Magic
	-------------------------------*/
	public static function i() {
		return self::_getMultiple(__CLASS__);
	}
	
	public function __construct($file) {
		//argument test
		Eden_Sqlite_Error::i()->argument(1, 'string');
		$this->_file = $file;
	}
	
	/* Public Methods
	-------------------------------*/	
	/**
	 * Returns the alter query builder
	 *
	 * @return Eden_Sql_Alter
	 */ 
	public function alter($name = NULL) {
		//Argument 1 must be a string or null
		Eden_Sqlite_Error::i()->argument(1, 'string', 'null');
		
		return Eden_Sqlite_Alter::i($name);
	}
	/**
	 * Connects to the database
	 * 
	 * @param array the connection options
	 * @return this
	 */
	public function connect(array $options = array()) {
		$this->_connection = new PDO('sqlite:'.$this->_file);
		$this->_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->trigger('connect');
		
		return $this;
	}
	
	/**
	 * Returns the create query builder
	 *
	 * @return Eden_Sql_Create
	 */ 
	public function create($name = NULL) {
		//Argument 1 must be a string or null
		Eden_Sqlite_Error::i()->argument(1, 'string', 'null');
		
		return Eden_Sqlite_Create::i($name);
	}
	
	/**
	 * Returns the columns and attributes given the table name
	 *
	 * @param the name of the table
	 * @return attay|false
	 */
	public function getColumns($table) {
		//Argument 1 must be a string
		Eden_Sqlite_Error::i()->argument(1, 'string');
		
		$query = $this->utility()->showColumns($table);
		$results = $this->query($query, $this->getBinds());
		
		$columns = array();
		foreach($results as $column) {
			$key = NULL;
			if($column['pk'] == 1) {
				$key = 'PRI';
			} 
			
			$columns[] = array(
				'Field' 	=> $column['name'],
				'Type' 		=> $column['type'],
				'Default' 	=> $column['dflt_value'], 
				'Null'		=> $column['notnull'] != 1,
				'Key'		=> $key);
		}
		
		return $columns;
	}
	
	/**
	 * Peturns the primary key name given the table
	 *
	 * @param string table
	 * @return string
	 */
	public function getPrimaryKey($table) {
		//Argument 1 must be a string
		Eden_Sqlite_Error::i()->argument(1, 'string');
		
		$query = $this->utility();
		$results = $this->getColumns($table, "`Key` = 'PRI'");
		return isset($results[0]['Field']) ? $results[0]['Field'] : NULL;
	}
	
	/**
	 * Returns the whole enitre schema and rows
	 * of the current databse
	 *
	 * @return string
	 */
	public function getSchema() {
		$backup = array();
		$tables = $this->getTables();
		foreach($tables as $table) {
			$backup[] = $this->getBackup();
		}
		
		return implode("\n\n", $backup);
	}
	
	/**
	 * Returns the whole enitre schema and rows
	 * of the current table
	 *
	 * @return string
	 */
	public function getTableSchema($table) {
		//Argument 1 must be a string
		Eden_Sqlite_Error::i()->argument(1, 'string');
		
		$backup = array();
		//get the schema
		$schema = $this->getColumns($table);
		if(count($schema)) {
			//lets rebuild this schema
			$query = $this->create()->setName($table);
			foreach($schema as $field) {
				//first try to parse what we can from each field
				$fieldTypeArray = explode(' ', $field['Type']);
				$typeArray = explode('(', $fieldTypeArray[0]);
				
				$type = $typeArray[0];
				$length = str_replace(')', '', $typeArray[1]);
				$attribute = isset($fieldTypeArray[1]) ? $fieldTypeArray[1] : NULL;
				
				$null = strtolower($field['Null']) == 'no' ? false : true;
				
				$increment = strtolower($field['Extra']) == 'auto_increment' ? true : false;
				
				//lets now add a field to our schema class
				$q->addField($field['Field'], array(
					'type' 				=> $type, 
					'length' 			=> $length,
					'attribute' 		=> $attribute, 
					'null' 				=> $null,
					'default'			=> $field['Default'],
					'auto_increment' 	=> $increment));
				
				//set keys where found
				switch($field['Key'])
				{
					case 'PRI':
						$query->addPrimaryKey($field['Field']);
						break;
					case 'UNI':
						$query->addUniqueKey($field['Field'], array($field['Field']));
						break;
					case 'MUL':
						$query->addKey($field['Field'], array($field['Field']));
						break;
				}
			}
			
			//store the query but dont run it
			$backup[] = $query;
		}
		
		//get the rows
		$rows = $this->query($this->select->from($table)->getQuery());	
		if(count($rows)) {
			//lets build an insert query
			$query = $this->insert($table);
			foreach($rows as $index => $row) {
				foreach($row as $key => $value) {
					$query->set($key, $this->getBinds($value), $index);
				}
			}
			
			//store the query but dont run it
			$backup[] = $query->getQuery(true);
		}
		
		return implode("\n\n", $backup);
	}
	
	/**
	 * Returns a listing of tables in the DB
	 *
	 * @param the like pattern
	 * @return attay|false
	 */
	public function getTables($like = NULL) {
		//Argument 1 must be a string or null
		Eden_Sqlite_Error::i()->argument(1, 'string', 'null');
		
		$query = $this->utility();
		$like = $like ? $this->bind($like) : NULL;
		$results = $this->query($query->showTables($like), $q->getBinds());
		$newResults = array();
		foreach($results as $result) {
			foreach($result as $key => $value) {
				$newResults[] = $value;
				break;
			}
		}
		
		return $newResults;
	}
	
	/**
	 * Returns the select query builder
	 *
	 * @return Eden_Sql_Select
	 */ 
	public function select($select = 'ROWID,*') {
		//Argument 1 must be a string or array
		Eden_Sqlite_Error::i()->argument(1, 'string', 'array');
		
		return Eden_Sql_Select::i($select);
	}
	
	/**
	 * Returns the alter query builder
	 *
	 * @return Eden_Sql_Utility
	 */ 
	public function utility() {
		return Eden_Sqlite_Utility::i();
	}
	
	/* Protected Methods
	-------------------------------*/
	/* Private Methods
	-------------------------------*/
}

