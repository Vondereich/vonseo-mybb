<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Social
{
    public function tags(VonSEO_Context $context)
    {
        global $mybb;

        if($context->title === '')
        {
            return array();
        }

        $articleType = in_array($context->type, array('thread', 'announcement'), true);
        $tags = array(
            array('property', 'og:title', $context->title),
            array('property', 'og:type', $articleType ? 'article' : ($context->type === 'profile' ? 'profile' : 'website')),
            array('property', 'og:url', $context->canonical),
            array('property', 'og:site_name', isset($mybb->settings['bbname']) ? trim(VonSEO_Utils::plainText($mybb->settings['bbname'])) : ''),
            array('name', 'twitter:card', $context->socialImage !== '' ? 'summary_large_image' : 'summary'),
            array('name', 'twitter:title', $context->title)
        );

        if($context->description !== '')
        {
            $tags[] = array('property', 'og:description', $context->description);
            $tags[] = array('name', 'twitter:description', $context->description);
        }

        if($context->socialImage !== '')
        {
            // One resolved image source prevents duplicate fallback tags.
            $tags[] = array('property', 'og:image', $context->socialImage);
            $tags[] = array('name', 'twitter:image', $context->socialImage);
        }

        if($context->type === 'thread')
        {
            $thread = isset($context->data['thread']) ? $context->data['thread'] : array();
            $forum = isset($context->data['forum']) ? $context->data['forum'] : array();

            if(!empty($thread['dateline']))
            {
                $tags[] = array('property', 'article:published_time', VonSEO_Utils::isoDate($thread['dateline']));
            }
            if(!empty($thread['lastpost']))
            {
                $tags[] = array('property', 'article:modified_time', VonSEO_Utils::isoDate($thread['lastpost']));
            }
            if(!empty($forum['name']))
            {
                $tags[] = array('property', 'article:section', VonSEO_Utils::plainText($forum['name']));
            }
            if(!empty($thread['username']))
            {
                $tags[] = array('property', 'article:author', VonSEO_Utils::plainText($thread['username']));
            }
        }
        elseif($context->type === 'announcement')
        {
            $announcement = isset($context->data['announcement']) ? $context->data['announcement'] : array();
            $forum = isset($context->data['forum']) ? $context->data['forum'] : array();

            if(!empty($announcement['startdate']))
            {
                $tags[] = array('property', 'article:published_time', VonSEO_Utils::isoDate($announcement['startdate']));
            }
            if(!empty($forum['name']))
            {
                $tags[] = array('property', 'article:section', VonSEO_Utils::plainText($forum['name']));
            }
            if(!empty($announcement['username']))
            {
                $tags[] = array('property', 'article:author', VonSEO_Utils::plainText($announcement['username']));
            }
        }

        return $tags;
    }
}
