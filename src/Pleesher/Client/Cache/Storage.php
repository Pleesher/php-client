<?php
namespace Pleesher\Client\Cache;

interface Storage
{
	const EMPTY_ARRAY_STRING = '_##_EMPTY_ARRAY_##_';
	const TO_BE_FETCHED_STRING = '_##_TO_BE_FETCHED_##_';

	function setScope($scope);
	function save($user_id, $key, $id, $data);
	function saveAll($user_id, $key, array $data);
	function load($user_id, $key, $id);
	function loadAll($user_id, $key);
	function refresh($user_id, $key, $id);
	function refreshAll($user_id, $key);
	function refreshGlobally($key);
}