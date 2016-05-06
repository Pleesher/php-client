<?php
namespace Pleesher\Client;

/**
 *
 * @author Jérémy Touati
 */
class Client extends Oauth2Client
{
	protected $goal_checkers = array();
	protected $achievements_awarded_actions = array();

	/**
	 * Binds a goal to its checker function
	 * @param string $goal_code
	 * @param \Closure $checker_function
	 */
	public function bindGoalChecker($goal_code, \Closure $checker_function)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$this->goal_checkers[$goal_code] = $checker_function;
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
	 * Checks an user's achievements
	 * @param int $user_id
	 */
	public function checkAchievements($user_id, array $goal_codes = null)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$goal_ids = array();

		$this->cache_storage->refreshAll($user_id, 'goal');
		foreach ($this->getGoals(array('user_id' => $user_id)) as $goal)
		{
			if (is_array($goal_codes) && !in_array($goal->code, $goal_codes))
				continue;

			if ($goal->achieved)
				continue;

			if (!isset($this->goal_checkers[$goal->code]))
				continue;

			$check_result = $this->goal_checkers[$goal->code]($goal, $user_id);
			if (is_array($check_result) && count($check_result) == 2)
			{
				list($current, $target) = $check_result;
				$check_result = $current >= $target;
			}
			if ($check_result)
				$goal_ids[] = $goal->id;
		}

		$awarded_goal_ids = $goal_ids;	// FIXME: check the result of award, need exception system first
		if (count($goal_ids) > 0)
			$this->award($user_id, $goal_ids);

		if (count($awarded_goal_ids) > 0)
		{
			$this->cache_storage->refreshAll($user_id, 'notification');
			$this->cache_storage->refresh($user_id, 'user', $user_id);
			$this->fireAchievementsAwarded($awarded_goal_ids);
		}
	}

	/**
	 * Retrieves information about the user
	 * @param int $user_id
	 */
	public function getUser($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$cache_key = 'user';

		$user = $this->cache_storage->load($user_id, $cache_key, $user_id);

		if (is_null($user))
		{
			$user = $this->call('GET', 'user', array('user_id' => (int)$user_id));
			$this->cache_storage->save($user_id, $cache_key, $user_id, $user);
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

		$user_id = isset($options['user_id']) ? $options['user_id'] : null;
		$cache_key = 'goal';

		$goals = $this->cache_storage->loadAll($user_id, $cache_key);

		if (count($goals) == 0)
		{
			$data = array();
			if (isset($user_id))
				$data['user_id'] = (int)$user_id;

			$goals = $this->call('GET', 'goals', $data);

			foreach ($goals as $goal)
			{
				if (isset($user_id))
					$goal = $this->computeGoalProgress($goal, $user_id);
				$this->cache_storage->save($user_id, $cache_key, $goal->id, $goal);
			}
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

		$user_id = isset($options['user_id']) ? $options['user_id'] : null;
		$cache_key = 'goal';

		$goal = $this->cache_storage->load($user_id, $cache_key, $goal_id_or_code);

		if (is_null($goal))
		{
			$data = array('goal_id' => $goal_id_or_code);
			if (isset($user_id))
				$data['user_id'] = (int)$user_id;

			$goal = $this->call('GET', 'goal', $data);
			$this->cache_storage->save($user_id, $cache_key, $goal->id, $goal);
		}

		if (isset($user_id))
			$goal = $this->computeGoalProgress($goal, $user_id);

		return $goal;
	}

	public function getAchievements($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$goals = $this->getGoals(array('user_id' => $user_id));
		return array_filter($goals, function($goal) {
			return $goal->achieved;
		});
	}

	/**
	 * Checks whether a given user has achieved a given goal
	 * @param int $user_id
	 * @param int|string $goal_id
	 */
	public function hasAchievedGoal($user_id, $goal_id_or_code)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$goal = $this->getGoal($goal_id_or_code, array('user_id' => $user_id));
		return $goal->achieved;
	}

	/**
	 * Retrieves the list of available rewards
	 * @param array $options
	 */
	public function getRewards(array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		$user_id = isset($options['user_id']) ? $options['user_id'] : null;
		$cache_key = 'reward';

		$rewards = $this->cache_storage->loadAll($user_id, $cache_key);

		if (count($rewards) == 0)
		{
			$data = array();
			if (isset($user_id))
				$data['user_id'] = (int)$user_id;

			$rewards = $this->call('GET', 'rewards', $data);

			foreach ($rewards as $reward)
				$this->cache_storage->save($user_id, $cache_key, $reward->id, $reward);
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

		$result = $this->call('POST', 'unlock_reward', array(
			'reward_id' => $reward_id,
			'user_id' => $user_id
		));

		$this->cache_storage->refresh($user_id, 'user', $user_id);
		$this->cache_storage->refresh($user_id, 'reward', $reward_id);

		return $result == 'ok';
	}

	/**
	 *
	 * @param int $user_id
	 * @param int $reward_id
	 */
	public function hasUnlockedReward($user_id, $reward_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$reward = $this->getReward($reward_id, array('user_id' => $user_id));
		return !!$reward->unlocked;
	}

	/**
	 * @param int $user_id
	 * @param array|int|string $goal_ids_or_codes
	 */
	public function award($user_id, $goal_ids_or_codes)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$result = $this->call('POST', 'award', array('user_id' => $user_id, 'goal_ids' => $goal_ids_or_codes));
		foreach ((array)$goal_ids_or_codes as $goal_id_or_code)
		{
			if (is_int($goal_id_or_code))
				$goal_id = $goal_id_or_code;
			else
			{
				$goal = $this->getGoal($goal_id_or_code);
				$goal_id = $goal->id;
			}
			$this->cache_storage->refresh($user_id, 'goal', $goal_id);
		}

		return $result;
	}

	/**
	 * @param int $user_id
	 * @param int|string $goal_id_or_code
	 */
	public function denyAchievement($user_id, $goal_id_or_code)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$result = $this->call('POST', 'deny', array('user_id' => $user_id, 'goal_id' => $goal_id_or_code));
		if (is_int($goal_id_or_code))
			$goal_id = $goal_id_or_code;
		else
		{
			$goal = $this->getGoal($goal_id_or_code);
			$goal_id = $goal->id;
		}
		$this->cache_storage->refresh($user_id, 'goal', $goal_id);

		return $result;
	}

	public function claimAchievement($user_id, $goal_id_or_code, $message = null)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$result = $this->call('POST', 'claim', array('user_id' => $user_id, 'goal_id' => $goal_id_or_code, 'message' => $message));
		if (is_int($goal_id_or_code))
			$goal_id = $goal_id_or_code;
		else
		{
			$goal = $this->getGoal($goal_id_or_code);
			$goal_id = $goal->id;
		}
		$this->cache_storage->refresh($user_id, 'goal', $goal_id);

		return $result;
	}

	/**
	 *
	 * @param int $user_id
	 */
	public function getNotifications($user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$cache_key = 'notification';
		$notifications = $this->cache_storage->loadAll($user_id, $cache_key);

		if (count($notifications) == 0)
		{
			$notifications = $this->call('GET', 'notifications', array('user_id' => $user_id));
			foreach ($notifications as $notification)
				$this->cache_storage->save($user_id, $cache_key, $notification->id, $notification);
		}

		return $notifications;
	}

	public function getClaims($user_id = null)
	{
		$this->logger->info(__METHOD__, func_get_args());

		return $this->call('GET', 'claims', array('user_id' => $user_id));
	}

	public function confirmAchievement($user_id, $goal_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		return $this->call('POST', 'award', array('user_id' => $user_id, 'goal_ids' => array($goal_id)));
	}

	/**
	 *
	 * @param int $user_id
	 * @param int|array $event_ids
	 */
	public function markNotificationsRead($user_id, $event_ids)
	{
		$this->logger->info(__METHOD__, func_get_args());

		$result = $this->call('POST', 'mark_notifications_read', array('user_id' => $user_id, 'event_ids' => (array)$event_ids));
		$this->cache_storage->refreshAll($user_id, 'notification');

		return $result;
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
			$access_token->expiration_time = time() + $access_token->expires_in;
			$this->cache_storage->save(null, 'access_token', null, $access_token);
		}

		return $access_token;
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

	protected function computeGoalProgress($goal, $user_id)
	{
		$this->logger->info(__METHOD__, func_get_args());

		if (isset($this->goal_checkers[$goal->code]))
		{
			$goal_progress = $this->goal_checkers[$goal->code]($goal, $user_id);
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

			if ((!isset($goal->participation) || $goal->participation->status != 'achieved') && $goal->achieved)
			{
				$this->award($user_id, array($goal->id));
				$goal = $this->getGoal($goal->id, array('user_id' => $user_id));
			}
		}
		else
			$goal->achieved = isset($goal->participation) && $goal->participation->status == 'achieved';

		return $goal;
	}
}
