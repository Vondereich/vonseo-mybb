<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Meta
{
    /**
     * @var VonSEO_Robots
     */
    protected $robots;

    /**
     * @var VonSEO_Social
     */
    protected $social;

    /**
     * @var VonSEO_Schema
     */
    protected $schema;

    public function __construct(VonSEO_Robots $robots, VonSEO_Social $social, VonSEO_Schema $schema)
    {
        $this->robots = $robots;
        $this->social = $social;
        $this->schema = $schema;
    }

    public function render(VonSEO_Context $context)
    {
        $lines = array();

        // Private and utility pages receive only a robots directive. VonSEO avoids
        // enriching non-public pages with descriptions, social metadata or schema.
        if($context->indexable)
        {
            if($context->description !== '')
            {
                $lines[] = '<meta name="description" content="'.VonSEO_Utils::h($context->description).'" />';
            }

            if($context->canonical !== '')
            {
                $lines[] = '<link rel="canonical" href="'.VonSEO_Utils::h($context->canonical).'" />';
            }

            foreach($this->social->tags($context) as $tag)
            {
                list($kind, $name, $value) = $tag;
                if($value === '')
                {
                    continue;
                }

                $lines[] = '<meta '.$kind.'="'.VonSEO_Utils::h($name).'" content="'.VonSEO_Utils::h($value).'" />';
            }

            $schema = $this->schema->build($context);
            if(!empty($schema))
            {
                $json = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if($json !== false)
                {
                    $lines[] = '<script type="application/ld+json" class="vonseo-schema">'.$json.'</script>';
                }
            }
        }

        $lines[] = '<meta name="robots" content="'.VonSEO_Utils::h($this->robots->directive($context)).'" />';

        return implode("\n", $lines);
    }

    /**
     * @param string $html
     * @return string
     */
    public function stripOwnedTags($html)
    {
        return VonSEO_Html::editHead($html, function($tag, $original) {
            return $this->ownsTag($tag) ? '' : $original;
        });
    }

    /** @return bool */
    public function ownsTag(array $tag)
    {
        if($tag['closing'])
        {
            return false;
        }
        $attributes = $tag['attributes'];
        if($tag['name'] === 'link' && isset($attributes['rel']))
        {
            $relations = preg_split('/\s+/', strtolower(trim($attributes['rel']['value'])));
            return in_array('canonical', $relations, true);
        }
        if($tag['name'] === 'meta')
        {
            $name = isset($attributes['name']) ? strtolower($attributes['name']['value']) : '';
            $property = isset($attributes['property']) ? strtolower($attributes['property']['value']) : '';
            return in_array($name, array('description', 'robots', 'twitter:card', 'twitter:title',
                'twitter:description', 'twitter:image'), true) ||
                strpos($property, 'og:') === 0 || strpos($property, 'article:') === 0;
        }
        if($tag['name'] === 'script' && $tag['complete'] && isset($attributes['class']))
        {
            $classes = preg_split('/\s+/', trim($attributes['class']['value']));
            return in_array('vonseo-schema', $classes, true);
        }
        return false;
    }
}
