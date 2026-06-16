<?php

namespace Cav7\ApiKeyManager\Job;

use XF\Job\AbstractJob;

class RecomputeKeyScopes extends AbstractJob
{
    protected $defaultData = [
        'user_id'       => 0,
        'user_group_id' => 0,
        'all'           => false,
        'last_user_id'  => 0,
        'batch'         => 50,
    ];

    public function run($maxRunTime)
    {
        $startTime = microtime(true);

        /** @var \Cav7\ApiKeyManager\Repository\ApiKey $keyRepo */
        $keyRepo = \XF::repository('Cav7\ApiKeyManager:ApiKey');
        $db = \XF::db();

        if ($this->data['user_id'])
        {
            $keyRepo->recomputeScopesForUser((int) $this->data['user_id']);
            return $this->complete();
        }

        $batch = max(1, (int) $this->data['batch']);
        $lastUserId = (int) $this->data['last_user_id'];

        do
        {
            $where = ['k.user_id > ?'];
            $params = [$lastUserId];

            if ($this->data['user_group_id'])
            {
                $where[] = '(u.user_group_id = ? OR FIND_IN_SET(?, u.secondary_group_ids))';
                $params[] = (int) $this->data['user_group_id'];
                $params[] = (int) $this->data['user_group_id'];
            }

            $sql = "SELECT k.user_id
                    FROM xf_cav7_api_key k
                    INNER JOIN xf_user u ON u.user_id = k.user_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY k.user_id ASC
                    LIMIT " . $batch;

            $userIds = $db->fetchAllColumn($sql, $params);

            if (!$userIds)
            {
                return $this->complete();
            }

            foreach ($userIds as $uid)
            {
                $keyRepo->recomputeScopesForUser((int) $uid);
                $lastUserId = (int) $uid;

                if (microtime(true) - $startTime >= $maxRunTime)
                {
                    $this->data['last_user_id'] = $lastUserId;
                    return $this->resume();
                }
            }
        }
        while (count($userIds) === $batch);

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return 'Recomputing API key scopes...';
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
