<?php
namespace Pleesher\Client\Cache;

// FIXME: not up to date...
// FIXME: store session key somewhere and/or have it customizable
class SessionStorage extends LocalStorage
{
	public function save($user_id, $key, $id, $data)
	{
		parent::save($user_id, $key, $id, $data);
		$_SESSION['pleesher_cache'] = $this->entries;
	}

	public function load($user_id, $key, $id = null, $default = null)
	{
		$this->entries = isset($_SESSION['pleesher_cache']) ? $_SESSION['pleesher_cache'] : array();
		return parent::load($user_id, $key, $id, $default);
	}

	public function refresh($user_id, $key, $id = null)
	{
		parent::refresh($user_id, $key, $id);
		$_SESSION['pleesher_cache'] = $this->entries;
	}
}