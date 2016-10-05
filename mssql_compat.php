<?php

// If mssql_connect() exists, there's no point in doing anything.
if(!function_exists('mssql_connect')) {
  // Unfortunately MSSQL function assume too much, hence the need for pesky globals.
  $mssql_compat_lastkey   = NULL;     // The last MSSQL DB we connected to
  $mssql_compat_conn_pool = array();  // The pool of active MSSQL connections
  $mssql_compat_lasterror = '';       // Last exception message

  // Map MSSQL defines to the PDO ones.
  define('MSSQL_ASSOC', PDO::FETCH_ASSOC);
  define('MSSQL_NUM',   PDO::FETCH_NUM  );
  define('MSSQL_BOTH',  PDO::FETCH_BOTH );

  function mssql_connect($servername='', $username='', $password='', $new_link = false) {
    global $mssql_compat_lastkey;
    global $mssql_compat_conn_pool;

    $key = $servername.'-'.$username;

    // Open a connection if it is not
    if($new_link || !array_key_exists($key, $mssql_compat_conn_pool)) {
      $mssql_compat_conn_pool[$key] = new MSSQLCompat($servername,$username,$password);
    }
    $mssql_compat_lastkey = $key;

    // Use the $key as connection identifier.
    return $key;
  }

  function mssql_select_db($dbname, $key='') {
    $dbh = _mssql_compat_get_db($key);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    // Apparently MSSQL does not like USE statements being prepared / executed.
    return $dbh->exec('USE '.$dbname);
  }

  function mssql_query($sql, $key='', $batch=1000) {
    ///TODO: Currently batch size is ignored

    $dbh = _mssql_compat_get_db($key);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    // Return the statement identifier
    return $dbh->query($sql);
  }

  function mssql_fetch_array($sth, $format=MSSQL_BOTH) {

    $dbh = _mssql_compat_get_db_from_sth($sth);
    if(!is_a($dbh, 'MSSQLCompat')) return false;

    return $dbh->fetchRow($sth, $format);
  }

  // No easy equivalent function, so just roll our own
  function mssql_guid_string($binguid) {
    $unpacked = unpack('Va/v2b/n2c/Nd', $binguid);
    return sprintf('%08X-%04X-%04X-%04X-%04X%08X', $unpacked['a'], $unpacked['b1'], $unpacked['b2'], $unpacked['c1'], $unpacked['c2'], $unpacked['d']);
  }

  function mssql_close($key='') {
    global $mssql_compat_conn_pool;

    $key = _mssql_compat_get_db($key, TRUE);
    if(array_key_exists($key, $mssql_compat_conn_pool)) {
      unset($mssql_compat_conn_pool[$key]);
    }
  }

  function _mssql_compat_get_db_from_sth($sth) {
    $sth = explode('#', $sth);
    $dbh = _mssql_compat_get_db($sth[0]);

    return $dbh;
  }

  function _mssql_compat_get_db($key, $keyonly = false) {
    global $mssql_compat_lastkey;
    global $mssql_compat_conn_pool;

    if($key === '') { $key = $mssql_compat_lastkey; }
    if($keyonly) { return $key; }
    // We choose to ignore the array element not being set, as it is handled
    // further below.
    $dbh = @$mssql_compat_conn_pool[$key];

    // If $dbh is not a PDO object just fail.
    if(!is_a($dbh, 'MSSQLCompat')) {
      return false;
    } else {
      return $dbh;
    }
  }


// Internal Class to actually do the PDO stuff.
  class MSSQLCompat {
    private $dbh      = null;
    private $key      = '';
    private $sth_pool = array();

    public function __construct($servername, $username='', $password='') {
      global $mssql_compat_lasterror;

      $this->key = $servername.'-'.$username;
      try {
        $this->dbh = new PDO ("dblib:host=$servername", $username, $password);
      } catch(PDOException $e) {
        $mssql_compat_lasterror = $e->code.': '.$e->message;
        return false;
      }
    }

    // Not really needed, only used for USE <db> at the moment.
    public function exec($sql) {
      global $mssql_compat_lasterror;

      try {
        $ret = $this->dbh->exec($sql);
      } catch(PDOException $e) {
        $mssql_compat_lasterror = $e->code.': '.$e->message;
        return false;
      }

      return $ret;
    }

    // Prepare and execute a query, create a statement handle.
    public function query($sql, $params=array()) {
      global $mssql_compat_lasterror;

      try {
        $sth = $this->dbh->prepare($sql);
        $sth->execute($params);
      } catch(PDOException $e) {
        $mssql_compat_lasterror = $e->code.': '.$e->message;
        return false;
      }

      // Add the returned statement to the statement handle pool
      $this->sth_pool[] = $sth;

      // And return a composite key to be able to find it again.
      return $this->key . '#' . (count($this->sth_pool)-1);
    }

    public function fetchRow($sth, $format=PDO::FETCH_BOTH) {
      global $mssql_compat_lasterror;
      static $sth_map = array();
      $sth_index      = NULL;

      // Find the statement handle index either from static cache or given $sth.
      if(array_key_exists($sth, $sth_map)) {
        $sth_index = $sth_map[$sth];
      } else {
        $tmp           = explode('#', $sth);
        $sth_index     = intval($tmp[1]);
        $sth_map[$sth] = $sth_index;
      }

      if( !isset($this->sth_pool[$sth_index]) ) { return false; }

      $sth = $this->sth_pool[$sth_index];

      try {
        $ret = $sth->fetch($format);
      } catch(PDOException $e) {
        $mssql_compat_lasterror = $e->code.': '.$e->message;
        return false;
      }

      return $ret;
    }

  }


}