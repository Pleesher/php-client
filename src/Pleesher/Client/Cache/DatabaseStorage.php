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
		$this->db->beginTransaction();

		$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `id` = 0 AND `data`= :data';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':data' => Storage::EMPTY_ARRAY_STRING);
		$query = $this->db->prepare($sql);
		$query->execute($params);

		$sql = 'REPLACE INTO ' . $this->cache_table_name . ' (`user_id`, `key`, `id`, `data`, `obsolete`) VALUES (:user_id, :key, :id, :data, 0)';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0, ':data' => json_encode($data));
		$query = $this->db->prepare($sql);
		$query->execute($params);

		$this->db->commit();
	}

	public function saveAll($user_id, $key, array $data)
	{
		$this->db->beginTransaction();

		$tuples = array();
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key);

		$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key';
		$query = $this->db->prepare($sql);
		$query->execute($params);

		if (count($data) > 0)
		{
			$number = 0;
			foreach ($data as $id => $instance_data)
			{
				$tuples[] = '(:user_id, :key, :id' . $number . ', :data' . $number . ', 0)';
				$params = array_merge($params, array(':id' . $number => $id, ':data' . $number => json_encode($instance_data)));
				$number++;
			}

			$sql = 'INSERT INTO ' . $this->cache_table_name . ' (`user_id`, `key`, `id`, `data`, `obsolete`) VALUES ' . join(', ', $tuples);
		}
		else
		{
			$sql = 'INSERT INTO ' . $this->cache_table_name . ' (`user_id`, `key`, `id`, `data`, `obsolete`) VALUES (:user_id, :key, 0, :data, 0)';
			$params = array_merge($params, array(':data' => Storage::EMPTY_ARRAY_STRING));
		}

		$query = $this->db->prepare($sql);
		$query->execute($params);

		$this->db->commit();
	}

	public function load($user_id, $key, $id = 0, $default = null)
	{
		$sql = 'SELECT data FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `id` = :id AND `obsolete` = 0';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0);

		$query = $this->db->prepare($sql);
		$query->execute($params);
		$result = $query->fetchAll(\PDO::FETCH_COLUMN);

		$data = reset($result);

		return !empty($data) && !($id == 0 && $data == Storage::EMPTY_ARRAY_STRING) ? json_decode($data) : $default;
	}

	public function loadAll($user_id, $key)
	{
		$sql = 'SELECT 1 FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `obsolete` = 1';
		$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key);
		$query = $this->db->prepare($sql);
		$query->execute($params);

		if ($query->rowCount() > 0)
			return null;

		$sql = 'SELECT data FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `id` = 0';
		$query = $this->db->prepare($sql);
		$query->execute($params);

		$result = $query->fetchAll(\PDO::FETCH_COLUMN);
		$data = reset($result);
		if ($data == Storage::EMPTY_ARRAY_STRING)
			return array();

		$sql = 'SELECT id, data FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key';
		$query = $this->db->prepare($sql);
		$query->execute($params);

		$result = $query->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);
		if (count($result) == 0)
			return null;

		$result = array_map(function($row) {
			return $row['data'];
		}, $result);

		return array_map('json_decode', $result);
	}

	public function refresh($user_id, $key, $id = 0)
	{
		if (strpos($key, '*') === false)
		{
			$sql = 'SELECT 1 FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` = :key AND `id` = :id';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0);
			$query = $this->db->prepare($sql);
			$query->execute($params);
			$insert_mode = $query->rowCount() == 0;
		}
		else
			$insert_mode = false;

		if ($insert_mode)
		{
			$sql = 'INSERT INTO ' . $this->cache_table_name . ' (`user_id`, `key`, `id`, `data`, `obsolete`) VALUES (:user_id, :key, :id, :data, 1)';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => $key, ':id' => isset($id) ? $id : 0, ':data' => Storage::TO_BE_FETCHED_STRING);
		}
		else
		{
			$sql = 'UPDATE ' . $this->cache_table_name . ' SET obsolete = 1 WHERE `user_id` = :user_id AND `key` LIKE :key AND id = :id';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => str_replace('*', '%', $key), ':id' => isset($id) ? $id : 0);
		}

		$query = $this->db->prepare($sql);
		$query->execute($params);
	}

	public function refreshAll($user_id, $key = null)
	{
		if (isset($key))
		{
			$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `user_id` = :user_id AND `key` LIKE :key';
			$params = array(':user_id' => isset($user_id) ? $user_id : 0, ':key' => str_replace('*', '%', $key));
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
			$sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE `key` LIKE :key';
			$params = array(':key' => str_replace('*', '%', $key));
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