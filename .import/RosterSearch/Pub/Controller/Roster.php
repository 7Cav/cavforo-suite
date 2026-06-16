<?php

namespace Cav7\RosterSearch\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

class Roster extends XFCP_Roster
{
    public function actionSearchOverlay(ParameterBag $params): View|AbstractReply
    {
        return $this->view(
            'Cav7\RosterSearch:SearchOverlay',
            'cav7_roster_search_overlay',
            []
        );
    }

    public function actionSearch(ParameterBag $params): View|AbstractReply
    {
        $query = trim($this->filter('q', 'str'));
        $results = [];
        $searched = false;

        if (strlen($query) >= 2)
        {
            $searched = true;
            $db = $this->app->db();
            $like = '%' . $db->escapeLike($query) . '%';

            $results = $db->fetchAll("
                SELECT
                    ru.relation_id,
                    COALESCE(u.username, ru.username) AS username,
                    fv.field_value AS gamertag,
                    r.title AS roster_name,
                    rk.title AS rank_title
                FROM xf_nf_rosters_user ru
                INNER JOIN xf_nf_rosters_field_value fv
                    ON fv.relation_id = ru.relation_id
                    AND fv.field_id = 'consoleGamertag'
                    AND fv.field_value != ''
                JOIN xf_nf_rosters r
                    ON r.roster_id = ru.roster_id
                LEFT JOIN xf_nf_rosters_rank rk
                    ON rk.rank_id = ru.rank_id
                LEFT JOIN xf_user u
                    ON u.user_id = ru.user_id
                WHERE COALESCE(u.username, ru.username) LIKE ?
                   OR fv.field_value LIKE ?
                ORDER BY COALESCE(u.username, ru.username) ASC
                LIMIT 50
            ", [$like, $like]);
        }

        return $this->view(
            'Cav7\RosterSearch:Search',
            'cav7_roster_search',
            [
                'query'    => $query,
                'results'  => $results,
                'searched' => $searched,
            ]
        );
    }
}
