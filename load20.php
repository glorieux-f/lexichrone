<?php
mb_internal_encoding ("UTF-8");
// Lexichrone::peek();
// Lexichrone::count();
// Lexichrone::lexique();
Lexichrone::connect("lexichrone.sqlite");
// Lexichrone::walk();
Lexichrone::ranks("word");

class Lexichrone {
  /** lien à la base de donnée */
  static $pdo;
  /** Nombre de mots par an */
  static $years = array();
  /** dicolecte */
  static $dic;
  /** translittération supprimant les accents */
  static $accents;
  /** mots sans accents */
  static $dicascii;

  static public function peek()
  {
    $glob = dirname(__FILE__).'/data2020/*.gz';
    foreach(glob($glob) as $srcfile) {
      $handle = fopen("compress.zlib://".$srcfile, "r");
      echo fread($handle, 8192);
      fclose($handle);
      break;
    }
  }



  
  static public function count()
  {
    $glob = dirname(__FILE__).'/data2020/*.gz';
    $dic = array();
    foreach(glob($glob) as $srcFile) {
      $start = microtime(true);
      fwrite(STDERR, $srcFile);
      $handle = fopen("compress.zlib://".$srcFile, "r");
      while (($line = fgets($handle)) !== FALSE) {
        $cells = explode("\t", $line);
        $form = $cells[0];
        $count = 0;
        for ($i = 1, $max = count($cells); $i < $max; $i++) {
          list($year, $occs, $books) = explode(",", $cells[$i]);
          $count += $occs;
        }
        if (isset($dic[$form])) {
          echo $form."\n";
          $dic[$form] += $count;
        }
        else {
          $dic[$form] = $count;
        }
      }
      fclose($handle);
      fwrite(STDERR, " ".(microtime(true) - $start)."\n");
    }
    echo "wc=".(count($dic)); // 2012 : 9 663 037, 
    arsort($dic);
    echo "form,count\n";
    foreach ($dic as $form => $count) {
      echo "$form,$count\n";
    }
  }


  /**
   * Toujour utile ?
   */
  static public function years()
  {
    self::$pdo->beginTransaction();
    $insert = self::$pdo->prepare(
      "INSERT INTO year (id, count) VALUES (?, ?)"
    );
    $id = $year = 0;
    $insert->bindParam(1, $id, PDO::PARAM_INT);
    $insert->bindParam(2, $count, PDO::PARAM_INT);
    $handle = fopen("data/googlebooks-fre-all-totalcounts-20120701.txt", "r");
    fgets($handle, 1000); // skip first line
    while (($line = fgets($handle, 4096)) !== FALSE) {
      list($id, $count) = explode(",", $line);
      // $insert->execute();
      $years[$id] = $count;
    }
    self::$pdo->commit();
    self::$years = $years;
  }

  /**
   * Charger lexique en mémoire
   */
  static public function lexique()
  {
    self::$accents = include(dirname(__FILE__).'/lib/accents.php');
    //fgetcsv: 3,053 s.
    // ne pas utiliser scanf, confond les espaces et les tabulations, La devient Rochelle
    // scanf:  0.238 s.
    // fgets + explode: 0,325 s.
    $start = microtime(true);
    // echo "mem=",memory_get_usage(),"\n";
    $i = 0;
    $dic = array();
    // charger d'abord lexique Alix, plus fiable sur les hautes fréquences
    $id = $fid = $flexion = $lemma = $line = null;
    $handle = fopen("datalecte/word.csv", "r");
    fgets($handle);// passer a première ligne
    while ($line = fgets($handle)) {
      list($flexion, $cat, $lemma) = explode(";", $line);
      $flexion = str_replace(array("œ", "Œ", "æ", "Æ"), array("oe", "Oe", "ae", "Ae"), $flexion);
      if (!$lemma) $lemma = $flexion;
      $lemma = str_replace(array("œ", "Œ", "æ", "Æ"), array("oe", "Oe", "ae", "Ae"), $lemma);
      if (isset($dic[$flexion])) continue;
      $dic[$flexion] = $lemma;
      
      $ascii = strtr($flexion, self::$accents);
      if (!isset(self::$dicascii[$ascii])) self::$dicascii[$ascii] = $flexion;
    }
    fclose($handle);


    $handle = fopen("datalecte/lexique.txt", "r");
    while ($line = fgets($handle)) {
      list($id, $fid, $flexion, $lemma) = explode("\t", $line);
      $flexion = str_replace(array("œ", "Œ", "æ", "Æ"), array("oe", "Oe", "ae", "Ae"), $flexion);
      $lemma = str_replace(array("œ", "Œ", "æ", "Æ"), array("oe", "Oe", "ae", "Ae"), $lemma);
      if (isset($dic[$flexion])) continue;
      $dic[$flexion] = $lemma;

      $ascii = strtr($flexion, self::$accents);
      if (!isset(self::$dicascii[$ascii])) self::$dicascii[$ascii] = $flexion;

      // $rank[$flexion] = $i;
      // echo $i,". ",$flexion," - ",$lemma,"\n";
    }
    fclose($handle);
    self::$dic = $dic;
    fwrite(STDERR, (microtime(true) - $start)." s.\n");
    
    /*
    $i = 1000;
    foreach ($dic as $key=>$value) {
      echo $key, " ", $value, "\n";
      if(--$i <= 0) break;
    }
    */
    
  }


  static public function walk()
  {
    $dic = self::$dic;
    
    self::$pdo->exec("
CREATE TEMP TABLE import(
  form        TEXT NOT NULL,
  lemma       TEXT NOT NULL,
  year        INTEGER NOT NULL,
  count       INTEGER NOT NULL
);    
    ");
    // gzopen 112s.
    // gzfile 111s.
    // fopen("compress.zlib://") 104s.
    // fopen+getcsv timeout 179 s.
    $glob = dirname(__FILE__).'/data20/*.gz';
    self::$pdo->beginTransaction();
    $line = $form = $lemma = $year = $count = null;
    $word = self::$pdo->prepare(
      "INSERT INTO import (form, lemma, year, count) VALUES (?, ?, ?, ?)"
    );
    $word->bindParam(1, $form, PDO::PARAM_STR);
    $word->bindParam(2, $lemma, PDO::PARAM_STR);
    $word->bindParam(3, $year, PDO::PARAM_INT);
    $word->bindParam(4, $count, PDO::PARAM_INT);
    /*
    $more = self::$pdo->prepare(
      "INSERT INTO more (year, form, count) VALUES (?, ?, ?)"
    );
    $more->bindParam(1, $year, PDO::PARAM_INT);
    $more->bindParam(2, $allograph, PDO::PARAM_STR);
    $more->bindParam(3, $count, PDO::PARAM_INT);
    */
    $noise = array();
    foreach(glob($glob) as $srcfile) {
      $start = microtime(true);
      fwrite(STDERR, $srcfile);
      $handle = fopen("compress.zlib://".$srcfile, "r");
      if(!$handle) exit("pb avec ".$srcfile);
      
      $test = array("ainsi"=>true, "Allemagne"=>true);
      
      
      while (($line = fgets($handle)) !== FALSE) {
      
        $cells = explode("\t", $line);
        $form = $cells[0];
        $orig = $form;
        if ($form[0] == '.') continue;
        if (preg_match("@[\[\]\-\"\\/*%§^<>_0123456789',{}~();:|«»“”?!•=+►♦□■°]@u", $form)) continue;
        $action = 1;
        $lemma = $form;
        do {
          if (isset($dic[$form])) break;
          $form = str_replace(array("œ", "Œ", "æ", "Æ"), array("oe", "Oe", "ae", "Ae"), $form);
          $lower = mb_strtolower(mb_substr($form, 1));
          $first = mb_substr($form, 0, 1);
          $form = $first.$lower;
          if (isset($dic[$form])) break;
          $form = mb_strtolower($first).$lower;
          if (isset($dic[$form])) break;
          /*
          $ascii = strtr($form, self::$accents);
          if (isset(self::$dicascii[$ascii])) {
            $form = self::$dicascii[$ascii];
            break;
          }
          */
          $action = 2;
        } while(false);
        if ($action == 1) {
          $lemma = $dic[$form];
        }
        else {
          // echo "$orig  $form  $lemma\n";
        }
        
        // loop on years
        for ($i = 1, $max = count($cells); $i < $max; $i++) {
          list($year, $count, $books) = explode(",", $cells[$i]);
          switch ($action) {
            case 1:
              $word->execute();
              break;
            case 2:
              if (isset($noise[$orig])) $noise[$orig] += $count;
              else $noise[$orig] = $count;
          }
        }
      }
      fwrite(STDERR, " ".(microtime(true) - $start)."\n");
    }
    self::$pdo->commit();
    arsort($noise);
    echo "form,count\n";
    foreach ($noise as $form => $count) {
      echo "$form,$count\n";
    }
    $start = microtime(true);
    self::$pdo->exec("
INSERT INTO word (form, lemma, year, count)
  SELECT form, lemma, year, SUM(count) as count FROM import GROUP BY year, form ORDER BY form, year;
    ");
    fwrite(STDERR, " ".(microtime(true) - $start)."\n"); // 1400 s. !! Todo, INDEX 
    // appliqué en CLI
    $sql = " INSERT INTO lemma (form, year, count)
      SELECT lemma, year, SUM(count) FROM word GROUP BY lemma, year; ";

  }
  /**
   * Connexion à la base de données
   */
  static function connect($sqlfile, $create=false)
  {
    $dsn = "sqlite:".$sqlfile;
    if($create && file_exists($sqlfile)) unlink($sqlfile);
    // create database
    if (!file_exists($sqlfile)) { // if base do no exists, create it
      fwrite(STDERR, "Base, création ".$sqlfile."\n");
      if (!file_exists($dir = dirname($sqlfile))) {
        mkdir($dir, 0775, true);
        @chmod($dir, 0775);  // let @, if www-data is not owner but allowed to write
      }
      self::$pdo = new PDO($dsn);
      self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      @chmod($sqlfile, 0775);
      self::$pdo->exec(file_get_contents(dirname(__FILE__)."/lexichrone.sql"));
      return;
    }
    else {
      // absolute path needed ?
      self::$pdo = new PDO($dsn);
      self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
  }

  /**
   *
   */
  static function more()
  {
    return; // done
    $pdo = self::$pdo;
    $pdo->beginTransaction();
    $count = $year = 0;
    $form = null;
    $select = $pdo->prepare(
      "SELECT count, form, year FROM more;"
    );
    $update = $pdo->prepare(
      "UPDATE word SET count = count + ? WHERE form = ? AND year = ?;"
    );
    $update->bindParam(1, $count, PDO::PARAM_INT);
    $update->bindParam(2, $form, PDO::PARAM_STR);
    $update->bindParam(3, $year, PDO::PARAM_INT);
    $select->execute();
    while(list($count, $form, $year) = $select->fetch()) {
      $update->execute();
    }
    self::$pdo->commit();
  }


  static function ranks($table = 'lemma')
  {
    $pdo = self::$pdo;
    // same count, same rank
    self::$pdo->beginTransaction();
    $id = $year = $lastyear = $count = $lastcount = 0;
    $select = $pdo->prepare(
      "SELECT id, year, count FROM $table ORDER BY year, count DESC;"
    );
    $update = $pdo->prepare(
      "UPDATE $table SET rank = ? WHERE id = ?;"
    );
    $update->bindParam(1, $rank, PDO::PARAM_INT);
    $update->bindParam(2, $id, PDO::PARAM_INT);
    $select->execute();
    $i = 0;
    while(list($id, $year, $count) = $select->fetch()) {
      if ($lastyear != $year) {
        $i=0;
        echo $year, "\n";
      }
      $i++;
      $lastyear = $year;
      if ($count != $lastcount) $rank = $i;
      $lastcount = $count;
      $update->execute();
    }
    self::$pdo->commit();

    return;
  }
}



 ?>
