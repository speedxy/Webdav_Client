<?php
/*
 * @copyright Eike Swat <swat@parrot-media.de
 * 
 * 
 * $p = xml_parser_create();
 * xml_parse_into_struct($p, $response, $vals, $index);
 * xml_parser_free($p);
 * echo "Index array\n";
 * print_r($index);
 * echo "\nVals array\n";
 * print_r($vals);
 *
 *
 * @TODO
 * - Der lokale Cache macht durch Filtern und Listen-/Baumansicht keinen Sinn mehr
 */
class Webdav_Client {
	private $username;
	private $password;
	private $host;
	private $host_parsed;
	private $cache = array ();
	
	// Liste der zu ignorierenden Dateitypen für die Listen
	public $ignore = array ();
	
	// Debug-Moduls ein/aus: Es werden alle Systemkommandos direkt ausgegeben
	public $debug = FALSE;
	
	/**
	 * @TODO:
	 * - Prüfung der Variable "host" auf Korrektheit (Protokoll, Slash am Ende)
	 *
	 * @param string $host        	
	 * @param string $username        	
	 * @param string $password        	
	 */
	public function __construct($host, $username, $password) {
		$this->host = $host;
		$this->host_parsed = parse_url ( $host );
		$this->username = $username;
		$this->password = $password;
		$this->ignore = array (
				".DS_Store" 
		);
	}
	
	/**
	 * Leert den internen Cache
	 */
	public function clear_cache() {
		$this->cache = array ();
	}
	
	/**
	 * Listet alle Dateien und Ordner innerhalb eines Pfades auf
	 *
	 * @param string $dir        	
	 * @return array Jedes Element ist dabei wie folgt aufgebaut:
	 *         [name] => Ordnername
	 *         [path] => Ordnerpfad
	 *         [mtime] => Teit der letzten Ädnerung als Unix Timestamp
	 *         [getetag] =>
	 *         [status] => HTTP-Status-Code (vollständig, z.B. "HTTP/1.1 200 OK")
	 *         [type] => [file|directory]
	 *         [size] => Größe des Verzeichnisses oder der Datei
	 *         === Nur bei Dateien
	 *         [content_type] => Mime-Type der Datei
	 *         === Nur bei Verzeichnissen
	 *         [free_space] => ???
	 * @param string $filter
	 *        	[all|files|directories]
	 */
	public function list_files($dir = "", $filter = "all") {
		// Parameter prüfen und modifizieren
		if ($dir [0] == "/") {
			$dir = substr ( $dir, 1 );
		}
		
		// Cache prüfen
		if (isset ( $this->cache ["list"] [$dir] )) {
			return $this->cache ["list"] [$dir];
		}
		
		// Command erzeugen
		$cmd = 'curl -k -X PROPFIND -u ' . escapeshellarg ( $this->username ) . ':' . escapeshellarg ( $this->password ) . ' ' . escapeshellarg ( $this->host . rawurlencode ( $dir ) );
		$response = $this->run ( $cmd );
		
		// Fix: https://github.com/christian-putzke/CardDAV-PHP/issues/8
		$response = preg_replace ( '/xmlns:d=\"[^\"]*\"/i', '', $response );
		$response = simplexml_load_string ( $response );
		
		$return = array ();
		foreach ( $response->{"response"} as $node ) {
			$file = array ();
			$node = json_decode ( json_encode ( $node ), true );
			$node ["href"] = urldecode ( $node ["href"] );
			$file ["name"] = basename ( $node ["href"] );
			
			// Überspringe Dateien auf der Ignore-Liste
			if (in_array ( $file ["name"], $this->ignore ))
				continue;
			
			$file ["path"] = $node ["href"];
			if (substr ( $file ["path"], 0, strlen ( $this->host_parsed ["path"] ) ) == $this->host_parsed ["path"])
				$file ["path"] = substr ( $file ["path"], strlen ( $this->host_parsed ["path"] ) - 1 );
			$file ["mtime"] = strtotime ( $node ["propstat"] ["prop"] ["getlastmodified"] );
			$file ["getetag"] = $node ["propstat"] ["prop"] ["getetag"];
			$file ["status"] = $node ["propstat"] ["status"];
			
			if (array_pop ( array_keys ( $node ["propstat"] ["prop"] ["resourcetype"] ) ) == "collection") {
				// Nur für Verzeichnisse
				$file ["type"] = "directory";
				$file ["size"] = $node ["propstat"] ["prop"] ["quota-used-bytes"];
				$file ["free_space"] = $node ["propstat"] ["prop"] ["quota-available-bytes"];
				// $file ["resourcetype"] = array_pop ( array_keys ( $node ["propstat"] ["prop"] ["resourcetype"] ) );
			} else {
				// Nur für Dateien
				$file ["type"] = "file";
				$file ["size"] = $node ["propstat"] ["prop"] ["getcontentlength"];
				$file ["content_type"] = $node ["propstat"] ["prop"] ["getcontenttype"];
			}
			/*
			 * switch ($filter) {
			 * case "files" :
			 * if ($file ["type"] == "file")
			 * $return [] = $file;
			 * break;
			 * case "directories" :
			 * if ($file ["type"] == "directory")
			 * $return [] = $file;
			 * break;
			 * default :
			 * case "all" :
			 * $return [] = $file;
			 * break;
			 * }
			 */
			$return [] = $file;
		}
		
		// Entferne erstes Element, da dies das Element selbst ist, wie brauchen aber nur die Tochterelemente
		// if ($filter == "directory" || $filter == "all") {
		array_shift ( $return );
		// }
		
		// Cache schreiben
		$this->cache ["list"] [$dir] = $return;
		
		return $return;
	}
	
	/**
	 * Liest einen kompletten Baum rekursiv aus und liefert alle Dateien und Ordner zurück
	 *
	 * @param string $dir        	
	 * @param string $list
	 *        	Gibt an, ob die Ausgabe hierarchisch oder als Liste erfolgen soll
	 * @param string $filter
	 *        	[all|files|directories]
	 */
	public function list_files_recursive($dir = "", $list = FALSE, $filter = "all") {
		// Parameter prüfen und modifizieren
		if ($dir [0] == "/") {
			$dir = substr ( $dir, 1 );
		}
		
		// Cache prüfen
		if (isset ( $this->cache ["list_recursive"] [$dir] )) {
			return $this->cache ["list_recursive"] [$dir];
		}
		
		// Alle Dateien auslesen
		$return = array ();
		$files = $this->list_files ( $dir, "all" );
		if ($list === TRUE) {
			// Erstelle eine Liste (eindimensionales Array)
			foreach ( $files as &$file ) {
				if ($file ["type"] == "directory") {
					
					// $return [] = $file;
					$files_rec = $this->list_files_recursive ( $file ["path"], $list, $filter );
					foreach ( $files_rec as $file_rec ) {
						if ($file_rec ["type"] == "file")
							$return [] = $file_rec;
					}
				} else {
					switch ($filter) {
						case "all" :
						case "files" :
							$return [] = $file;
							break;
						case "directories" :
							break;
						default :
							if (preg_match ( $filter, $file ["path"] ) or $file ["content_type"] == $filter) {
								$return [] = $file;
							}
							break;
					}
				}
			}
		} else {
			// Erstelle einen Baum (mehrdimensionales array)
			foreach ( $files as $file ) {
				if ($file ["type"] == "directory") {
					$file ["contains"] = $this->list_files_recursive ( $file ["path"], $list, $filter );
					if (! ($filter != "directories" && count ( $file ["contains"] ) == 0))
						$return [] = $file;
				} else {
					switch ($filter) {
						case "all" :
						case "files" :
							$return [] = $file;
							break;
						case "directories" :
							break;
						default :
							if (preg_match ( $filter, $file ["path"] ) or $file ["content_type"] == $filter) {
								$return [] = $file;
							}
							break;
					}
				}
			}
		}
		
		// Cache schreiben
		$this->cache ["list_recursive"] [$dir] = $return;
		
		return $return;
	}
	
	/**
	 * Lädt eine Datei vom WebDAV-Server herunter
	 *
	 * @param string $file        	
	 * @param string $mode
	 *        	[s|o] (s=Datei speichern in $destination, o=Direkte Ausgabe)
	 * @param string $destination
	 *        	@TODO:
	 *        	- Prüfen der Variable $destination auf Gültigkeit und Berechtigung
	 */
	public function get_file($file, $destination = NULL) {
		// Parameter prüfen und modifizieren
		if ($file [0] == "/") {
			$file = substr ( $file, 1 );
		}
		
		// Command erzeugen
		$cmd = 'curl -k --user ' . escapeshellarg ( $this->username ) . ':' . escapeshellarg ( $this->password ) . ' ' . escapeshellarg ( $this->host . rawurlencode ( $file ) );
		// --digest
		
		if (! $destination) {
			// Direct Output
			// header ( 'Content-Type: application/pdf' );
			// header ( 'Content-Disposition: attachment; filename="downloaded.pdf"' );
		} else {
			$cmd .= ' --output ' . escapeshellarg ( $destination );
		}
		return $this->run ( $cmd );
	}
	
	/**
	 * Führt ein Kommando auf der lokalen Shell aus.
	 *
	 * @param string $cmd        	
	 */
	private function run($cmd) {
		if ($this->debug === TRUE)
			echo $cmd;
		return shell_exec ( $cmd );
	}
}
