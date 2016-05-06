<?php
namespace Pleesher\Client\Cache;

// FIXME: not up to date...
// FIXME: store session key somewhere and/or have it customizable
class SessionStorage extends LocalStorage
{
	public function save($key, $id, $data)
	{
		parent::save($key, $id, $data);
		$_SESSION['pleesher_cache'] = $this->entries;
	}

	public function load($key, $id = null, $default = null)
	{
		$this->entries = isset($_SESSION['pleesher_cache']) ? $_SESSION['pleesher_cache'] : array();
		return parent::load($key, $id, $default);
	}

	public function refresh($key, $id = null)
	{
		parent::refresh($key, $id);
		$_SESSION['pleesher_cache'] = $this->entries;
	}
}