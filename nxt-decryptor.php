<?php

	# nxt-decryptor.php
	#
	# Based on SysEleven decrypt-file.php
	# https://github.com/syseleven/nextcloud-tools/blob/master/decrypt-file.php
	#
	# Enhanced by https://github.com/mwellck
	#
	# usage:
	# ======
	#
	# php ./nxt-decryptor.php <username> <custom-destination>
	# Default decryption location: DATADIRECTORY/username/files_decrypted/

	// static definitions
	define("BLOCKSIZE",     8192);
	define("DEBUG_DEBUG",   2);
	define("DEBUG_DEFAULT", 0);
	define("DEBUG_INFO",    1);
	define("HEADER_END",    "HEND");
	define("HEADER_START",  "HBEGIN");
	define("KEY_MASTER",    0);
	define("KEY_PUBSHARE",  1);
	define("KEY_RECOVERY",  2);
	define("KEY_USER",      3);

	// nextcloud definitions - you can get these values from config/config.php
	define("DATADIRECTORY", "");
	define("INSTANCEID",    "");
	define("SECRET",        "");

	// custom definitions
	define("DEBUGLEVEL",        DEBUG_DEFAULT);
	define("KEYTYPE",           KEY_MASTER);
	define("RECOVERY_PASSWORD", "");
	define("USER_NAME",         "");
	define("USER_PASSWORD",     "");

	// saving options
	define("SAVE_DECRYPT", 	 true);
	define("OVERWRITE_ORIG", false);

	function concatPath($directory, $file) {
		if (0 < strlen($directory)) {
			if ("/" !== $directory[strlen($directory)-1]) {
				$directory .= "/";
			}
		}

		if (0 < strlen($file)) {
			if ("/" === $file[0]) {
				$file = substr($file, 1);
			}
		}

		return $directory.$file;
	}

	function debug($text, $debuglevel = DEBUG_DEFAULT) {
		if (DEBUGLEVEL >= $debuglevel) {
			print("$text\n");
		}
	}

	function getData($dir, &$results = array()) {
		$files = scandir($dir);

    	foreach ($files as $key => $value) {
        	$path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        	
        	if (!is_dir($path)) {
            	$results[] = $path;
        	} else if ($value != "." && $value != "..") {
            	getData($path, $results);
        	}
    	}

    	return $results;
	}

	// https://stackoverflow.com/questions/2124195/command-line-progress-bar-in-php
	function progressBar($done, $total) {
    	$perc = floor(($done / $total) * 100);
    	$left = 100 - $perc;
    	$write = sprintf("\033[0G\033[2K[%'={$perc}s>%-{$left}s] - $perc%%", "", "");
    	fwrite(STDERR, $write);
	}

	function decryptPrivateKey($file, $password, $keyid) {
		$result = false;

		$header = parseHeader($file);
		$meta   = splitMetaData($file);

		if (array_key_exists("cipher", $header) &&
		    array_key_exists("encrypted", $meta) &&
		    array_key_exists("iv", $meta)) {
			if (array_key_exists("keyFormat", $header) && ("hash" === $header["keyFormat"])) {
				$password = generatePasswordHash($password, $header["cipher"], $keyid);
			}

			$key = openssl_decrypt(stripHeader($meta["encrypted"]), $header["cipher"], $password, false, $meta["iv"]);
			if (false !== $key) {
				$res = openssl_pkey_get_private($key);
				if (is_resource($res)) {
					$sslInfo = openssl_pkey_get_details($res);
					if (array_key_exists("key", $sslInfo)) {
						$result = $key;
					}
				}
			}
		}

		return $result;
	}

	function generatePasswordHash($password, $cipher, $uid = "") {
		$result = false;

		$keySize = getKeySize($cipher);
		$salt    = hash("sha256", $uid.INSTANCEID.SECRET, true);
		if ((false !== $keySize) && (false !== $salt)) {
			$result = hash_pbkdf2("sha256", $password, $salt, 100000, $keySize, true);
		}

		return $result;
	}

	function getFilename($argv) {
		$result = null;

		if (count($argv) >= 2) {
			$result = $argv[1];
			if (0 < strlen($result)) {
				if ("/" !== $result[0]) {
					$result = concatPath(DATADIRECTORY, $result);
				}
			}
		}

		return $result;
	}

	function getCustomDestination($argv) {
		$result = null;

		if (count($argv) == 3) {
			$result = $argv[2];
			if (0 < strlen($result)) {
				if ("/" !== $result[0]) {
					$result = concatPath(DATADIRECTORY, $result);
				}
			}
		}

		return $result;
	}

	function getKeyFilename($keyname) {
		$result = false;

		switch (KEYTYPE) {
			case KEY_MASTER:
			case KEY_PUBSHARE:
			case KEY_RECOVERY:
				$result = concatPath(DATADIRECTORY, "files_encryption/OC_DEFAULT_MODULE/".$keyname.".privateKey");
				break;

			case KEY_USER:
				$result = concatPath(DATADIRECTORY, $keyname."/files_encryption/OC_DEFAULT_MODULE/".$keyname.".privateKey");
				break;
		}

		return $result;
	}

	function getKeyId() {
		$result = false;

		switch (KEYTYPE) {
			case KEY_MASTER:
				$result = getMasterKeyName();
				break;

			case KEY_PUBSHARE:
				$result = "";
				break;

			case KEY_RECOVERY:
				$result = "";
				break;

			case KEY_USER:
				$result = USER_NAME;
				break;
		}

		return $result;
	}

	function getKeyName() {
		$result = false;

		switch (KEYTYPE) {
			case KEY_MASTER:
				$result = getMasterKeyName();
				break;

			case KEY_PUBSHARE:
				$result = getPubShareKeyName();
				break;

			case KEY_RECOVERY:
				$result = getRecoveryKeyName();
				break;

			case KEY_USER:
				$result = USER_NAME;
				break;
		}

		return $result;
	}

	function getKeyPassword() {
		$result = false;

		switch (KEYTYPE) {
			case KEY_MASTER:
				$result = SECRET;
				break;

			case KEY_PUBSHARE:
				$result = "";
				break;

			case KEY_RECOVERY:
				$result = RECOVERY_PASSWORD;
				break;

			case KEY_USER:
				$result = USER_PASSWORD;
				break;
			}

		return $result;
	}

	function getUserPassword($username) {
		$result = false;

		if (USER_NAME === $username) {
			$result = USER_PASSWORD;
		} else {
			if (defined("USER_PASSWORD_".strtoupper($username))) {
				$result = constant("USER_PASSWORD_".strtoupper($username));
			}
		}

		return $result;
	}

	function getKeySize($cipher) {
		$result = false;

		$supportedCiphersAndKeySize = ["AES-256-CTR" => 32,
		                               "AES-128-CTR" => 16,
		                               "AES-256-CFB" => 32,
		                               "AES-128-CFB" => 16];

		if (array_key_exists($cipher, $supportedCiphersAndKeySize)) {
			$result = $supportedCiphersAndKeySize[$cipher];
		}

		return $result;
	}

	function getMasterKeyName() {
		$result = false;

		$filelist = recursiveScandir(concatPath(DATADIRECTORY, "files_encryption/OC_DEFAULT_MODULE/"));
		foreach ($filelist as $filename) {
			if (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                     "files_encryption/OC_DEFAULT_MODULE/(?<keyid>master_[0-9a-z]+)\.privateKey$@", $filename, $matches)) {
				$result = $matches["keyid"];

				break;
			}
		}

		return $result;
	}

	function getPubShareKeyName() {
		$result = false;

		$filelist = recursiveScandir(concatPath(DATADIRECTORY, "files_encryption/OC_DEFAULT_MODULE/"));
		foreach ($filelist as $filename) {
			if (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                     "files_encryption/OC_DEFAULT_MODULE/(?<keyid>pubShare_[0-9a-z]+)\.privateKey$@", $filename, $matches)) {
				$result = $matches["keyid"];

				break;
			}
		}

		return $result;
	}

	function getRecoveryKeyName() {
		$result = false;

		$filelist = recursiveScandir(concatPath(DATADIRECTORY, "files_encryption/OC_DEFAULT_MODULE/"));
		foreach ($filelist as $filename) {
			if (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                     "files_encryption/OC_DEFAULT_MODULE/(?<keyid>recoveryKey_[0-9a-z]+)\.privateKey$@", $filename, $matches)) {
				$result = $matches["keyid"];

				break;
			}
		}

		return $result;
	}

	function hasPadding($padded, $hasSignature = false) {
		$result = false;

		if ($hasSignature) {
			$result = ("xxx" === substr($padded, -3));
		} else {
			$result = ("xx" === substr($padded, -2));
		}

		return $result;
	}	

	function hasSignature($file) {
		$meta = substr($file, -93);
		$pos  = strpos($meta, "00sig00");

		return ($pos !== false);
	}

	function parseHeader($file) {
		$result = [];

		if (substr($file, 0, strlen(HEADER_START)) === HEADER_START) {
			$endAt  = strpos($file, HEADER_END);
			$header = substr($file, 0, $endAt+strlen(HEADER_END));

			// +1 not to start with an ':' which would result in empty element at the beginning
			$exploded = explode(":", substr($header, strlen(HEADER_START)+1));
			$element  = array_shift($exploded);

			while ($element !== HEADER_END) {
				$result[$element] = array_shift($exploded);
				$element          = array_shift($exploded);
			}
		}

		return $result;
	}

	function recursiveScandir($path = "") {
		$result = [];

		if ("" === $path) {
			$path = DATADIRECTORY;
		}

		$content = scandir($path);
		foreach ($content as $content_item) {
			if (("." !== $content_item) && (".." !== $content_item)) {
				if (is_file(concatPath($path, $content_item))) {
					$result[] = concatPath($path, $content_item);
				} elseif (is_dir(concatPath($path, $content_item))) {
					$result = array_merge($result, recursiveScandir(concatPath($path, $content_item)));
				}
			}
		}

		return $result;
	}

	function removePadding($padded, $hasSignature = false) {
		$result = false;

		if ($hasSignature) {
			if ("xxx" === substr($padded, -3)) {
				$result = substr($padded, 0, -3);
			}
		} else {
			if ("xx" === substr($padded, -2)) {
				$result = substr($padded, 0, -2);
			}
		}

		return $result;
	}

	function splitMetaData($file) {
		if (hasSignature($file)) {
			$file      = removePadding($file, true);
			$meta      = substr($file, -93);
			$iv        = substr($meta, strlen("00iv00"), 16);
			$sig       = substr($meta, 22+strlen("00sig00"));
			$encrypted = substr($file, 0, -93);
		} else {
			$file      = removePadding($file);
			$meta      = substr($file, -22);
			$iv        = substr($meta, -16);
			$sig       = false;
			$encrypted = substr($file, 0, -22);
		}

		return ["encrypted" => $encrypted,
			"iv"        => $iv,
			"signature" => $sig];
	}

	function stripHeader($encrypted) {
		return substr($encrypted, strpos($encrypted, HEADER_END)+strlen(HEADER_END));
	}

	function decryptFile($filepath, $file, $filekey, $key, $sharekey, $custDest) {
		$result = false;

		$keyid = getKeyId();
		debug("\$keyid = ".var_export($keyid, true), DEBUG_DEBUG);

		if (false !== $keyid) {
			$keyModified = decryptPrivateKey($key, getKeyPassword(), $keyid);
			if (openssl_open($filekey, $filekeyModified, $sharekey, $keyModified)) {
				$result = true;

				if (SAVE_DECRYPT) {
					if (!OVERWRITE_ORIG) {
						$origFilePath = str_replace(DATADIRECTORY, "", $filepath);
						$username = strtok($origFilePath, '/');
						$newFilePath = substr($origFilePath, strpos($origFilePath, '/', strlen($username)+2));

						if ($custDest) {
							$newFileFolder = concatPath($custDest, $username. dirname($newFilePath));
						} else {
							$newFileFolder = concatPath(DATADIRECTORY, $username . "/files_decrypted" . dirname($newFilePath));
						}

						print("Decrypting: " . $newFilePath . "\n");
						print("To: " . $newFileFolder . "/\n");
						

						if (!is_dir($newFileFolder)) {
							mkdir($newFileFolder, 0755, true);
						}

						$filepath = $newFileFolder . '/' . basename($filepath);
					}
	
					$fp = fopen($filepath, "wb");						
				}

				$strlen = strlen($file);
				$maxSize = intval(ceil($strlen/BLOCKSIZE));
				for ($i = 0; $i < $maxSize; $i++) {
					// Get the Progress bar moving (+ handling small size files)
					if ($maxSize == 1) {
						progressBar(1, 1);
					} else {
						progressBar($i, $maxSize-1);
					}					

					$block = substr($file, $i*BLOCKSIZE, BLOCKSIZE);
					$temp  = false;

					if (0 === $i) {
						$header = parseHeader($block);
						debug("\$header = ".var_export($header, true), DEBUG_DEBUG);

						$temp = true;
					} else {
						$meta = splitMetaData($block);
						debug("\$meta = ".var_export($meta, true), DEBUG_DEBUG);

						if (array_key_exists("cipher", $header) &&
						    array_key_exists("encrypted", $meta) &&
						    array_key_exists("iv", $meta)) {
							$output = openssl_decrypt($meta["encrypted"], $header["cipher"], $filekeyModified, false, $meta["iv"]);

							if (false !== $output) {
								fwrite($fp, $output);
								flush();
								$temp = true;
							}
						}
					}

					$result = ($result && $temp);
				}

				if (SAVE_DECRYPT) {
					printf("\n");
					fclose($fp);
				}
			}
		}

		return $result;
	}

	function handleFile($filename, $username, $datafilename, $istrashbin = false, $custDest = null) {
		$result = 1;

		$keyname = getKeyName();
		if (false === $keyname) {
			debug("$filename: Key ID could not be retrieved.", DEBUG_DEFAULT);
		} else {
			$keyfilename = getKeyFilename($keyname);

			if ($istrashbin) {
				$filekeyfilename  = concatPath(DATADIRECTORY,
					                       $username."/files_encryption/keys/files_trashbin/files/".$datafilename."/OC_DEFAULT_MODULE/fileKey");
				$sharekeyfilename = concatPath(DATADIRECTORY,
				                               $username."/files_encryption/keys/files_trashbin/files/".$datafilename."/OC_DEFAULT_MODULE/".$keyname.".shareKey");
			} else {
				$filekeyfilename  = concatPath(DATADIRECTORY,
				                               $username."/files_encryption/keys/files/".$datafilename."/OC_DEFAULT_MODULE/fileKey");
				$sharekeyfilename = concatPath(DATADIRECTORY,
				                               $username."/files_encryption/keys/files/".$datafilename."/OC_DEFAULT_MODULE/".$keyname.".shareKey");
			}

			if (!is_file($filename)) {
				debug("$filename: File is not a file.", DEBUG_DEFAULT);
			} else {
				if (!is_file($keyfilename)) {
					debug("$filename: Key is not a file.", DEBUG_DEFAULT);
				} else {
					if (!is_file($filekeyfilename)) {
						debug("$filename: Filekey is not a file.", DEBUG_DEFAULT);
					} else {
						if (!is_file($sharekeyfilename)) {
							debug("$filename: Sharekey is not a file.", DEBUG_DEFAULT);
						} else {
							$file = file_get_contents($filename);
							if (false === $file) {
								debug("$filename: File could not be read.", DEBUG_DEFAULT);
							} else {
								$key = file_get_contents($keyfilename);
								if (false === $key) {
									debug("$filename: Key could not be read.", DEBUG_DEFAULT);
								} else {
									$filekey = file_get_contents($filekeyfilename);
									if (false === $filekey) {
										debug("$filename: Filekey could not be read.", DEBUG_DEFAULT);
									} else {
										$sharekey = file_get_contents($sharekeyfilename);
										if (false === $sharekey) {
											debug("$filename: Sharekey could not be read.", DEBUG_DEFAULT);
										} else {
											if (!decryptFile($filename, $file, $filekey, $key, $sharekey, $custDest)) {
												debug("$filename: File not decrypted.", DEBUG_DEFAULT);
											} else {
												$result = 0;
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}

		return $result;
	}

	function main($argv) {
		$result = 1;

		$userDataPath = getFilename($argv);

		if ($userDataPath) {
			$customDest = getCustomDestination($argv);
			$userFiles = getData($userDataPath . '/files/');

			printf("\n##################################################\n");
			printf("####### Welcome to the Nextcloud Decryptor #######\n");
			printf("##################################################\n\n");

			foreach ($userFiles as $filename) {
				if (null !== $filename) {
					debug("##################################################", DEBUG_DEBUG);
					debug("\$filename = ".var_export($filename, true), DEBUG_DEBUG);

					if (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                     "(?<username>[^/]+)/files/(?<datafilename>.+)$@", $filename, $matches)) {
						$result = handleFile($filename, $matches["username"], $matches["datafilename"], false, $customDest);
					} elseif (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                           "(?<username>[^/]+)/files_trashbin/files/(?<datafilename>.+)$@", $filename, $matches)) {
						$result = handleFile($filename, $matches["username"], $matches["datafilename"], true, $customDest);
					} elseif (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                           "(?<username>[^/]+)/files_versions/(?<datafilename>.+)\.v[0-9]+$@", $filename, $matches)) {
						$result = handleFile($filename, $matches["username"], $matches["datafilename"], false, $customDest);
					} elseif (1 === preg_match("@^".preg_quote(concatPath(DATADIRECTORY, ""), "@").
			                           "(?<username>[^/]+)/files_trashbin/versions/(?<datafilename>.+)\.v[0-9]+(?<deletetime>\.d[0-9]+)$@", $filename, $matches)) {
						$result = handleFile($filename, $matches["username"], $matches["datafilename"].$matches["deletetime"], true, $customDest);
					} else {
						debug("$filename: File has unknown filename format.", DEBUG_DEFAULT);
					}

					debug("##################################################", DEBUG_DEBUG);
				}
			}

			printf("\n##################################################\n");
			printf("######### Imma get some sleep now.. bye! #########\n");
			printf("##################################################\n\n");
		}

		return $result;
	}

	exit(main($argv));
