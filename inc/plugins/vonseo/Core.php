<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Core
{
    /** @var VonSEO_Url */
    protected $url;

    public function __construct()
    {
        $this->url = new VonSEO_Url();
    }

    /**
     * @param mixed $html
     * @return mixed
     */
    public function render($html)
    {
        if(!is_string($html) || $html === '')
        {
            return $html;
        }

        // Only work on actual HTML documents with a head.
        if(stripos($html, '<head') === false || stripos($html, '</head>') === false)
        {
            return $html;
        }

        $robots = new VonSEO_Robots($this->url);
        $social = new VonSEO_Social();
        $schema = new VonSEO_Schema($this->url);
        $meta = new VonSEO_Meta($robots, $social, $schema);

        $status = VonSEO_Errors::prepareResponse();

        if(VonSEO_Utils::setting('vonseo_replace_existing_meta', 1))
        {
            $html = $meta->stripOwnedTags($html);
        }

        // Error and permission pages must never inherit private entity SEO.
        // Preserve MyBB's error title and inject only a crawler directive.
        if($status >= 400 || VonSEO_Errors::isErrorResponse())
        {
            $payload = '<meta name="robots" content="noindex,follow" />';
            $marker = "\n<!-- VonSEO error guard -->\n".$payload."\n<!-- /VonSEO -->\n";
            return preg_replace_callback('#</head>#i', function() use ($marker) {
                return $marker.'</head>';
            }, $html, 1);
        }

        $context = new VonSEO_Context($this->url);
        $context->resolve();

        // Do not add entity titles to password/permission/noindex pages.
        if($context->indexable && $context->title !== '')
        {
            $title = '<title>'.VonSEO_Utils::h($context->title).'</title>';

            if(preg_match('#<title\b[^>]*>.*?</title>#is', $html))
            {
                $html = preg_replace_callback('#<title\b[^>]*>.*?</title>#is', function() use ($title) {
                    return $title;
                }, $html, 1);
            }
            else
            {
                $html = preg_replace_callback('#(<head\b[^>]*>)#i', function($match) use ($title) {
                    return $match[1]."\n".$title;
                }, $html, 1);
            }
        }

        $payload = $meta->render($context);
        if($payload === '')
        {
            return $html;
        }

        $marker = "\n<!-- VonSEO -->\n".$payload."\n<!-- /VonSEO -->\n";
        $html = preg_replace_callback('#</head>#i', function() use ($marker) {
            return $marker.'</head>';
        }, $html, 1);

        return $html;
    }
}
