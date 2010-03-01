<?php
define('VOTE_HANDLER_SCRIPT', 'votes.php');

function create_voted_result($vote_type, $user_voted_list, $target_id)
{
    if (empty($user_voted_list))
    {
        return '';
    }
    global $vbulletin, $vbphrase, $stylevar;
    $voted_table = '';
    $votes = array();
    $votes['vote_list'] = '';
    $votes['remove_vote_link'] = '';
    foreach ($user_voted_list as $voted_user)
    {
        eval('$user_vote_bit = "' . fetch_template('forum_voted_user_bit') . '";');
        $votes['vote_list'] .= $user_vote_bit;
        // add link remove own user vote
        if ($voted_user['userid'] == $vbulletin->userinfo['userid'] AND $vbulletin->options['vbv_enable_neg_votes'])
        {
            $votes['remove_vote_link'] = create_vote_url(array('do'=>'remove'));
        }
    }

    if ('' !== $votes['vote_list'])
    {
        $votes['target_id'] = $target_id;
        $votes['vote_type'] = 'Positive';
        $votes['post_user_votes'] = $vbphrase['positive_user_votes'];
        if ('-1' == $vote_type)
        {
            $votes['vote_type'] = 'Negative';
            $votes['post_user_votes'] = $vbphrase['negative_user_votes'];
        }
        if (can_administer())
        {
            $votes['remove_all_votes_link'] = create_vote_url(array('do'=>'remove', 'all'=>1, 'value'=>(string)$vote_type));
        }

        eval('$voted_table = "' . fetch_template('voted_postbit') . '";');
    }
    return $voted_table;
}

function get_vote_for_post($target_id, $vote_type = NULL, $target_type = NULL)
{
    $target_id_list[] = $target_id;
    $result = get_vote_for_post_list($target_id_list, $vote_type, $target_type);
    return $result[$target_id];
}

function get_vote_for_post_list($target_id_list, $vote_type = NULL, $target_type = NULL)
{
    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }
    global $db;
    static $target_votes;
    $result = array();
    // if post id in static store, then remove id from search list and add value to result array
    if (!empty($target_votes) and is_array($target_votes))
    {
        foreach ($target_id_list as $post_id)
        {
            if (isset($target_id_list[$post_id]))
            {
                $result[$post_id] = $target_votes[$post_id];
                unset($target_id_list[$post_id]);
            }
        }
    }
    if (!empty($target_id_list))
    {
        $vote_type_condition = '';
        if (!is_null($vote_type))
        {
            $vote_type_condition = ' AND pv.`vote` = "' . $vote_type;
        }
        $sql = 'SELECT
                    pv.`targetid`, pv.`targettype`, pv.`vote`, pv.`userid`, u.`username`
                FROM
                    ' . TABLE_PREFIX . 'post_votes AS pv
                LEFT JOIN
                    `user` AS u ON u.`userid` = pv.`userid`
                WHERE
                    pv.`targetid` IN (' . implode($target_id_list, ', ')  . ') AND pv.`targettype` = "' . $target_type . '" ' .$vote_type_condition;
        $db_resource = $db->query_read($sql);
        $target_votes = array();
        while ($vote = $db->fetch_array($db_resource))
        {
            $target_votes[$vote['targetid']][$vote['vote']][] = array('userid'=>$vote['userid'], 'username'=>$vote['username']);
            $result[$vote['targetid']][$vote['vote']][] = array('userid'=>$vote['userid'], 'username'=>$vote['username']);
        }
    }
    return $result;
}

function is_user_can_vote($target_id, $throw_error = false, $target_type = NULL)
{
    global $vbulletin;
    $error = null;

    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }

    // user is not in banned, "disabled mod" or read only group
    $bann_groups = unserialize($vbulletin->options['vbv_grp_banned']);
    $read_only_groups = unserialize($vbulletin->options['vbv_grp_read_only']);
    if (!is_user_can_see_votes_result() OR
        is_member_of($vbulletin->userinfo, $bann_groups) OR
        is_member_of($vbulletin->userinfo, $read_only_groups)
    )
    {
        if ($throw_error)
        {
            print_no_permission();
        }
        return false;
    }

    if ('forum' == $target_type)
    {
        $postinfo = fetch_postinfo($target_id);

        // this is new post
        if ((int)$vbulletin->options['vbv_post_days_old'] > 0)
        {
            $date_limit = TIMENOW - 24 * 60 * 60 * (int)$vbulletin->options['vbv_post_days_old'];
            if ($postinfo['dateline'] < $date_limit)
            {
                if ($throw_error)
                {
                    standard_error(fetch_error('vbv_post_old'));
                }
                return false;
            }
        }

        $threadinfo = fetch_threadinfo($postinfo['threadid']);
        // this post is not in close forum
        $unvoted_forum = explode(',', $vbulletin->options['vbv_ignored_forums']);
        if (in_array($threadinfo['forumid'], $unvoted_forum))
        {
            if ($throw_error)
            {
                standard_error(fetch_error('vbv_post_can_not_be_voted'));
            }
            return false;
        }

        // user is not author of this topic
        if ( $vbulletin->userinfo['userid'] == $postinfo['userid'])
        {

            if ($throw_error)
            {
                standard_error(fetch_error('vbv_your_post'));
            }
            return false;
        }
    }
    // user didn't vote for this post
    $votes_list = get_vote_for_post($target_id);
    if (is_array($votes_list))
    {
        foreach ($votes_list as $vote_type)
        {
            foreach ($vote_type as $vote)
            {
                if ($vote['userid'] == $vbulletin->userinfo['userid'])
                {
                    if ($throw_error)
                    {
                        standard_error(fetch_error('vbv_post_voted'));
                    }
                    return false;
                }
            }
        }
    }
    // user have free vote
    if (is_user_have_free_votes() )
    {
        if ($throw_error)
        {
            standard_error(fetch_error('vbv_to_many_votes_per_day'));
        }
        return false;
    }
    return true;
}

function create_vote_url($options, $script = null)
{
    if (is_null($options) OR !is_array($options))
    {
        return false;
    }
    if (is_null($script))
    {
        $script = VOTE_HANDLER_SCRIPT;
    }
    return $script . '?' . http_build_query($options, '', '&amp;');
}

function clear_votes_by_user_id($user_id)
{
    global $db;
    $sql = 'DELETE FROM
                `' . TABLE_PREFIX . 'post_votes`
            WHERE
                `userid` = ' . $user_id;
    $db->query_write($sql);
    return true;
}

function is_user_have_free_votes($user_id = null)
{
    global $db, $vbulletin;
    static $result;

    if (0 == $vbulletin->options['vbv_max_votes_daily'])
    {
        return true;
    }
    if (is_null($user_id))
    {
        $user_id = $vbulletin->userinfo['userid'];
    }
    if (!isset($result[$user_id]))
    {
        $result[$user_id] = false;
        $time_line = TIMENOW - (24 * 60 * 60 * 1);
        $sql = 'SELECT
                    count(`userid`) as today_amount
                FROM
                    `' . TABLE_PREFIX . 'post_votes`
                WHERE
                    `userid` = ' . $user_id .' AND
                    `date` >= ' . $time_line;
        $user_post_vote_amount = $db->query_first($sql);
        if ((int)$user_post_vote_amount['today_amount'] >= $vbulletin->options['vbv_max_votes_daily'])
        {
            $result[$user_id] = true;
        }
    }
    return $result[$user_id];
}

function is_user_can_see_votes_result()
{
    global $vbulletin;
    // user is not in "disabled mod" group
    $disable_mod_groups = unserialize($vbulletin->options['vbv_grp_disable']);
    if (is_member_of($vbulletin->userinfo, $disable_mod_groups))
    {
        return false;
    }
    return true;
}

function delete_votes_by_target_id_list($target_id_list, $target_type = null)
{
    global $db;
    
    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }

    $sql = 'DELETE FROM
                ' . TABLE_PREFIX . 'post_votes
            WHERE
                `targetid` IN(' . implode(', ', $target_id_list) . ') AND
                `targettype` = "' . $target_type . '"';
    $db->query_write($sql);
    return trus;
}
?>