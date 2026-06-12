<?php

namespace Cav7\UserGroupsScope\XF\Entity;

use XF\Api\Result\EntityResult;
use XF\Entity\OAuthToken;

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

		// Never expose another user's groups, regardless of granted scopes
		$visitor = \XF::visitor();
		if (!$visitor->user_id || $visitor->user_id !== $this->user_id)
		{
			return;
		}

		$tokenOrKey = \XF::accessToken() ?? \XF::apiKey();
		if (!$tokenOrKey || !$tokenOrKey->hasScope('user:groups'))
		{
			return;
		}

		// The API app aligns the visitor with the token's user at request
		// setup, but that is a framework invariant, not ours: re-check it in
		// case anything renders inside \XF::asVisitor(). API keys are exempt
		// on purpose — their user_id is the key owner, and the API app
		// validates the acting-user binding itself.
		if ($tokenOrKey instanceof OAuthToken && $tokenOrKey->user_id !== $this->user_id)
		{
			return;
		}

		$result->includeColumn(['user_group_id', 'secondary_group_ids']);
	}
}
