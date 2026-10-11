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
        if(!VonSEO_Html::hasHead($html))
        {
            return $html;
        }

        $robots = new VonSEO_Robots($this->url);
        $social = new VonSEO_Social();
        $schema = new VonSEO_Schema($this->url);
        $meta = new VonSEO_Meta($robots, $social, $schema);

        $status = VonSEO_Errors::prepareResponse();

        $replaceOwned = (bool)VonSEO_Utils::setting('vonseo_replace_existing_meta', 1);

        // Error and permission pages must never inherit private entity SEO.
        // Preserve MyBB's error title and inject only a crawler directive.
        if($status >= 400 || VonSEO_Errors::isErrorResponse())
        {
            $payload = '<meta name="robots" content="noindex,follow" />';
            $marker = "\n<!-- VonSEO error guard -->\n".$payload."\n<!-- /VonSEO -->\n";
            return VonSEO_Html::editHead($html, function($tag, $original) use ($meta, $replaceOwned) {
                return $replaceOwned && $meta->ownsTag($tag) ? '' : $original;
            }, $marker);
        }

        $context = new VonSEO_Context($this->url);
        $context->resolve();

        // Do not add entity titles to password/permission/noindex pages.
        $title = $context->indexable && $context->title !== ''
            ? '<title>'.VonSEO_Utils::h($context->title).'</title>' : '';

        $payload = $meta->render($context);
        if($payload === '')
        {
            return $html;
        }

        $marker = "\n<!-- VonSEO -->\n".$payload."\n<!-- /VonSEO -->\n";
        $titleWritten = false;
        return VonSEO_Html::editHead($html, function($tag, $original) use ($meta, $replaceOwned, $title, &$titleWritten) {
            if($replaceOwned && $meta->ownsTag($tag))
            {
                return '';
            }
            if($title !== '' && !$titleWritten && $tag['name'] === 'title' && !$tag['closing'] && $tag['complete'])
            {
                $titleWritten = true;
                return $title;
            }
            return $original;
        }, function() use ($title, &$titleWritten, $marker) {
            return ($title !== '' && !$titleWritten ? "\n".$title : '').$marker;
        });
    }
}
