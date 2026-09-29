<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Schema
{
    /**
     * @var VonSEO_Url
     */
    protected $url;

    public function __construct(VonSEO_Url $url)
    {
        $this->url = $url;
    }

    public function build(VonSEO_Context $context)
    {
        global $mybb;

        if(!$context->indexable || $context->canonical === '')
        {
            return array();
        }

        $graph = array();

        if($context->type === 'home')
        {
            $website = array(
                '@type' => 'WebSite',
                '@id' => $context->canonical.'#website',
                'url' => $context->canonical,
                'name' => isset($mybb->settings['bbname']) ? trim(VonSEO_Utils::plainText($mybb->settings['bbname'])) : $context->title
            );

            if($context->description !== '')
            {
                $website['description'] = $context->description;
            }

            if(VonSEO_Utils::setting('vonseo_searchbox_schema', 0))
            {
                $website['potentialAction'] = array(
                    '@type' => 'SearchAction',
                    'target' => array(
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $this->url->absolute('search.php?action=do_search&keywords={search_term_string}')
                    ),
                    'query-input' => 'required name=search_term_string'
                );
            }

            $publisher = $this->organizationSchema();
            if(!empty($publisher))
            {
                $website['publisher'] = $publisher;
            }

            $graph[] = $website;
        }
        elseif($context->type === 'forum')
        {
            $graph[] = array(
                '@type' => 'CollectionPage',
                '@id' => $context->canonical.'#webpage',
                'url' => $context->canonical,
                'name' => $context->title,
                'description' => $context->description
            );

            $breadcrumb = $this->breadcrumbForForum($context);
            if(!empty($breadcrumb))
            {
                $graph[] = $breadcrumb;
            }
        }
        elseif($context->type === 'thread')
        {
            $thread = isset($context->data['thread']) ? $context->data['thread'] : array();
            $forum = isset($context->data['forum']) ? $context->data['forum'] : array();
            $firstPageUrl = !empty($context->data['first_page_url'])
                ? $context->data['first_page_url'] : $context->canonical;
            $firstPostText = isset($context->data['first_post_text'])
                ? trim((string)$context->data['first_post_text']) : '';
            $authorName = isset($thread['username'])
                ? VonSEO_Utils::plainText($thread['username']) : '';

            // On later pages, Google permits a reference to the original post
            // at its first-page URL. On page one, do not emit an incomplete
            // posting if MyBB did not render the original post to a guest.
            if(!empty($thread['dateline']) && (int)$thread['dateline'] > 0 && $authorName !== '' &&
               ($context->page > 1 || $firstPostText !== ''))
            {
                $posting = array(
                    '@type' => 'DiscussionForumPosting',
                    '@id' => $firstPageUrl.'#posting',
                    'url' => $firstPageUrl,
                    'headline' => isset($thread['subject']) ? VonSEO_Utils::plainText($thread['subject']) : $context->title,
                    'datePublished' => VonSEO_Utils::isoDate($thread['dateline']),
                    'author' => array(
                        '@type' => 'Person',
                        'name' => $authorName
                    ),
                    'commentCount' => isset($thread['replies']) ? (int)$thread['replies'] : 0
                );

                if($context->page === 1)
                {
                    $posting['mainEntityOfPage'] = $context->canonical;
                    $posting['text'] = $firstPostText;
                    $posting['description'] = $context->description;
                }

                if(!empty($forum['name']))
                {
                    $posting['articleSection'] = VonSEO_Utils::plainText($forum['name']);
                }

                if($context->page === 1 && !empty($context->data['first_post_edittime']))
                {
                    $posting['dateModified'] = VonSEO_Utils::isoDate($context->data['first_post_edittime']);
                }

                if(!empty($thread['uid']) && VonSEO_Utils::setting('vonseo_profile_index', 0))
                {
                    $posting['author']['url'] = $this->url->profile((int)$thread['uid']);
                }

                $interactionStatistic = array();
                if(isset($thread['replies']))
                {
                    $interactionStatistic[] = array(
                        '@type' => 'InteractionCounter',
                        'interactionType' => 'https://schema.org/ReplyAction',
                        'userInteractionCount' => (int)$thread['replies']
                    );
                }
                if(isset($thread['views']))
                {
                    $interactionStatistic[] = array(
                        '@type' => 'InteractionCounter',
                        'interactionType' => 'https://schema.org/ViewAction',
                        'userInteractionCount' => (int)$thread['views']
                    );
                }
                if(!empty($interactionStatistic))
                {
                    $posting['interactionStatistic'] = $interactionStatistic;
                }

                $publisher = $this->organizationSchema();
                if(!empty($publisher))
                {
                    $posting['publisher'] = $publisher;
                }

                // A default social image is not an inline post image.
                $graph[] = $posting;
            }

            $breadcrumb = $this->breadcrumbForThread($context);
            if(!empty($breadcrumb))
            {
                $graph[] = $breadcrumb;
            }
        }
        elseif($context->type === 'announcement')
        {
            $announcement = isset($context->data['announcement']) ? $context->data['announcement'] : array();
            $text = isset($context->data['announcement_text'])
                ? trim((string)$context->data['announcement_text']) : '';
            $headline = isset($announcement['subject'])
                ? VonSEO_Utils::plainText($announcement['subject']) : '';
            $authorName = isset($announcement['username'])
                ? VonSEO_Utils::plainText($announcement['username']) : '';

            if($text !== '' && $headline !== '' && !empty($announcement['startdate']))
            {
                $article = array(
                    '@type' => 'Article',
                    '@id' => $context->canonical.'#article',
                    'url' => $context->canonical,
                    'mainEntityOfPage' => $context->canonical,
                    'headline' => $headline,
                    'description' => $context->description,
                    'articleBody' => $text,
                    'datePublished' => VonSEO_Utils::isoDate($announcement['startdate'])
                );

                if($authorName !== '')
                {
                    $article['author'] = array('@type' => 'Person', 'name' => $authorName);
                }
                $publisher = $this->organizationSchema();
                if(!empty($publisher))
                {
                    $article['publisher'] = $publisher;
                    if($authorName === '')
                    {
                        $article['author'] = $publisher;
                    }
                }
                $graph[] = $article;
            }

            $breadcrumb = $this->breadcrumbForAnnouncement($context);
            if(!empty($breadcrumb))
            {
                $graph[] = $breadcrumb;
            }
        }
        elseif($context->type === 'calendar')
        {
            $graph[] = array(
                '@type' => 'CollectionPage',
                '@id' => $context->canonical.'#webpage',
                'url' => $context->canonical,
                'name' => $context->title,
                'description' => $context->description
            );

            $breadcrumb = $this->breadcrumbForCalendar($context);
            if(!empty($breadcrumb))
            {
                $graph[] = $breadcrumb;
            }
        }
        elseif($context->type === 'event')
        {
            $eventData = isset($context->data['event']) ? $context->data['event'] : array();
            $calendarData = isset($context->data['calendar']) ? $context->data['calendar'] : array();
            $name = isset($eventData['name']) ? VonSEO_Utils::plainText($eventData['name']) : '';
            $startDate = !empty($eventData['starttime']) ? VonSEO_Utils::isoDate($eventData['starttime']) : '';

            if($name !== '' && $startDate !== '')
            {
                $event = array(
                    '@type' => 'Event',
                    '@id' => $context->canonical.'#event',
                    'url' => $context->canonical,
                    'name' => $name,
                    'description' => $context->description,
                    'startDate' => $startDate
                );
                if(!empty($eventData['endtime']) && (int)$eventData['endtime'] >= (int)$eventData['starttime'])
                {
                    $event['endDate'] = VonSEO_Utils::isoDate($eventData['endtime']);
                }
                $authorName = isset($eventData['username'])
                    ? VonSEO_Utils::plainText($eventData['username']) : '';
                if($authorName !== '')
                {
                    $event['organizer'] = array('@type' => 'Person', 'name' => $authorName);
                }
                $graph[] = $event;
            }

            $breadcrumb = $this->breadcrumbForEvent($context, $calendarData);
            if(!empty($breadcrumb))
            {
                $graph[] = $breadcrumb;
            }
        }
        elseif($context->type === 'profile')
        {
            $profile = $context->data;
            $person = array(
                '@type' => 'Person',
                'name' => isset($profile['username']) ? VonSEO_Utils::plainText($profile['username']) : $context->title,
                'url' => $context->canonical
            );

            $graph[] = array(
                '@type' => 'ProfilePage',
                '@id' => $context->canonical.'#profile',
                'url' => $context->canonical,
                'name' => $context->title,
                'mainEntity' => $person
            );
        }

        if(empty($graph))
        {
            return array();
        }

        return array(
            '@context' => 'https://schema.org',
            '@graph' => $graph
        );
    }

    protected function breadcrumbForForum(VonSEO_Context $context)
    {
        $forum = $context->data;
        if(empty($forum['fid']))
        {
            return array();
        }

        $items = $this->forumTrail($forum);
        return $this->breadcrumbSchema($items);
    }

    protected function breadcrumbForThread(VonSEO_Context $context)
    {
        $thread = isset($context->data['thread']) ? $context->data['thread'] : array();
        $forum = isset($context->data['forum']) ? $context->data['forum'] : array();

        $items = $this->forumTrail($forum);
        $items[] = array(
            'name' => isset($thread['subject']) ? VonSEO_Utils::plainText($thread['subject']) : $context->title,
            'url' => $context->canonical
        );

        return $this->breadcrumbSchema($items);
    }

    /**
     * @param VonSEO_Context $context
     * @return array
     */
    protected function breadcrumbForAnnouncement(VonSEO_Context $context)
    {
        $announcement = isset($context->data['announcement']) ? $context->data['announcement'] : array();
        $forum = isset($context->data['forum']) ? $context->data['forum'] : array();
        $items = !empty($forum['fid']) ? $this->forumTrail($forum) : array(
            array('name' => $this->boardName(), 'url' => $this->url->home())
        );
        $items[] = array(
            'name' => isset($announcement['subject'])
                ? VonSEO_Utils::plainText($announcement['subject']) : $context->title,
            'url' => $context->canonical
        );

        return $this->breadcrumbSchema($items);
    }

    /**
     * @param VonSEO_Context $context
     * @return array
     */
    protected function breadcrumbForCalendar(VonSEO_Context $context)
    {
        $calendar = isset($context->data['calendar']) ? $context->data['calendar'] : array();
        $name = isset($calendar['name']) ? VonSEO_Utils::plainText($calendar['name']) : $context->title;
        return $this->breadcrumbSchema(array(
            array('name' => $this->boardName(), 'url' => $this->url->home()),
            array('name' => $name, 'url' => $context->canonical)
        ));
    }

    /**
     * @param VonSEO_Context $context
     * @param array $calendar
     * @return array
     */
    protected function breadcrumbForEvent(VonSEO_Context $context, array $calendar)
    {
        $event = isset($context->data['event']) ? $context->data['event'] : array();
        $calendarName = isset($calendar['name']) ? VonSEO_Utils::plainText($calendar['name']) : '';
        $eventName = isset($event['name']) ? VonSEO_Utils::plainText($event['name']) : $context->title;
        $items = array(array('name' => $this->boardName(), 'url' => $this->url->home()));
        if(!empty($calendar['cid']) && $calendarName !== '')
        {
            $items[] = array(
                'name' => $calendarName,
                'url' => $this->url->calendar((int)$calendar['cid'])
            );
        }
        $items[] = array('name' => $eventName, 'url' => $context->canonical);
        return $this->breadcrumbSchema($items);
    }

    protected function forumTrail(array $forum)
    {
        global $cache;

        $items = array(
            array(
                'name' => $this->boardName(),
                'url' => $this->url->home()
            )
        );

        $forums = is_object($cache) ? $cache->read('forums') : false;
        if(!is_array($forums))
        {
            if(function_exists('cache_forums'))
            {
                $forums = cache_forums();
            }
            elseif(is_object($cache) && method_exists($cache, 'update_forums'))
            {
                $cache->update_forums();
                $forums = $cache->read('forums');
            }
            if(!is_array($forums))
            {
                $forums = array();
            }
        }

        $parentIds = array();
        if(!empty($forum['parentlist']))
        {
            foreach(explode(',', $forum['parentlist']) as $fid)
            {
                $fid = (int)$fid;
                if($fid > 0)
                {
                    $parentIds[] = $fid;
                }
            }
        }

        if(empty($parentIds) && !empty($forum['fid']))
        {
            $parentIds[] = (int)$forum['fid'];
        }

        foreach($parentIds as $fid)
        {
            if(!isset($forums[$fid]))
            {
                continue;
            }

            $node = $forums[$fid];
            if(isset($node['type']) && $node['type'] !== 'f')
            {
                // Categories can still be represented in breadcrumbs if a URL exists,
                // but MyBB categories are not normal indexable forum pages.
                continue;
            }

            $items[] = array(
                'name' => VonSEO_Utils::plainText($node['name']),
                'url' => $this->url->forum($fid, 0, isset($node['name']) ? $node['name'] : '')
            );
        }

        return $items;
    }

    protected function breadcrumbSchema(array $items)
    {
        if(count($items) < 2)
        {
            return array();
        }

        $elements = array();
        $position = 1;

        foreach($items as $item)
        {
            if(empty($item['name']) || empty($item['url']))
            {
                continue;
            }

            $elements[] = array(
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $item['name'],
                'item' => $item['url']
            );
        }

        if(count($elements) < 2)
        {
            return array();
        }

        return array(
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements
        );
    }

    protected function boardName()
    {
        global $mybb;
        return isset($mybb->settings['bbname']) ? trim(VonSEO_Utils::plainText($mybb->settings['bbname'])) : '';
    }

    protected function organizationSchema()
    {
        global $mybb;

        $name = isset($mybb->settings['homename'])
            ? trim(VonSEO_Utils::plainText($mybb->settings['homename']))
            : '';
        $url = isset($mybb->settings['homeurl']) ? trim((string)$mybb->settings['homeurl']) : '';
        $parts = $url !== '' ? @parse_url($url) : false;
        $validHomepage = is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']) &&
            in_array(strtolower((string)$parts['scheme']), array('http', 'https'), true) &&
            empty($parts['user']) && empty($parts['pass']);

        // Site Details may describe a parent website that publishes the forum.
        // Use it only as a complete, safe pair; otherwise keep the board itself
        // as the Organization identity.
        if($name === '' || !$validHomepage)
        {
            $name = $this->boardName();
            $url = $this->url->home();
        }
        else
        {
            $url = preg_replace('/#.*$/', '', $url);
        }

        if($name === '' || $url === '')
        {
            return array();
        }

        $org = array(
            '@type' => 'Organization',
            '@id' => rtrim($url, '/').'#organization',
            'name' => $name,
            'url' => $url
        );

        $logo = trim((string)VonSEO_Utils::setting('vonseo_organization_logo', ''));
        if($logo !== '')
        {
            $org['logo'] = array(
                '@type' => 'ImageObject',
                'url' => $this->url->absolute($logo)
            );
        }

        return $org;
    }
}
