<?php
/**
 * MyBB scheduled task for VonSEO's non-blocking IndexNow queue.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * @param array $task
 * @return void
 */
function task_vonseo_indexnow($task)
{
    global $mybb, $cache;

    $pluginCache = is_object($cache) && method_exists($cache, 'read') ? $cache->read('plugins') : false;
    $active = is_array($pluginCache) && !empty($pluginCache['active']) &&
        (isset($pluginCache['active']['vonseo']) || in_array('vonseo', $pluginCache['active'], true));
    if(!$active || empty($mybb->settings['vonseo_enabled']) || empty($mybb->settings['vonseo_indexnow_enabled']))
    {
        return;
    }

    require_once MYBB_ROOT.'inc/plugins/vonseo/Utils.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Url.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/State.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/IndexNow.php';

    $result = (new VonSEO_IndexNow())->processQueue(25);
    if($result['processed'] > 0 && function_exists('add_task_log'))
    {
        $message = $result['processed'].' queued item(s) processed; '.$result['remaining'].' remaining.';
        if(!empty($result['dropped']))
        {
            $message .= ' '.$result['dropped'].' stale or exhausted item(s) dropped.';
        }
        if(!$result['success'])
        {
            if(isset($result['status']) && $result['status'] === 'config_error')
            {
                $message .= ' Delivery paused by a configuration error; the queue was retained.';
            }
            elseif(isset($result['status']) && $result['status'] === 'rate_limited')
            {
                $message .= ' The remote service rate limited delivery; the queue was retained for the longer retry window.';
            }
            else
            {
                $message .= ' The remote batch failed and was scheduled for retry.';
            }
        }
        add_task_log($task, $message);
    }
}
