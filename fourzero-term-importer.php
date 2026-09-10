<?php
/**
 * Plugin Name: FourZero Term Importer
 * Description: Import WordPress categories, subcategories and tags from CSV, with preview, duplicate handling and optional SEO metadata.
 * Version: 2.0.0
 * Author: FourZero
 * Author URI: https://fourzero.work
 * License: GPL-2.0-or-later
 * Text Domain: fourzero-term-importer
 */

if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
    add_management_page('FourZero Term Importer', 'Term Importer', 'manage_categories', 'fourzero-term-importer', 'fourzero_term_importer_page');
});

function fourzero_term_importer_page() {
    if (!current_user_can('manage_categories')) wp_die('You do not have permission to use this importer.');
    echo '<div class="wrap"><h1>FourZero Term Importer <span style="font-size:12px;vertical-align:middle;background:#2271b1;color:#fff;padding:4px 8px;border-radius:3px;">v2.0.0</span></h1><p>Import WordPress categories, subcategories and tags from a CSV file.</p>';
    echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:950px;margin:20px 0;"><h2>CSV Format</h2><p>Required: <code>type</code>, <code>name</code>. Optional: <code>slug</code>, <code>parent</code>, <code>description</code>, <code>seo_title</code>, <code>seo_description</code>.</p><pre style="background:#f6f7f7;border:1px solid #ccd0d4;padding:15px;overflow:auto;">type,name,slug,parent,description,seo_title,seo_description
category,Digital,digital,,,Digital Services | FourZero,Digital services from FourZero.
category,Web Design,web-design,Digital,Professional website design,Web Design | FourZero,Professional website design from FourZero.
tag,WordPress,wordpress,,,WordPress | FourZero,WordPress websites and development.</pre></div>';
    if (isset($_POST['fourzero_term_action'], $_POST['fourzero_import_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fourzero_import_nonce'])), 'fourzero_import_terms')) echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        else fourzero_term_import_handle_request();
    }
    echo '<form method="post" enctype="multipart/form-data" style="max-width:950px;">';
    wp_nonce_field('fourzero_import_terms', 'fourzero_import_nonce');
    echo '<table class="form-table"><tr><th scope="row"><label for="fourzero_csv">CSV File</label></th><td><input type="file" name="fourzero_csv" id="fourzero_csv" accept=".csv,text/csv" required><p class="description">Use a UTF-8 CSV file. First row must contain column names.</p></td></tr><tr><th scope="row">Import Options</th><td>';
    echo '<label style="display:block;margin-bottom:10px;"><input type="checkbox" name="fourzero_preview" value="1" checked> <strong>Preview only</strong> — validate without making changes.</label>';
    echo '<label style="display:block;margin-bottom:10px;"><input type="checkbox" name="fourzero_update" value="1"> <strong>Update existing terms</strong> — update supplied description, parent and SEO fields.</label>';
    echo '<label style="display:block;"><input type="checkbox" name="fourzero_seo" value="1" checked> <strong>Import SEO metadata</strong> — Yoast SEO, Rank Math or AIOSEO when detected.</label></td></tr></table><p><button type="submit" name="fourzero_term_action" value="preview" class="button">Validate / Preview</button> <button type="submit" name="fourzero_term_action" value="import" class="button button-primary">Import Terms</button></p></form>';
    echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:950px;margin-top:25px;"><h2>How it works</h2><ol><li>Upload CSV.</li><li>Validate / Preview first.</li><li>Fix errors.</li><li>Untick Preview only and import.</li></ol><p><strong>Safety:</strong> existing terms are not changed unless Update existing terms is enabled.</p></div></div>';
}

function fourzero_term_import_handle_request() {
    if (empty($_FILES['fourzero_csv']['tmp_name'])) { echo '<div class="notice notice-error"><p>Please select a CSV file.</p></div>'; return; }
    $file = $_FILES['fourzero_csv'];
    if (!empty($file['error'])) { echo '<div class="notice notice-error"><p>There was an error uploading the CSV file.</p></div>'; return; }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') { echo '<div class="notice notice-error"><p>Please upload a CSV file.</p></div>'; return; }
    $csv = fourzero_term_read_csv($file['tmp_name']);
    if (is_wp_error($csv)) { echo '<div class="notice notice-error"><p><strong>CSV Error:</strong> '.esc_html($csv->get_error_message()).'</p></div>'; return; }
    $action = isset($_POST['fourzero_term_action']) ? sanitize_key(wp_unslash($_POST['fourzero_term_action'])) : 'preview';
    $preview = ($action !== 'import') || !empty($_POST['fourzero_preview']);
    $update = !empty($_POST['fourzero_update']);
    $seo = !empty($_POST['fourzero_seo']);
    fourzero_term_display_results(fourzero_term_validate_and_import($csv, $preview, $update, $seo), $preview);
}

function fourzero_term_read_csv($filename) {
    $handle = fopen($filename, 'r');
    if (!$handle) return new WP_Error('file_open', 'Could not read the CSV file.');
    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); return new WP_Error('empty_csv', 'The CSV file is empty.'); }
    if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map(function($h){ return strtolower(trim($h)); }, $headers);
    $required = array('type','name');
    foreach ($required as $column) if (!in_array($column, $headers, true)) { fclose($handle); return new WP_Error('missing_column', 'Missing required CSV column: '.$column); }
    $allowed = array('type','name','slug','parent','description','seo_title','seo_description');
    foreach ($headers as $header) if (!in_array($header, $allowed, true)) { fclose($handle); return new WP_Error('unknown_column', 'Unknown CSV column: '.$header); }
    $rows = array(); $line = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $line++;
        if (count($data) === 1 && trim((string)$data[0]) === '') continue;
        $row = array();
        foreach ($headers as $i => $header) $row[$header] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        $row['_line'] = $line; $rows[] = $row;
    }
    fclose($handle);
    return array('headers'=>$headers,'rows'=>$rows);
}

function fourzero_term_validate_and_import($csv, $preview=true, $update=false, $seo=true) {
    $result = array('rows'=>count($csv['rows']),'categories_created'=>0,'categories_existing'=>0,'categories_updated'=>0,'tags_created'=>0,'tags_existing'=>0,'tags_updated'=>0,'errors'=>array(),'warnings'=>array(),'preview'=>$preview);
    $prepared = array();
    foreach ($csv['rows'] as $row) {
        $type = strtolower(trim($row['type'])); $name = trim($row['name']);
        if ($type === 'category') $taxonomy='category'; elseif ($type === 'tag') $taxonomy='post_tag'; else { $result['errors'][]='Line '.intval($row['_line']).': type must be "category" or "tag".'; continue; }
        if ($name === '') { $result['errors'][]='Line '.intval($row['_line']).': name cannot be empty.'; continue; }
        $slug = !empty($row['slug']) ? sanitize_title($row['slug']) : sanitize_title($name);
        if ($slug === '') { $result['errors'][]='Line '.intval($row['_line']).': could not generate a valid slug for "'.$name.'".'; continue; }
        $prepared[] = array('line'=>intval($row['_line']),'type'=>$type,'taxonomy'=>$taxonomy,'name'=>$name,'slug'=>$slug,'parent'=>trim($row['parent']),'description'=>$row['description'],'seo_title'=>$row['seo_title'],'seo_description'=>$row['seo_description']);
    }
    $seen=array();
    foreach ($prepared as $item) { $key=$item['taxonomy'].'|'.$item['slug']; if(isset($seen[$key])) $result['warnings'][]='Duplicate CSV entry for "'.$item['name'].'" on line '.$item['line'].'.'; $seen[$key]=true; }
    usort($prepared,function($a,$b){ if($a['taxonomy']!==$b['taxonomy']) return $a['taxonomy']==='category'?-1:1; $ap=$a['parent']!==''; $bp=$b['parent']!==''; return $ap===$bp?0:($ap?1:-1); });
    foreach ($prepared as $item) {
        $taxonomy=$item['taxonomy']; $existing=get_term_by('slug',$item['slug'],$taxonomy); $parent_id=0;
        if($taxonomy==='category' && $item['parent']!=='') {
            $parent=get_term_by('slug',sanitize_title($item['parent']),'category');
            if(!$parent) $parent=get_term_by('name',$item['parent'],'category');
            if(!$parent || is_wp_error($parent)) { $result['errors'][]='Line '.$item['line'].': parent category "'.$item['parent'].'" was not found for "'.$item['name'].'".'; continue; }
            $parent_id=intval($parent->term_id);
        }
        if($existing && !is_wp_error($existing)) {
            $taxonomy==='category' ? $result['categories_existing']++ : $result['tags_existing']++;
            if($update && !$preview) {
                $args=array('description'=>$item['description']); if($taxonomy==='category') $args['parent']=$parent_id;
                $changed=wp_update_term(intval($existing->term_id),$taxonomy,$args);
                if(is_wp_error($changed)) { $result['errors'][]='Line '.$item['line'].': could not update "'.$item['name'].'": '.$changed->get_error_message(); continue; }
                $taxonomy==='category' ? $result['categories_updated']++ : $result['tags_updated']++;
                if($seo) fourzero_term_save_seo(intval($existing->term_id),$item['seo_title'],$item['seo_description']);
            }
            continue;
        }
        if($preview) { $taxonomy==='category' ? $result['categories_created']++ : $result['tags_created']++; continue; }
        $args=array('slug'=>$item['slug'],'description'=>$item['description']); if($taxonomy==='category') $args['parent']=$parent_id;
        $created=wp_insert_term($item['name'],$taxonomy,$args);
        if(is_wp_error($created)) { $result['errors'][]='Line '.$item['line'].': could not create "'.$item['name'].'": '.$created->get_error_message(); continue; }
        $term_id=intval($created['term_id']); $taxonomy==='category' ? $result['categories_created']++ : $result['tags_created']++;
        if($seo) fourzero_term_save_seo($term_id,$item['seo_title'],$item['seo_description']);
    }
    return $result;
}

function fourzero_term_save_seo($term_id,$seo_title,$seo_description) {
    $seo_title=sanitize_text_field($seo_title); $seo_description=sanitize_textarea_field($seo_description); if($seo_title==='' && $seo_description==='') return;
    if(defined('WPSEO_VERSION') || defined('WPSEO_FILE')) { if($seo_title!=='') update_term_meta($term_id,'wpseo_title',$seo_title); if($seo_description!=='') update_term_meta($term_id,'wpseo_desc',$seo_description); return; }
    if(defined('RANK_MATH_VERSION') || class_exists('RankMath')) { if($seo_title!=='') update_term_meta($term_id,'rank_math_title',$seo_title); if($seo_description!=='') update_term_meta($term_id,'rank_math_description',$seo_description); return; }
    if(defined('AIOSEO_VERSION') || defined('AIOSEO_FILE')) { if($seo_title!=='') update_term_meta($term_id,'_aioseo_title',$seo_title); if($seo_description!=='') update_term_meta($term_id,'_aioseo_description',$seo_description); }
}

function fourzero_term_display_results($result,$preview) {
    echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:950px;margin:25px 0;"><h2>'.($preview?'Preview Results':'Import Results').'</h2>';
    echo $preview ? '<p><strong>No changes have been made.</strong> This was a preview.</p>' : '<p><strong>Import complete.</strong></p>';
    echo '<table class="widefat striped" style="max-width:700px;"><tbody>';
    fourzero_result_row('CSV rows processed',$result['rows']); fourzero_result_row('Categories to create / created',$result['categories_created']); fourzero_result_row('Categories already existing',$result['categories_existing']); fourzero_result_row('Categories updated',$result['categories_updated']); fourzero_result_row('Tags to create / created',$result['tags_created']); fourzero_result_row('Tags already existing',$result['tags_existing']); fourzero_result_row('Tags updated',$result['tags_updated']); fourzero_result_row('Errors',count($result['errors'])); fourzero_result_row('Warnings',count($result['warnings']));
    echo '</tbody></table>';
    if(!empty($result['errors'])) { echo '<h3>Errors</h3><div style="background:#fcf0f1;border-left:4px solid #d63638;padding:10px 15px;"><ul>'; foreach($result['errors'] as $error) echo '<li>'.esc_html($error).'</li>'; echo '</ul></div>'; }
    if(!empty($result['warnings'])) { echo '<h3>Warnings</h3><div style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 15px;"><ul>'; foreach($result['warnings'] as $warning) echo '<li>'.esc_html($warning).'</li>'; echo '</ul></div>'; }
    if($preview && empty($result['errors'])) echo '<p><strong>Next step:</strong> run the import again with <strong>Preview only</strong> unchecked.</p>';
    echo '</div>';
}

function fourzero_result_row($label,$value) { echo '<tr><td><strong>'.esc_html($label).'</strong></td><td>'.intval($value).'</td></tr>'; }
''