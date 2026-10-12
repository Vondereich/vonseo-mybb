<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/** ACP-only editor and configuration snapshot. Never requests a remote URL. */
class VonSEO_AdminSEO
{
    /** @param string $action @return void */
    public static function handle($action)
    {
        global $mybb, $page, $vonseoAdminCss;
        $writeAction = in_array($action, array('seo_save', 'seo_reset', 'seo_preview'), true);
        if($writeAction)
        {
            if($mybb->request_method !== 'post')
            {
                admin_redirect('index.php?module=config-vonseo&action=seo');
                return;
            }
            verify_post_check($mybb->get_input('my_post_key'));
        }
        $type = $mybb->get_input('entity_type');
        $rawId = $mybb->get_input('entity_id');
        $rawPage = $mybb->get_input('seo_page');
        $valid = is_string($type) && in_array($type, array('forum', 'thread'), true) &&
            is_string($rawId) && ctype_digit($rawId) && strlen($rawId) <= 10 && (int)$rawId > 0 && (int)$rawId <= 2147483647;
        $id = $valid ? (int)$rawId : 0;
        $number = is_string($rawPage) && ctype_digit($rawPage) ? max(1, min(1000000, (int)$rawPage)) : 1;
        $context = $valid ? (new VonSEO_Context(new VonSEO_Url()))->resolveEntity($type, $id, $number, true) : null;
        if($context !== null && $context->publicContent)
        {
            $number = $context->page;
        }
        $eligible = $context !== null && $context->publicContent && VonSEO_Overrides::entityEligible($type, $id);
        $ready = VonSEO_Overrides::ready();
        $errors = array();
        $values = $eligible && $ready ? VonSEO_Overrides::get($type, $id) : array();
        $values += array('seo_title' => '', 'seo_description' => '', 'seo_image' => '', 'index_mode' => 'inherit');
        $storedValues = $values;
        if($writeAction)
        {
            if(!$eligible || !$ready)
            {
                $errors[] = 'A current, guest-visible forum/thread and schema 6 override storage are required. Nothing was saved.';
            }
            $input = array();
            foreach(array('seo_title', 'seo_description', 'seo_image', 'index_mode') as $field)
            {
                $value = $action === 'seo_reset' ? ($field === 'index_mode' ? 'inherit' : '') :
                    (isset($mybb->input[$field]) ? $mybb->input[$field] : $mybb->get_input($field));
                $input[$field] = $value;
            }
            $validated = VonSEO_Overrides::validate($input);
            $errors = array_merge($errors, $validated['errors']);
            $values = array_map(function($value) { return is_string($value) ? $value : ''; }, $input);
            if(!$errors && $action !== 'seo_preview')
            {
                $saved = VonSEO_Overrides::save($type, $id, $validated['values'], isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0);
                $errors = $saved['errors'];
                if(!$errors)
                {
                    log_admin_action('vonseo_'.$action, $type, $id);
                    flash_message($action === 'seo_reset' ? 'Entity overrides were reset to automatic. A containing forum noindex policy still applies.' : 'SEO overrides saved. Native subjects, forum names and URLs were not changed.', 'success');
                    admin_redirect('index.php?module=config-vonseo&action=seo&entity_type='.$type.'&entity_id='.$id.'&seo_page='.$number);
                    return;
                }
            }
            if(!$errors)
            {
                $values = $validated['values'];
            }
        }
        if($eligible)
        {
            // Invalid draft fields must not contaminate the resolved preview.
            $safeDraft = VonSEO_Overrides::validate($values);
            VonSEO_Overrides::apply($context, $errors || $safeDraft['errors'] ? $storedValues : $safeDraft['values']);
        }
        $page->output_header('VonSEO SEO Controls');
        echo $vonseoAdminCss;
        vonseo_admin_tabs('seo');
        echo '<style>.vs-seo-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}.vs-seo-pane{padding:18px;border:1px solid #d9dee7;border-radius:8px;background:#fff;min-width:0}.vs-seo-pane input[type=text],.vs-seo-pane textarea,.vs-seo-pane select{box-sizing:border-box;width:100%;margin:6px 0 16px;padding:9px}.vs-seo-pane pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f3f5;padding:12px}.vs-seo-preview{border:1px solid #d9dee7;border-radius:6px;margin:12px 0;padding:14px;overflow-wrap:anywhere}.vs-seo-search-title{color:#234766;font-size:19px}.vs-seo-mobile{max-width:360px}.vs-seo-social img{display:block;max-width:100%;max-height:240px;object-fit:contain;margin-bottom:10px}.vs-seo-details{display:grid;grid-template-columns:110px minmax(0,1fr);gap:10px;overflow-wrap:anywhere}.vs-seo-details dt{font-weight:600}.vs-seo-details dd{margin:0}@media(max-width:850px){.vs-seo-layout{grid-template-columns:1fr}}@media(max-width:420px){.vs-seo-details{grid-template-columns:1fr}}</style>';
        echo '<h2>SEO Controls and Inspector</h2><p>Configuration snapshot, not a crawl or indexing report. Supported entities: ordinary forums and public approved threads.</p>';
        echo '<style>.vs-seo-launcher{display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin-bottom:18px}.vs-seo-launcher label{display:flex;flex-direction:column;gap:5px;min-width:0}.vs-seo-launcher input{width:110px;max-width:100%;box-sizing:border-box}.vs-seo-launcher input,.vs-seo-launcher select,.vs-seo-launcher button,.vs-seo-pane button{min-height:44px}.vs-seo-pane button{white-space:normal;margin-bottom:8px}</style>';
        echo '<form class="vs-seo-launcher" action="index.php" method="get"><input type="hidden" name="module" value="config-vonseo" /><input type="hidden" name="action" value="seo" />'
            .'<label>Entity <select name="entity_type"><option value="thread"'.($type === 'thread' ? ' selected="selected"' : '').'>Thread</option><option value="forum"'.($type === 'forum' ? ' selected="selected"' : '').'>Forum</option></select></label> '
            .'<label>ID <input type="text" name="entity_id" inputmode="numeric" value="'.($id ?: '').'" /></label> '
            .'<label>Page <input type="text" name="seo_page" inputmode="numeric" value="'.$number.'" size="6" /></label> <button class="button" type="submit">Inspect</button></form>';
        if($errors)
        {
            echo '<div class="vs-notice"><strong>Nothing was saved.</strong><ul>';
            foreach($errors as $error)
            {
                echo '<li>'.self::h($error).'</li>';
            }
            echo '</ul></div>';
        }
        if(!$eligible)
        {
            echo '<div class="vs-notice">'.($valid ? 'Missing, unsupported or guest-inaccessible entity. No private title, description, image or schema is previewed.' : 'Select a valid entity and positive numeric ID to begin.').'</div>';
            $page->output_footer();
            return;
        }
        if(!$ready)
        {
            echo '<div class="vs-notice">Override storage is not ready. Visit Overview for upgrade diagnostics, or deactivate/reactivate VonSEO without uninstalling. Do not save overrides until schema 6 is available.</div>';
        }
        echo '<div class="vs-seo-layout"><section class="vs-seo-pane"><h3>'.self::h(ucfirst($type)).' #'.$id.'</h3>';
        echo '<p>Blank fields use automatic values. These overrides do not rename MyBB content or change its URL.</p>';
        echo '<form action="index.php?module=config-vonseo&amp;action=seo_save" method="post">';
        self::hiddenFields($type, $id, $number);
        foreach(array('seo_title' => array('SEO title', 255), 'seo_description' => array('SEO description', 320), 'seo_image' => array('Social image URL', 2048)) as $field => $label)
        {
            echo '<label for="'.$field.'"><strong>'.self::h($label[0]).'</strong></label>';
            if($field === 'seo_description')
            {
                echo '<textarea id="'.$field.'" name="'.$field.'" rows="4" maxlength="'.$label[1].'">'.self::h($values[$field]).'</textarea>';
            }
            else
            {
                echo '<input class="text_input" type="text" id="'.$field.'" name="'.$field.'" value="'.self::h($values[$field]).'" maxlength="'.$label[1].'" />';
            }
        }
        echo '<label for="index_mode"><strong>Indexing policy</strong></label><select id="index_mode" name="index_mode"><option value="inherit"'.($values['index_mode'] === 'inherit' ? ' selected="selected"' : '').'>Use existing policy</option><option value="noindex"'.($values['index_mode'] === 'noindex' ? ' selected="selected"' : '').'>Noindex</option></select>';
        echo '<p>A forum noindex policy also covers threads directly in that forum, not its subforums. Thread settings cannot override a containing forum noindex policy or guest permission restrictions.</p>';
        echo '<button class="button" type="submit" formaction="index.php?module=config-vonseo&amp;action=seo_preview">Preview without saving</button> ';
        if($ready)
        {
            echo '<button class="button" type="submit">Save overrides</button>';
        }
        echo '</form>';
        if($ready)
        {
            echo '<form action="index.php?module=config-vonseo&amp;action=seo_reset" method="post" style="margin-top:16px">';
            self::hiddenFields($type, $id, $number);
            echo '<button class="button" type="submit">Reset this entity to automatic</button></form>';
        }
        echo '</section><section class="vs-seo-pane">';
        if($errors)
        {
            echo '<p>The invalid draft was not applied. This snapshot uses the saved configuration.</p>';
        }
        self::preview($context, $action === 'seo_preview' && !$errors);
        echo '</section></div>';
        $page->output_footer();
    }

    /** @param string $type @param int $id @param int $number @return void */
    protected static function hiddenFields($type, $id, $number)
    {
        global $mybb;
        echo '<input type="hidden" name="my_post_key" value="'.self::h($mybb->post_code).'" /><input type="hidden" name="entity_type" value="'.$type.'" /><input type="hidden" name="entity_id" value="'.$id.'" /><input type="hidden" name="seo_page" value="'.$number.'" />';
    }

    /** @param bool $draft @return void */
    public static function preview(VonSEO_Context $context, $draft = false)
    {
        $url = new VonSEO_Url();
        $robots = (new VonSEO_Robots($url))->directive($context);
        $enabled = (bool)VonSEO_Utils::setting('vonseo_enabled', 0);
        echo '<h3>'.($draft ? 'Unsaved draft' : 'Resolved snapshot').'</h3><p><strong>Engine output: '.($enabled ? 'On' : 'Off (inactive candidates)').'.</strong></p><dl class="vs-seo-details">';
        $resolved = array('Canonical' => $context->canonical, 'Robots' => $robots,
            'Title' => $context->title, 'Description' => $context->description, 'Image' => $context->socialImage);
        $sources = array('Title' => 'title', 'Description' => 'description', 'Image' => 'socialImage', 'Robots' => 'indexable');
        foreach($resolved as $label => $value)
        {
            echo '<dt>'.self::h($label).'</dt><dd>'.self::h($value !== '' ? $value : 'Not set (optional)').
                (isset($sources[$label]) ? '<br /><small>'.self::h($context->seoSources[$sources[$label]]).'</small>' : '').'</dd>';
        }
        echo '</dl><p><a href="'.self::h($context->canonical).'" target="_blank" rel="noopener noreferrer">Open actual page for a guest check</a></p>';
        foreach($context->seoWarnings as $warning)
        {
            if($context->seoSources['description'] === 'Entity override' && strpos($warning, 'ACP cannot capture') === 0)
            {
                continue;
            }
            echo '<div class="vs-notice">'.self::h($warning).'</div>';
        }
        if(!$enabled)
        {
            echo '<div class="vs-notice"><strong>SEO engine is off.</strong> VonSEO currently emits no metadata or robots directive. These saved/draft values and cards are inactive candidates, not live output. An override noindex policy is not enforced while the engine is off.</div>';
        }
        if(!$context->indexable && $enabled)
        {
            echo '<div class="vs-notice">Noindex is effective. VonSEO emits only a robots directive, not entity canonical/social/schema metadata or a replacement title. The values above are resolved candidates, not emitted tags.</div>';
        }
        elseif($context->indexable)
        {
            echo '<h4>Illustrative search previews</h4><p>Search engines can choose different titles/snippets. These are not live results or pixel-exact predictions.</p>';
            foreach(array('Desktop-style', 'Mobile-style') as $label)
            {
                echo '<div class="vs-seo-preview'.($label === 'Mobile-style' ? ' vs-seo-mobile' : '').'"><small>'.self::h($label).'</small><div>'.self::h($context->canonical).'</div><div class="vs-seo-search-title">'.self::h($context->title).'</div><p>'.self::h($context->description).'</p></div>';
            }
            echo '<h4>Illustrative social preview</h4><div class="vs-seo-preview vs-seo-social">';
            if($context->socialImage !== '')
            {
                echo '<img src="'.self::h($context->socialImage).'" alt="Configured social image preview" referrerpolicy="no-referrer" />';
            }
            else
            {
                echo '<p>No image configured. Text-only preview; this is not a proven code failure.</p>';
            }
            echo '<strong>'.self::h($context->title).'</strong><p>'.self::h($context->description).'</p></div>';
        }
        $meta = new VonSEO_Meta(new VonSEO_Robots($url), new VonSEO_Social(), new VonSEO_Schema($url));
        echo '<details><summary>'.($enabled ? 'Expected VonSEO head tags (escaped text)' : 'Configured head tags (inactive candidates)').'</summary><pre>'.self::h($meta->render($context)).'</pre></details>';
    }

    /** @param string $value @return string */
    protected static function h($value)
    {
        return VonSEO_Utils::h($value);
    }
}
