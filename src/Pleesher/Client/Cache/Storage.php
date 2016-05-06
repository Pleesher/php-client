<?php
namespace Pleesher\Client\Cache;

interface Storage
{
	function save($user_id, $key, $id, $data);
	function load($user_id, $key, $id);
	function loadAll($user_id, $key);
	function refresh($user_id, $key, $id);
	function refreshAll($user_id, $key);
}