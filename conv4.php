<?php
/**
* Search and Replace on Database.
*
* @author Yuya Tajima
* @link https://github.com/yuya-tajima/mysql_replace_db/blob/master/conv4.php
*/

class Conv4
{
  protected $param = array();
  protected $mysqli;
  protected $tables;
  protected $table_set;
  protected $regexs;
  protected $method;
  protected $check   = true;
  protected $mode    = 'test';
  protected $verbose = false;
  protected $log     = NULL;

  const TABLE_REGEX         = '/.*/';
  const TABLE_EXCLUDE_REGEX = '/^$/';
  const FIELD_REGEX         = '/.*/';
  const FIELD_EXCLUDE_REGEX = '/^$/';

  const OPTION_NAME = 'siteurl';

  public function __construct( $file_name = NULL )
  {
    if ( is_string($file_name) ) {
      $fp = fopen($file_name, 'wb');
      if( is_resource($fp) && flock( $fp, LOCK_EX | LOCK_NB ) ) {
        $this->log = $fp;
      }
    }

    if ( ! $this->log ) {
      $this->log = fopen('php://output', 'w');
    }

    $this->paramInit();

    if ( $this->check ) {
      $from = trim( $this->getWord('replace from ') );
      $to   = trim( $this->getWord('replace to ?') );
      $regexs = array( $from  => $to );
      $this->regexs = $this->getQuotedRegexs( $regexs );
    }

    $db_args = array(
      'server'  => 'localhost',
      'user'    => $this->getWord('db user'),
      'pass'    => $this->getWord('Password', true),
      'db_name' => $this->getWord('db name'),
    );

    $this->param['server']  = $db_args['server'];
    $this->param['user']    = $db_args['user'];
    $this->param['pass']    = $db_args['pass'];
    $this->param['db_name'] = $db_args['db_name'];

    $this->dbInit();
  }

  public function checkBeforeExe()
  {
    if ( ! $this->check ) {
      return;
    }

    echo PHP_EOL;
    echo 'DB name is \'' . $this->param['db_name'] . '\'' .  PHP_EOL;
    echo PHP_EOL;
    echo 'The following replacement words..' . PHP_EOL;
    foreach ( $this->regexs as $k => $v) {
      echo $k . ' => ' . $v . PHP_EOL;
    }
    echo PHP_EOL;
    $final_answer = trim( $this->getWord('Ok ? yes or no') );

    if ( $final_answer !== 'yes') {
      die( 'Stop the execution.' . PHP_EOL );
    }
  }

  public function exe()
  {
    $this->{$this->method}();
    $this->mysqli->close();
  }

  private function paramInit()
  {

    $opt = getopt('sv', array('update', 'test', 'split:'));
    if ( ! $opt) {
      $this->displayHelpMsg();
    }

    if ( isset($opt['s']) ) {
      $this->method = 'searchdomain';
      $this->check = false;
    } else {
      $this->method = 'mainAction';
      if ( isset($opt['split']) && $opt['split'] ) {
        $this->split = (int) trim( $opt['split'] );
      }
      if ( isset($opt['update']) ) {
        $this->mode = 'update';
      }
      if ( isset($opt['v']) ) {
        $this->verbose = true;
      }
    }
  }

  private function displayHelpMsg()
  {
echo <<< EOF

    please add options when you execute this program.

    -s          search domain. ( When use the WordPress DB )

    --test      replace test.

    --update    execute replacement to the database.


EOF;
    die();
  }

  public function searchDomain()
  {
    foreach($this->tables as $table){
      if( preg_match('/\A(?:.*)options\z/', $table, $m) ){
        $option_name = self::OPTION_NAME;
        $result = $this->mysqli->query("select option_value from {$table} where option_name = '{$option_name}'");
        $row = $result->fetch_assoc();
        echo $m[0] . ' current domain is ' . reset($row) . PHP_EOL;
      }
    }
  }

  protected function getQuotedRegexs($regexs, $delimiter = '/', $modifiers =  'u' )
  {
    $_regexes = array();

    foreach($regexs as $key => $val){
      $_regexes[$delimiter . preg_quote($key, $delimiter) . $delimiter . $modifiers ] = $val;
    }

    return $_regexes;
  }

  protected function dbInit()
  {
    $this->mysqli = $this->dbConnect();
    $this->tables = $this->getTables();
    $this->table_set = $this->getTableSet();
  }

  protected function dbConnect()
  {
    $mysqli = @ new mysqli(
      $this->param['server'],
      $this->param['user'],
      $this->param['pass'],
      $this->param['db_name']
    );

    if( $mysqli->connect_errno ){
      die( 'Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error . PHP_EOL);
    }else{
      return $mysqli;
    }
  }

  protected function getTables()
  {
    $result = $this->mysqli->query('show tables');

    $tables = array();

    while ($row = $result->fetch_assoc()) {
      $tname = array_shift($row);
      if (preg_match(self::TABLE_REGEX, $tname) && !preg_match(self::TABLE_EXCLUDE_REGEX, $tname)) {
        $tables[] = $tname;
      }
    }

    //$this->stdOut( 'Target tables are ...' . PHP_EOL . implode( PHP_EOL, $tables ) );

    return $tables;
  }

  protected function getTableSet()
  {
    $table_set = array();

    foreach ( $this->tables as $t) {
      $tmp = array();
      $tmp['table'] = $t;
      $result = $this->mysqli->query("DESCRIBE `$t`");
      while( $row = $result->fetch_assoc() ) {
        if ( $row['Key'] === 'PRI' ) {
          $tmp['id'][] = $row['Field'];
        }
        $tmp['fs'][] = $row['Field'];
      }
      if ( ! isset($tmp['id'] ) ) {
        $this->stdOut( $t . ' primary key does not exist' );
        continue;
      }
      $table_set[] = $tmp;
    }

    return $table_set;
  }

  private function convertToIntegerID ( $row, $id )
  {
    $new_row = array();
    foreach ( $row as $k => $v ) {
      if ( in_array( $k, $id, true ) ) {
        $v = (int) $v;
      }
      $new_row[$k] = $v;
    }

    return $new_row;
  }

  public function mainAction()
  {
    foreach ( $this->table_set as $meta ) {
      $t  = $meta['table'];
      $id = $meta['id'];
      $fs = $meta['fs'];

      $off_set = $this->split ? 0 : false;
      $sql     = $this->make_sql_select_all_data($t, $id, $fs, $off_set );
      $result  = $this->mysqli->query($sql);
      $this->stdOut( $t . ' table fetch num :' . $result->num_rows );
      $change_num  = 0;
      while( $row = $result->fetch_assoc() ) {
        $row   = $this->convertToIntegerID( $row, $id );
        $after = $this->check( $row, $id );
        if ( $after !== $row ) {
          if ( $this->verbose ) {
            $diff = array_diff($after, $row);
            var_dump($diff);
          }
          ++$change_num;
          if ( $this->mode === 'update' ) {
            $after = $this->make_diff_field($row, $after, $id);
            $sql = $this->make_sql_for_update($t, $id, $after);
            //$up = $this->mysqli->query($sql);
            if ( $up === false ) {
              $this->stdOut( sprintf( "Errormessage: %s\n", $this->mysqli->error ) );
              exit;
            }
          }
        }
      }
      $this->stdOut( 'replace counts ' . $change_num );
    }
  }

  protected function make_sql_select_all_data($t, $id, $fs, &$off_set)
  {

    $fs = array_diff($fs, $id);

    foreach ($fs as $key => $val) {
      $fs[$key] = '`' . $val . '`';
    }

    $id = implode(',', $id);
    $fs = implode(',', $fs);

    $limit = '';
    if ( ( $off_set !== false ) && $this->split ) {
      $row_count = $off_set + $this->split;
      $limit = "LIMIT $off_set, $row_count";
    }

    $sql = "SELECT $id, $fs FROM `$t` $limit ";

    if ( ( $off_set !== false ) && $this->split ) {
      $off_set += $this->split;
    }
    return $sql;
  }

  protected function make_diff_field($row, $after, $id)
  {
    $new = array();
    foreach ( $row as $key => $val ) {
      if ( in_array( $key, $id, true ) ) {
        continue;
      }
      if ($val === $after[$key]) {
        unset($after[$key]);
      }
    }

    return $after;
  }

  protected function make_sql_for_update($t, $id, $after)
  {

    $where_arr = array();
    foreach ($id as $v ) {
       $where_arr[] = ' `' . $v . '` = ' . $after[$v];
    }

    $where = implode( 'AND', $where_arr );

    $this->escape_sql($after);
    $update = array();
    foreach ($after as $key => $val) {
      if ( is_string ($val ) ) {
        $val = "'$val'";
      }
      $update[] = "`$key` = $val";
    }
    $update = implode(',' , $update);
    $sql = "UPDATE `$t` SET $update WHERE $where";

    return $sql;
  }

  private function excludePkFromColumn ( $id, $column )
  {
    $id     = array_flip($id);
    $column = array_diff_key($column, $id);

    return $column;
  }

  protected function escape_sql(&$arr)
  {
    if ( is_array($arr) ) {
      foreach ($arr as $key => &$val) {
        $this->escape_sql($val);
      }
    } else {
      if ( is_string ($arr) ) {
        $arr = $this->mysqli->real_escape_string($arr);
      }
    }
  }

  // from WordPress function.
  protected function is_serialized( $data, $strict = true )
  {
    // if it isn't a string, it isn't serialized.
    if ( ! is_string( $data ) ) {
      return false;
    }
    $data = trim( $data );
    if ( 'N;' == $data ) {
      return true;
    }
    if ( strlen( $data ) < 4 ) {
      return false;
    }
    if ( ':' !== $data[1] ) {
      return false;
    }
    if ( $strict ) {
      $lastc = substr( $data, -1 );
      if ( ';' !== $lastc && '}' !== $lastc ) {
        return false;
      }
    } else {
      $semicolon = strpos( $data, ';' );
      $brace     = strpos( $data, '}' );
      // Either ; or } must exist.
      if ( false === $semicolon && false === $brace )
        return false;
      // But neither must be in the first X characters.
      if ( false !== $semicolon && $semicolon < 3 )
        return false;
      if ( false !== $brace && $brace < 4 )
        return false;
    }
    $token = $data[0];
    switch ( $token ) {
      case 's' :
        if ( $strict ) {
          if ( '"' !== substr( $data, -2, 1 ) ) {
            return false;
          }
        } elseif ( false === strpos( $data, '"' ) ) {
          return false;
        }
        // or else fall through
      case 'a' :
      case 'O' :
        return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
      case 'b' :
      case 'i' :
      case 'd' :
        $end = $strict ? '$' : '';
        return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
    }
    return false;
  }

  protected function check($arr, $id)
  {
    $new = $arr;

    $arr = $this->excludePkFromColumn($id, $arr);
    foreach ($arr as $key => $val) {

      if ( $this->is_serialized( $val ) ) {
        $test = unserialize($val);
        if ($test === false) {
          echo "Fail to unserialize $key => $val.\n";
          $new[$key] = $val;
          continue;
        }

        if ( is_array($test) || is_object($test) ) {
          array_walk_recursive($test, array($this, 'test') );
        } else {
          $this->test($test, false);
        }
        $test = serialize($test);
      } else {
        $test = $val;
        $this->test($test, false);
      }
      $new[$key] = $test;
    }
    return $new;
  }

  protected function test(&$obj, $key)
  {

    $type = gettype($obj);

    if ( $type == 'object' || $type == 'array' ) {
      array_walk_recursive( $obj, array( $this, 'test'));
      return;
    }

    foreach ($this->regexs as $regex => $replace) {
      if ( preg_match($regex, $obj) ) {
        $obj = preg_replace($regex, $replace, $obj);
      }
    }
  }
  private function getWord ( $title, $hidden = false )
  {
    fwrite( STDOUT, $title . ': ');
    if( $hidden ) system( 'stty -echo' );
    @flock( STDIN, LOCK_EX );
    $input = fgets(STDIN );
    if( $hidden ) system( 'stty echo' );
    @flock( STDIN, LOCK_UN );
    if( $hidden ) fwrite( STDOUT, "\n" );

    return trim( $input );
  }

  private function stdOut ( $msg  )
  {
    fwrite($this->log, $msg . PHP_EOL );
  }
}

$db = new Conv4();
$db->checkBeforeExe();
$db->exe();
