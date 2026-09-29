<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Robots
{
    /**
     * @var VonSEO_Url
     */
    protected $url;

    public function __construct(?VonSEO_Url $url = null)
    {
        $this->url = $url ?: new VonSEO_Url();
    }

    public function directive(VonSEO_Context $context)
    {
        if(!$context->indexable)
        {
            return 'noindex,follow';
        }

        return 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    }

    public function outputTxt()
    {
        global $mybb;

        $rules = trim((string)VonSEO_Utils::setting('vonseo_robots_txt'));

        $lines = preg_split('/\r\n|\r|\n/', $rules);
        $clean = array();

        foreach($lines as $line)
        {
            $clean[] = rtrim($line);
        }

        if(VonSEO_Utils::setting('vonseo_block_ai_crawlers', 0))
        {
            $aiBots = array('GPTBot', 'ClaudeBot', 'Google-Extended', 'Bytespider', 'CCBot', 'cohere-ai');
            $clean[] = '';
            $clean[] = '# AI training and scraper crawlers';
            foreach($aiBots as $bot)
            {
                $clean[] = "User-agent: {$bot}";
                $clean[] = 'Disallow: /';
            }
        }

        if(VonSEO_Utils::setting('vonseo_block_ai_search_crawlers', 0))
        {
            $clean[] = '';
            $clean[] = '# AI search crawlers';
            foreach(array('OAI-SearchBot', 'PerplexityBot') as $bot)
            {
                $clean[] = "User-agent: {$bot}";
                $clean[] = 'Disallow: /';
            }
        }

        $body = trim(implode("\n", $clean));
        if($body !== '')
        {
            $body .= "\n\n";
        }

        if(VonSEO_Utils::setting('vonseo_sitemap_enabled', 1))
        {
            $body .= 'Sitemap: '.$this->url->misc('vonseo_sitemap')."\n";
        }

        if(!headers_sent())
        {
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        echo $body;
    }
}
