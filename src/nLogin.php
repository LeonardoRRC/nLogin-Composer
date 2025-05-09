<?php
/*
 * This code or part of it was taken from the AuthMe project,
 * licensed under the GNU General Public License v3.0 (https://github.com/AuthMe/AuthMeReloaded/blob/master/LICENSE)
 * 
 * For more details, access the original source code:
 * https://github.com/AuthMe/AuthMeReloaded/tree/master/samples/website_integration 
 */

/*
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace NLogin;
 
use NLogin\Algorithms\Algorithm;
use NLogin\Algorithms\AuthMe;
use NLogin\Algorithms\Bcrypt;
use NLogin\Algorithms\Sha256;
use NLogin\Algorithms\Sha512;
use mysqli;

class nLogin
{

	const FETCH_WITH_MOJANG_ID = 1;
	const FETCH_WITH_BEDROCK_ID = 2;
	const FETCH_WITH_LAST_NAME = 3;
	
	private Algorithm $authme;
	private Algorithm $bcrypt;
	private Algorithm $sha256;
	private Algorithm $sha512;
	private Algorithm $hashing_algorithm;

	private string $table_name;
	private string $mysql_host, $mysql_user, $mysql_pass, $mysql_database;
	private bool $using_username_appender;

	/**
	 * Constructor responsible for creating the connection with the database
	 *
	 * @param string $mysql_host MySQL Host
	 * @param string $mysql_user MySQL User
	 * @param string $mysql_pass MySQL Password
	 * @param string $mysql_database MySQL Database Name 
	 * @param bool $using_username_appender Set this true if the "username-appender" option is enabled in nLogin's config.yml
	 * @param string $table_name Sets the table name. Default value: "nlogin"
	 */
	public function __construct(string $mysql_host, string $mysql_user, string $mysql_pass, string $mysql_database, bool $using_username_appender, string $table_name = 'nlogin')
	{
		$this->init_algorithm();
		$this->mysql_host = $mysql_host;
		$this->mysql_user = $mysql_user;
		$this->mysql_pass = $mysql_pass;
		$this->mysql_database = $mysql_database;
		$this->using_username_appender = $using_username_appender;
		$this->table_name = $table_name;
	}

	/**
	 * Initializes algorithm instances
	 */
	private function init_algorithm() {
		$this->authme = new AuthMe();
		$this->bcrypt = new Bcrypt();
		$this->sha256 = new Sha256();
		$this->sha512 = new Sha512();
		$this->hashing_algorithm = $this->bcrypt;
	}

	/**
	 * Destroys the class instance
	 */
	public function __destruct() 
	{
	}

	/**
	 * Retrieves the user identifier for the player.
	 * 
	 * @param string $search the value used to search, it can be a username (nLogin::FETCH_WITH_LAST_NAME), a mojang id (nLogin::FETCH_WITH_MOJANG_ID) or a bedrock id (nLogin::FETCH_WITH_BEDROCK_ID)
	 * @param string $mode the mode used to search
	 * 
	 * @return int|null the user identifier, -1 if not found, or null if failed
	 */
	public function fetch_user_id(string $search, int $mode) {
		$mysqli = $this->get_mysqli();
		if ($mysqli == null) {
			return null;
		}

		$search = trim($search);

		switch ($mode) {
			case self::FETCH_WITH_MOJANG_ID:
				$stmt = $mysqli->prepare('SELECT ai FROM ' . $this->table_name . ' WHERE mojang_id = ? LIMIT 1');
				$stmt->bind_param('s', $search);
				break;

			case self::FETCH_WITH_BEDROCK_ID:
				$stmt = $mysqli->prepare('SELECT ai FROM ' . $this->table_name . ' WHERE bedrock_id = ? LIMIT 1');
				$stmt->bind_param('s', $search);
				break;

			case self::FETCH_WITH_LAST_NAME:
				if ($this->using_username_appender) {
					$stmt = $mysqli->prepare('SELECT ai FROM ' . $this->table_name . ' WHERE last_name = ? AND mojang_id IS NULL AND bedrock_id IS NULL LIMIT 1');
					$stmt->bind_param('s', $search);
				}
				else {
					$stmt = $mysqli->prepare('SELECT ai FROM ' . $this->table_name . ' WHERE last_name = ? ORDER BY mojang_id DESC LIMIT 1');
					$stmt->bind_param('s', $search);
				}
				break;
			
			default:
				throw new \Exception('Invalid search mode (' . $mode . '), valid values: nLogin::FETCH_WITH_MOJANG_ID, nLogin::FETCH_WITH_BEDROCK_ID or nLogin::FETCH_WITH_LAST_NAME');
		}

		$stmt->execute();
		$stmt->bind_result($user_id);

		if (!$stmt->fetch()) {
			return null;
		}

		return $user_id ?? -1;
	}

	/**
	 * Returns whether the user exists in the database or not.
	 *
	 * @param int $user_id the user identifier
	 * 
	 * @return bool true if the user exists; false otherwise
	 */
	public function is_user_registered(int $user_id) {
		return $this->__exists_in_database('ai', $user_id);
	}

	/**
	 * Returns whether the ip exists in the database or not.
	 *
	 * @param string $ip the username to check
	 * 
	 * @return bool true if the ip exists; false otherwise
	 */
	public function is_ip_registered(string $ip) {
		return $this->__exists_in_database('last_ip', $ip);
	}

	/**
	 * Entry point function to check supplied credentials against the nLogin database.
	 *
	 * @param int $user_id the user identifier
	 * @param string $password the plain password
	 * 
	 * @return bool true if the data is correct, false otherwise
	 */
	public function verify_password(int $user_id, string $password) {
		$hashed_password = $this->get_hashed_password($user_id);
		if (!$hashed_password) {
			return false;
		}

		$algorithm = $this->detect_algorithm($hashed_password);
		if ($algorithm == null) {
			throw new \Exception('Hashing algorithm cannot be determined for user identifier: ' . $user_id);
		}

		return $algorithm->verify($password, $hashed_password);
	}

	/**
	 * Changes password for player.
	 *
	 * @param int $user_id the user identifier
	 * @param string $password the new plain password
	 * 
	 * @return bool true whether or not password change was successful 
	 */
	public function change_password(int $user_id, string $password) {
		$mysqli = $this->get_mysqli();
		if ($mysqli == null) {
			return false;
		}

		$hash = $this->hashing_algorithm->hash($password);
		$stmt = $mysqli->prepare('UPDATE ' . $this->table_name . ' SET password = ? WHERE ai = ? LIMIT 1');
		$stmt->bind_param('si', $hash, $user_id);
		
		return $stmt->execute();
	}

	/**
	 * Registers a player with the given username.
	 *
	 * @param string $username the username to register
	 * @param string $password the password to associate to the user
	 * @param string $email the email (may be empty)
	 * @param string $ip the ip (optional)
	 * @param string $unique_id the unique id to register (optional)
	 * @param string $mojang_id the mojang id (optional). It should be null if $bedrock_id is not null
	 * @param string $bedrock_id the bedrock id (optional). It should be null if $mojang_id is not null 
	 * 
	 * @return bool whether or not the registration was successful
	 */
	public function register(string $username, string $password, string $email, string $ip = null, string $unique_id = null, string $mojang_id = null, string $bedrock_id = null) {
		if ($ip == null) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		$mysqli = $this->get_mysqli();
		if ($mysqli == null) {
			return false;
		}

		if ($mojang_id != null && $bedrock_id != null) {
			throw new \Exception('$mojang_id and $bedrock_id cannot be both not null!');
		}

		$username = trim($username);

		if ($mojang_id != null) {
			$search = $mojang_id;
			$mode = self::FETCH_WITH_MOJANG_ID;
			if ($unique_id == null) {
				$unique_id = $mojang_id;
			}
		}
		else if ($bedrock_id != null) {
			$search = $bedrock_id;
			$mode = self::FETCH_WITH_BEDROCK_ID;
			if ($unique_id == null) {
				$unique_id = $bedrock_id;
			}
		} 
		else {
			$search = $username;
			$mode = self::FETCH_WITH_LAST_NAME;
			if ($unique_id == null) {
				$unique_id = $this->__create_offline_id($username);
			}
		}

		if ($unique_id == null || strlen($unique_id) !== 32) {
			throw new \Exception('Invalid $unique_id provided! ' . $unique_id);
		}

		$user_id = $this->fetch_user_id($search, $mode);
		if ($user_id == null) {
			return false;
		}

		$email = $email ?? '';
		$hashed_password = $this->hashing_algorithm->hash($password);

		if ($user_id < 0) {
			$stmt = $mysqli->prepare('INSERT INTO ' . $this->table_name . ' (last_name, password, last_ip, unique_id, mojang_id, bedrock_id, email) '
				. 'VALUES (?, ?, ?, ?, ?, ?, ?) ');
			$stmt->bind_param('sssssss', $username, $hashed_password, $ip, $unique_id, $mojang_id, $bedrock_id, $email);
		} 
		else if ($mojang_id != null) {
			$stmt = $mysqli->prepare('UPDATE ' . $this->table_name . ' SET ' 
				. 'password = ?, last_ip = ?, mojang_id = ?, email = ? WHERE ai = ? LIMIT 1');
			$stmt->bind_param('ssssi', $hashed_password, $ip, $mojang_id, $email, $user_id);
		} 
		else if ($bedrock_id != null) {
			$stmt = $mysqli->prepare('UPDATE ' . $this->table_name . ' SET ' 
				. 'password = ?, last_ip = ?, bedrock_id = ?, email = ? WHERE ai = ? LIMIT 1');
			$stmt->bind_param('ssssi', $hashed_password, $ip, $bedrock_id, $email, $user_id);
		} 
		else {
			$stmt = $mysqli->prepare('UPDATE ' . $this->table_name . ' SET ' 
				. 'password = ?, last_ip = ?, email = ? WHERE ai = ? LIMIT 1');
			$stmt->bind_param('sssi', $hashed_password, $ip, $email, $user_id);
		}

		return $stmt->execute();
	}

	/**
	 * Retrieves the hash associated with the given user from the database.
	 *
	 * @param int $user_id the user identifier
	 * 
	 * @return string|null the hash, or null if unavailable (e.g. user identifier doesn't exist)
	 */
	public function get_hashed_password(int $user_id) {
		$mysqli = $this->get_mysqli();
		if ($mysqli == null) {
			return null;
		}

		$stmt = $mysqli->prepare('SELECT password FROM ' . $this->table_name . ' WHERE ai = ? LIMIT 1');
		
		$stmt->bind_param('i', $user_id);
		$stmt->execute();
		$stmt->bind_result($hashed_password);

		if (!$stmt->fetch()) {
			return null;
		}

		return $hashed_password;
	}

	/**
	 * Returns the algorithm used in the password.
	 *
	 * @param string $hashed_pass the hashed password.
	 * 
	 * @return object|null the algorithm used, or null if unknown or unsupported
	 */
	private function detect_algorithm(string $hashed_pass)
	{
		$algo = strtoupper(strpos($hashed_pass, '$') !== false ? explode('$', $hashed_pass)[1] : '');
		switch ($algo) {
		 	case '2':
		 	case '2A':
		 		return $this->bcrypt;
			
			case "SHA256":
				return $this->sha256;
			   
			case "SHA512":
				return $this->sha512;

			case "SHA":
				return $this->authme;

			default:
		 		return null;
		 } 
	}

	/**
	 * Returns whether the row exists with a specific value exists in the database or not.
	 *
	 * @param string $column the column name
	 * @param string $value the username to check
	 * 
	 * @return bool true if the row exists; false otherwise
	 */
	private function __exists_in_database(string $column, string $value) {
		$mysqli = $this->get_mysqli();
		if ($mysqli == null) {
			return true;
		}

		$stmt = $mysqli->prepare('SELECT 1 FROM ' . $this->table_name . ' WHERE ' . $column . ' = ? LIMIT 1');
	
		$stmt->bind_param('s', $value);
		$stmt->execute();

		return $stmt->fetch();
	}

	/**
	 * Returns an offline UUID derived from the nickname.
	 *
	 * @param string $username the username to be used in generation
	 *
	 * @return string the UUID in undash format
	 */
	private function __create_offline_id(string $username) {
		$data = hex2bin(md5("OfflinePlayer:" . $username));
		$data[6] = chr(ord($data[6]) & 0x0f | 0x30);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return bin2hex($data);
	}

	/**
	 * Returns a connection to the database.
	 *
	 * @return mysqli|null the mysqli object or null upon error
	 */
	private function get_mysqli() {
		$mysqli = new mysqli($this->mysql_host, $this->mysql_user, $this->mysql_pass, $this->mysql_database);
		if (mysqli_connect_error()) {
			printf('Could not connect to ' . $this->mysql_database . ' database. Errno: %d, error: "%s"',
				mysqli_connect_errno(), mysqli_connect_error());
			return null;
		}
		return $mysqli;
	}
}
