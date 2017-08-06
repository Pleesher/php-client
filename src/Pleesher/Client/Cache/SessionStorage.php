<?php
namespace Pleesher\Client\Cache;

// FIXME: store session key somewhere and/or have it customizable
class SessionStorage extends LocalStorage
{
	public function save($user_id, $key, $id, $data)
	{
		parent::save($user_id, $key, $id, $data);
		$_SESSION['pleesher_cache']['entries'] = $this->entries;
		$_SESSION['pleesher_cache']['obsolete_keys'] = $this->obsolete_keys;
	}

	public function saveAll($user_id, $key, array $data)
	{
		parent::saveAll($user_id, $key, $data);
		$_SESSION['pleesher_cache']['entries'] = $this->entries;
		$_SESSION['pleesher_cache']['obsolete_keys'] = $this->obsolete_keys;
	}

	public function load($user_id, $key, $id = null, $default = null)
	{
		$this->entries = isset($_SESSION['pleesher_cache']['entries']) ? $_SESSION['pleesher_cache']['entries'] : array();
		$this->obsolete_keys = isset($_SESSION['pleesher_cache']['obsolete_keys']) ? $_SESSION['pleesher_cache']['obsolete_keys'] : array();
		return parent::load($user_id, $key, $id, $default);
	}

	public function loadAll($user_id, $key)
	{
		$this->entries = isset($_SESSION['pleesher_cache']['entries']) ? $_SESSION['pleesher_cache']['entries'] : array();
		$this->obsolete_keys = isset($_SESSION['pleesher_cache']['obsolete_keys']) ? $_SESSION['pleesher_cache']['obsolete_keys'] : array();
		return parent::loadAll($user_id, $key);
	}

	public function refresh($user_id, $key, $id = null)
	{
		parent::refresh($user_id, $key, $id);
		$_SESSION['pleesher_cache']['entries'] = $this->entries;
		$_SESSION['pleesher_cache']['obsolete_keys'] = $this->obsolete_keys;
	}

	public function refreshAll($user_id, $key = null)
	{
		parent::refreshAll($user_id, $key);
		$_SESSION['pleesher_cache']['entries'] = $this->entries;
		$_SESSION['pleesher_cache']['obsolete_keys'] = $this->obsolete_keys;
	}

	public function refreshGlobally($key = null)
	{
		parent::refreshGlobally(key);
		$_SESSION['pleesher_cache']['entries'] = $this->entries;
		$_SESSION['pleesher_cache']['obsolete_keys'] = $this->obsolete_keys;
	}
}