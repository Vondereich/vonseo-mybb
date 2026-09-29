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
        // Canonical.
        $html = preg_replace('#<link\b[^>]*\brel=(["\'])canonical\1[^>]*>\s*#i', '', $html);

        // Name-based SEO tags.
        $names = array(
            'description',
            'robots',
            'twitter:card',
            'twitter:title',
            'twitter:description',
            'twitter:image'
        );
        $namePattern = implode('|', array_map('preg_quote', $names));
        $html = preg_replace('#<meta\b(?=[^>]*\bname=(["\'])(?:'.$namePattern.')\1)[^>]*>\s*#i', '', $html);

        // Property-based Open Graph and Article tags.
        $html = preg_replace('#<meta\b(?=[^>]*\bproperty=(["\'])(?:og:|article:)[^"\']+\1)[^>]*>\s*#i', '', $html);

        // Only remove schema nodes explicitly marked as VonSEO.
        $html = preg_replace('#<script\b[^>]*\bclass=(["\'])[^"\']*\bvonseo-schema\b[^"\']*\1[^>]*>.*?</script>\s*#is', '', $html);

        return $html;
    }
}
