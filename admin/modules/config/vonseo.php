<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

if(function_exists('vonseo_require_core'))
{
    vonseo_require_core();
}
else
{
    require_once MYBB_ROOT.'inc/plugins/vonseo/Utils.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Url.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Redirects.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Errors.php';
}

/** @var Page $page */
/** @var MyBB $mybb */
/** @var DB_Base $db */
/** @var MyLanguage $lang */
global $page, $mybb, $db, $lang;

$page->add_breadcrumb_item('VonSEO', 'index.php?module=config-vonseo');

$action = $mybb->get_input('action');
$redirects = new VonSEO_Redirects();
$pluginInfo = function_exists('vonseo_info') ? vonseo_info() : array();
$pluginVersion = !empty($pluginInfo['version']) ? $pluginInfo['version'] : 'Unknown';
$canManageSiteSettings = function_exists('check_admin_permissions') &&
    (check_admin_permissions(array('module' => 'config', 'action' => 'vonseo_settings'), false) ||
     check_admin_permissions(array('module' => 'config', 'action' => 'settings'), false));
if(in_array($action, array('site_details_save', 'indexnow_rotate_key'), true) && !$canManageSiteSettings)
{
    if(function_exists('check_admin_permissions'))
    {
        check_admin_permissions(array('module' => 'config', 'action' => 'vonseo_settings'));
    }
    die('Access denied.');
}

$vonseoAdminCss = <<<'CSS'
<style>
.vs-page{color:#2f3640}
.vs-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:18px 0;padding:20px 22px;background:#fff;border:1px solid #d9dee7;border-left:4px solid #4f6fad;box-shadow:0 1px 2px rgba(22,34,51,.04)}
.vs-page-header h2{margin:0 0 5px;color:#202833;font-size:18px;line-height:1.3}
.vs-page-header p{max-width:720px;margin:0;color:#697386;line-height:1.55}
.vs-eyebrow{display:block;margin-bottom:5px;color:#4f6fad;font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}
.vs-version{color:#7a8493;font-size:11px;font-weight:400;white-space:nowrap}
.vs-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.vs-inline-form{display:inline;margin:0}
.vs-inline-form button{margin:0!important}
.vs-page a.button,.vs-page button.button{display:inline-block;margin:0;padding:6px 10px;border:1px solid #b7bfca;border-radius:3px;background:#f6f7f9;color:#34445e;font-family:inherit;font-size:12px;font-weight:600;line-height:1.35;text-decoration:none;white-space:nowrap;cursor:pointer}
.vs-page a.button:hover,.vs-page a.button:focus,.vs-page button.button:hover,.vs-page button.button:focus{background:#e9eef5;border-color:#8b9bb0;color:#273d5b;text-decoration:none}
.vs-page a.vs-primary.button,.vs-page input.submit_button{background:#415f96;border-color:#334f82;color:#fff;text-shadow:none}
.vs-page a.vs-primary.button:hover,.vs-page a.vs-primary.button:focus,.vs-page input.submit_button:hover{background:#334f82;border-color:#29436f;color:#fff}
.vs-page input.submit_button{padding:6px 11px;border-radius:3px;font-size:12px}
.vs-page .form_button_wrapper a.button{margin-left:6px}
.vs-page a.vs-danger.button{background:#fff5f5;border-color:#d8a1a1;color:#963b3b}
.vs-page a.vs-danger.button:hover,.vs-page a.vs-danger.button:focus{background:#fbe8e8;border-color:#c98282;color:#7d2d2d}
.vs-page a.button:focus-visible,.vs-button-link:focus-visible{outline:2px solid #2f5fa5;outline-offset:2px}
.vs-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 20px}
.vs-summary.vs-three{grid-template-columns:repeat(3,minmax(0,1fr))}
.vs-summary-card{min-width:0;padding:15px 17px;background:#fff;border:1px solid #d9dee7;border-top:3px solid #8b9ab0}
.vs-summary-card strong{display:block;overflow:hidden;color:#202833;font-size:22px;line-height:1.15;text-overflow:ellipsis;white-space:nowrap}
.vs-summary-card span{display:block;margin-top:5px;color:#697386;font-size:11px;letter-spacing:.02em}
.vs-status{display:inline-flex;align-items:center;gap:7px;font-weight:600;white-space:nowrap}
.vs-status:before{width:8px;height:8px;border-radius:50%;background:#98a2b3;content:""}
.vs-status.is-on{color:#28734f}
.vs-status.is-on:before{background:#36a269}
.vs-status.is-off{color:#8a4b4b}
.vs-status.is-off:before{background:#c86c6c}
.vs-status.is-warn{color:#856019}
.vs-status.is-warn:before{background:#d4a638}
.vs-status.is-neutral{color:#5d6877}
.vs-status.is-neutral:before{background:#98a2b3}
.vs-setup{margin:16px 0 20px;padding:15px 17px;background:#f7f9fc;border:1px solid #d9e1ec;border-left:3px solid #6d87ad;line-height:1.5}
.vs-setup.is-warning{background:#fffaf0;border-color:#e8d6ab;border-left-color:#c79632}
.vs-setup-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.vs-setup-head h3{margin:0;color:#202833;font-size:15px}
.vs-setup p{margin:9px 0 0;color:#4c596b}
.vs-setup ol{margin:9px 0 0;padding-left:20px;color:#4c596b}
.vs-setup li+li{margin-top:4px}
.vs-setup-meta{display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:9px;color:#4c596b}
.vs-setup-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;margin-top:12px}
.vs-setup-help{margin-top:10px;color:#4c596b}
.vs-setup-help summary{color:#405b88;cursor:pointer;font-weight:600}
.vs-setup-help summary:focus-visible,.vs-details summary:focus-visible{outline:2px solid #2f5fa5;outline-offset:2px}
.vs-setup code{overflow-wrap:anywhere}
.vs-details{margin:16px 0 20px}
.vs-details summary{padding:10px 12px;background:#f6f7f9;border:1px solid #d9dee7;color:#34445e;cursor:pointer;font-weight:600}
.vs-details[open] summary{margin-bottom:16px}
.vs-guide{margin:18px 0;background:#fff;border:1px solid #d9dee7}
.vs-guide-step{display:flex;gap:15px;padding:16px 18px;border-bottom:1px solid #e5e9ef;line-height:1.55}
.vs-guide-step:last-child{border-bottom:0}
.vs-guide-number{display:inline-flex;flex:none;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#e9eef5;color:#405b88;font-weight:700}
.vs-guide-step h3{margin:0 0 3px;color:#202833;font-size:14px}
.vs-guide-step p{margin:0 0 7px;color:#4c596b}
.vs-guide-step p:last-child{margin-bottom:0}
.vs-table-wrap{max-width:100%;margin-bottom:18px;overflow-x:auto;border:1px solid transparent}
.vs-table-wrap table{min-width:680px}
.vs-table-wrap td{vertical-align:middle}
.vs-code{display:inline-block;max-width:520px;overflow-wrap:anywhere;color:#2b405e;font-family:Consolas,"Liberation Mono",monospace;font-size:11px;line-height:1.5}
.vs-subtle{color:#7a8493;font-size:11px;line-height:1.45}
.vs-row-actions{display:flex;justify-content:center;gap:10px;white-space:nowrap}
.vs-row-actions a{font-weight:600}
.vs-row-actions .vs-delete{color:#a13f3f}
.vs-notice{margin:14px 0;padding:12px 14px;background:#f5f8fc;border:1px solid #d5deeb;border-left:3px solid #6d87ad;color:#4c596b;line-height:1.55}
.vs-notice.is-warning{background:#fffaf0;border-color:#e8d6ab;border-left-color:#c79632;color:#67542c}
.vs-form-note{margin:0 0 16px;padding:12px 14px;background:#f6f8fa;border:1px solid #dfe3e8;color:#5d6877;line-height:1.55}
.vs-form-note code{white-space:normal}
.vs-preview-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px}
.vs-preview-links a{font-weight:600}
.vs-empty{padding:18px!important;color:#697386;text-align:center}
.vs-limit-note{margin:-8px 0 18px;color:#7a8493;font-size:11px;text-align:right}
.vs-endpoint{font-family:Consolas,"Liberation Mono",monospace;font-size:11px;overflow-wrap:anywhere}
.vs-button-link{display:inline-block;padding:5px 9px;border:1px solid #cbd2dc;background:#fff;color:#405574;font-weight:600;text-decoration:none}
.vs-button-link:hover{border-color:#9facc0;background:#f5f7fa;color:#273b59;text-decoration:none}
@media(max-width:900px){.vs-summary,.vs-summary.vs-three{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1100px){
.vs-page-header{align-items:stretch;flex-direction:column;gap:12px}
.vs-actions{justify-content:flex-start}
.vs-table-wrap{overflow-x:visible}
.vs-table-health table,.vs-table-endpoints table{min-width:0;width:100%;table-layout:fixed}
.vs-table-health th,.vs-table-health td,.vs-table-endpoints th,.vs-table-endpoints td{overflow-wrap:anywhere}
.vs-table-cards table,.vs-table-cards tbody,.vs-table-cards tr{display:block;width:100%;min-width:0;box-sizing:border-box}
.vs-table-cards thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
.vs-table-cards tbody tr{padding:5px 0;border-bottom:1px solid #dfe3e8}
.vs-table-cards tbody tr:last-child{border-bottom:0}
.vs-table-cards tbody td{display:grid;grid-template-columns:minmax(100px,30%) minmax(0,1fr);gap:10px;width:auto!important;min-width:0;box-sizing:border-box;text-align:left!important;overflow-wrap:anywhere}
.vs-table-cards tbody td:before{color:#5d6877;font-weight:700}
.vs-table-cards tbody td[colspan]{display:block}
.vs-table-cards tbody td[colspan]:before{display:none}
.vs-table-cards .vs-row-actions{justify-content:flex-start;white-space:normal}
.vs-table-redirects td:nth-child(1):before{content:"Source"}
.vs-table-redirects td:nth-child(2):before{content:"Destination / Result"}
.vs-table-redirects td:nth-child(3):before{content:"Response"}
.vs-table-redirects td:nth-child(4):before{content:"Hits"}
.vs-table-redirects td:nth-child(5):before{content:"Last Hit"}
.vs-table-redirects td:nth-child(6):before{content:"Controls"}
.vs-table-notfound td:nth-child(1):before{content:"Missing URL"}
.vs-table-notfound td:nth-child(2):before{content:"Hits"}
.vs-table-notfound td:nth-child(3):before{content:"First Seen"}
.vs-table-notfound td:nth-child(4):before{content:"Last Seen"}
.vs-table-notfound td:nth-child(5):before{content:"Controls"}
}
@media(max-width:680px){.vs-page-header{align-items:stretch;flex-direction:column;padding:16px}.vs-actions{justify-content:flex-start}.vs-summary{grid-template-columns:1fr 1fr}.vs-summary-card strong{font-size:19px}}
@media(max-width:420px){.vs-summary,.vs-summary.vs-three{grid-template-columns:1fr}.vs-setup-head{align-items:flex-start;flex-direction:column}.vs-table-cards tbody td{grid-template-columns:minmax(85px,34%) minmax(0,1fr)}}
</style>
CSS;

/**
 * @param string $active
 * @return void
 */
function vonseo_admin_tabs($active)
{
    global $page;

    $tabs = array(
        'overview' => array(
            'title' => 'Overview',
            'link' => 'index.php?module=config-vonseo',
            'description' => 'VonSEO module health and crawler endpoints.'
        ),
        'guide' => array(
            'title' => 'How to use',
            'link' => 'index.php?module=config-vonseo&amp;action=guide',
            'description' => 'A short guide to VonSEO and its optional tools.'
        ),
        'redirects' => array(
            'title' => 'Redirects',
            'link' => 'index.php?module=config-vonseo&amp;action=redirects',
            'description' => 'Manage 301, 302, 307, 308 and 410 URL rules.'
        ),
        'notfound' => array(
            'title' => '404 Monitor',
            'link' => 'index.php?module=config-vonseo&amp;action=notfound',
            'description' => 'Review recurring missing public URLs and create fixes.'
        )
    );

    $page->output_nav_tabs($tabs, $active);
}

if($action === 'site_details_save')
{
    if($mybb->request_method !== 'post')
    {
        admin_redirect('index.php?module=config-vonseo');
    }
    verify_post_check($mybb->get_input('my_post_key'));

    $siteName = trim(VonSEO_Utils::plainText($mybb->get_input('site_name')));
    $siteUrl = rtrim(trim((string)$mybb->get_input('site_url')), '/');
    $homepageName = trim(VonSEO_Utils::plainText($mybb->get_input('homepage_name')));
    $homepageUrl = trim((string)$mybb->get_input('homepage_url'));
    $siteDescription = trim(VonSEO_Utils::plainText($mybb->get_input('site_description')));

    $siteUrlParts = $siteUrl !== '' ? @parse_url($siteUrl) : false;
    $homepageUrlParts = $homepageUrl !== '' ? @parse_url($homepageUrl) : false;
    $siteUrlValid = is_array($siteUrlParts) && !empty($siteUrlParts['scheme']) && !empty($siteUrlParts['host']) &&
        in_array(strtolower((string)$siteUrlParts['scheme']), array('http', 'https'), true) &&
        empty($siteUrlParts['user']) && empty($siteUrlParts['pass']) && empty($siteUrlParts['query']) && empty($siteUrlParts['fragment']) &&
        !preg_match('/[\x00-\x20\x7F]/', $siteUrl) && strpos($siteUrl, '\\') === false;
    $homepageUrlValid = $homepageUrl === '' || (is_array($homepageUrlParts) && !empty($homepageUrlParts['scheme']) && !empty($homepageUrlParts['host']) &&
        in_array(strtolower((string)$homepageUrlParts['scheme']), array('http', 'https'), true) &&
        empty($homepageUrlParts['user']) && empty($homepageUrlParts['pass']) &&
        !preg_match('/[\x00-\x20\x7F]/', $homepageUrl) && strpos($homepageUrl, '\\') === false);

    $error = '';
    if($siteName === '' || VonSEO_Utils::strlen($siteName) > 75)
    {
        $error = 'Board Name is required and must not exceed 75 characters.';
    }
    elseif(!$siteUrlValid)
    {
        $error = 'Board URL must be an absolute HTTP or HTTPS URL without credentials, a query string or fragment.';
    }
    elseif(VonSEO_Utils::strlen($homepageName) > 120)
    {
        $error = 'Homepage Name must not exceed 120 characters.';
    }
    elseif(!$homepageUrlValid)
    {
        $error = 'Homepage URL must be blank or an absolute HTTP or HTTPS URL without credentials.';
    }
    elseif(VonSEO_Utils::strlen($siteDescription) > 320)
    {
        $error = 'SEO Description must not exceed 320 characters.';
    }

    if($error !== '')
    {
        flash_message($error, 'error');
        admin_redirect('index.php?module=config-vonseo');
    }

    $db->update_query('settings', array('value' => $db->escape_string($siteName)), "name='bbname'");
    $db->update_query('settings', array('value' => $db->escape_string($siteUrl)), "name='bburl'");
    $db->update_query('settings', array('value' => $db->escape_string($homepageName)), "name='homename'");
    $db->update_query('settings', array('value' => $db->escape_string($homepageUrl)), "name='homeurl'");
    // Keep the SEO description in MyBB's settings cache without duplicating it
    // on the Advanced Settings screen. Site Details is its only editor.
    $db->update_query('settings', array('value' => $db->escape_string($siteDescription), 'gid' => 0), "name='vonseo_site_description'");
    $db->delete_query('settings', "name='vonseo_home_title'");
    rebuild_settings();
    log_admin_action('vonseo_site_details_save');
    flash_message('Site Details were saved to the original MyBB settings and VonSEO SEO Description. No duplicate site-identity settings were created.', 'success');
    admin_redirect('index.php?module=config-vonseo');
}

if($action === 'indexnow_rotate_key')
{
    if($mybb->request_method !== 'post')
    {
        admin_redirect('index.php?module=config-vonseo');
    }
    verify_post_check($mybb->get_input('my_post_key'));
    vonseo_require_core();
    $newKey = (new VonSEO_IndexNow())->generateKey();
    $db->update_query('settings', array('value' => $newKey), "name='vonseo_indexnow_key'");
    rebuild_settings();
    log_admin_action('vonseo_indexnow_rotate_key');
    flash_message('A new IndexNow key replaced the previous key. The dynamic verification endpoint now serves only the new key; the queue was preserved.', 'success');
    admin_redirect('index.php?module=config-vonseo');
}

if($action === 'redirect_save')
{
    if($mybb->request_method !== 'post')
    {
        admin_redirect('index.php?module=config-vonseo&action=redirects');
        return;
    }
    verify_post_check($mybb->get_input('my_post_key'));

    $rid = $mybb->get_input('rid', MyBB::INPUT_INT);
    $source = $mybb->get_input('source');
    $target = $mybb->get_input('target');
    $statusCode = $mybb->get_input('status_code', MyBB::INPUT_INT);
    $enabled = $mybb->get_input('enabled', MyBB::INPUT_INT) ? 1 : 0;

    $result = $redirects->saveRule($source, $target, $statusCode, $enabled, $rid);
    if(!empty($result['errors']))
    {
        $mybb->input['action'] = $rid ? 'redirect_edit' : 'redirect_add';
        $action = $mybb->input['action'];
        $GLOBALS['vonseo_admin_errors'] = $result['errors'];
        $GLOBALS['vonseo_admin_form_data'] = array(
            'rid' => $rid,
            'source_path' => $source,
            'target_url' => $target,
            'status_code' => $statusCode,
            'enabled' => $enabled
        );
    }
    else
    {
        log_admin_action('vonseo_redirect', $result['rid'], $result['source'], $result['status_code']);
        flash_message('VonSEO redirect saved.', 'success');
        admin_redirect('index.php?module=config-vonseo&action=redirects');
    }
}

if($action === 'redirect_delete')
{
    $rid = $mybb->get_input('rid', MyBB::INPUT_INT);
    $query = $db->simple_select('vonseo_redirects', '*', "rid='{$rid}'", array('limit' => 1));
    $rule = $db->fetch_array($query);

    if(!$rule)
    {
        flash_message('Redirect rule was not found.', 'error');
        admin_redirect('index.php?module=config-vonseo&action=redirects');
    }

    if($mybb->request_method === 'post')
    {
        verify_post_check($mybb->get_input('my_post_key'));
        $db->delete_query('vonseo_redirects', "rid='{$rid}'");
        if(class_exists('VonSEO_State'))
        {
            VonSEO_State::refreshRedirectCount();
        }
        log_admin_action('vonseo_redirect_delete', $rid, $rule['source_path']);
        flash_message('Redirect rule deleted.', 'success');
        admin_redirect('index.php?module=config-vonseo&action=redirects');
    }

    $page->output_confirm_action('index.php?module=config-vonseo&action=redirect_delete&rid='.$rid, 'Delete this VonSEO redirect rule?');
}

if($action === 'redirects_export')
{
    $csv = $redirects->exportCsv();
    $filename = 'vonseo_redirects_'.date('Y-m-d').'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $csv;
    exit;
}

if($action === 'redirects_import')
{
    if($mybb->request_method === 'post')
    {
        verify_post_check($mybb->get_input('my_post_key'));
        $csvContent = '';
        $result = null;
        if(isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name']))
        {
            $overwrite = (bool)$mybb->get_input('overwrite', MyBB::INPUT_INT);
            $result = $redirects->importCsvFile($_FILES['csv_file']['tmp_name'], $overwrite);
        }
        if($result === null)
        {
            $csvContent = $mybb->get_input('csv_text');
            $overwrite = (bool)$mybb->get_input('overwrite', MyBB::INPUT_INT);
            $result = $redirects->importCsv($csvContent, $overwrite);
        }

        $msg = "Import completed: {$result['imported']} created, {$result['updated']} updated, {$result['skipped']} skipped.";
        if(!empty($result['errors']))
        {
            $msg .= '<br />Warnings: '.htmlspecialchars_uni(implode(' | ', array_slice($result['errors'], 0, 5)));
        }

        log_admin_action('vonseo_redirects_import', (int)$result['imported'], (int)$result['updated'], (int)$result['skipped'], count($result['errors']));
        flash_message($msg, !empty($result['errors']) && $result['imported'] === 0 ? 'error' : 'success');
        admin_redirect('index.php?module=config-vonseo&action=redirects');
    }

    $page->output_header('VonSEO - Import Redirects');
    vonseo_admin_tabs('redirects');

    echo $vonseoAdminCss;
    echo '<div class="vs-page">';
    echo '<div class="vs-page-header">';
    echo '<div><span class="vs-eyebrow">Redirect tools</span><h2>Import redirect rules</h2>';
    echo '<p>Upload a CSV file or paste its contents below. Review overwrite behaviour before importing into an existing rule set.</p></div>';
    echo '<div class="vs-actions"><a class="button" href="index.php?module=config-vonseo&amp;action=redirects">Back to redirects</a></div>';
    echo '</div>';
    echo '<div class="vs-form-note"><strong>Required header:</strong> <code>source_path,target_url,status_code,enabled</code><br />Accepted status codes: 301, 302, 307, 308 and 410. Maximum 2 MiB and 5,000 rows. Quoted multiline fields are supported.</div>';

    /** @var Form $form */
    $form = new Form('index.php?module=config-vonseo&amp;action=redirects_import', 'post', 'redirect_import_form', 1);
    /** @var FormContainer $container */
    $container = new FormContainer('Import Redirect Rules (CSV)');
    $container->output_row(
        'CSV File',
        'Choose a UTF-8 CSV file. If a file is selected, the pasted text below is ignored.',
        $form->generate_file_upload_box('csv_file')
    );
    $container->output_row(
        'Or Paste CSV Text',
        'Paste the header and one redirect rule per line.',
        $form->generate_text_area('csv_text', '', array('rows' => 10, 'style' => 'width: 100%; max-width: 820px; font-family: Consolas, monospace;'))
    );
    $container->output_row(
        'Overwrite Existing',
        'Yes updates rules with identical source paths. No leaves those existing rules unchanged.',
        $form->generate_yes_no_radio('overwrite', 0)
    );
    $container->end();

    $buttons = array(
        $form->generate_submit_button('Import redirects'),
        '<a class="button" href="index.php?module=config-vonseo&amp;action=redirects">Cancel</a>'
    );
    $form->output_submit_wrapper($buttons);
    $form->end();
    echo '</div>';
    $page->output_footer();
    exit;
}

if($action === 'notfound_clear')
{
    $hash = $mybb->get_input('hash');
    if($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash))
    {
        admin_redirect('index.php?module=config-vonseo&action=notfound');
    }

    if($mybb->request_method === 'post')
    {
        verify_post_check($mybb->get_input('my_post_key'));
        $db->delete_query('vonseo_404_log', "path_hash='".$db->escape_string($hash)."'");
        if(class_exists('VonSEO_State'))
        {
            VonSEO_State::refresh404Count();
        }
        log_admin_action('vonseo_404_clear', $hash);
        flash_message('404 entry cleared.', 'success');
        admin_redirect('index.php?module=config-vonseo&action=notfound');
    }

    $page->output_confirm_action('index.php?module=config-vonseo&action=notfound_clear&hash='.$hash, 'Clear this VonSEO 404 entry?');
}

if($action === 'notfound_clear_all')
{
    if($mybb->request_method === 'post')
    {
        verify_post_check($mybb->get_input('my_post_key'));
        $db->delete_query('vonseo_404_log');
        if(class_exists('VonSEO_State'))
        {
            VonSEO_State::refresh404Count();
        }
        log_admin_action('vonseo_404_clear_all');
        flash_message('404 monitor cleared.', 'success');
        admin_redirect('index.php?module=config-vonseo&action=notfound');
    }

    $page->output_confirm_action('index.php?module=config-vonseo&action=notfound_clear_all', 'Clear all VonSEO 404 monitor entries?');
}

if($action === 'redirect_add' || $action === 'redirect_edit')
{
    $rid = $mybb->get_input('rid', MyBB::INPUT_INT);
    $data = array(
        'rid' => 0,
        'source_path' => $mybb->get_input('source'),
        'target_url' => '',
        'status_code' => 301,
        'enabled' => 1
    );

    if(!empty($GLOBALS['vonseo_admin_form_data']))
    {
        $data = array_merge($data, $GLOBALS['vonseo_admin_form_data']);
    }
    elseif($action === 'redirect_edit' && $rid > 0)
    {
        $query = $db->simple_select('vonseo_redirects', '*', "rid='{$rid}'", array('limit' => 1));
        $row = $db->fetch_array($query);
        if(!$row)
        {
            flash_message('Redirect rule was not found.', 'error');
            admin_redirect('index.php?module=config-vonseo&action=redirects');
        }
        $data = $row;
    }

    $page->output_header('VonSEO - '.($data['rid'] ? 'Edit Redirect' : 'Add Redirect'));
    vonseo_admin_tabs('redirects');

    echo $vonseoAdminCss;
    echo '<div class="vs-page">';
    echo '<div class="vs-page-header">';
    echo '<div><span class="vs-eyebrow">Redirect manager</span><h2>'.($data['rid'] ? 'Edit redirect rule' : 'Create a redirect rule').'</h2>';
    echo '<p>Match one board-relative path and choose the exact HTTP response VonSEO should return.</p></div>';
    echo '<div class="vs-actions"><a class="button" href="index.php?module=config-vonseo&amp;action=redirects">Back to redirects</a></div>';
    echo '</div>';

    if(!empty($GLOBALS['vonseo_admin_errors']))
    {
        $page->output_inline_error($GLOBALS['vonseo_admin_errors']);
    }

    $form = new Form('index.php?module=config-vonseo&amp;action=redirect_save', 'post');
    echo $form->generate_hidden_field('rid', (int)$data['rid']);

    $container = new FormContainer($data['rid'] ? 'Edit Redirect Rule' : 'Add Redirect Rule');
    $container->output_row(
        'Source URL <em>*</em>',
        'Use a board-relative path such as <code>/old-thread</code> or <code>/old?ref=1</code>. Matching is exact.',
        $form->generate_text_box('source', $data['source_path'], array('id' => 'source', 'style' => 'width: 100%; max-width: 820px', 'placeholder' => '/old-thread')),
        'source'
    );
    $container->output_row(
        'Destination URL',
        'Use an internal path or an allowed HTTP/HTTPS URL. Leave this empty only when using 410 Gone.',
        $form->generate_text_box('target', $data['target_url'], array('id' => 'target', 'style' => 'width: 100%; max-width: 820px', 'placeholder' => '/new-thread')),
        'target'
    );
    $container->output_row(
        'HTTP Status',
        'Use 301/308 for permanent moves, 302/307 for temporary moves, or 410 when the resource is intentionally gone.',
        $form->generate_select_box('status_code', array(
            301 => '301 - Moved Permanently',
            302 => '302 - Found / Temporary',
            307 => '307 - Temporary Redirect (method preserving)',
            308 => '308 - Permanent Redirect (method preserving)',
            410 => '410 - Gone'
        ), (int)$data['status_code'], array('id' => 'status_code')),
        'status_code'
    );
    $container->output_row(
        'Enabled',
        'Disabled rules stay in the database but are ignored at runtime.',
        $form->generate_yes_no_radio('enabled', (int)$data['enabled']),
        'enabled'
    );
    $container->end();

    echo '<div class="vs-form-note"><strong>Tip:</strong> Disable a rule when you want to pause it without losing its hit history.';
    echo '<div class="vs-preview-links"><span>Preview entered URLs:</span>';
    echo '<a id="vs-preview-source" href="#" target="_blank" rel="noopener noreferrer" hidden>Open source URL</a>';
    echo '<a id="vs-preview-target" href="#" target="_blank" rel="noopener noreferrer" hidden>Open destination URL</a></div>';
    echo '<span class="vs-subtle">These links open the entered addresses; they do not simulate an unsaved redirect response.</span></div>';

    $buttons = array(
        $form->generate_submit_button($data['rid'] ? 'Save changes' : 'Create redirect'),
        '<a class="button" href="index.php?module=config-vonseo&amp;action=redirects">Cancel</a>'
    );
    $form->output_submit_wrapper($buttons);
    $form->end();
    $previewBase = rtrim((string)$mybb->settings['bburl'], '/').'/';
    $previewConfig = json_encode(array(
        'base' => $previewBase,
        'allowExternal' => !empty($mybb->settings['vonseo_external_redirects'])
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    echo '<script>(function(config){'
        .'var source=document.getElementById("source"),target=document.getElementById("target"),status=document.getElementById("status_code");'
        .'var sourceLink=document.getElementById("vs-preview-source"),targetLink=document.getElementById("vs-preview-target");'
        .'var baseUrl;try{baseUrl=new URL(config.base);}catch(error){return;}'
        .'function resolve(value,sourceMode){value=value.trim();if(!value){return "";}try{'
        .'var absolute=/^https?:\/\//i.test(value);var parsed=absolute?new URL(value):new URL(value.replace(/^\/+/,""),baseUrl);'
        .'if(parsed.protocol!=="http:"&&parsed.protocol!=="https:"){return "";}'
        .'if(sourceMode&&parsed.origin!==baseUrl.origin){return "";}'
        .'if(!sourceMode&&absolute&&!config.allowExternal&&parsed.origin!==baseUrl.origin){return "";}'
        .'return parsed.href;}catch(error){return "";}}'
        .'function update(){var sourceUrl=resolve(source.value,true);sourceLink.hidden=!sourceUrl;sourceLink.href=sourceUrl||"#";'
        .'var targetUrl=status.value==="410"?"":resolve(target.value,false);targetLink.hidden=!targetUrl;targetLink.href=targetUrl||"#";}'
        .'source.addEventListener("input",update);target.addEventListener("input",update);status.addEventListener("change",update);update();'
        .'}('.$previewConfig.'));</script>';
    echo '</div>';
    $page->output_footer();
    exit;
}

if($action === 'redirects')
{
    $redirectCount = 0;
    $enabledRedirectCount = 0;
    $redirectHits = 0;
    if($db->table_exists('vonseo_redirects'))
    {
        $summaryQuery = $db->simple_select('vonseo_redirects', 'COUNT(rid) AS total, SUM(enabled) AS enabled, SUM(hits) AS hits');
        $summary = $db->fetch_array($summaryQuery);
        $redirectCount = (int)$summary['total'];
        $enabledRedirectCount = (int)$summary['enabled'];
        $redirectHits = (int)$summary['hits'];
    }

    $page->output_header('VonSEO - Redirects');
    vonseo_admin_tabs('redirects');

    echo $vonseoAdminCss;
    echo '<div class="vs-page">';
    echo '<div class="vs-page-header">';
    echo '<div><span class="vs-eyebrow">URL maintenance</span><h2>Redirect manager</h2>';
    echo '<p>Create exact-path redirects, retire removed URLs with 410 responses, and review which rules are actually used.</p></div>';
    echo '<div class="vs-actions">';
    echo '<a class="button" href="index.php?module=config-vonseo&amp;action=redirects_import">Import CSV</a>';
    echo '<a class="button" href="index.php?module=config-vonseo&amp;action=redirects_export">Export CSV</a>';
    echo '<a class="button vs-primary" href="index.php?module=config-vonseo&amp;action=redirect_add">Create redirect</a>';
    echo '</div></div>';
    if(empty($mybb->settings['vonseo_enabled']) || empty($mybb->settings['vonseo_redirects_enabled']))
    {
        echo '<div class="vs-notice is-warning">Redirects are currently disabled in VonSEO settings. Saved rules will not run until the SEO engine and redirect engine are enabled.</div>';
    }

    echo '<div class="vs-summary">';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($redirectCount).'</strong><span>Total rules</span></div>';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($enabledRedirectCount).'</strong><span>Enabled</span></div>';
    echo '<div class="vs-summary-card"><strong>'.my_number_format(max(0, $redirectCount - $enabledRedirectCount)).'</strong><span>Disabled</span></div>';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($redirectHits).'</strong><span>Total matched requests</span></div>';
    echo '</div>';

    $table = new Table;
    $table->construct_header('Source');
    $table->construct_header('Destination / Result');
    $table->construct_header('Response', array('class' => 'align_center', 'width' => 120));
    $table->construct_header('Hits', array('class' => 'align_center', 'width' => 80));
    $table->construct_header('Last Hit', array('class' => 'align_center', 'width' => 150));
    $table->construct_header('Controls', array('class' => 'align_center', 'width' => 140));

    if($db->table_exists('vonseo_redirects'))
    {
        $query = $db->simple_select('vonseo_redirects', '*', '', array('order_by' => 'rid', 'order_dir' => 'DESC', 'limit' => 200));
        while($rule = $db->fetch_array($query))
        {
            $target = (int)$rule['status_code'] === 410
                ? '<span class="vs-subtle">No destination: resource is gone</span>'
                : '<span class="vs-code">'.htmlspecialchars_uni($rule['target_url']).'</span>';
            $stateClass = $rule['enabled'] ? 'is-on' : 'is-off';
            $stateLabel = $rule['enabled'] ? 'Enabled' : 'Disabled';
            $status = '<strong>'.(int)$rule['status_code'].'</strong><br /><span class="vs-status '.$stateClass.'">'.$stateLabel.'</span>';
            $last = $rule['last_hit'] ? my_date('relative', $rule['last_hit']) : '<span class="vs-subtle">Never</span>';
            $table->construct_cell('<span class="vs-code"><strong>'.htmlspecialchars_uni($rule['source_path']).'</strong></span>');
            $table->construct_cell($target);
            $table->construct_cell($status, array('class' => 'align_center'));
            $table->construct_cell(my_number_format($rule['hits']), array('class' => 'align_center'));
            $table->construct_cell($last, array('class' => 'align_center'));
            $controls = '<span class="vs-row-actions"><a href="index.php?module=config-vonseo&amp;action=redirect_edit&amp;rid='.(int)$rule['rid'].'">Edit</a>'
                .'<a class="vs-delete" href="index.php?module=config-vonseo&amp;action=redirect_delete&amp;rid='.(int)$rule['rid'].'">Delete</a></span>';
            $table->construct_cell($controls, array('class' => 'align_center'));
            $table->construct_row();
        }
    }

    if($table->num_rows() === 0)
    {
        $table->construct_cell('<div class="vs-empty"><strong>No redirect rules yet.</strong><br />Create a rule manually or import an existing CSV file.</div>', array('colspan' => 6));
        $table->construct_row();
    }

    echo '<div class="vs-table-wrap vs-table-cards vs-table-redirects">';
    $table->output('Redirect rules');
    echo '</div>';
    if($redirectCount > 200)
    {
        echo '<p class="vs-limit-note">Showing the 200 newest rules.</p>';
    }
    echo '</div>';
    $page->output_footer();
    exit;
}

if($action === 'notfound')
{
    $notFoundCount = 0;
    $notFoundHits = 0;
    $topPathHits = 0;
    if($db->table_exists('vonseo_404_log'))
    {
        $summaryQuery = $db->simple_select('vonseo_404_log', 'COUNT(path_hash) AS total, SUM(hits) AS hits, MAX(hits) AS top_hits');
        $summary = $db->fetch_array($summaryQuery);
        $notFoundCount = (int)$summary['total'];
        $notFoundHits = (int)$summary['hits'];
        $topPathHits = (int)$summary['top_hits'];
    }

    $page->output_header('VonSEO - 404 Monitor');
    vonseo_admin_tabs('notfound');

    echo $vonseoAdminCss;
    echo '<div class="vs-page">';
    echo '<div class="vs-page-header">';
    echo '<div><span class="vs-eyebrow">URL maintenance</span><h2>404 monitor</h2>';
    echo '<p>Find recurring missing paths, create a redirect when a replacement exists, or clear entries you have reviewed.</p></div>';
    if($notFoundCount > 0)
    {
        echo '<div class="vs-actions"><a class="button vs-danger" href="index.php?module=config-vonseo&amp;action=notfound_clear_all">Clear all entries</a></div>';
    }
    echo '</div>';
    if(empty($mybb->settings['vonseo_enabled']) || empty($mybb->settings['vonseo_404_monitor']))
    {
        echo '<div class="vs-notice is-warning">404 monitoring is currently disabled in VonSEO settings. New missing paths will not be logged until the SEO engine and 404 monitor are enabled.</div>';
    }
    echo '<div class="vs-summary vs-three">';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($notFoundCount).'</strong><span>Unique missing paths</span></div>';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($notFoundHits).'</strong><span>Total 404 hits</span></div>';
    echo '<div class="vs-summary-card"><strong>'.my_number_format($topPathHits).'</strong><span>Hits on top path</span></div>';
    echo '</div>';
    echo '<div class="vs-notice">Only missing URL paths, route-specific public identifiers and aggregate counts are stored. Unknown, tracking and secret-bearing query fields are discarded; visitor IP addresses are not recorded.</div>';

    $table = new Table;
    $table->construct_header('Missing URL');
    $table->construct_header('Hits', array('class' => 'align_center', 'width' => 90));
    $table->construct_header('First Seen', array('class' => 'align_center', 'width' => 160));
    $table->construct_header('Last Seen', array('class' => 'align_center', 'width' => 160));
    $table->construct_header('Controls', array('class' => 'align_center', 'width' => 180));

    if($db->table_exists('vonseo_404_log'))
    {
        $query = $db->simple_select('vonseo_404_log', '*', '', array('order_by' => 'hits', 'order_dir' => 'DESC', 'limit' => 250));
        while($row = $db->fetch_array($query))
        {
            $path = htmlspecialchars_uni($row['path']);
            $table->construct_cell('<span class="vs-code">'.$path.'</span>');
            $table->construct_cell(my_number_format($row['hits']), array('class' => 'align_center'));
            $table->construct_cell(my_date('relative', $row['first_seen']), array('class' => 'align_center'));
            $table->construct_cell(my_date('relative', $row['last_seen']), array('class' => 'align_center'));
            $create = strpos((string)$row['path'], 'REDACTED') === false
                ? '<a href="index.php?module=config-vonseo&amp;action=redirect_add&amp;source='.urlencode($row['path']).'">Create redirect</a>'
                : '<span title="Legacy redacted rows cannot form a safe redirect source.">Legacy row</span>';
            $controls = '<span class="vs-row-actions">'.$create
                .'<a class="vs-delete" href="index.php?module=config-vonseo&amp;action=notfound_clear&amp;hash='.htmlspecialchars_uni($row['path_hash']).'">Clear</a></span>';
            $table->construct_cell($controls, array('class' => 'align_center'));
            $table->construct_row();
        }
    }

    if($table->num_rows() === 0)
    {
        $table->construct_cell('<div class="vs-empty"><strong>No missing URLs recorded.</strong><br />New 404 paths will appear here when VonSEO and monitoring are enabled.</div>', array('colspan' => 5));
        $table->construct_row();
    }

    echo '<div class="vs-table-wrap vs-table-cards vs-table-notfound">';
    $table->output('Most requested missing paths');
    echo '</div>';
    if($notFoundCount > 250)
    {
        echo '<p class="vs-limit-note">Showing the 250 paths with the most hits.</p>';
    }
    echo '</div>';
    $page->output_footer();
    exit;
}

if($action === 'guide')
{
    $page->output_header('VonSEO - How to use');
    vonseo_admin_tabs('guide');

    $guideGidQuery = $db->simple_select('settinggroups', 'gid', "name='vonseo'", array('limit' => 1));
    $guideGid = (int)$db->fetch_field($guideGidQuery, 'gid');
    $guideBoardUrl = rtrim($mybb->settings['bburl'], '/');

    echo $vonseoAdminCss;
    echo '<div class="vs-page">';
    echo '<div class="vs-page-header"><div><span class="vs-eyebrow">Quick guide</span><h2>How to use VonSEO <span class="vs-version">v'.htmlspecialchars_uni($pluginVersion).'</span></h2>';
    echo '<p>You do not need to change every advanced setting. Start with the defaults, then enable optional tools only when you need them.</p></div></div>';
    echo '<div class="vs-guide">';
    echo '<div class="vs-guide-step"><span class="vs-guide-number">1</span><div><h3>Keep basic SEO on</h3>';
    echo '<p>VonSEO automatically adds descriptions, canonical links, social tags and structured data to eligible public pages. No per-thread form is needed.</p>';
    echo '<p><a href="index.php?module=config-vonseo">Review SEO status on the Overview page</a></p></div></div>';
    echo '<div class="vs-guide-step"><span class="vs-guide-number">2</span><div><h3>Choose whether to use keyword URLs</h3>';
    echo '<p>This is optional. If you want readable forum, thread, exact-post and stock action links, add the six Apache rules from <code>extras/htaccess-vonseo.txt</code>, test direct visits, then turn on Keyword URLs. Leave the setting off to keep MyBB links.</p>';
    if($guideGid > 0)
    {
        echo '<p><a href="index.php?module=config-settings&amp;action=change&amp;gid='.$guideGid.'#row_setting_vonseo_keyword_urls">Open keyword URL setting</a></p>';
    }
    echo '</div></div>';
    echo '<div class="vs-guide-step"><span class="vs-guide-number">3</span><div><h3>Check a public page and crawler links</h3>';
    echo '<p>Open a public thread as a guest and check its page source for one canonical link and a description. When enabled, the sitemap and robots endpoints list public pages and crawler rules.</p>';
    echo '<p><a href="'.htmlspecialchars_uni($guideBoardUrl.'/misc.php?action=vonseo_sitemap').'" target="_blank" rel="noopener noreferrer" title="Opens raw XML in a new tab">Open sitemap (raw XML)</a> | <a href="'.htmlspecialchars_uni($guideBoardUrl.'/misc.php?action=vonseo_robots').'" target="_blank" rel="noopener noreferrer" title="Opens plain text in a new tab">Open robots rules (plain text)</a></p></div></div>';
    echo '<div class="vs-guide-step"><span class="vs-guide-number">4</span><div><h3>Use maintenance tools only when needed</h3>';
    echo '<p>Redirects repair old or moved links. The 404 monitor shows missing paths. IndexNow is optional and needs its own key and settings; it is not required for basic SEO.</p>';
    echo '<p><a href="index.php?module=config-vonseo&amp;action=redirects">Manage redirects</a> | <a href="index.php?module=config-vonseo&amp;action=notfound">Review 404s</a></p></div></div>';
    echo '</div>';
    echo '<div class="vs-notice"><strong>Rolling back keyword URLs?</strong> Turn the keyword setting off, but keep the six Apache rules while old keyword links may still be shared. Uninstalling VonSEO also removes its redirect, 404 and IndexNow queue data.</div>';
    echo '</div>';
    $page->output_footer();
    exit;
}

// Overview / status page.
$page->output_header('VonSEO');
vonseo_admin_tabs('overview');

$gid = 0;
$query = $db->simple_select('settinggroups', 'gid', "name='vonseo'", array('limit' => 1));
$gid = (int)$db->fetch_field($query, 'gid');

$redirectCount = 0;
$notFoundCount = 0;
$notFoundHits = 0;
$indexNowQueueCount = 0;
$enabledRedirectCountOverview = 0;
if($db->table_exists('vonseo_redirects'))
{
    $query = $db->simple_select('vonseo_redirects', 'COUNT(rid) AS total, SUM(enabled=1) AS enabled');
    $redirectCount = (int)$db->fetch_field($query, 'total');
    $enabledRedirectCountOverview = (int)$db->fetch_field($query, 'enabled');
}
if($db->table_exists('vonseo_404_log'))
{
    $query = $db->simple_select('vonseo_404_log', 'COUNT(path_hash) AS total, SUM(hits) AS hits');
    $notFoundCount = (int)$db->fetch_field($query, 'total');
    $notFoundHits = (int)$db->fetch_field($query, 'hits');
}
if($db->table_exists('vonseo_indexnow_queue'))
{
    $query = $db->simple_select('vonseo_indexnow_queue', 'COUNT(qid) AS total');
    $indexNowQueueCount = (int)$db->fetch_field($query, 'total');
}

$operationalState = class_exists('VonSEO_State') ? VonSEO_State::merge(array(
    'active_redirects' => $enabledRedirectCountOverview,
    'not_found_rows' => $notFoundCount,
    'indexnow_queue_depth' => $indexNowQueueCount
)) : array();
$schemaVersion = class_exists('VonSEO_State') ? VonSEO_State::schemaVersion() : 0;
$lastIndexNowSuccess = isset($operationalState['indexnow_last_success']) ? (int)$operationalState['indexnow_last_success'] : 0;
$lastIndexNowFailure = isset($operationalState['indexnow_last_failure']) ? (int)$operationalState['indexnow_last_failure'] : 0;
$lastIndexNowError = isset($operationalState['indexnow_last_error']) ? (string)$operationalState['indexnow_last_error'] : '';
$lastIndexNowHttp = isset($operationalState['indexnow_last_http']) ? (int)$operationalState['indexnow_last_http'] : 0;
$indexNowWorkerStatus = isset($operationalState['indexnow_status']) ? (string)$operationalState['indexnow_status'] : 'ready';

$htaccessState = false;
$keywordRuleCount = 0;
$htaccessNote = 'Optional router for unknown custom paths. Keyword forum/thread URLs do not need it.';
if(is_readable(MYBB_ROOT.'.htaccess'))
{
    $htaccess = @file_get_contents(MYBB_ROOT.'.htaccess');
    if(is_string($htaccess))
    {
        $keywordRuleMarkers = array(
            '^t-([1-9][0-9]*)-[^/]+--p([1-9][0-9]*)$',
            '^t-([1-9][0-9]*)-[^/]+--post-([1-9][0-9]*)$',
            '^t-([1-9][0-9]*)-[^/]+--(lastpost|newpost|nextnewest|nextoldest)$',
            '^t-([1-9][0-9]*)-[^/]+$',
            '^f-([1-9][0-9]*)-[^/]+--p([1-9][0-9]*)$',
            '^f-([1-9][0-9]*)-[^/]+$'
        );
        foreach($keywordRuleMarkers as $marker)
        {
            if(strpos($htaccess, 'RewriteRule '.$marker) !== false)
            {
                ++$keywordRuleCount;
            }
        }
        if(strpos($htaccess, 'vonseo_route') !== false)
        {
            $htaccessState = true;
            $htaccessNote = 'Optional router found in the root .htaccess; it handles unknown custom paths.';
        }
    }
}

$indexNowKeySet = !empty($mybb->settings['vonseo_indexnow_key']);
$engineEnabled = !empty($mybb->settings['vonseo_enabled']);
$keywordSettingEnabled = !empty($mybb->settings['vonseo_keyword_urls']);
$keywordUrlsActive = $engineEnabled && $keywordSettingEnabled;
$indexNowEnabled = $engineEnabled && !empty($mybb->settings['vonseo_indexnow_enabled']);
if($indexNowEnabled && !$indexNowKeySet)
{
    $indexNowWorkerStatus = 'config_error';
    $lastIndexNowError = 'IndexNow API key is missing. The queue is retained until a key is configured.';
}
$indexNowLabels = array(
    'ready' => array('Ready', 'is-on'),
    'config_error' => array('Config Error', 'is-warn'),
    'rate_limited' => array('Rate Limited', 'is-warn'),
    'remote_error' => array('Remote Error', 'is-warn')
);
if(!$indexNowEnabled)
{
    $indexNowStatusLabel = 'Disabled';
    $indexNowStatusClass = 'is-off';
}
else
{
    $indexNowStatus = isset($indexNowLabels[$indexNowWorkerStatus]) ? $indexNowLabels[$indexNowWorkerStatus] : $indexNowLabels['remote_error'];
    $indexNowStatusLabel = $indexNowStatus[0];
    $indexNowStatusClass = $indexNowStatus[1];
}
$mybbSeoUrls = !empty($mybb->seo_support);
$bburl = rtrim($mybb->settings['bburl'], '/');
$bburlParts = @parse_url($bburl);
$boardPath = is_array($bburlParts) && !empty($bburlParts['path']) ? '/'.trim($bburlParts['path'], '/') : '';

echo $vonseoAdminCss;
echo '<div class="vs-page">';
echo '<div class="vs-page-header">';
echo '<div><span class="vs-eyebrow">MyBB SEO controls</span><h2>VonSEO overview <span class="vs-version">v'.htmlspecialchars_uni($pluginVersion).'</span></h2>';
echo '<p>See what is active. The detailed settings are optional for most boards.</p></div>';
echo '<div class="vs-actions">';
if($gid > 0)
{
    echo '<a class="button" href="index.php?module=config-settings&amp;action=change&amp;gid='.$gid.'">Advanced settings</a>';
}
if($canManageSiteSettings)
{
    echo '<form class="vs-inline-form" action="index.php?module=config-vonseo&amp;action=indexnow_rotate_key" method="post">'
        .'<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />'
        .'<button class="button" type="submit">Generate new IndexNow key</button></form>';
}
echo '</div></div>';

echo '<div class="vs-notice"><strong>Basic SEO: '.($engineEnabled ? 'On' : 'Off').'.</strong> '
    .($engineEnabled ? 'The default settings already work; you do not need to fill every field.'
        : 'Turn on Enable VonSEO in advanced settings to output metadata and crawler endpoints.').'</div>';

$siteDetails = new Table;
$siteDetails->construct_header('Field', array('width' => 190));
$siteDetails->construct_header('Value');
$siteDetailRows = array(
    array('Board Name', 'site_name', isset($mybb->settings['bbname']) ? trim(VonSEO_Utils::plainText($mybb->settings['bbname'])) : '', 'Used for site name, titles and Open Graph.'),
    array('Board URL', 'site_url', $bburl, 'Canonical base. Change only when the forum really moves.'),
    array('Homepage Name', 'homepage_name', isset($mybb->settings['homename']) ? trim(VonSEO_Utils::plainText($mybb->settings['homename'])) : '', 'Organization / publisher name in schema.'),
    array('Homepage URL', 'homepage_url', isset($mybb->settings['homeurl']) ? trim((string)$mybb->settings['homeurl']) : '', 'Organization / publisher URL in schema.'),
    array('SEO Description', 'site_description', isset($mybb->settings['vonseo_site_description']) ? trim((string)$mybb->settings['vonseo_site_description']) : '', 'VonSEO homepage description and fallback summary.')
);
if($canManageSiteSettings)
{
    echo '<form action="index.php?module=config-vonseo&amp;action=site_details_save" method="post">';
    echo '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
}
foreach($siteDetailRows as $siteDetailRow)
{
    $siteDetails->construct_cell('<label for="vonseo_'.htmlspecialchars_uni($siteDetailRow[1]).'"><strong>'.htmlspecialchars_uni($siteDetailRow[0]).'</strong></label><br /><span class="vs-muted">'.htmlspecialchars_uni($siteDetailRow[3]).'</span>');
    if(!$canManageSiteSettings)
    {
        $field = '<span class="vs-code">'.htmlspecialchars_uni($siteDetailRow[2] !== '' ? $siteDetailRow[2] : 'Not set').'</span>';
    }
    elseif($siteDetailRow[1] === 'site_description')
    {
        $field = '<textarea class="text_input" id="vonseo_site_description" name="site_description" rows="3" style="width:98%">'.htmlspecialchars_uni($siteDetailRow[2]).'</textarea>';
    }
    else
    {
        $field = '<input class="text_input" id="vonseo_'.htmlspecialchars_uni($siteDetailRow[1]).'" name="'.htmlspecialchars_uni($siteDetailRow[1]).'" type="text" value="'.htmlspecialchars_uni($siteDetailRow[2]).'" style="width:98%" />';
    }
    $siteDetails->construct_cell($field);
    $siteDetails->construct_row();
}
echo '<div class="vs-table-wrap">';
$siteDetails->output('Site Details: shared directly with MyBB');
echo '</div>';
if($canManageSiteSettings)
{
    echo '<div class="form_button_wrapper"><button class="button" type="submit">Save Site Details</button></div></form>';
}
else
{
    echo '<div class="vs-form-note">Site Details are read-only for this administrator.</div>';
}

$attention = array();
if($schemaVersion !== VonSEO_State::SCHEMA_VERSION)
{
    $attention[] = 'Database upgrade is incomplete (schema '.$schemaVersion.' of '.VonSEO_State::SCHEMA_VERSION.'). Re-activate VonSEO once.';
}
if($indexNowEnabled && $indexNowWorkerStatus !== 'ready' && $lastIndexNowError !== '')
{
    $attention[] = $lastIndexNowError.' Queued URLs remain stored.';
}
if($engineEnabled && !empty($mybb->settings['vonseo_robots_enabled']) && $boardPath !== '')
{
    $attention[] = 'This board is installed in '.$boardPath.'. Crawlers request robots.txt at the host root, so add a host-level /robots.txt file or rewrite; the forum-root rule alone creates '.$boardPath.'/robots.txt.';
}
if(!empty($attention))
{
    echo '<div class="vs-notice is-warning"><strong>Needs attention</strong><ul>';
    foreach($attention as $item)
    {
        echo '<li>'.htmlspecialchars_uni($item).'</li>';
    }
    echo '</ul></div>';
}

echo '<div class="vs-setup'.($keywordSettingEnabled && $keywordRuleCount < 6 ? ' is-warning' : '').'">';
echo '<div class="vs-setup-head"><h3>Keyword URLs for forums and threads</h3>';
echo '<span class="vs-status '.($keywordUrlsActive ? 'is-on' : ($keywordSettingEnabled ? 'is-warn' : 'is-neutral')).'">'
    .($keywordUrlsActive ? 'On' : ($keywordSettingEnabled ? 'Paused' : 'Off')).'</span></div>';
if($keywordSettingEnabled)
{
    if($keywordRuleCount < 6)
    {
        echo '<p>Check the Apache rules before sharing keyword links. Equivalent rules may be configured elsewhere.</p>';
    }
    else
    {
        echo '<p>Keyword links are enabled. Check one forum and thread link on your site.</p>';
    }
}
else
{
    echo '<p>Optional feature. Native MyBB links continue to work without it.</p>';
}
if($gid > 0)
{
    echo '<div class="vs-setup-actions"><a class="button" href="index.php?module=config-settings&amp;action=change&amp;gid='.$gid.'#row_setting_vonseo_keyword_urls">Change keyword URL setting</a></div>';
}
echo '<details class="vs-setup-help"><summary>Setup and rollback help</summary>';
echo '<p><strong>Apache check:</strong> '.$keywordRuleCount.'/6 keyword patterns found in the root .htaccess. Equivalent rules may be configured elsewhere.</p>';
echo '<ol><li>Add the six keyword rules from the ZIP <code>extras/htaccess-vonseo.txt</code> to the forum root .htaccess.</li>';
echo '<li>With this setting Off, a direct visit to a public <code>t-ID-topic</code> and <code>f-ID-forum</code> should temporarily redirect to a MyBB URL. Then turn it On and retest.</li>';
echo '<li>To roll back, turn the setting Off but keep the six rules so shared keyword links continue to redirect.</li></ol>';
echo '<p>A file check cannot prove Apache routing; test a direct visit on your server.</p></details>';
echo '</div>';

echo '<details class="vs-details"><summary>Technical details: module status, redirects and endpoints</summary>';
echo '<div class="vs-summary">';
echo '<div class="vs-summary-card"><strong>'.my_number_format($redirectCount).'</strong><span>Redirect rules</span></div>';
echo '<div class="vs-summary-card"><strong>'.my_number_format($notFoundCount).'</strong><span>Missing paths</span></div>';
echo '<div class="vs-summary-card"><strong>'.my_number_format($notFoundHits).'</strong><span>Total 404 hits</span></div>';
echo '<div class="vs-summary-card"><strong>'.my_number_format($indexNowQueueCount).'</strong><span>IndexNow queued</span></div>';
echo '</div>';

$modules = array(
    array('SEO engine', $engineEnabled, 'Canonical URLs, metadata, social tags and structured data.'),
    array('IndexNow', $engineEnabled && !empty($mybb->settings['vonseo_indexnow_enabled']), 'Queues public content and submits it through a background MyBB task.'),
    array('Redirect engine', $engineEnabled && !empty($mybb->settings['vonseo_redirects_enabled']), my_number_format($redirectCount).' rules configured.'),
    array('404 status guard', $engineEnabled && !empty($mybb->settings['vonseo_404_enabled']), 'Returns a 404 status for missing content.'),
    array('404 monitor', $engineEnabled && !empty($mybb->settings['vonseo_404_monitor']), 'Tracks missing paths and aggregate hit counts.'),
    array('XML sitemap', $engineEnabled && !empty($mybb->settings['vonseo_sitemap_enabled']), 'Lists public forums and threads.'),
    array('Robots output', $engineEnabled && !empty($mybb->settings['vonseo_robots_enabled']), 'Provides dynamic crawler directives.'),
    array('Keyword URLs', $keywordUrlsActive, $keywordUrlsActive
        ? 'Public forum/thread links use keyword paths. Verify direct visits on this server.'
        : 'MyBB native URLs remain canonical until this setting and VonSEO are enabled.')
);

$health = new Table;
$health->construct_header('Module', array('width' => 190));
$health->construct_header('Status', array('class' => 'align_center', 'width' => 130));
$health->construct_header('What it does');
foreach($modules as $module)
{
    $health->construct_cell('<strong>'.htmlspecialchars_uni($module[0]).'</strong>');
    $health->construct_cell('<span class="vs-status '.($module[1] ? 'is-on' : 'is-off').'">'.($module[1] ? 'Enabled' : 'Disabled').'</span>', array('class' => 'align_center'));
    $health->construct_cell(htmlspecialchars_uni($module[2]));
    $health->construct_row();
}
$health->construct_cell('<strong>MyBB URL format</strong>');
$health->construct_cell('<span class="vs-status '.($mybbSeoUrls ? 'is-on' : '').'">'.($mybbSeoUrls ? 'Friendly' : 'PHP / query').'</span>', array('class' => 'align_center'));
$health->construct_cell($mybbSeoUrls
    ? 'MyBB is generating ID-based friendly links. Server rewrite rules must also resolve those links.'
    : 'MyBB is generating PHP and query URLs. VonSEO follows the same URL format.');
$health->construct_row();
$health->construct_cell('<strong>Custom-path router (optional)</strong>');
$health->construct_cell('<span class="vs-status '.($htaccessState ? 'is-on' : 'is-neutral').'">'.($htaccessState ? 'Installed' : 'Not installed').'</span>', array('class' => 'align_center'));
$health->construct_cell(htmlspecialchars_uni($htaccessNote));
$health->construct_row();
$health->construct_cell('<strong>Host-root robots.txt</strong>');
$health->construct_cell('<span class="vs-status '.($boardPath === '' ? 'is-on' : 'is-warn').'">'.($boardPath === '' ? 'Root board' : 'Host setup').'</span>', array('class' => 'align_center'));
$health->construct_cell($boardPath === ''
    ? 'The optional MyBB-root rewrite can serve the crawler-standard /robots.txt path.'
    : 'The dynamic endpoint remains available, but the web-document root must map /robots.txt to it or provide a static file.');
$health->construct_row();
$health->construct_cell('<strong>Database schema</strong>');
$health->construct_cell('<span class="vs-status '.($schemaVersion === VonSEO_State::SCHEMA_VERSION ? 'is-on' : 'is-warn').'">'.$schemaVersion.' / '.VonSEO_State::SCHEMA_VERSION.'</span>', array('class' => 'align_center'));
$health->construct_cell($schemaVersion === VonSEO_State::SCHEMA_VERSION
    ? 'All VonSEO database migrations are installed.'
    : 'Re-activate VonSEO to retry the idempotent database upgrade.');
$health->construct_row();
$health->construct_cell('<strong>IndexNow queue</strong>');
$health->construct_cell('<span class="vs-status '.$indexNowStatusClass.'">'.htmlspecialchars_uni($indexNowStatusLabel).'</span>', array('class' => 'align_center'));
$indexNowDetail = my_number_format($indexNowQueueCount).' waiting. ';
if($lastIndexNowSuccess > 0)
{
    $indexNowDetail .= 'Last success: '.htmlspecialchars_uni(my_date('relative', $lastIndexNowSuccess)).'. ';
}
else
{
    $indexNowDetail .= 'No successful background submission recorded. ';
}
if($lastIndexNowError !== '')
{
    $indexNowDetail .= htmlspecialchars_uni($lastIndexNowError).($lastIndexNowHttp > 0 ? ' HTTP '.$lastIndexNowHttp.'.' : '');
}
if($indexNowWorkerStatus === 'config_error')
{
    $indexNowDetail .= ' Worker delivery is paused until configuration is fixed; the queue is retained.';
}
$health->construct_cell($indexNowDetail);
$health->construct_row();

echo '<div class="vs-table-wrap vs-table-health">';
$health->output('Module status');
echo '</div>';

$endpoints = array(
    array('Canonical base', $bburl),
    array('XML sitemap', $bburl.'/misc.php?action=vonseo_sitemap'),
    array('Robots output', $bburl.'/misc.php?action=vonseo_robots'),
    array('IndexNow key', $bburl.'/misc.php?action=vonseo_indexnow_key'),
    array('Custom 404 page', $bburl.'/misc.php?action=vonseo_404')
);
$endpointTable = new Table;
$endpointTable->construct_header('Endpoint', array('width' => 190));
$endpointTable->construct_header('URL');
$endpointTable->construct_header('Action', array('class' => 'align_center', 'width' => 100));
foreach($endpoints as $endpoint)
{
    $safeUrl = htmlspecialchars_uni($endpoint[1]);
    $endpointTable->construct_cell('<strong>'.htmlspecialchars_uni($endpoint[0]).'</strong>');
    $endpointTable->construct_cell('<span class="vs-endpoint">'.$safeUrl.'</span>');
    $endpointTable->construct_cell('<a class="vs-button-link" href="'.$safeUrl.'" target="_blank" rel="noopener noreferrer">Open</a>', array('class' => 'align_center'));
    $endpointTable->construct_row();
}
echo '<div class="vs-table-wrap vs-table-endpoints">';
$endpointTable->output('Public endpoints');
echo '</div>';
echo '</details>';
echo '</div>';

$page->output_footer();
