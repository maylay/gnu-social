<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Database schema utilities
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Database
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @copyright 2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * Class representing the database schema
 *
 * A class representing the database schema. Can be used to
 * manipulate the schema -- especially for plugins and upgrade
 * utilities.
 *
 * @category Database
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @author   Brion Vibber <brion@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class Schema
{
    static $_static = null;
    protected $conn = null;

    /**
     * Constructor. Only run once for singleton object.
     */

    protected function __construct($conn = null)
    {
        if (is_null($conn)) {
            // XXX: there should be an easier way to do this.
            $user = new User();
            $conn = $user->getDatabaseConnection();
            $user->free();
            unset($user);
        }

        $this->conn = $conn;
    }

    /**
     * Main public entry point. Use this to get
     * the schema object.
     *
     * @return Schema the Schema object for the connection
     */

    static function get($conn = null)
    {
        if (is_null($conn)) {
            $key = 'default';
        } else {
            $key = md5(serialize($conn->dsn));
        }
        
        $type = common_config('db', 'type');
        if (empty(self::$_static[$key])) {
            $schemaClass = ucfirst($type).'Schema';
            self::$_static[$key] = new $schemaClass($conn);
        }
        return self::$_static[$key];
    }

    /**
     * Gets a ColumnDef object for a single column.
     *
     * Throws an exception if the table is not found.
     *
     * @param string $table  name of the table
     * @param string $column name of the column
     *
     * @return ColumnDef definition of the column or null
     *                   if not found.
     */

    public function getColumnDef($table, $column)
    {
        $td = $this->getTableDef($table);

        foreach ($td->columns as $cd) {
            if ($cd->name == $column) {
                return $cd;
            }
        }

        return null;
    }

    /**
     * Creates a table with the given names and columns.
     *
     * @param string $name    Name of the table
     * @param array  $columns Array of ColumnDef objects
     *                        for new table.
     *
     * @return boolean success flag
     */

    public function createTable($name, $columns)
    {
        $uniques = array();
        $primary = array();
        $indices = array();

        $sql = "CREATE TABLE $name (\n";

        for ($i = 0; $i < count($columns); $i++) {

            $cd =& $columns[$i];

            if ($i > 0) {
                $sql .= ",\n";
            }

            $sql .= $this->_columnSql($cd);

            switch ($cd->key) {
            case 'UNI':
                $uniques[] = $cd->name;
                break;
            case 'PRI':
                $primary[] = $cd->name;
                break;
            case 'MUL':
                $indices[] = $cd->name;
                break;
            }
        }

        if (count($primary) > 0) { // it really should be...
            $sql .= ",\nconstraint primary key (" . implode(',', $primary) . ")";
        }

        foreach ($uniques as $u) {
            $sql .= ",\nunique index {$name}_{$u}_idx ($u)";
        }

        foreach ($indices as $i) {
            $sql .= ",\nindex {$name}_{$i}_idx ($i)";
        }

        $sql .= "); ";

        $res = $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a table from the schema
     *
     * Throws an exception if the table is not found.
     *
     * @param string $name Name of the table to drop
     *
     * @return boolean success flag
     */

    public function dropTable($name)
    {
        $res = $this->conn->query("DROP TABLE $name");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Adds an index to a table.
     *
     * If no name is provided, a name will be made up based
     * on the table name and column names.
     *
     * Throws an exception on database error, esp. if the table
     * does not exist.
     *
     * @param string $table       Name of the table
     * @param array  $columnNames Name of columns to index
     * @param string $name        (Optional) name of the index
     *
     * @return boolean success flag
     */

    public function createIndex($table, $columnNames, $name=null)
    {
        if (!is_array($columnNames)) {
            $columnNames = array($columnNames);
        }

        if (empty($name)) {
            $name = "{$table}_".implode("_", $columnNames)."_idx";
        }

        $res = $this->conn->query("ALTER TABLE $table ".
                                   "ADD INDEX $name (".
                                   implode(",", $columnNames).")");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a named index from a table.
     *
     * @param string $table name of the table the index is on.
     * @param string $name  name of the index
     *
     * @return boolean success flag
     */

    public function dropIndex($table, $name)
    {
        $res = $this->conn->query("ALTER TABLE $table DROP INDEX $name");

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Adds a column to a table
     *
     * @param string    $table     name of the table
     * @param ColumnDef $columndef Definition of the new
     *                             column.
     *
     * @return boolean success flag
     */

    public function addColumn($table, $columndef)
    {
        $sql = "ALTER TABLE $table ADD COLUMN " . $this->_columnSql($columndef);

        $res = $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Modifies a column in the schema.
     *
     * The name must match an existing column and table.
     *
     * @param string    $table     name of the table
     * @param ColumnDef $columndef new definition of the column.
     *
     * @return boolean success flag
     */

    public function modifyColumn($table, $columndef)
    {
        $sql = "ALTER TABLE $table MODIFY COLUMN " .
          $this->_columnSql($columndef);

        $res = $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Drops a column from a table
     *
     * The name must match an existing column.
     *
     * @param string $table      name of the table
     * @param string $columnName name of the column to drop
     *
     * @return boolean success flag
     */

    public function dropColumn($table, $columnName)
    {
        $sql = "ALTER TABLE $table DROP COLUMN $columnName";

        $res = $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Ensures that a table exists with the given
     * name and the given column definitions.
     *
     * If the table does not yet exist, it will
     * create the table. If it does exist, it will
     * alter the table to match the column definitions.
     *
     * @param string $tableName name of the table
     * @param array  $columns   array of ColumnDef
     *                          objects for the table
     *
     * @return boolean success flag
     */

    public function ensureTable($tableName, $def)
    {
        // XXX: DB engine portability -> toilet

        try {
            $old = $this->getTableDef($tableName);
        } catch (Exception $e) {
            if (preg_match('/no such table/', $e->getMessage())) {
                return $this->createTable($tableName, $columns);
            } else {
                throw $e;
            }
        }

        $cur = array_keys($old['fields']);
        $new = array_keys($def['fields']);

        $toadd  = array_diff($new, $cur);
        $todrop = array_diff($cur, $new);
        $same   = array_intersect($new, $cur);
        $tomod  = array();

        // Find which fields have actually changed definition
        // in a way that we need to tweak them for this DB type.
        foreach ($same as $name) {
            $curCol = $old['fields'][$name];
            $newCol = $cur['fields'][$name];

            if (!$this->columnsEqual($curCol, $newCol)) {
                $tomod[] = $name;
            }
        }

        if (count($toadd) + count($todrop) + count($tomod) == 0) {
            // nothing to do
            return true;
        }

        // For efficiency, we want this all in one
        // query, instead of using our methods.

        $phrase = array();

        foreach ($toadd as $columnName) {
            $this->appendAlterAddColumn($phrase, $columnName,
                    $def['fields'][$columnName]);
        }

        foreach ($todrop as $columnName) {
            $this->appendAlterModifyColumn($phrase, $columnName,
                    $old['fields'][$columnName],
                    $def['fields'][$columnName]);
        }

        foreach ($tomod as $columnName) {
            $this->appendAlterDropColumn($phrase, $columnName);
        }

        $sql = 'ALTER TABLE ' . $tableName . ' ' . implode(', ', $phrase);

        $res = $this->conn->query($sql);

        if (PEAR::isError($res)) {
            throw new Exception($res->getMessage());
        }

        return true;
    }

    /**
     * Append phrase(s) to an array of partial ALTER TABLE chunks in order
     * to add the given column definition to the table.
     *
     * @param array $phrase
     * @param string $columnName
     * @param array $cd 
     */
    function appendAlterAddColumn(array &$phrase, $columnName, array $cd)
    {
        $phrase[] = 'ADD COLUMN ' .
                    $this->quoteIdentifier($columnName) .
                    ' ' .
                    $this->columnSql($cd);
    }

    /**
     * Append phrase(s) to an array of partial ALTER TABLE chunks in order
     * to alter the given column from its old state to a new one.
     *
     * @param array $phrase
     * @param string $columnName
     * @param array $old previous column definition as found in DB
     * @param array $cd current column definition
     */
    function appendAlterModifyColumn(array &$phrase, $columnName, array $old, array $cd)
    {
        $phrase[] = 'MODIFY COLUMN ' .
                    $this->quoteIdentifier($columnName) .
                    ' ' .
                    $this->columnSql($cd);
    }

    /**
     * Append phrase(s) to an array of partial ALTER TABLE chunks in order
     * to drop the given column definition from the table.
     *
     * @param array $phrase
     * @param string $columnName
     */
    function appendAlterDropColumn(array &$phrase, $columnName)
    {
        $phrase[] = 'DROP COLUMN ' . $this->quoteIdentifier($columnName);
    }

    /**
     * Quote a db/table/column identifier if necessary.
     *
     * @param string $name
     * @return string
     */
    function quoteIdentifier($name)
    {
        return $name;
    }

    function quoteDefaultValue($cd)
    {
        if ($cd['type'] == 'datetime' && $cd['default'] == 'CURRENT_TIMESTAMP') {
            return $cd['default'];
        } else {
            return $this->quoteValue($cd['default']);
        }
    }

    function quoteValue($val)
    {
        return $this->conn->escape($val);
    }

    /**
     * Check if two column definitions are equivalent.
     * The default implementation checks _everything_ but in many cases
     * you may be able to discard a bunch of equivalencies.
     *
     * @param array $a
     * @param array $b
     * @return boolean
     */
    function columnsEqual(array $a, array $b)
    {
        return !array_diff_assoc($a, $b) && !array_diff_assoc($b, $a);
    }

    /**
     * Returns the array of names from an array of
     * ColumnDef objects.
     *
     * @param array $cds array of ColumnDef objects
     *
     * @return array strings for name values
     */

    protected function _names($cds)
    {
        $names = array();

        foreach ($cds as $cd) {
            $names[] = $cd->name;
        }

        return $names;
    }

    /**
     * Get a ColumnDef from an array matching
     * name.
     *
     * @param array  $cds  Array of ColumnDef objects
     * @param string $name Name of the column
     *
     * @return ColumnDef matching item or null if no match.
     */

    protected function _byName($cds, $name)
    {
        foreach ($cds as $cd) {
            if ($cd->name == $name) {
                return $cd;
            }
        }

        return null;
    }

    /**
     * Return the proper SQL for creating or
     * altering a column.
     *
     * Appropriate for use in CREATE TABLE or
     * ALTER TABLE statements.
     *
     * @param ColumnDef $cd column to create
     *
     * @return string correct SQL for that column
     */

    function columnSql(array $cd)
    {
        $line = array();
        $line[] = $this->typeAndSize();

        if (isset($cd['default'])) {
            $line[] = 'default';
            $line[] = $this->quoted($cd['default']);
        } else if (!empty($cd['not null'])) {
            // Can't have both not null AND default!
            $line[] = 'not null';
        }

        return implode(' ', $line);
    }

    /**
     *
     * @param string $column canonical type name in defs
     * @return string native DB type name
     */
    function mapType($column)
    {
        return $column;
    }

    function typeAndSize($column)
    {
        $type = $this->mapType($column);
        $lengths = array();

        if ($column['type'] == 'numeric') {
            if (isset($column['precision'])) {
                $lengths[] = $column['precision'];
                if (isset($column['scale'])) {
                    $lengths[] = $column['scale'];
                }
            }
        } else if (isset($column['length'])) {
            $lengths[] = $column['length'];
        }

        if ($lengths) {
            return $type . '(' . implode(',', $lengths) . ')';
        } else {
            return $type;
        }
    }

    /**
     * Convert an old-style set of ColumnDef objects into the current
     * Drupal-style schema definition array, for backwards compatibility
     * with plugins written for 0.9.x.
     *
     * @param string $tableName
     * @param array $defs
     * @return array
     */
    function oldToNew($tableName, $defs)
    {
        $table = array();
        $prefixes = array(
            'tiny',
            'small',
            'medium',
            'big',
        );
        foreach ($defs as $cd) {
            $cd->addToTableDef($table);
            $column = array();
            $column['type'] = $cd->type;
            foreach ($prefixes as $prefix) {
                if (substr($cd->type, 0, strlen($prefix)) == $prefix) {
                    $column['type'] = substr($cd->type, strlen($prefix));
                    $column['size'] = $prefix;
                    break;
                }
            }

            if ($cd->size) {
                if ($cd->type == 'varchar' || $cd->type == 'char') {
                    $column['length'] = $cd->size;
                }
            }
            if (!$cd->nullable) {
                $column['not null'] = true;
            }
            if ($cd->autoincrement) {
                $column['type'] = 'serial';
            }
            if ($cd->default) {
                $column['default'] = $cd->default;
            }
            $table['fields'][$cd->name] = $column;

            if ($cd->key == 'PRI') {
                // If multiple columns are defined as primary key,
                // we'll pile them on in sequence.
                if (!isset($table['primary key'])) {
                    $table['primary key'] = array();
                }
                $table['primary key'][] = $cd->name;
            } else if ($cd->key == 'MUL') {
                // Individual multiple-value indexes are only per-column
                // using the old ColumnDef syntax.
                $idx = "{$tableName}_{$cd->name}_idx";
                $table['indexes'][$idx] = array($cd->name);
            } else if ($cd->key == 'UNI') {
                // Individual unique-value indexes are only per-column
                // using the old ColumnDef syntax.
                $idx = "{$tableName}_{$cd->name}_idx";
                $table['unique keys'][$idx] = array($cd->name);
            }
        }

        return $table;
    }

    function isNumericType($type)
    {
        $type = strtolower($type);
        $known = array('int', 'serial', 'numeric');
        return in_array($type, $known);
    }
}

class SchemaTableMissingException extends Exception
{
    // no-op
}

