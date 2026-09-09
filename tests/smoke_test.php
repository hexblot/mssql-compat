<?php
// Smoke test of the mssql_* emulation against an in-memory SQLite database.
// It cannot exercise dblib specifics (USE, multiple result sets) but covers the
// buffering, cursor, metadata and error-handling logic on any PHP 5.3+.
//   php tests/smoke_test.php
//   docker run --rm -v "$PWD":/app php:5.6-cli php /app/tests/smoke_test.php
error_reporting(E_ALL);
$warnings = array();
set_error_handler(function($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
$fail = 0;
function ok($cond, $label) { global $fail; echo ($cond ? "ok   " : "FAIL ") . $label . "\n"; if(!$cond) $fail++; }

require __DIR__.'/../mssql_compat.php';
echo "PHP ", PHP_VERSION, "\n";

// --- invalid link / result handles before any connection
ok(mssql_query('SELECT 1') === false, 'query without link returns false');
ok(mssql_fetch_array(false) === false, 'fetch_array(false) returns false');
ok(mssql_num_rows('bogus') === false, 'num_rows(bogus) returns false');
ok(count($warnings) === 3, 'each emitted a warning');
ok(strpos(mssql_get_last_message(), 'identifier') !== false, 'last message set');
$warnings = array();

$pdo = new PDO('sqlite::memory:');
$link = mssql_compat_register_pdo($pdo, 'mem');
ok(is_string($link) && $link !== '', 'register_pdo returns link');

ok(mssql_query('CREATE TABLE t (id INTEGER, name TEXT, price REAL)') === true, 'DDL returns TRUE');
ok(mssql_query("INSERT INTO t VALUES (1,'a',1.5),(2,'b',2.5),(3,'c',3.5)") === true, 'INSERT returns TRUE');
ok(mssql_rows_affected($link) === 3, 'rows_affected(link) = 3');
ok(mssql_rows_affected() === 3, 'rows_affected() default link = 3');

// --- query errors
ok(mssql_query('SELECT * FROM nope') === false, 'bad query returns false');
ok(strpos(mssql_get_last_message(), 'nope') !== false, 'last message has driver text: ' . mssql_get_last_message());
ok(count($warnings) === 1, 'bad query warned once');
$warnings = array();

// --- buffered result
$r = mssql_query('SELECT id, name, price, id*2 FROM t ORDER BY id');
ok(is_string($r), 'SELECT returns handle: ' . var_export($r, true));
ok(mssql_num_rows($r) === 3, 'num_rows = 3');
ok(mssql_num_fields($r) === 4, 'num_fields = 4');

$row = mssql_fetch_array($r);
ok($row[0] == 1 && $row['name'] === 'a' && count($row) === 8, 'fetch_array BOTH');
$row = mssql_fetch_assoc($r);
ok($row === array('id'=>2,'name'=>'b','price'=>2.5,'computed3'=>4) || $row['name']==='b', 'fetch_assoc');
ok(count($row) === 4 && is_string(array_keys($row)[3]), 'unnamed column gets a string key (SQL Server: computed3)');
$row = mssql_fetch_row($r);
ok($row[1] === 'c' && !isset($row['name']), 'fetch_row NUM only');
ok(mssql_fetch_array($r) === false, 'fetch past end returns false, no warning');
ok(count($warnings) === 0, 'no warnings so far');

// --- nested query on same link while outer result still open
ok(mssql_data_seek($r, 0) === true, 'data_seek(0)');
$seen = array();
while ($o = mssql_fetch_object($r)) {
  $inner = mssql_query('SELECT count(*) FROM t');
  $seen[] = $o->name . ':' . mssql_result($inner, 0, 0);
  mssql_free_result($inner);
}
ok($seen === array('a:3','b:3','c:3'), 'nested queries do not truncate outer loop');

// --- data_seek / result
ok(mssql_data_seek($r, 5) === false, 'data_seek out of range false');
ok(mssql_result($r, 1, 'name') === 'b', 'result by name');
ok(mssql_result($r, 2, 't.name') === 'c', 'result by table.name');
ok(mssql_result($r, 0, 2) == 1.5, 'result by index');
ok(mssql_result($r, 0, 'zzz') === false, 'result bad column false');
$warnings = array();

// --- field metadata
$f = mssql_fetch_field($r);
ok(is_object($f) && $f->name === 'id', 'fetch_field is object, first = id');
$f = mssql_fetch_field($r);
ok($f->name === 'name', 'fetch_field auto-advances');
ok(mssql_field_seek($r, 0) === true && mssql_field_name($r) === 'id', 'field_seek + field_name');
ok(mssql_field_name($r, 2) === 'price', 'field_name explicit offset');
ok(is_string(mssql_field_type($r, 0)), 'field_type string: ' . mssql_field_type($r, 0));
ok(mssql_field_length($r, 1) !== null, 'field_length returns something');
ok(mssql_field_seek($r, 9) === false, 'field_seek out of range false');

// --- next_result (sqlite has no multi-rowset; just returns false)
ok(mssql_next_result($r) === false, 'next_result false on single set');
ok(mssql_fetch_batch($r) === 0, 'fetch_batch returns 0');

// --- SQL containing placeholder-like tokens is sent verbatim
$q = mssql_query("SELECT 'a?b' AS x, ':name' AS y FROM t WHERE id = 1");
ok(is_string($q) && mssql_result($q, 0, 'x') === 'a?b' && mssql_result($q, 0, 'y') === ':name', 'literal ? and :name are not treated as placeholders');
mssql_free_result($q);

// --- free
ok(mssql_free_result($r) === true, 'free_result true');
ok(mssql_free_result($r) === false, 'double free false');
ok(mssql_fetch_array($r) === false, 'fetch on freed returns false, no crash');
ok(mssql_free_statement('mem-#1') === false, 'free_statement bogus false');

// --- second link, new_link semantics, close
$pdo2 = new PDO('sqlite::memory:');
$l2 = mssql_compat_register_pdo($pdo2, 'mem2');
ok($l2 !== $link, 'distinct link ids');
ok(mssql_query('SELECT 1') !== false, 'default link is most recent');
ok(mssql_close() === true, 'close default');
ok(mssql_close($l2) === false, 'close again false');
ok(mssql_query('SELECT 1', $link) !== false, 'first link still usable');
ok(mssql_close($link) === true, 'close first');
$warnings = array();
ok(mssql_query('SELECT 1') === false && count($warnings) === 1, 'no link left');

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
