<?php
/*
	BackupMySQL.php — 2017-V-7 — Francisco Cascales
 	Backup a MySQL database only with PHP (without mysqldump)
	https://github.com/fcocascales/phpbackupmysql

	Example 1:
			// Download a SQL backup file
			require_once "BackupMySQL.php";
			$connection = array(
				'host'=> "localhost",
				'database'=> "acme",
				'user'=> "root",
				'password'=> "",
			);
			$backup = new BackupMySQL($connection);
			$backup->download();

	Example 2:
			// Download a ZIP backup file
			require_once "BackupMySQL.php";
			$connection = [
				'host'=> "localhost",
				'database'=> "acme",
				'user'=> "root",
				'password'=> "",
			];
			$tables = [
				"wp_*",
				"mytable1",
			];
			$config = [
				'folder'=> "../backups",
				'show'=> ['TABLES', 'DATA'],
			];
			$backup = new BackupMySQL($connection, $tables, $config);
			$backup->zip();
			$backup->download();

	Example 3:
			// Stores a SQL backup file to a writable folder
			require_once "BackupMySQL.php";
			$setup = [
				'connection'=> [
					'host'=> "localhost",
					'database'=> "acme",
					'user'=> "root",
					'password'=> "",
				],
				'tables'=> "wp_*,mytable1",
				'config'=> [
					'folder'=> "../backups"
					'show'=> "TABLES,DATA"
				],
			];
			$backup = new BackupMySQL($setup['connection'], $setup['tables'], $setup['config']);
			$backup->run();

	TODO:
		- Avoid timeout with database too large

	DONE:
		- Extract the FOREIGN KEY of CREATE TABLE sentence.
		- Compress SQL file to ZIP file (without shell)
		- Method to download the SQL or ZIP file (using header)
		- Detect a temporary writable folder to store SQL & ZIP files
		- Delete file after download
		- Publish in GitHub
*/

class BackupMySQL {

	//——————————————————————————————————————————————
	// CONSTRUCTOR

	private $connection = array( // Database parameters connection
		'host'=> "localhost",
		'database'=> "acme",
		'user'=> "root",
		'password'=> ""
	);

	private $tables = array( // Backup selection tables
		'wp_*', 'table1', 'table2',
	);

	private $config = array(
		'folder'=> "", // Destination folder of backups with write permission
		'show'=> array(
			'DB', // Generate SQL to create and use DB
			'TABLES', // Generate SQL to drop and create TABLEs
			'VIEWS', // Generate SQL to create or replace VIEWs
			'PROCEDURES', // Generate SQL to drop and create PROCEDUREs and FUNCTIONs
			'DATA', // Generate SQL to truncate tables and dump data
		),
	);

	public function __construct($connection, $tables=[], $config=[]) {
		$this->connection = $connection;
		$this->setTables($tables);
		$this->setConfig($config);
	}

	public function setTables($array) {
		if (empty($array)) $this->tables = array();
		elseif (!is_array($array)) $this->tables = explode(',', $array);
		else $this->tables = $array;
	}

	public function setConfig($assoc) {
		if (!empty($assoc) && is_array($assoc)) {
			if (!isset($assoc['folder'])) $assoc['folder'] = self::getTempFolder();
			if (!isset($assoc['show'])) $assoc['show'] = array();
			elseif (!is_array($assoc['show'])) $assoc['show'] = explode(',', $assoc['show']);
			$this->config = $assoc;
		}
	}
	public function setFolder($folder) {
		$this->config['folder'] = $folder;
	}

	private static function getTempFolder() {
		return ini_get('upload_tmp_dir')? ini_get('upload_tmp_dir') : sys_get_temp_dir();
	}

	//——————————————————————————————————————————————
	// CONFIGURATION

	private function show($ITEM) {
		if (empty($this->config['show'])) return true;
		else return in_array($ITEM, $this->config['show']);
	}

	//——————————————————————————————————————————————
	// PUBLIC

	/*
		Creates a database backup in a SQL file

		Example:
			$backup->run();
			echo $backup->getPath();
	*/
	public function run() {
		try {
			$this->initDatabase();
			$this->initFile();
			$this->initTables();
			$this->initViews();
			$this->openFile();
				$this->backupDB();
			$this->closeFile();
		}
		catch (Exception $ex) {
			die($ex->getMessage());
		}
	}

	/*
		Creates a database backup and show it in the web browser
	*/
	public function test() {
		try {
			header("Content-Type: text/plain");
			$this->initDatabase();
			$this->initTables();
			$this->initViews();
			$this->backupDB();
		}
		catch (Exception $ex) {
			die($ex->getMessage());
		}
	}

	/*
		Creates a database backup in a ZIP file

		Example:
			$backup->zip();
			echo $backup->getPath();
	*/
	public function zip() {
		if (empty($this->path)) $this->run();
		$path = $this->getPath();
		$zip = rtrim($path, '.sql').'.zip';
		$za = new ZipArchive();
		if ($za->open($zip, ZipArchive::CREATE) !== true)
    	throw new Exception ("Cannot open $zip");
		$za->addFile($path, basename($path));
		$za->close();
		unlink($path);
		$this->path = $zip;
	}

	/*
		Creates a database backup in a SQL or ZIP file
		and starts the download

		Ejemplo 1:
			$backup->download();

		Ejemplo 2:
			$backup->run();
			$backup->zip();
			$backup->download();
	*/
	public function download($purge=true) {
		if (empty($this->path)) $this->run();
		$path = $this->getPath();
		$quoted = sprintf('"%s"', addcslashes(basename($path), '"\\'));
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		header('Content-Description: File Transfer');
		//header('Content-Type: application/octet-stream');
		header("Content-Type: application/$ext");
		header('Content-Disposition: attachment; filename='.$quoted);
		header('Content-Transfer-Encoding: binary');
		header('Connection: Keep-Alive');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: '.filesize($path));
		echo file_get_contents($path);
		if ($purge) unlink($path);
	}

	/*
		Get the path of the SQL or ZIP backup file
		or empty text
	*/
	public function getPath() {
		return $this->path;
	}

	//——————————————————————————————————————————————
	// FILE

	private $file = null;
	private $path = "";
	private $buffer = "";

	private function initFile() {
		$database = $this->connection['database'];
		$time = date('Y-m-d_H-i-s'); //time();
		$folder = rtrim($this->config['folder'], '/');
		$this->path = "$folder/{$database}_{$time}.sql";
	}

	private function openFile() {
		$this->file = fopen($this->path,'w+');
		if ($this->file === false) throw new Exception("Failed to open $this->path");
	}

	private function append($text) {
		if ($this->file == null) echo $text;
		else $this->buffer .= $text;
	}

	private function writeFile() {
		if ($this->file == null) return;
		fwrite($this->file, $this->buffer);
		$this->buffer = "";
	}

	private function closeFile() {
		if ($this->file == null) return;
		$this->writeFile();
		fclose($this->file);
	}

	//——————————————————————————————————————————————
	// INIT DATABASE

	private $pdo = null;
	private $dbtables = array();
	private $dbviews = array();

	private function initDatabase() {
		extract($this->connection); // $host, $database, $user, $password
		$pdo = new PDO("mysql:host=$host;dbname=$database", $user, $password);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('SET NAMES utf8');
		$pdo->exec('SET CHARACTER SET utf8');
		$this->pdo = $pdo;
	}

	private function initTables() {
		$this->dbtables = $this->findTables('TABLE', $this->tables);
	}
	private function initViews() {
		$this->dbviews = $this->findTables('VIEW', $this->tables);
	}
	private function findTables($like, $tables) {
		$database = $this->connection['database'];
		$sql = "SHOW FULL TABLES IN $database WHERE TABLE_TYPE LIKE '%$like%'";
		$result = $this->pdo->query($sql);
		$all = $result->fetchAll(PDO::FETCH_COLUMN);
		if (empty($tables)) return $all;
		else {
			$result = array();
			foreach ($all as $table) {
				if ($this->matchTable($table, $tables)) $result[]= $table;
			}
			return $result;
		}
	}
	private function matchTable($table, $list) {
		foreach($list as $item) {
			$item = trim($item);
			if (substr($item, -1) == '*') {
				$prefix = rtrim($item, '*');
				if (substr($table, 0, strlen($prefix)) == $prefix) return true;
			}
			elseif ($table == $item) return true;
		}
		return false;
	}

	//——————————————————————————————————————————————
	// BACKUP

	private function backupDB() {
		$this->sqlHeader();

		if ($this->show('DB')) {
			$this->sqlComment('CREATE DB');
			$this->sqlCreateDB();
		}

		if ($this->show('TABLES')) {
			$this->sqlComment('DROP TABLES');
			foreach ($this->dbtables as $table) $this->sqlDropTable($table);
			$this->append("\n");

			$this->sqlComment('CREATE TABLES');
			foreach ($this->dbtables as $table) $this->sqlCreateTable($table);

			$this->sqlComment('FOREIGN KEYS');
			foreach($this->dbtables as $table) $this->sqlForeignsKeys($table);
		}

		if ($this->show('VIEWS')) {
			$this->sqlComment('CREATE VIEWS');
			foreach ($this->dbviews as $view) $this->sqlCreateView($view);
		}

		if ($this->show('PROCEDURES') || $this->show('FUNCTIONS')) {
			$this->sqlComment('CREATE PROCEDURES');
			foreach ($this->listProcedures() as $proc) $this->sqlCreateProc($proc);

			$this->sqlComment('CREATE FUNCTIONS');
			foreach ($this->listFunctions() as $func) $this->sqlCreateFunc($func);
		}

		if ($this->show('DATA')) {
			$this->sqlComment('TRUNCATE DATA');
			$this->append("SET FOREIGN_KEY_CHECKS = FALSE;\n\n");
			foreach ($this->dbtables as $table) $this->sqlTruncateTable($table);
			$this->append("\nSET FOREIGN_KEY_CHECKS = TRUE;\n\n");

			$this->sqlComment('DUMP DATA');
			$this->append("SET FOREIGN_KEY_CHECKS = FALSE;\n\n");
			foreach ($this->dbtables as $table) $this->sqlDumpTable($table);
			$this->append("SET FOREIGN_KEY_CHECKS = TRUE;\n\n");
		}

		$this->sqlFooter();
	}

	//——————————————————————————————————————————————
	// SQL GENERATION

	private $starttime;

	private function sqlComment($text) {
		$comment = "-- $text ";
		$dashes = str_repeat("-", 50-strlen($comment));
		$this->append("$comment$dashes\n\n");
	}

	private function sqlHeader() {
		$this->starttime = microtime(true);
		$database = $this->connection['database'];
		$datetime = date('Y-m-d H:i:s');
		$comment = "/* BACKUP — $database — $datetime */\n\n";
		$this->append($comment);
	}

	private function sqlFooter() {
		$timediff = microtime(true) - $this->starttime;
		$timediff = self::secondsToTime($timediff);
		$this->sqlComment("ELAPSED $timediff");
	}
	private static function secondsToTime($sec) {
		$dec = substr(ltrim($sec - floor($sec), '0'), 0, 5);
    $hor = floor($sec / 3600); $sec -= $hor * 3600;
    $min = floor($sec / 60);   $sec -= $min * 60;
    return sprintf('%02d:%02d:%02d%s', $hor, $min, $sec, $dec);
	}

	private function sqlCreateDB() {
		$database = $this->connection['database'];
		$sql = "CREATE DATABASE IF NOT EXISTS $database\n".
			"\tCHARACTER SET utf8\n".
			"\tCOLLATE utf8_general_ci;\n\n".
			"USE $database;\n\n";
		$this->append($sql);
	}

	private function sqlDropTable($table) {
		$this->append("DROP TABLE IF EXISTS `$table`;\n");
	}

	private function sqlCreateTable($table) {
		$result = $this->pdo->query("SHOW CREATE TABLE `$table`");
		$array = $result->fetch(PDO::FETCH_NUM); // Table, Create Table
		$sql = $array[1];
		$sql = substr($sql, 0, 12)." IF NOT EXISTS ".substr($sql, 13);
		$sql = $this->extractForeignKeys($table, $sql);
		$this->append("$sql;\n\n");
	}

	/*
		CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER
			VIEW `vi_aromas` AS select ...
	*/
	private function sqlCreateView($view) {
		$result = $this->pdo->query("SHOW CREATE TABLE `$view`");
		$array = $result->fetch(PDO::FETCH_NUM); // Table, Create Table
		$sql = $array[1];
		if (($pos = strpos($sql, 'VIEW')) !== false) $sql = "CREATE OR REPLACE ".substr($sql, $pos);
		$this->append("$sql;\n\n");
	}

	private function sqlTruncateTable($table) {
		$this->append("TRUNCATE `$table`;\n");
	}

	//——————————————————————————————————————————————
	// FOREIGN KEYS

	private $foreignKeys = array(
		//'table1'=> [ 'ADD CONSTRAINT `name1` FOREIGN KEY(`field1d`) REFERENCES...', ... ],
	);

	private function extractForeignKeys($table, $sqlCreateTable) {
		$lines = explode("\n", $sqlCreateTable);
		$result = array();
		foreach($lines as $line) {
			if (strpos($line, 'FOREIGN KEY') !== false) {
				if (!isset($this->foreignKeys[$table])) $this->foreignKeys[$table] = array();
				$this->foreignKeys[$table][] = "ADD ".rtrim(trim($line),',');
 			}
			else $result[] = $line;
		}
		$result[count($result)-2] = rtrim($result[count($result)-2], ',');
		return implode("\n", $result);
	}

	private function sqlForeignsKeys($table) {
		if (!isset($this->foreignKeys[$table])) return;
		$this->append("ALTER TABLE `$table`\n ");
		$this->append(implode(",\n ", $this->foreignKeys[$table]));
		$this->append(";\n\n");
	}

	//——————————————————————————————————————————————
	// DUMP DATA

	private function sqlDumpTable($table) {
		$MAXIM = 1000;
		$index = 0;
		$result = $this->pdo->query("SELECT * FROM `$table`", PDO::FETCH_ASSOC);
		$count = 0;
		$COUNT = $result->rowCount();
		$this->writeFile();
		foreach ($result as $row) {
			$count++;
			if ($index++ == 0) $this->sqlInsertTable($table, $row);
			$this->sqlInsertValues($row);
			if ($index >= $MAXIM || $count >= $COUNT) {
				$this->append(";\n\n");
				$this->writeFile();
				$index = 0;
			}
			else $this->append(",\n");
		}
	}
	private function sqlInsertTable($table, $row) {
		$fields = [];
		foreach($row as $key=>$value) $fields[] = "`$key`";
		$fields = implode(',', $fields);
		$this->append("INSERT INTO `$table`($fields) VALUES\n");
	}
	private function sqlInsertValues($row) {
		$values = [];
		foreach ($row as $key=>$value) {
			if (isset($value)) {
				$value = addslashes($value);
				$value = str_replace(array("\n","\r"), array('\\n','\\r'), $value);
				$values[] = "'$value'";
			}
			else $values[] = "NULL";
		}
		$this->append(" (".implode(',', $values).")");
	}

	//——————————————————————————————————————————————
	// STORED PROCEDURES AND FUNCTIONS

	private function listProcedures() {
		return $this->listStoredProgram('PROCEDURE');
	}
	private function listFunctions() {
		return $this->listStoredProgram('FUNCTION');
	}
	private function listStoredProgram($TYPE) {
		$database = $this->connection['database'];
		$sql = "SHOW $TYPE STATUS WHERE Db = '$database' AND Type = '$TYPE'";
		$result = $this->pdo->query($sql);
		$list = array();
		foreach($result as $row) $list[] = $row['Name'];
		return $list;
	}

	private function sqlCreateProc($name) {
		$this->sqlCreateStoredProgram($name, 'PROCEDURE');
	}
	private function sqlCreateFunc($name) {
		$this->sqlCreateStoredProgram($name, 'FUNCTION');
	}
	private function sqlCreateStoredProgram($name, $TYPE) {
		//$sql = "SHOW $type CODE $proc"; // Pos, Instruction
		$database = $this->connection['database'];
		$sql = "SHOW CREATE $TYPE `$database`.`$name`";
		$result = $this->pdo->query($sql);
		$fieldname = ucwords(strtolower("create $TYPE"));
		$sql = $result->fetch()[$fieldname];
		$lines = array(
			'DELIMITER $$',
			"DROP $TYPE IF EXISTS `$name`".'$$',
			$sql.'$$',
			'DELIMITER ;'
		);
		$this->append(implode("\n", $lines)."\n\n");
	}

} // class

//——————————————————————————————————————————————
// TEST

/*---

//test1();
test2();
//test3();

function test1() {
	require_once "server/connection.php";
	$connection = [
		'host'=> Connection::$host,
		'database'=> Connection::$database,
		'user'=> Connection::$user,
		'password'=> Connection::$password,
	];
	$tables = empty($_GET['tables'])? "" : $_GET['tables'];
	$config = [
		'folder'=> "../backups",
	];
	$backup = new BackupMySQL($connection, $tables, $config);
	$backup->test();
	//$backup->run();
	//$backup->download();
}

function test2() {
	$connection = [
		'host'=> "localhost",
		'database'=> "vispoke",
		'user'=> "root",
		'password'=> "",
	];
	$tables = ["ext_*", "vi_*", "wp_users"];
	$config = [
		//'folder'=> "../backups",
	];
	$backup = new BackupMySQL($connection, $tables, $config);
	//$backup->test();
	$backup->run();
	$backup->zip();
	$backup->download();
	//echo $backup->getPath();
}

function test3() {
	$connection = [
		'host'=> "localhost",
		'database'=> "bd_neptuno",
		'user'=> "root",
		'password'=> "",
	];
	$tables = "";
	$config = [
		//'folder'=> "../backups",
		'show'=> ['TABLES', 'DATA'],
	];
	$backup = new BackupMySQL($connection, $tables, $config);
	//$backup->test();
	//$backup->run();
	$backup->zip();
	$backup->download();
}

---*/