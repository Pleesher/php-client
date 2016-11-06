<?php
namespace Pleesher\Client\Cache;

class LocalStorage implements Storage
{
	const KEY_SEPARATOR = '##__##';

	protected $fallbackStorage;
	protected $entries = array();
	protected $obsolete_keys = array();

	public function __construct(Storage $fallbackStorage = null)
	{
		$this->fallbackStorage = $fallbackStorage;
	}

	public function save($user_id, $key, $id, $data)
	{
		$unique_key = $key . self::KEY_SEPARATOR . (isset($user_id) ? $user_id : '0');
		unset($this->obsolete_keys[$unique_key . self::KEY_SEPARATOR . (isset($id) ? $id : '0')]);

		if (!isset($this->entries[$unique_key]))
			$this->entries[$unique_key] = array();
		$this->entries[$unique_key][isset($id) ? $id : 0] = $data;

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->save($user_id, $key, $id, $data);
	}

	public function load($user_id, $key, $id = null, $default = null)
	{
		$unique_key = $key . self::KEY_SEPARATOR . (isset($user_id) ? $user_id : '0');
		$obsolete = !empty($this->obsolete_keys[$unique_key . self::KEY_SEPARATOR . (isset($id) ? $id : '0')]);

		if (!$obsolete && isset($this->entries[$unique_key][isset($id) ? $id : 0]))
			return $this->entries[$unique_key][isset($id) ? $id : 0];
		if (isset($this->fallbackStorage))
			return $this->entries[$unique_key][isset($id) ? $id : 0] = $this->fallbackStorage->load($user_id, $key, $id, $default);

		return $default;
	}

	public function loadAll($user_id, $key)
	{
		$unique_key = $key . self::KEY_SEPARATOR . (isset($user_id) ? $user_id : '0');

		if (isset($this->entries[$unique_key]))
		{
			$obsolete = false;
			foreach (array_keys($this->entries[$unique_key]) as $id)
			{
				if (!empty($this->obsolete_keys[$unique_key . self::KEY_SEPARATOR . (isset($id) ? $id : '0')]))
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
		if (strpos($key, '*') === false)
		{
			$unique_key = $key . self::KEY_SEPARATOR . (isset($user_id) ? $user_id : '0') . self::KEY_SEPARATOR . (isset($id) ? $id : '0');
			$this->obsolete_keys[$unique_key] = true;
		}
		else
		{
			foreach ($this->entries as $_unique_key => $_sub_entries)
			{
				list($_key, $_user_id) = explode(self::KEY_SEPARATOR, $_unique_key);
				foreach (array_keys($_sub_entries) as $_id)
				{
					if (($user_id ?: '0') == $_user_id && $this->keyMatches($key, $_key) && ($id ?: '0') == $_id)
						$this->obsolete_keys[$_unique_key . self::KEY_SEPARATOR . $_id] = true;
				}
			}
		}

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->refresh($user_id, $key, $id);
	}

	public function refreshAll($user_id, $key = null)
	{
		foreach (array_keys($this->entries) as $_unique_key)
		{
			list($_key, $_user_id) = explode(self::KEY_SEPARATOR, $_unique_key);

			if (($user_id ?: '0') == $_user_id)
			{
				if (!isset($key) || (isset($key) && $this->keyMatches($key, $_key)))
					unset($this->entries[$_unique_key]);
			}
		}

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->refreshAll($user_id, $key);
	}

	public function refreshGlobally($key = null)
	{
		if (isset($key))
		{
			foreach (array_keys($this->entries) as $_unique_key)
			{
				list($_key, $_user_id) = explode(self::KEY_SEPARATOR, $_unique_key);
				if ($_user_id == $user_id && $this->keyMatches($key, $_key))
					unset($this->entries[$_unique_key]);
			}
		}
		else
			$this->entries = array();

		if (isset($this->fallbackStorage))
			$this->fallbackStorage->refreshGlobally($key);
	}

	protected function keyMatches($key_pattern, $actual_key)
	{
		if (strpos($key_pattern, '*') === false)
			return $key_pattern == $actual_key;

		return preg_match('/^' . str_replace('\*', '.*?', preg_quote($key_pattern)) . '$/', $actual_key);
	}
}