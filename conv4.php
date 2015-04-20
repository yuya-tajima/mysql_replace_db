<?php

/*
  key   is replace form.
  value is replace to.

*/

$regexs = array(
  'example.com'  => 'test.example.com',
);

class Conv4
{
  protected $param = array();
  protected $mysqli;
  protected $tables;
  protected $tset;
  protected $regexs;
  protected $method;
  protected $no_check = false;
  protected $mode     = 'test';

  const TABLE_REGEX         = '/.*/';
  const TABLE_EXCLUDE_REGEX = '/^$/';
  const FIELD_REGEX         = '/.*/';
  const FIELD_EXCLUDE_REGEX = '/^$/';

  const OPTION_NAME = 'siteurl';

  public function __construct($db_args, $regexs)
  {

    $this->paramInit();

    $this->param['server']  = $db_args['server'];
    $this->param['user']    = $db_args['user'];
    $this->param['pass']    = $db_args['pass'];
    $this->param['db_name'] = $db_args['db_name'];

    $this->regexs = $this->getQuotedRegexs($regexs);

    $this->dbInit();

  }

  public function checkBeforeExe()
  {
    if ( $this->no_check ) {
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
    $final_answer = getWord('Ok ? yes or no');

    if ( $final_answer !== 'yes') {
      die( 'Stop the execution.' . PHP_EOL );
    }
  }

  public function exe()
  {
    $this->{$this->method}();
  }

  private function paramInit()
  {
    $argv = $GLOBALS['argv'];

    if(! isset($argv[1])){
      $this->displayHelpMsg();
    }

    if( $opt = $argv[1] ){
      if($opt === '-s'){
        $this->method = 'searchdomain';
        $this->no_check = true;
      }elseif($opt === '--test' || $opt === '--update' ){
        $this->method = 'mainAction';
        if($opt === '--update'){
          $this->mode = 'update';
        }
      }else{
        $this->displayHelpMsg();
      }
    }else{
      $this->displayHelpMsg();
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

  protected function getQuotedRegexs($regexs, $delimiter = '/')
  {
    $_regexes = array();

    foreach($regexs as $key => $val){
      $_regexes[$delimiter . preg_quote($key, $delimiter) . $delimiter] = $val;
    }

    return $_regexes;
  }

  protected function dbInit()
  {
    $this->mysqli = $this->dbConnect();
    $this->tables = $this->getTables();
    $this->tset   = $this->getTset();
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

    return $tables;
  }

  protected function getTset()
  {
    $tset = array();

    foreach ($this->tables as $t) {
      $sql = "
        SELECT *
        FROM `$t`
        LIMIT 1
        ";
      $result = $this->mysqli->query($sql);
      $row = $result->fetch_assoc();
      if (is_array($row)) {
        $fields = array_keys($row);
        $id = array_shift($fields);
        $tmp = array();
        foreach ($fields as $f) {
          if (preg_match(self::FIELD_REGEX, $f) && !preg_match(self::FIELD_EXCLUDE_REGEX, $f)) {
            $tmp['table'] = $t;
            $tmp['id'] = $id;
            $tmp['fs'][] = $f;
          }
        }
        if (!empty($tmp)) {
          $tset[] = $tmp;
        }
      }
    }
    return $tset;
  }

  public function mainAction()
  {
    $i = 0;
    $all = 0;

    foreach ($this->tset as $meta) {
      $t = $meta['table'];
      $id = $meta['id'];
      $fs = $meta['fs'];

      $sql = $this->make_sql_select_all_data($t, $id, $fs);
      $result = $this->mysqli->query($sql);
      while($row = $result->fetch_assoc()) {
        $after = $this->check($row);
        if ($after != $row) {
          $i++;
          $after = $this->make_diff_field($row, $after);
          $sql = $this->make_sql_for_update($t, $id, $after);
          if ($this->mode === 'update') {
            $up = $this->mysqli->query($sql);
            if ($up == false) {
              echo "update error.\n";
              exit;
            }
          }
        }
        $all++;
      }
    }
    var_dump($i, $all);
  }

  protected function make_sql_select_all_data($t, $id, $fs)
  {
    foreach ($fs as $key => $val) {
      $fs[$key] = '`' . $val . '`';
    }
    $fs = implode(',', $fs);

    $sql = "
      SELECT  `$id`,
      $fs
      FROM  `$t`
      ";
    return $sql;
  }

  protected function make_diff_field($row, $after)
  {
    $new = array();
    $first = true;
    foreach ($row as $key => $val) {
      if ($first) {
        $first = false;
        continue;
      }
      if ($val == $after[$key]) {
        unset($after[$key]);
      }
    }
    return $after;
  }

  protected function make_sql_for_update($t, $idname, $after) 
  {
    $id_value = array_shift($after);
    $this->escape_sql($after);
    $update = array();
    foreach ($after as $key => $val) {
      $update[] = "\t`$key` = '$val'";
    }
    $update = implode(",\n" , $update);
    $sql = "
      UPDATE  `$t` SET
      $update
      WHERE `$idname` = '$id_value'
      ";
    return $sql;
  }

  protected function escape_sql(&$arr)
  {
    if (is_array($arr)) {
      foreach ($arr as $key => $val) {
        $arr[$key] = $this->mysqli->real_escape_string($val);
      }
    } else {
      $arr = $this->mysqli->real_escape_string($arr);
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

  protected function check($arr)
  {
    $new = $arr;
    $id = array_shift($arr);
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

    if ($key) {
      foreach ($this->regexs as $regex => $replace){
        if (preg_match($regex, $key)) {
          echo "in key => $key\n";
        }
      }
    }

    if ($type == 'object' || $type == 'array') {
      array_walk_recursive( $obj, array( $this, 'test'));
      return;
    }

    foreach ($this->regexs as $regex => $replace) {
      if ( preg_match($regex, $obj) ) {
        $obj = preg_replace($regex, $replace, $obj);
      }
    }
  }
}

$db_user   = getWord('db user');
$pass_word = getWord('Password', true);
$db_name   = getWord('db name');

$db_args = array(
  'server'  => 'localhost',
  'user'    => $db_user,
  'pass'    => $pass_word,
  'db_name' => $db_name
);

$db = new Conv4( $db_args, $regexs );
$db->checkBeforeExe();
$db->exe();

function getWord( $title, $hidden = false ) {

  fwrite( STDOUT, $title . ': ');
  if( $hidden ) system( 'stty -echo' );
  @flock( STDIN, LOCK_EX );
  $input = fgets(STDIN );
  if( $hidden ) system( 'stty echo' );
  @flock( STDIN, LOCK_UN );
  if( $hidden ) fwrite( STDOUT, "\n" );

  return trim( $input );
}
