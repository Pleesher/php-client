<?php
namespace Pleesher\Client\Cache;

// FIXME: not cross-DBMS at all... (backtick escaping + REPLACE INTO)
// FIXME: check query errors
class DatabaseStorage implements Storage
{
	protected $db;
	protected $cache_table_name;

	public function __construct(\PDO $db, $cache_table_name)
	{
		$this->db = $db;
		$this->cache_table_name = $cache_table_name;
	}

	public function save($user_id, $key, $id, $data)
	{
		$sql = 'REPLACE INTO ' . $this->cache_table_name . ' (`user_id`, `key`, `id`, `data`, `obsolete`) VALUES (:user_id, :key, :id, :data, 0)';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0, ':data' => json_encode($data));

		$query = $this->db->prepare($sql);
		$query->execute($params);
	}

	public function load($user_id, $key, $id = 0, $default = null)
	{
		$sql = 'SELECT data FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `id` = :id AND `obsolete` = 0';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0);

		$query = $this->db->prepare($sql);
		$query->execute($params);
		$result = $query->fetchAll(\PDO::FETCH_COLUMN);

		$data = reset($result);

		return !empty($data) ? json_decode($data) : $default;
	}

	public function loadAll($user_id, $key)
	{
		$sql = 'SELECT 1 FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `obsolete` = 1';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key);
		$query = $this->db->prepare($sql);
		$query->execute($params);

		if ($query->rowCount() > 0)
			return array();

		$sql = 'SELECT id, data FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key';
		$query = $this->db->prepare($sql);
		$query->execute($params);

		$result = array_map(function($row) {
			return $row['data'];
		}, $query->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC));

		return array_map('json_decode', $result);
	}

	public function refresh($user_id, $key, $id = 0)
	{
		$sql = 'UPDATE ' . $this->cache_table_name . ' SET obsolete = 1 WHERE `user_id` = :user_id AND `key` = :key AND id = :id';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0);

		$query = $this->db->prepare($sql);
		$query->execute($params);
	}

	public function refreshAll($user_id, $key = null)
	{
		if (isset($key))
		{
			$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key);
		}
		else
		{
			$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0);
		}

		$query = $this->db->prepare($sql);
		$query->execute($params);
	}

	public function refreshGlobally($key = null)
	{
		if (isset($key))
		{
			$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `key` = :key';
			$params = array(':key' => $key);
		}
		else
		{
			$sql = 'TRUNCATE TABLE ' . $this->cache_table_name;
			$params = array();
		}

		$query = $this->db->prepare($sql);
		$query->execute($params);
	}
}