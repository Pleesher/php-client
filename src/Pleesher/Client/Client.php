<?php
namespace Pleesher\Client;

use Pleesher\Client\Exception\Exception;
use Pleesher\Client\Exception\NoSuchObjectException;
use Pleesher\Client\Exception\InvalidArgumentException;

/**
 *
 * @author Jérémy Touati
 */
class Client extends Oauth2Client
{
	const PARTICIPATION_STATUS_RESERVED              = 'reserved';
	const PARTICIPATION_STATUS_CLAIMED               = 'claimed';
	const PARTICIPATION_STATUS_ACHIEVED              = 'achieved';
	const PARTICIPATION_STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

	protected $exception_handler;
	protected $previous_exception_handler;

	protected $goal_checkers = array();
	protected $achievements_awarded_actions = array();
	protected $achievements_revoked_actions = array();

	public function __construct($client_id, $client_secret, $api_version = '1.0', array $options = array())
	{
		parent::__construct($client_id, $client_secret, $api_version, $options);
		$this->setExceptionHandler($this->getDefaultExceptionHandler());
	}

	public function setExceptionHandler(\Closure $handler)
	{
		$this->previous_exception_handler = $this->exception_handler;
		$this->exception_handler = $handler;
	}

	public function restoreExceptionHandler()
	{
		if (is_callable($this->previous_exception_handler))
			$this->exception_handler = $this->previous_exception_handler;
	}

	public function getDefaultExceptionHandler()
	{
		return function(Exception $e)	{
			throw $e;
		};
	}

	/**
	 * Binds a goal to its checker function
	 * @param string $goal_code
	 * @param \Closure $checker_function
	 */
	public function bindGoalChecker($goal_code, \Closure $checker_function, $context = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		$this->goal_checkers[$goal_code] = array($checker_function, $context);
	}

	/**
	 * Adds an action to be executed when achievements are awarded
	 * @param \Closure $action
	 */
	public function onAchievementsAwarded(\Closure $action)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$this->achievements_awarded_actions[] = $action;
	}

	/**
	 * Adds an action to be executed when achievements are revoked
	 * @param \Closure $action
	 */
	public function onAchievementsRevoked(\Closure $action)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$this->achievements_revoked_actions[] = $action;
	}

	/**
	 * Checks an user's achievements
	 * @param int $user_id
	 */

	public function checkAchievements($user_id, array $goal_codes = null, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$from_scratch = isset($options['from_scratch']) ? $options['from_scratch'] : false;
			$auto_award = isset($options['auto_award']) ? $options['auto_award'] : true;
			$auto_revoke = isset($options['auto_revoke']) ? $options['auto_revoke'] : false;

			if ($from_scratch)
				$this->cache_storage->refreshAll($user_id, 'goal_relative_to_user');

			$this->getGoals(array('user_id' => $user_id, 'auto_award' => $auto_award, 'auto_revoke' => $auto_revoke, 'force_recheck' => true));

		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	/**
	 * Retrieves information about all users who have interacted with Pleesher
	 */
	public function getUsers(array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			$cache_key = 'user';

			$users = $this->cache_storage->loadAll(null, $cache_key);

			if (!is_array($users))
			{
				$users = $this->call('GET', 'users');

				$cache_data = array();
				foreach ($users as $user)
					$cache_data[$user->id] = $user;
				$this->cache_storage->saveAll(null, $cache_key, $cache_data);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($users) || !is_array($users))
				$users = array();
		}

		return $users;
	}

	/**
	 * Retrieves information about the user
	 * @param int $user_id
	 */
	public function getUser($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$this->getGoals(array('user_id' => $user_id));

			$cache_key = 'user';
			$user = $this->cache_storage->load(null, $cache_key, $user_id);

			if (is_null($user))
			{
				$user = $this->call('GET', 'user', array('user_id' => (int)$user_id));
				$this->cache_storage->save(null, $cache_key, $user_id, $user);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($user) || !is_object($user))
				$user = null;
		}

		return $user;
	}

	/**
	 * Retrieves the list of existing goals
	 * @param array $options
	 */
	public function getGoals(array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			$user_id = isset($options['user_id']) ? $options['user_id'] : null;
			$auto_award = isset($options['auto_award']) ? $options['auto_award'] : false;
			$auto_revoke = isset($options['auto_revoke']) ? $options['auto_revoke'] : false;
			$force_recheck = isset($options['force_recheck']) ? $options['force_recheck'] : false;
			$check_achievements = !is_null($user_id) && ($force_recheck || $auto_award || $auto_revoke);

			$cache_key = isset($user_id) ? 'goal_relative_to_user' : 'goal';

			$goals = $this->cache_storage->loadAll($user_id, $cache_key);
			$goals_in_cache = is_array($goals);

			$awarded_goal_codes = array();
			$revoked_goal_codes = array();

			if (!$goals_in_cache)
			{
				$data = array();
				if (isset($user_id))
					$data['user_id'] = (int)$user_id;

				$goals = $this->call('GET', 'goals', $data);
			}

			$cache_data = array();
			foreach ($goals as $goal)
			{
				if ($user_id && (!$goals_in_cache || $force_recheck))
				{
					$goal = $this->computeGoalProgress($goal, $user_id, array('force_compute' => !$goals_in_cache, 'auto_award' => $auto_award, 'auto_revoke' => $auto_revoke));
					if (!empty($goal->code))
					{
						if (!empty($goal->just_awarded))
						{
							$awarded_goal_codes[] = $goal->code;
							unset($goal->just_awarded);
						}
						else if (!empty($goal->just_revoked))
						{
							$revoked_goal_codes[] = $goal->code;
							unset($goal->just_revoked);
						}
					}
				}

				$cache_data[$goal->id] = $goal;
			}

			if (!$goals_in_cache || $check_achievements)
				$this->cache_storage->saveAll($user_id, $cache_key, $cache_data);

			if (count($awarded_goal_codes) > 0 || count($revoked_goal_codes) > 0)
			{
				if (count($awarded_goal_codes) > 0)
					$this->fireAchievementsAwarded($awarded_goal_codes);
				if (count($revoked_goal_codes) > 0)
					$this->fireAchievementsRevoked($revoked_goal_codes);
			}

			$goals = $this->cache_storage->loadAll($user_id, $cache_key);

			$index_by = isset($options['index_by']) ? $options['index_by'] : null;
			switch ($index_by)
			{
				case 'id':
					$indexed_goals = array();
					foreach ($goals as $goal)
						$indexed_goals[$goal->id] = $goal;
					$goals = $indexed_goals;
					break;
				case 'code':
					$indexed_goals = array();
					foreach ($goals as $goal)
					{
						if (isset($goal->code))
							$indexed_goals[$goal->code] = $goal;
					}
					$goals = $indexed_goals;
					break;
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($goals) || !is_array($goals))
				$goals = array();
		}

		return $goals;
	}

	/**
	 * Retrieves a goal based on its unique code or ID
	 * @param int|string $goal_id_or_code
	 * @param array $options
	 */
	public function getGoal($goal_id_or_code, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($goal_id_or_code))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id_or_code');

			$goals = $this->getGoals(array_merge($options, array('index_by' => is_int($goal_id_or_code) ? 'id' : 'code')));
			if (!isset($goals[$goal_id_or_code]))
				throw new NoSuchObjectException(sprintf('No goal with ID or code "%s"', $goal_id_or_code), 'no_such_goal', array('goal_id_or_code' => $goal_id_or_code));

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($goals))
				$goals = array();
		}

		return isset($goals[$goal_id_or_code]) ? $goals[$goal_id_or_code] : null;
	}

	/**
	 * Retrieves the list of goals achieved by a given user
	 * @param int $user_id
	 */
	public function getAchievements($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$goals = $this->getGoals(array('user_id' => $user_id, 'auto_award' => true));

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($goals))
				$goals = array();
		}

		return array_filter($goals, function($goal) {
			return $goal->achieved;
		});
	}

	/**
	 * Retrieves a list of participations (reservations, claims, achievements), given optional filters
	 * @param array $filters
	 */
	public function getParticipations(array $filters = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (isset($filters['status']))
				$params = array('status' => $filters['status']);
			if (isset($filters['max_age']))
				$params = array('max_age' => $filters['max_age']);

			$cache_key = 'participations_'
				. (isset($filters['status']) ? $filters['status'] : 'anystatus') . '_'
				. (isset($filters['max_age']) ? $filters['max_age'] : 'anymaxage');

			$participations = $this->cache_storage->load(null, $cache_key, null);

			$awarded_goal_codes = array();
			$revoked_goal_codes = array();
			if (!is_array($participations))
			{
				$participations = $this->call('GET', 'participations', $params);
				$this->cache_storage->save(null, $cache_key, null, $participations);
			}

			foreach ($participations as $participation)
				$participation->datetime = new \DateTime($participation->datetime->date, new \DateTimeZone($participation->datetime->timezone));

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($participations) || !is_array($participations))
				$participations = array();
		}

		return $participations;
	}

	/**
	 * Retrieves the list of achievers for a given goal
	 * @param array $options
	 */
	public function getAchievers($goal_id_or_code, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($goal_id_or_code))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id_or_code');

			$goal = $this->getGoal($goal_id_or_code);

			$cache_key = 'achievers_of_' . $goal->id;

			$achievers = $this->cache_storage->load(null, $cache_key, null);

			if (!is_array($achievers))
			{
				$achievers = $this->call('GET', 'achievers', array('goal_id' => $goal->id));
				$this->cache_storage->save(null, $cache_key, null, $achievers);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($achievers) || !is_array($achievers))
				$achievers = array();
		}

		return $achievers;
	}

	/**
	 * Checks whether a given user has achieved a given goal
	 * @param int $user_id
	 * @param int|string $goal_id
	 */
	public function hasAchievedGoal($user_id, $goal_id_or_code)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_id_or_code))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id_or_code');

			$goal = $this->getGoal($goal_id_or_code, array('user_id' => $user_id));

		} catch (Exception $e) {
			$this->handleException($e);
			return false;
		}

		return $goal->achieved;
	}

	/**
	 * Retrieves the list of available rewards
	 * @param array $options
	 */
	public function getRewards(array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			$user_id = isset($options['user_id']) ? $options['user_id'] : null;
			$cache_key = 'reward';

			$rewards = $this->cache_storage->loadAll($user_id, $cache_key);

			if (!is_array($rewards))
			{
				$data = array();
				if (isset($user_id))
					$data['user_id'] = (int)$user_id;

				$rewards = $this->call('GET', 'rewards', $data);

				$cache_data = array();
				foreach ($rewards as $reward)
					$cache_data[$reward->id] = $reward;
				$this->cache_storage->saveAll($user_id, $cache_key, $cache_data);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($rewards) || !is_array($rewards))
				$rewards = array();
		}

		return $rewards;
	}

	/**
	 * Retrieves a reward based on its unique ID
	 * @param int $reward_id
	 * @param array $options
	 */
	public function getReward($reward_id, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($reward_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $reward_id');

			$user_id = isset($options['user_id']) ? $options['user_id'] : null;
			$cache_key = 'reward';

			$reward = $this->cache_storage->load($user_id, $cache_key, $reward_id);

			if (is_null($reward))
			{
				$data = array('reward_id' => $reward_id);
				if (isset($user_id))
					$data['user_id'] = (int)$user_id;

				$reward = $this->call('GET', 'reward', $data);
				$this->cache_storage->save($user_id, $cache_key, $reward->id, $reward);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($reward) || !is_object($reward))
				$reward = null;
		}

		return $reward;
	}

	/**
	 *
	 * @param int $reward_id
	 * @param int $user_id
	 */
	public function unlockReward($reward_id, $user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($reward_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $reward_id');
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$result = $this->call('POST', 'unlock_reward', array(
				'reward_id' => $reward_id,
				'user_id' => $user_id
			));

			$this->cache_storage->refresh(null, 'user', $user_id);
			$this->cache_storage->refresh(null, 'reward', $reward_id);

			return $result == 'ok';

		} catch (Exception $e) {
			$this->handleException($e);
			return false;
		}

		return false;
	}

	/**
	 *
	 * @param int $user_id
	 * @param int $reward_id
	 */
	public function hasUnlockedReward($user_id, $reward_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($reward_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $reward_id');

			$reward = $this->getReward($reward_id, array('user_id' => $user_id));

		} catch (Exception $e) {
			$this->handleException($e);
			return false;
		}

		return !!$reward->unlocked;
	}

	/**
	 * @param int $user_id
	 * @param array|int|string $goal_ids_or_codes
	 */
	public function award($user_id, $goal_ids_or_codes)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_ids_or_codes))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_ids_or_codes');

			$participations = $this->call('POST', 'award', array('user_id' => $user_id, 'goal_ids' => $goal_ids_or_codes));

			// FIXME: why not use goal_ids from $participations instead of using getGoalId?
			foreach ((array)$goal_ids_or_codes as $goal_id_or_code)
			{
				$goal_id = $this->getGoalId($goal_id_or_code);
				$this->cache_storage->refresh($user_id, 'goal_relative_to_user', $goal_id);
				$this->cache_storage->refresh(null, 'achievers_of_' . $goal_id, null);
			}

			$this->cache_storage->refresh(null, 'user', $user_id);
			$this->cache_storage->refresh(null, 'participations_*', null);
			$this->cache_storage->refreshAll($user_id, 'notification');

		} catch (Exception $e) {
			$this->handleException($e);
			$participations = array();
		}

		return $participations;
	}

	/**
	 * @param int $user_id
	 * @param array|int|string $goal_ids_or_codes
	 */
	public function revoke($user_id, $goal_ids_or_codes, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_ids_or_codes))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_ids_or_codes');

			$params = array('user_id' => $user_id, 'goal_ids' => $goal_ids_or_codes, 'duration' => isset($options['duration']) ? $options['duration'] : null);

			$result = $this->call('POST', 'revoke', $params);

			// FIXME: have revoke return the revoked participations and do the same as in award() (see related FIXME)
			foreach ((array)$goal_ids_or_codes as $goal_id_or_code)
			{
				$goal_id = $this->getGoalId($goal_id_or_code);
				$this->cache_storage->refresh($user_id, 'goal_relative_to_user', $goal_id);
				$this->cache_storage->refresh(null, 'achievers_of_' . $goal_id, null);
			}

			$this->cache_storage->refresh(null, 'user', $user_id);
			$this->cache_storage->refresh(null, 'participations_*', null);
			$this->cache_storage->refreshAll($user_id, 'notification');

		} catch (Exception $e) {
			$this->handleException($e);
			$result = false;
		}

		return $result == 'ok';
	}

	/**
	 * @param int $user_id
	 * @param int|string $goal_id_or_code
	 */
	public function denyAchievement($user_id, $goal_id_or_code)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_id_or_code))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id_or_code');

			$result = $this->call('POST', 'deny', array('user_id' => $user_id, 'goal_id' => $goal_id_or_code));

			$goal = $this->getGoal($goal_id_or_code);
			$this->cache_storage->refresh($user_id, 'goal', $goal->id);

		} catch (Exception $e) {
			$this->handleException($e);
			$result = false;
		}

		return $result;
	}

	public function claimAchievement($user_id, $goal_id_or_code, $message = null)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_id_or_code))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id_or_code');

			$participation = $this->call('POST', 'claim', array('user_id' => $user_id, 'goal_id' => $goal_id_or_code, 'message' => $message));

			$goal = $this->getGoal($goal_id_or_code);
			$this->cache_storage->refresh($user_id, 'goal', $goal->id);

		} catch (Exception $e) {
			$this->handleException($e);
			$participation = null;
		}

		return $participation;
	}

	/**
	 *
	 * @param int $user_id
	 */
	public function getNotifications($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$cache_key = 'notification';
			$notifications = $this->cache_storage->loadAll($user_id, $cache_key);

			if (!is_array($notifications))
			{
				$notifications = $this->call('GET', 'notifications', array('user_id' => $user_id));
				$cache_data = array();
				foreach ($notifications as $notification)
					$cache_data[$notification->id] = $notification;

				$this->cache_storage->saveAll($user_id, $cache_key, $cache_data);
			}

		} catch (Exception $e) {
			$this->handleException($e);
		}

		return $notifications;
	}

	public function getClaims($user_id = null)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$claims = $this->call('GET', 'claims', array('user_id' => $user_id));

		} catch (Exception $e) {
			$this->handleException($e);
			$claims = array();
		}

		return $claims;
	}

	public function confirmAchievement($user_id, $goal_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($goal_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $goal_id');

			$participations = $this->call('POST', 'award', array('user_id' => $user_id, 'goal_ids' => array($goal_id)));

		} catch (Exception $e) {
			$this->handleException($e);
			$participations = array();
		}

		return $participations;
	}

	/**
	 *
	 * @param int $user_id
	 * @param int|array $event_ids
	 */
	public function markNotificationsRead($user_id, $event_ids)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');
			if (empty($event_ids))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $event_ids');

			$result = $this->call('POST', 'mark_notifications_read', array('user_id' => $user_id, 'event_ids' => (array)$event_ids));
			$this->cache_storage->refreshAll($user_id, 'notification');

		} catch (Exception $e) {
			$this->handleException($e);
			$result = false;
		}

		return $result == 'ok';
	}

	/**
	 * @param unknown $user_id
	 * @return string
	 */
	public function getUserMergeUrl($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($user_id))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $user_id');

			$result = $this->call('GET', 'user_merge_url', array('user_id' => $user_id));
			$this->cache_storage->refreshAll($user_id, 'notification');

		} catch (Exception $e) {
			$this->handleException($e);
			$result = null;
		}

		return $result;
	}

	/**
	 * @param string $object_type
	 * @param int $object_id
	 * @param int $user_id
	 * @param string $key
	 * @return mixed
	 */
	public function getObjectData($object_type, $object_id, $user_id, $key)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($object_type))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $object_type');

			$cache_key = 'object_data_' . $object_type . '_' . ($key ?: 'anykey');

			if (isset($object_id))
			{
				$data = $this->cache_storage->load($user_id, $cache_key, $object_id);
				if (is_null($data))
				{
					$data = $this->call('GET', 'object_data', array('object_type' => $object_type, 'object_id' => $object_id, 'user_id' => $user_id, 'key' => $key));
					$this->cache_storage->save($user_id, $cache_key, $object_id, $data);
				}
			}
			else
			{
				$data = $this->cache_storage->loadAll($user_id, $cache_key);
				if (!is_array($data))
				{
					$data = (array)$this->call('GET', 'object_data', array('object_type' => $object_type, 'object_id' => $object_id, 'user_id' => $user_id, 'key' => $key));
					$_data = array();
					foreach ($data as $_object_id => $_value)
						$_data[(int)$_object_id] = $_value;
					$this->cache_storage->saveAll($user_id, $cache_key, $_data);
					$data = $_data;
				}
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($data) || !is_array($data))
				$data = array();
		}

		return $data;
	}

	/**
	 * @param string $object_type
	 * @param int $object_id
	 * @param int $user_id
	 * @param string $key
	 * @param string $value
	 * @return mixed
	 */
	public function setObjectData($object_type, $object_id, $user_id, $key, $value)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($object_type))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $object_type');

			$cache_key = 'object_data_' . $object_type . '_' . ($key ?: 'anykey');

			$result = $this->call('POST', 'object_data', array('object_type' => $object_type, 'object_id' => $object_id, 'user_id' => $user_id, 'key' => $key, 'value' => $value));
			$this->cache_storage->save($user_id, $cache_key, $object_id, $value);

		} catch (Exception $e) {
			$this->handleException($e);
			$result = false;
		}

		return $result == 'ok';
	}

	/**
	 * @param string $object_type
	 * @param int $object_id
	 * @param int $user_id
	 * @param string $key
	 * @return mixed
	 */
	public function deleteObjectData($object_type, $object_id, $user_id, $key)
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			if (empty($object_type))
				throw new InvalidArgumentException(__METHOD__ . ' was called with an empty $object_type');

			$cache_key = 'object_data_' . $object_type . '_' . ($key ?: 'anykey');

			$result = $this->call('DELETE', 'object_data', array('object_type' => $object_type, 'object_id' => $object_id, 'user_id' => $user_id, 'key' => $key));
			$this->cache_storage->refresh($user_id, $cache_key, $object_id);

		} catch (Exception $e) {
			$this->handleException($e);
			$result = false;
		}

		return $result == 'ok';
	}

	public function refreshCache($user_id, $keys = null)
	{
		if (is_null($keys))
			$this->cache_storage->refreshAll($user_id);

		foreach ((array)$keys as $key)
			$this->cache_storage->refreshAll($user_id, $key);
	}

	public function refreshCacheGlobally($keys = null)
	{
		if (is_null($keys))
			$this->cache_storage->refreshGlobally();

		foreach ((array)$keys as $key)
			$this->cache_storage->refreshGlobally($key);
	}

	protected function handleException(Exception $e)
	{
		$this->in_error = true;
		$this->logger->error($e->__toString(), $e->getTrace());
		call_user_func($this->exception_handler, $e);
	}

	protected function getRootUrl()
	{
		return 'https://pleesher.com/api';
	}

	/**
	 * @see Oauth2Client::getAccessToken()
	 */
	protected function getAccessToken()
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			$access_token = $this->cache_storage->load(null, 'access_token');

			if (is_null($access_token) || time() > $access_token->expiration_time)
			{
				$access_token = $this->getResultContents($this->curl(array(
					CURLOPT_HEADER => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_URL => $this->getRootUrl() . '/token',
					CURLOPT_CUSTOMREQUEST => 'POST',
					CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
					CURLOPT_USERPWD => $this->client_id . ':' . $this->client_secret,
					CURLOPT_POSTFIELDS => html_entity_decode(http_build_query(array('grant_type' => 'client_credentials')))
				)));

				if (!isset($access_token->access_token, $access_token->expires_in))
					throw new Exception('Could not retrieve access token');

				$access_token->expiration_time = time() + $access_token->expires_in;
				$this->cache_storage->save(null, 'access_token', null, $access_token);
			}

		} catch (Exception $e) {
			$this->handleException($e);
			if (!isset($access_token) || !is_object($access_token))
				$access_token = null;
		}

		return $access_token;
	}

	/**
	 * @see \Pleesher\Client\Oauth2Client::refreshAccessToken()
	 */
	protected function refreshAccessToken()
	{
		$this->cache_storage->refresh(null, 'access_token');
	}

	/**
	 *
	 * @param unknown $goal_codes
	 */
	protected function fireAchievementsAwarded($goal_codes)
	{
		$this->logger->info(__METHOD__, func_get_args());

		foreach ($this->achievements_awarded_actions as $action)
			$action($goal_codes);
	}

	/**
	 *
	 * @param unknown $goal_codes
	 */
	protected function fireAchievementsRevoked($goal_codes)
	{
		$this->logger->info(__METHOD__, func_get_args());

		foreach ($this->achievements_revoked_actions as $action)
			$action($goal_codes);
	}

	/**
	 * @param object $goal A goal whose progress to compute
	 * @param int $user_id The ID of the user for whom that goal's progress is to compute
	 * @param array $options May contain:
	 *                       force_compute (boolean, default to false): if true, always computes goal status/progress
	 *                       auto_award (boolean, default to true): if true, calls award() on newly-achieved goals
	 *                       auto_revoke (boolean, default to true): if true, calls revoke() on newly-revoked goals
	 * @return object A goal with relevant properties 'achieved', 'participation' and/or 'progress'
	 */
	protected function computeGoalProgress($goal, $user_id, array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		if (isset($this->goal_checkers[$goal->code]))
		{
			$force_compute = isset($options['force_compute']) ? $options['force_compute'] : false;
			$auto_award = isset($options['auto_award']) ? $options['auto_award'] : true;
			$auto_revoke = isset($options['auto_revoke']) ? $options['auto_revoke'] : true;

			$goal_was_achieved = isset($goal->participation) && $goal->participation->status == 'achieved';

			if ($force_compute || (!$goal_was_achieved && $auto_award) || ($goal_was_achieved && $auto_revoke))
			{
				list($checker_function, $context) = $this->goal_checkers[$goal->code];
				$goal_progress = $checker_function($goal, $user_id, $context);
				if (is_array($goal_progress))
				{
					list($current, $target) = $goal_progress;
					$goal->progress = new \stdClass();
					$goal->progress->current = $current;
					$goal->progress->target = $target;
					$goal->achieved = $current >= $target;
				}
				else
					$goal->achieved = $goal_progress;

				if (!$goal_was_achieved && $goal->achieved)
				{
					$participations = $this->award($user_id, array($goal->id));
					$participation = reset($participations) ?: null;

					if (is_object($participation))
					{
						$goal->participation = $participation;
						$goal->achieved = $participation->status == 'achieved';
						unset($goal->participation->goal);
					}
					else
						$goal->achieved = false;

					$goal->just_awarded = true;
				}
				else if ($goal_was_achieved && !$goal->achieved)
				{
					$this->revoke($user_id, array($goal->id));
					unset($goal->participation);
					$goal->just_revoked = true;
				}
			}
			else
				$goal->achieved = $goal_was_achieved;
		}
		else
			$goal->achieved = isset($goal->participation) && $goal->participation->status == 'achieved';

		return $goal;
	}

	protected function getGoalId($goal_id_or_code)
	{
		if (is_int($goal_id_or_code))
			return $goal_id_or_code;

		$this->setExceptionHandler($this->getDefaultExceptionHandler());
		try {
			$goal = $this->getGoal($goal_id_or_code);
		} catch (NoSuchObjectException $e) {
			$this->restoreExceptionHandler();
			$this->cache_storage->refreshAll(null, 'goal');
			$goal = $this->getGoal($goal_id_or_code);
		}
		$this->restoreExceptionHandler();

		return $goal->id;
	}
}
