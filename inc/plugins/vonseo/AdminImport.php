<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/** One-use CSV feedback, scoped to the current MyBB administrator session. */
class VonSEO_AdminImport
{
    const SESSION_KEY = 'vonseo_import_report';
    const MAX_WARNINGS = 50;
    const MAX_MESSAGE_BYTES = 500;
    const REPORT_TTL = 900;

    /** @return array */
    public static function prepare(array $result): array
    {
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();
        $report = array('created_at' => TIME_NOW, 'warning_count' => count($errors), 'warnings' => array());
        foreach(array('imported', 'updated', 'skipped') as $field)
        {
            $report[$field] = isset($result[$field]) && is_scalar($result[$field]) ? max(0, (int)$result[$field]) : 0;
        }
        foreach(array_slice($errors, 0, self::MAX_WARNINGS) as $error)
        {
            $message = is_scalar($error) ? (string)$error : 'Invalid import warning.';
            $row = 0;
            if(preg_match('/^Row ([0-9]+)(?::\s*|\s+)/', $message, $match))
            {
                $row = (int)$match[1];
                $message = substr($message, strlen($match[0]));
            }
            if(strlen($message) > self::MAX_MESSAGE_BYTES)
            {
                $message = substr($message, 0, self::MAX_MESSAGE_BYTES).'...';
            }
            $report['warnings'][] = array('row' => $row, 'message' => $message);
        }
        return $report;
    }

    /** @return void */
    public static function store(array $result): void
    {
        // MyBB escapes and persists only this administrator's session data.
        update_admin_session(self::SESSION_KEY, self::prepare($result));
    }

    /** @return array */
    public static function take(): array
    {
        global $admin_session;
        if(!isset($admin_session['data'][self::SESSION_KEY]))
        {
            return array();
        }
        $report = $admin_session['data'][self::SESSION_KEY];
        update_admin_session(self::SESSION_KEY, null);
        if(!is_array($report) || !isset($report['created_at']) || !is_int($report['created_at']) ||
           $report['created_at'] > TIME_NOW || $report['created_at'] < TIME_NOW - self::REPORT_TTL)
        {
            return array();
        }
        foreach(array('imported', 'updated', 'skipped', 'warning_count') as $field)
        {
            if(!isset($report[$field]) || !is_int($report[$field]) || $report[$field] < 0)
            {
                return array();
            }
        }
        if(!isset($report['warnings']) || !is_array($report['warnings']) ||
           count($report['warnings']) !== min($report['warning_count'], self::MAX_WARNINGS))
        {
            return array();
        }
        foreach($report['warnings'] as $warning)
        {
            if(!is_array($warning) || !isset($warning['row'], $warning['message']) ||
               !is_int($warning['row']) || $warning['row'] < 0 || !is_string($warning['message']) ||
               strlen($warning['message']) > self::MAX_MESSAGE_BYTES + 3)
            {
                return array();
            }
        }
        return $report;
    }

    /** @return string */
    public static function render(array $report): string
    {
        if(empty($report))
        {
            return '';
        }
        $html = '<section class="vs-import-report vs-notice" aria-labelledby="vs-import-result">'
            .'<h3 id="vs-import-result">Last import result</h3><p>'
            .my_number_format((int)$report['imported']).' created, '
            .my_number_format((int)$report['updated']).' updated, '
            .my_number_format((int)$report['skipped']).' skipped.</p>';
        if(!empty($report['warning_count']))
        {
            $html .= '<p>'.my_number_format((int)$report['warning_count']).' warnings. '
                .'Showing '.count($report['warnings']).' of '.my_number_format((int)$report['warning_count'])
                .'. Correct the rejected records and import only those records again; valid rules may already have been saved.</p>'
                .'<div class="vs-table-wrap"><table><caption>CSV import warnings</caption><thead><tr>'
                .'<th scope="col">CSV row</th><th scope="col">Reason</th></tr></thead><tbody>';
            foreach($report['warnings'] as $warning)
            {
                // ENT_SUBSTITUTE also handles a byte-limited or malformed UTF-8 message.
                $message = htmlspecialchars($warning['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<tr><th scope="row">'.($warning['row'] > 0 ? (int)$warning['row'] : 'File / format')
                    .'</th><td>'.$message.'</td></tr>';
            }
            $html .= '</tbody></table></div><p class="vs-subtle">CSV row numbers count parsed records, including the header and blank records. A quoted multiline record counts as one row. Long reasons are shortened; at most 50 warnings are displayed.</p>';
        }
        $html .= '<p class="vs-subtle">With overwrite set to No, existing source paths are skipped without a warning. This result is shown once and expires after 15 minutes; it is not a permanent import history.</p>';
        return $html.'</section>';
    }
}
