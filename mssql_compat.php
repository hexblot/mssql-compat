<?php
/**
 * MSSQL-Compat: emulate the removed mssql_* extension on top of PDO (dblib).
 *
 * Behavioural notes (see README for details):
 *  - Result sets are fully buffered at query time, like the original extension
 *    did with batch_size = 0. This keeps the connection free for nested queries
 *    and makes mssql_num_rows() / mssql_data_seek() / mssql_result() reliable.
 *  - Link and result identifiers are strings, not resources.
 *  - Errors set the message returned by mssql_get_last_message() and raise an
 *    E_USER_WARNING, mirroring the warnings the original extension emitted.
 */

// If mssql_connect() exists, there's no point in doing anything.
if(!function_exists('mssql_connect')) {
  // Map MSSQL defines to the PDO ones.
  define('MSSQL_ASSOC', PDO::FETCH_ASSOC);
  define('MSSQL_NUM',   PDO::FETCH_NUM  );
  define('MSSQL_BOTH',  PDO::FETCH_BOTH );

  // Unfortunately MSSQL functions assume too much, hence the need for pesky globals.
  global $mssql_compat_metadata;
  $mssql_compat_metadata  = array(
    'last_key'   => '',
    'last_error' => '',
    'conn_pool'  => array(),
    'conn_seq'   => 0,
  );

  /*****************************************************************************
  ***************** PART I - MSSQL API EQUIVALENT FUNCTIONS ********************
  *****************************************************************************/

  // See: http://php.net/manual/en/function.mssql-connect.php
  function mssql_connect($servername='', $username='', $password='', $new_link = false) {
    global $mssql_compat_metadata;

    $key = $servername.'-'.$username;

    if($new_link || !array_key_exists($key, $mssql_compat_metadata['conn_pool'])) {
      // A new link must not clobber an existing one with the same credentials.
      if($new_link && array_key_exists($key, $mssql_compat_metadata['conn_pool'])) {
        $mssql_compat_metadata['conn_seq']++;
        $key .= '|'.$mssql_compat_metadata['conn_seq'];
      }

      $conn = new MSSQLCompat($key, $servername, $username, $password);
      if(!$conn->isConnected()) {
        // Original extension: warning + FALSE.
        return false;
      }
      $mssql_compat_metadata['conn_pool'][$key] = $conn;
    }
    $mssql_compat_metadata['last_key'] = $key;

    // Use the $key as connection identifier.
    return $key;
  }

  // See: http://php.net/manual/en/function.mssql-pconnect.php
  function mssql_pconnect($servername='', $username='', $password='', $new_link = false) {
    return mssql_connect($servername, $username, $password, $new_link);
  }

  // See: http://php.net/manual/en/function.mssql-close.php
  function mssql_close($key='') {
    global $mssql_compat_metadata;

    $key = _mssql_compat_get_db($key, TRUE);
    if(!array_key_exists($key, $mssql_compat_metadata['conn_pool'])) {
      return false;
    }
    unset($mssql_compat_metadata['conn_pool'][$key]);
    if($mssql_compat_metadata['last_key'] === $key) {
      $mssql_compat_metadata['last_key'] = '';
    }
    return true;
  }

  // See: http://php.net/manual/en/function.mssql-select-db.php
  function mssql_select_db($dbname, $key='') {
    $dbh = _mssql_compat_get_db($key);
    if(!$dbh) return false;

    return $dbh->selectDb($dbname);
  }

  // See: http://php.net/manual/en/function.mssql-query.php
  function mssql_query($sql, $key='', $batch_size=0) {
    // Batch size is accepted for signature compatibility; results are always
    // fully buffered (equivalent to batch_size = 0 in the original extension).
    $dbh = _mssql_compat_get_db($key);
    if(!$dbh) return false;

    // Returns a result identifier, TRUE for statements without a result set,
    // or FALSE on error.
    return $dbh->query($sql);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-array.php
  function mssql_fetch_array($stmt, $format=MSSQL_BOTH) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->fetchRow($stmt, $format);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-assoc.php
  function mssql_fetch_assoc($stmt) {
    return mssql_fetch_array($stmt, MSSQL_ASSOC);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-row.php
  function mssql_fetch_row($stmt) {
    return mssql_fetch_array($stmt, MSSQL_NUM);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-object.php
  function mssql_fetch_object($stmt) {
    $ret = mssql_fetch_array($stmt, MSSQL_ASSOC);
    if($ret===FALSE) {
      return FALSE;
    } else {
      return (object) $ret;
    }
  }

  // See: http://php.net/manual/en/function.mssql-fetch-batch.php
  function mssql_fetch_batch($stmt) {
    // Everything is already buffered, so there is never a further batch.
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;
    return 0;
  }

  // See: http://php.net/manual/en/function.mssql-data-seek.php
  function mssql_data_seek($stmt, $row_number) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->dataSeek($stmt, $row_number);
  }

  // See: http://php.net/manual/en/function.mssql-result.php
  function mssql_result($stmt, $row, $field) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->result($stmt, $row, $field);
  }

  // See: http://php.net/manual/en/function.mssql-next-result.php
  function mssql_next_result($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->nextResult($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-num-rows.php
  function mssql_num_rows($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->getRowCount($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-num-fields.php
  function mssql_num_fields($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->getColumnCount($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-rows-affected.php
  function mssql_rows_affected($key='') {
    // Takes a LINK identifier, unlike mssql_num_rows().
    $dbh = _mssql_compat_get_db($key);
    if(!$dbh) return false;

    return $dbh->getRowsAffected();
  }

  // See: http://php.net/manual/en/function.mssql-fetch-field.php
  function mssql_fetch_field($stmt, $offset=-1) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->fetchFieldMeta($stmt, $offset);
  }

  // See: http://php.net/manual/en/function.mssql-field-length.php
  function mssql_field_length($stmt, $offset=-1) {
    $field = mssql_fetch_field($stmt, $offset);
    return $field ? $field->max_length : false;
  }

  // See: http://php.net/manual/en/function.mssql-field-name.php
  function mssql_field_name($stmt, $offset=-1) {
    $field = mssql_fetch_field($stmt, $offset);
    return $field ? $field->name : false;
  }

  // See: http://php.net/manual/en/function.mssql-field-type.php
  function mssql_field_type($stmt, $offset=-1) {
    $field = mssql_fetch_field($stmt, $offset);
    return $field ? $field->type : false;
  }

  // See: http://php.net/manual/en/function.mssql-field-seek.php
  function mssql_field_seek($stmt, $offset) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->setFieldOffset($stmt, $offset);
  }

  // See: http://php.net/manual/en/function.mssql-free-result.php
  function mssql_free_result($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!$dbh) return false;

    return $dbh->freeStmt($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-free-statement.php
  function mssql_free_statement($stmt) {
    return mssql_free_result($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-get-last-message.php
  function mssql_get_last_message() {
    global $mssql_compat_metadata;
    return $mssql_compat_metadata['last_error'];
  }

  // See: http://php.net/manual/en/function.mssql-guid-string.php
  function mssql_guid_string($binguid, $short_format = false) {
    // No easy equivalent function, so just roll our own
    // Source: http://php.net/manual/en/function.mssql-guid-string.php#119391
    $unpacked = unpack('Va/v2b/n2c/Nd', $binguid);
    return sprintf('%08X-%04X-%04X-%04X-%04X%08X', $unpacked['a'], $unpacked['b1'], $unpacked['b2'], $unpacked['c1'], $unpacked['c2'], $unpacked['d']);
  }

  // See: http://php.net/manual/en/function.mssql-min-error-severity.php
  function mssql_min_error_severity($severity) {
    // Not an actual implementation, included to avoid your program crashing for
    // no apparent reason.
    return;
  }

  // See: http://php.net/manual/en/function.mssql-min-message-severity.php
  function mssql_min_message_severity($severity) {
    // Not an actual implementation, included to avoid your program crashing for
    // no apparent reason.
    return;
  }

  /*****************************************************************************
  ************************ EXTRAS (not in the original API) ********************
  *****************************************************************************/

  /**
   * Register an already-open PDO connection so legacy code can use it through
   * the mssql_* functions. Returns the link identifier and makes it the default.
   */
  function mssql_compat_register_pdo(PDO $pdo, $servername='pdo', $username='') {
    global $mssql_compat_metadata;

    $key = $servername.'-'.$username;
    $mssql_compat_metadata['conn_pool'][$key] = new MSSQLCompat($key, $servername, $username, '', $pdo);
    $mssql_compat_metadata['last_key'] = $key;
    return $key;
  }

  /*****************************************************************************
  ***************************** INTERNAL HELPERS *******************************
  *****************************************************************************/

  // Record an error message and emit the warning the old extension would have.
  function _mssql_compat_error($message) {
    global $mssql_compat_metadata;
    $mssql_compat_metadata['last_error'] = $message;
    trigger_error('mssql_compat: '.$message, E_USER_WARNING);
  }

  // Internal function to get MSSQLCompat instance from statement identifier
  function _mssql_compat_get_db_from_stmt($stmt) {
    if(!is_string($stmt) || strpos($stmt, '#') === FALSE) {
      _mssql_compat_error('supplied argument is not a valid MS SQL-result identifier');
      return false;
    }
    $parts = explode('#', $stmt, 2);
    return _mssql_compat_get_db($parts[0]);
  }

  // Internal function to get MSSQLCompat instance from DB identifier
  function _mssql_compat_get_db($key, $keyonly = false) {
    global $mssql_compat_metadata;

    if($key === '' || $key === null) { $key = $mssql_compat_metadata['last_key']; }
    if($keyonly) { return $key; }

    if(!is_string($key) || !isset($mssql_compat_metadata['conn_pool'][$key])) {
      _mssql_compat_error('supplied argument is not a valid MS SQL-Link identifier');
      return false;
    }
    return $mssql_compat_metadata['conn_pool'][$key];
  }

  /*****************************************************************************
  ********************** PART II - CLASS WITH PDO CALLS ************************
  *****************************************************************************/

  class MSSQLCompat {
    private $dbh           = NULL;
    private $key           = '';
    private $stmt_pool     = array();
    private $stmt_seq      = 0;
    private $rows_affected = 0;

    // Types the original extension reported as "numeric".
    private static $numeric_types = array(
      'int', 'bigint', 'smallint', 'tinyint', 'bit', 'real', 'float',
      'decimal', 'numeric', 'money', 'smallmoney',
    );

    /**
     * $key is the link identifier; $pdo may be supplied to reuse an existing
     * connection (see mssql_compat_register_pdo()).
     */
    public function __construct($key, $servername, $username='', $password='', $pdo = NULL) {
      $this->key = $key;

      if($pdo instanceof PDO) {
        // We rely on exceptions everywhere (PHP < 8 defaults to silent mode).
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $pdo;
        return;
      }

      // If needed, convert "hostname:port" syntax to "host=myhost;port=myport"
      if(strpos($servername, ':')!==FALSE) {
        $servername = str_replace(':', ';port=', $servername);
      }

      $dsn = 'dblib:host='.$servername;
      // Optional extra DSN parameters, e.g. define('MSSQL_COMPAT_DSN_OPTIONS', 'charset=UTF-8;version=7.4');
      if(defined('MSSQL_COMPAT_DSN_OPTIONS') && MSSQL_COMPAT_DSN_OPTIONS !== '') {
        $dsn .= ';'.ltrim(MSSQL_COMPAT_DSN_OPTIONS, ';');
      }

      try {
        // PHP 7 defaults to silent error mode; we rely on exceptions everywhere.
        $this->dbh = new PDO($dsn, $username, $password, array(
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ));
      } catch(PDOException $e) {
        $this->dbh = NULL;
        _mssql_compat_error('Unable to connect to server: '.$servername.' ('.$e->getMessage().')');
      }
    }

    public function isConnected() {
      return $this->dbh instanceof PDO;
    }

    public function selectDb($dbname) {
      try {
        $this->dbh->exec('USE ['.str_replace(']', ']]', $dbname).']');
      } catch(PDOException $e) {
        _mssql_compat_error($e->getMessage());
        return false;
      }
      return true;
    }

    /**
     * Execute a query and buffer every result set it produces.
     * Returns a result identifier, TRUE when no result set was produced, or FALSE.
     */
    public function query($sql) {
      try {
        // query() rather than prepare(): legacy SQL is sent verbatim, so stray
        // '?' or ':name' tokens are never mistaken for PDO placeholders.
        $stmt = $this->dbh->query($sql);
        $this->rows_affected = (int) $stmt->rowCount();

        $sets = array();
        do {
          if($stmt->columnCount() > 0) {
            $sets[] = $this->bufferResultSet($stmt);
          }
        } while($this->nextRowset($stmt));
        $stmt->closeCursor();
      } catch(PDOException $e) {
        _mssql_compat_error($e->getMessage());
        return false;
      }

      if(empty($sets)) {
        // INSERT/UPDATE/DELETE etc: the original extension returned TRUE.
        return true;
      }

      $this->stmt_seq++;
      $this->stmt_pool[$this->stmt_seq] = array(
        'sets'         => $sets,
        'set_cursor'   => 0,
        'row_cursor'   => 0,
        'field_cursor' => 0,
      );

      // A composite key to be able to find it again.
      return $this->key . '#' . $this->stmt_seq;
    }

    public function fetchRow($stmt, $format=PDO::FETCH_BOTH) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      $set = $res['sets'][$res['set_cursor']];
      if(!isset($set['rows'][$res['row_cursor']])) {
        return false;
      }
      $row = $set['rows'][$res['row_cursor']];
      $res['row_cursor']++;

      return $this->formatRow($set, $row, $format);
    }

    public function dataSeek($stmt, $row_number) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      $count = count($res['sets'][$res['set_cursor']]['rows']);
      if($row_number < 0 || $row_number >= $count) {
        _mssql_compat_error('Bad row offset ('.$row_number.')');
        return false;
      }
      $res['row_cursor'] = (int) $row_number;
      return true;
    }

    public function result($stmt, $row, $field) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      $set = $res['sets'][$res['set_cursor']];
      if(!isset($set['rows'][$row])) {
        _mssql_compat_error('Bad row offset ('.$row.')');
        return false;
      }

      if(is_int($field) || ctype_digit((string) $field)) {
        $idx = (int) $field;
      } else {
        // Accept "column" as well as "table.column".
        $name = $field;
        if(strpos($name, '.') !== FALSE) {
          $name = substr($name, strrpos($name, '.')+1);
        }
        $idx = array_search($name, $set['names'], TRUE);
        if($idx === FALSE) {
          _mssql_compat_error('Bad column offset ('.$field.')');
          return false;
        }
      }

      if(!array_key_exists($idx, $set['rows'][$row])) {
        _mssql_compat_error('Bad column offset ('.$field.')');
        return false;
      }
      return $set['rows'][$row][$idx];
    }

    public function nextResult($stmt) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      if(!isset($res['sets'][$res['set_cursor']+1])) {
        return false;
      }
      $res['set_cursor']++;
      $res['row_cursor']   = 0;
      $res['field_cursor'] = 0;
      return true;
    }

    public function fetchFieldMeta($stmt, $offset=-1) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      $set = $res['sets'][$res['set_cursor']];
      if($offset == -1) {
        // Auto incrementing offset
        $offset = $res['field_cursor'];
        $res['field_cursor'] = $offset + 1;
      }
      if(!isset($set['meta'][$offset])) {
        _mssql_compat_error('Bad column offset ('.$offset.')');
        return false;
      }
      return (object) $set['meta'][$offset];
    }

    public function setFieldOffset($stmt, $offset) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;

      $count = count($res['sets'][$res['set_cursor']]['names']);
      if($offset < 0 || $offset >= $count) {
        _mssql_compat_error('Bad column offset ('.$offset.')');
        return false;
      }
      $res['field_cursor'] = (int) $offset;
      return true;
    }

    public function getColumnCount($stmt) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;
      return count($res['sets'][$res['set_cursor']]['names']);
    }

    public function getRowCount($stmt) {
      $res = &$this->getStmtObj($stmt);
      if($res === NULL) return false;
      return count($res['sets'][$res['set_cursor']]['rows']);
    }

    public function getRowsAffected() {
      return $this->rows_affected;
    }

    public function freeStmt($stmt) {
      $index = $this->getStmtIndex($stmt);
      if($index === NULL || !isset($this->stmt_pool[$index])) {
        _mssql_compat_error('supplied argument is not a valid MS SQL-result identifier');
        return false;
      }
      unset($this->stmt_pool[$index]);
      return true;
    }

    /* ------------------------------------------------------------------ */

    // Read the whole current result set of a PDOStatement into memory.
    private function bufferResultSet(PDOStatement $stmt) {
      $names = array();
      $meta  = array();
      $count = $stmt->columnCount();

      // Column metadata must be read before the rows are consumed.
      for($i=0; $i<$count; $i++) {
        $m = $stmt->getColumnMeta($i);
        if($m === false) { $m = array(); }

        $name = isset($m['name']) ? $m['name'] : '';
        if($name === '') { $name = 'computed'.$i; } // as the original extension did

        $type = isset($m['native_type']) ? strtolower($m['native_type']) : 'unknown';
        $len  = isset($m['max_length']) ? $m['max_length'] : (isset($m['len']) ? $m['len'] : -1);

        $names[] = $name;
        $meta[]  = array(
          'name'          => $name,
          'column_source' => isset($m['column_source']) ? $m['column_source'] : (isset($m['table']) ? $m['table'] : ''),
          'max_length'    => (int) $len,
          'numeric'       => in_array($type, self::$numeric_types, TRUE) ? 1 : 0,
          'type'          => $type,
        );
      }

      $rows = $stmt->fetchAll(PDO::FETCH_NUM);
      if($rows === false) { $rows = array(); }

      return array('names' => $names, 'meta' => $meta, 'rows' => $rows);
    }

    // Move to the next result set; FALSE when there is none (or driver can't).
    private function nextRowset(PDOStatement $stmt) {
      try {
        return (bool) @$stmt->nextRowset();
      } catch(PDOException $e) {
        return false;
      }
    }

    private function formatRow(array $set, array $row, $format) {
      if($format == PDO::FETCH_NUM) {
        return $row;
      }
      $assoc = array();
      foreach($set['names'] as $i => $name) {
        $assoc[$name] = $row[$i];
      }
      if($format == PDO::FETCH_ASSOC) {
        return $assoc;
      }
      // FETCH_BOTH (and anything else): numeric keys followed by names.
      return $row + $assoc;
    }

    private function getStmtIndex($stmt) {
      if(!is_string($stmt)) return NULL;
      $parts = explode('#', $stmt, 2);
      if(count($parts) !== 2 || $parts[0] !== $this->key || !ctype_digit($parts[1])) {
        return NULL;
      }
      return (int) $parts[1];
    }

    // Returns the pooled result by reference, or NULL (never false: callers
    // dereference the result) after recording the error.
    private function &getStmtObj($stmt) {
      $null  = NULL;
      $index = $this->getStmtIndex($stmt);
      if($index === NULL || !isset($this->stmt_pool[$index])) {
        _mssql_compat_error('supplied argument is not a valid MS SQL-result identifier');
        return $null;
      }
      return $this->stmt_pool[$index];
    }
  }
}
