<?php
namespace Pleesher\Client\Cache;

class LocalStorage implements Storage
{
	protected $fallbackStorage;
	protected $entries = array();
	protected $obsolete_keys = array();

	public function __construct(Storage $fallbackStorage = null)
	{
		$this->fallbackStorage = $fallbackStorage;
	}

	public function save($user_id, $key, $id, $data)
	{
		$unique_key = $key . '_' . (isset($user_id) ? $user_id : '0');
		unset($this->obsolete_keys[$unique_key . '_' . (isset($id) ? $id : '0')]);

		if (is_null($id))
			$this->entries[$unique_key] = $data;
		else
		{
			if (!isset($this->entries[$unique_key]))
				$this->entries[$unique_key] = array();
			$this->entries[$unique_key][$id] = $data;
		}

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->save($user_id, $key, $id, $data);
	}

	public function load($user_id, $key, $id = null, $default = null)
	{
		$unique_key = $key . '_' . (isset($user_id) ? $user_id : '0');
		$obsolete = !empty($this->obsolete_keys[$unique_key . '_' . (isset($id) ? $id : '0')]);

		if (is_null($id))
		{
			if (!$obsolete && isset($this->entries[$unique_key]))
				return $this->entries[$unique_key];
			if (isset($this->fallbackStorage))
				return $this->entries[$unique_key] = $this->fallbackStorage->load($user_id, $key, $id, $default);
		}

		else
		{
			if (!$obsolete && isset($this->entries[$unique_key][$id]))
				return $this->entries[$unique_key][$id];
			if (isset($this->fallbackStorage))
				return $this->entries[$unique_key][$id] = $this->fallbackStorage->load($user_id, $key, $id, $default);
		}

		return $default;
	}

	public function loadAll($user_id, $key)
	{
		$unique_key = $key . '_' . (isset($user_id) ? $user_id : '0');

		if (isset($this->entries[$unique_key]))
		{
			$obsolete = false;
			foreach (array_keys($this->entries[$unique_key]) as $id)
			{
				if (!empty($this->obsolete_keys[$unique_key . '_' . (isset($id) ? $id : '0')]))
				{
					$obsolete = true;
					break;
				}
			}

			if (!$obsolete)
				return $this->entries[$unique_key];
		}
		if (isset($this->fallbackStorage))
			return $this->entries[$unique_key] = $this->fallbackStorage->loadAll($user_id, $key);

		return array();
	}

	public function refresh($user_id, $key, $id = null)
	{
		$unique_key = $key . '_' . (isset($user_id) ? $user_id : '0') . '_' . (isset($id) ? $id : '0');

		$this->obsolete_keys[$unique_key] = true;
		if (isset($this->fallbackStorage))
			$this->fallbackStorage->refresh($user_id, $key, $id);
	}

	public function refreshAll($user_id, $key)
	{
		$unique_key = $key . '_' . (isset($user_id) ? $user_id : '0');
		unset($this->entries[$unique_key]);

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->refreshAll($user_id, $key);
	}
}