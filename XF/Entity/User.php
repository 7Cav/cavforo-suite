<?php

namespace Cav7\UserGroupsScope\XF\Entity;

use XF\Api\Result\EntityResult;

/**
 * Extends \XF\Entity\User
 */
class User extends XFCP_User
{
	protected function setupApiResultData(
		EntityResult $result,
		$verbosity = self::VERBOSITY_NORMAL,
		array $options = []
	)
	{
		parent::setupApiResultData($result, $verbosity, $options);

		if ($result->getResultType() !== EntityResult::TYPE_API)
		{
			return;
		}

		$visitor = \XF::visitor();
		if (!$visitor->user_id || $visitor->user_id != $this->user_id)
		{
			return;
		}

		$tokenOrKey = \XF::accessToken() ?? \XF::apiKey();
		if (!$tokenOrKey || !$tokenOrKey->hasScope('user:groups'))
		{
			return;
		}

		$result->includeColumn(['user_group_id', 'secondary_group_ids']);
	}
}
