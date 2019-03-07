<?php

// If mssql_connect() exists, there's no point in doing anything.
if(!function_exists('mssql_connect')) {
  // Map MSSQL defines to the PDO ones.
  define('MSSQL_ASSOC', PDO::FETCH_ASSOC);
  define('MSSQL_NUM',   PDO::FETCH_NUM  );
  define('MSSQL_BOTH',  PDO::FETCH_BOTH );

  // Unfortunately MSSQL function assume too much, hence the need for pesky globals.
  global $mssql_compat_metadata;
  $mssql_compat_metadata  = array(
    'last_key'   => '',
    'last_error' => '',
    'conn_pool'  => array(),
  );

  /*****************************************************************************
  ***************** PART I - MSSQL API EQUIVALENT FUNCTIONS ********************
  *****************************************************************************/

  // See: http://php.net/manual/en/function.mssql-close.php
  function mssql_close($key='') {
    global $mssql_compat_metadata;

    $key = _mssql_compat_get_db($key, TRUE);
    if(array_key_exists($key, $mssql_compat_metadata['conn_pool'])) {
      unset($mssql_compat_metadata['conn_pool'][$key]);
    }
  }

  // See: http://php.net/manual/en/function.mssql-connect.php
  function mssql_connect($servername='', $username='', $password='', $new_link = false) {
    global $mssql_compat_metadata;

    $key = $servername.'-'.$username;

    // Open a connection if it is not
    if($new_link || !array_key_exists($key, $mssql_compat_metadata['conn_pool'])) {
      $mssql_compat_metadata['conn_pool'][$key] = new MSSQLCompat($servername, $username, $password);
    }
    $mssql_compat_metadata['last_key'] = $key;

    // Use the $key as connection identifier.
    return $key;
  }

  // See: http://php.net/manual/en/function.mssql-fetch-array.php
  function mssql_fetch_array($stmt, $format=MSSQL_BOTH) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    return $dbh->fetchRow($stmt, $format);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-assoc.php
  function mssql_fetch_assoc($stmt) {
    return mssql_fetch_array($stmt, MSSQL_ASSOC);
  }

  // See: http://php.net/manual/en/function.mssql-fetch-field.php
  function mssql_fetch_field($stmt, $offset=-1) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    return $dbh->fetchFieldMeta($stmt, $offset);
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

  // See: http://php.net/manual/en/function.mssql-fetch-row.php
  function mssql_fetch_row($stmt) {
    return mssql_fetch_array($stmt, MSSQL_NUM);
  }

  // See: http://php.net/manual/en/function.mssql-field-length.php
  function mssql_field_length($stmt, $offset=-1) {
    $field = mssql_fetch_field($stmt, $offset);
    return $field['len'];
  }

  // See: http://php.net/manual/en/function.mssql-field-name.php
  function mssql_field_name($stmt, $offset=-1) {
    $field = mssql_fetch_field($stmt, $offset);
    return $field['name'];
  }

  // See: http://php.net/manual/en/function.mssql-field-seek.php
  function mssql_field_seek($stmt, $offset) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    return $dbh->setOffset($stmt, $offset);
  }

  // See: http://php.net/manual/en/function.mssql-free-result.php
  function mssql_free_result($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    return $dbh->freeStmt($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-get-last-message.php
  function mssql_get_last_message() {
    global $mssql_compat_metadata;
    return $mssql_compat_metadata['last_error'];
  }

  // See: http://php.net/manual/en/function.mssql-guid-string.php
  function mssql_guid_string($binguid) {
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

  // See: http://php.net/manual/en/function.mssql-num-fields.php
  function mssql_num_fields($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    return $dbh->getColumnCount($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-num-rows.php
  function mssql_num_rows($stmt) {
    $dbh = _mssql_compat_get_db_from_stmt($stmt);
    return $dbh->getRowCount($stmt);
  }

  // See: http://php.net/manual/en/function.mssql-pconnect.php
  function mssql_pconnect($servername='', $username='', $password='', $new_link = false) {
    return mssql_connect($servername, $username, $password, $new_link);
  }

  // See: http://php.net/manual/en/function.mssql-query.php
  function mssql_query($sql, $key='', $batch=1000) {
    ///TASK: Currently batch size is ignored

    $dbh = _mssql_compat_get_db($key);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    // Return the statement identifier
    return $dbh->query($sql);
  }


  // See: http://php.net/manual/en/function.mssql-select-db.php
  function mssql_select_db($dbname, $key='') {
    $dbh = _mssql_compat_get_db($key);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    // Apparently MSSQL does not like USE statements being prepared / executed.
    return $dbh->exec('USE '.$dbname);
  }

  // See: http://php.net/manual/en/function.mssql-rows-affected.php
  function mssql_rows_affected($stmt) {
    // PDO uses the same function to return SELECT row count as well as others
    return mssql_num_rows($stmt);
  }


  // Internal function to get MSSQLCompat instance from statement identifier
  function _mssql_compat_get_db_from_stmt($stmt) {
    $stmt = explode('#', $stmt);
    $dbh = _mssql_compat_get_db($stmt[0]);

    return $dbh;
  }

  // Internal function to get MSSQLCompat instance from DB identifier
  function _mssql_compat_get_db($key, $keyonly = false) {
    global $mssql_compat_metadata;

    if($key === '') { $key = $mssql_compat_metadata['last_key']; }
    if($keyonly) { return $key; }
    // We choose to ignore the array element not being set, as it is handled
    // further below.
    $dbh = @$mssql_compat_metadata['conn_pool'][$key];

    // If $dbh is not a PDO object just fail.
    if(!is_a($dbh, 'MSSQLCompat')) {
      return false;
    } else {
      return $dbh;
    }
  }

  /*****************************************************************************
  ********************** PART II - CLASS WITH PDO CALLS ************************
  *****************************************************************************/

  class MSSQLCompat {
    private $dbh          = NULL;
    private $key          = '';
    private $stmt_pool    = array();

    // Constructor
    public function __construct($servername, $username='', $password='') {
      global $mssql_compat_metadata;

      $this->key = $servername.'-'.$username;

      // If needed, convert "hostname:port" syntax to "host=myhost;port=myport"
      if(strpos($servername, ':')!==FALSE) {
        $servername = str_replace(':', ';port=', $servername);
      }

      try {
        $this->dbh = new PDO ("dblib:host=$servername", $username, $password);
      } catch(PDOException $e) {
        $mssql_compat_metadata['last_error']  = $e->code.': '.$e->message;
        return false;
      }
    }

    // Not really needed, only used for USE <db> at the moment.
    public function exec($sql) {
      global $mssql_compat_metadata;

      try {
        $ret = $this->dbh->exec($sql);
      } catch(PDOException $e) {
        $mssql_compat_metadata['last_error']  = $e->code.': '.$e->message;
        return false;
      }

      return $ret;
    }

    // Prepare and execute a query, create a statement handle.
    public function query($sql) {
      global $mssql_compat_metadata;

      try {
        $stmt = $this->dbh->prepare($sql,array(PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL));
        $stmt->execute();
      } catch(PDOException $e) {
        $mssql_compat_metadata['last_error']  = $e->code.': '.$e->message;
        return false;
      }

      // Add the returned statement to the statement handle pool
      $this->stmt_pool[] = array(
        'pdo'          => $stmt,
        'field_cursor' => 0,
        'row_cursor'   => 0,
      );

      // And return a composite key to be able to find it again.
      return $this->key . '#' . (count($this->stmt_pool)-1);
    }

    public function fetchRow($stmt, $format=PDO::FETCH_BOTH) {
      global $mssql_compat_metadata;

      $stmt = &$this->getStmtObj($stmt);

      try {
        $ret = $stmt['pdo']->fetch($format);
      } catch(PDOException $e) {
        $mssql_compat_metadata['last_error']  = $e->code.': '.$e->message;
        return false;
      }

      return $ret;
    }

    public function fetchFieldMeta($stmt, $offset=-1) {
      $stmt = &$this->getStmtObj($stmt);

      if($offset==-1) {
        // Auto incrementing offset, do some housekeeping and roll back
        $offset = $stmt['field_cursor'];
        $stmt['field_cursor']++;
      }

      return $stmt['pdo']->getColumnMeta($offset);
    }

    public function getColumnCount($stmt) {
      $stmt = &$this->getStmtObj($stmt);
      return $stmt['pdo']->columnCount();
    }

    public function getRowCount($stmt) {
      $stmt = &$this->getStmtObj($stmt);
      return $stmt['pdo']->rowCount();
    }

    public function setOffset($stmt, $offset) {
      $stmt = &$this->getStmtObj($stmt);
      if($offset>=0 && $offset<$stmt['pdo']->columnCount()) {
        $stmt['field_cursor'] = $offset;
        return TRUE;
      } else {
        return FALSE;
      }
    }

    public function freeStmt($stmt) {
      $stmt_index = &$this->getStmtObj($stmt, TRUE);
      unset($this->stmt_pool[$stmt_index]);
      return TRUE;
    }

    private function &getStmtObj($stmt, $indexOnly = FALSE) {
      static $stmt_map = array();
      $stmt_index      = NULL;

      // Find the statement handle index either from static cache or given $stmt.
      if(array_key_exists($stmt, $stmt_map)) {
        $stmt_index      = $stmt_map[$stmt];
      } else {
        $tmp             = explode('#', $stmt);
        $stmt_index      = intval($tmp[1]);
        $stmt_map[$stmt] = $stmt_index;
      }

      if( !isset($this->stmt_pool[$stmt_index]) ) {
        return false;
      } else {
        if($indexOnly) {
          return $stmt_index;
        } else {
          return $this->stmt_pool[$stmt_index];
        }
      }
    }
  }
}
