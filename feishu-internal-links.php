<?php
/**
 * Plugin Name: Feishu Internal Links
 * Description: Sync keyword rules from a Feishu document and add internal links in the block editor.
 * Version: 0.5.7
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/draft-link-check.php';
const FIL_OPTION = 'fil_settings';
const FIL_RULES = 'fil_rules';
const FIL_CRON = 'fil_scheduled_sync_v2';
function fil_next_sync_timestamp($settings, $now = null) {
 $frequency=$settings['sync_frequency'] ?? 'daily';
 if ($frequency==='off') return false;
 $timezone=wp_timezone();
 $now=$now === null ? new DateTimeImmutable('now', $timezone) : (new DateTimeImmutable('@'.(int)$now))->setTimezone($timezone);
 $time=$settings['sync_time'] ?? '03:00';
 if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) $time='03:00';
 list($hour,$minute)=array_map('intval', explode(':', $time));
 $next=$frequency==='hourly' ? $now->setTime((int)$now->format('H'), $minute, 0) : $now->setTime($hour, $minute, 0);
 if ($frequency==='hourly') {
  if ($next <= $now) $next=$next->modify('+1 hour');
 } elseif ($frequency==='weekly') {
  $day=isset($settings['sync_day']) ? (int)$settings['sync_day'] : 1;
  if ($day<0 || $day>6) $day=1;
  $offset=($day-(int)$next->format('w')+7)%7;
  if ($offset) $next=$next->modify('+'.$offset.' days');
  if ($next <= $now) $next=$next->modify('+7 days');
 } elseif ($next <= $now) $next=$next->modify('+1 day');
 return $next->getTimestamp();
}
function fil_schedule_sync() {
 if (wp_next_scheduled('fil_daily_sync')) wp_clear_scheduled_hook('fil_daily_sync');
 if (!wp_next_scheduled(FIL_CRON)) {
  $timestamp=fil_next_sync_timestamp(get_option(FIL_OPTION, []));
  if ($timestamp) wp_schedule_single_event($timestamp, FIL_CRON);
 }
}
add_action('init', 'fil_schedule_sync');
register_activation_hook(__FILE__, 'fil_schedule_sync');
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook(FIL_CRON); wp_clear_scheduled_hook('fil_daily_sync'); });
add_action('admin_menu', function () { add_options_page('飞书内链', '飞书内链', 'manage_options', 'fil-settings', 'fil_settings_page'); });
function fil_settings_page() {
 if (!current_user_can('manage_options')) return;
 $s = get_option(FIL_OPTION, []); $rules = get_option(FIL_RULES, []);
 echo '<div class="wrap"><h1>飞书内链</h1>';
 if (isset($_GET['fil_message'])) echo '<div class="notice notice-info"><p>'.esc_html(wp_unslash($_GET['fil_message'])).'</p></div>';
 echo '<p>请提供多维表格自身的 /base/ 链接和数据表 ID（table 参数），而不是外层 /docx/ 文档链接。字段名称须为“关键词”“内链地址”“状态”，状态为“启用”或“停用”。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
 wp_nonce_field('fil_save'); echo '<input type="hidden" name="action" value="fil_save"><table class="form-table">';
 foreach (['app_id'=>'App ID','base'=>'多维表格 /base/ 链接或 App Token','table_id'=>'数据表 ID（单表可留空）'] as $key=>$label) echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($s[$key] ?? '').'"></td></tr>';
 foreach (['max_per_run'=>['每次识别最多添加',10], 'max_per_target'=>['每个目标地址每篇最多出现',1]] as $key=>$setting) {
  $value=isset($s[$key]) ? intval($s[$key]) : $setting[1];
  echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($setting[0]).'</label></th><td><input type="number" min="0" max="1000" step="1" required id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'"> <span>0 表示不限制；每个目标地址的数量包含已有链接</span></td></tr>';
 }
 echo '<tr><th scope="row"><label for="same_site_only">仅匹配当前站点链接</label></th><td>';
 echo '<input type="checkbox" id="same_site_only" name="same_site_only" value="1" aria-describedby="fil-site-scope" '.checked(!empty($s['same_site_only']), true, false).'>';
 echo '<p class="description" id="fil-site-scope">按 WordPress 站点首页域名判断。带 www 与不带 www 视为同站，其他子域名单独判断。设置保存后重新打开文章编辑器即可生效。</p></td></tr>';
 $frequency=$s['sync_frequency'] ?? 'daily'; $day=isset($s['sync_day']) ? (int)$s['sync_day'] : 1; $time=$s['sync_time'] ?? '03:00';
 echo '<tr><th><label for="sync_frequency">自动同步频率</label></th><td><select id="sync_frequency" name="sync_frequency">';
 foreach (['hourly'=>'每小时','daily'=>'每天','weekly'=>'每周','off'=>'关闭'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($frequency,$value,false).'>'.esc_html($label).'</option>';
 echo '</select></td></tr><tr><th><label for="sync_day">每周同步日</label></th><td><select id="sync_day" name="sync_day">';
 foreach (['星期日','星期一','星期二','星期三','星期四','星期五','星期六'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($day,$value,false).'>'.esc_html($label).'</option>';
 echo '</select> <span>仅每周同步时生效</span></td></tr><tr><th><label for="sync_time">自动同步时间</label></th><td><input type="time" required id="sync_time" name="sync_time" value="'.esc_attr($time).'"> <span>使用 WordPress 站点时区；每小时同步仅使用分钟</span></td></tr>';
 echo '<tr><th><label for="secret">App Secret</label></th><td><input type="password" class="regular-text" id="secret" name="secret" value="" autocomplete="new-password"><p class="description">'.(empty($s['secret']) ? '请输入密钥。' : '已保存；留空保留原密钥。').'</p></td></tr></table>';
 submit_button('保存设置'); echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('fil_sync'); echo '<input type="hidden" name="action" value="fil_sync">'; submit_button('同步飞书关键词库', 'secondary'); echo '</form><p>已保存 '.count($rules).' 条规则。上次成功同步：'.esc_html(get_option('fil_synced_at', '尚未同步')).'</p>';
 $result=get_option('fil_sync_result', []);
 if (!empty($result['time'])) echo '<p>最近一次同步（'.esc_html($result['time']).'）：'.esc_html($result['message']).'</p>';
 $next=wp_next_scheduled(FIL_CRON);
 echo '<p>自动同步：'.esc_html(['hourly'=>'每小时','daily'=>'每天','weekly'=>'每周','off'=>'关闭'][$s['sync_frequency'] ?? 'daily'] ?? '每天').'。'.($next ? '下次计划时间：'.esc_html(wp_date('Y-m-d H:i:s', $next)).'（站点时区）。' : (($s['sync_frequency'] ?? 'daily')==='off' ? '自动同步已关闭。' : '计划任务尚未安排，请检查 WordPress 定时任务。')).'</p><p>同步时间可能因网站访问和定时任务配置而延后。</p></div>';
}
function fil_redirect($message) { wp_safe_redirect(add_query_arg(['page'=>'fil-settings','fil_message'=>$message], admin_url('options-general.php'))); exit; }
add_action('admin_post_fil_save', function () {
 if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('fil_save');
 $old=get_option(FIL_OPTION, []); $secret=trim(wp_unslash($_POST['secret'] ?? ''));
 $limits=[]; foreach (['max_per_run'=>10, 'max_per_target'=>1] as $key=>$default) {
  $raw=isset($_POST[$key]) ? wp_unslash($_POST[$key]) : (string)$default;
  if (!is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,3})$/', $raw) || (int)$raw>1000) fil_redirect('数量设置必须为 0 至 1000 的整数；设置未保存。');
  $limits[$key]=(int)$raw;
 }
 $frequency=sanitize_text_field(wp_unslash($_POST['sync_frequency'] ?? 'daily'));
 $day=wp_unslash($_POST['sync_day'] ?? '1'); $time=wp_unslash($_POST['sync_time'] ?? '03:00');
 if (!in_array($frequency, ['hourly','daily','weekly','off'], true) || !is_string($day) || !preg_match('/^[0-6]$/', $day) || !is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) fil_redirect('自动同步设置无效；设置未保存。');
 update_option(FIL_OPTION, [
  'app_id' => sanitize_text_field(wp_unslash($_POST['app_id'] ?? '')),
  'base' => sanitize_text_field(wp_unslash($_POST['base'] ?? '')),
  'table_id' => sanitize_text_field(wp_unslash($_POST['table_id'] ?? '')),
  'secret' => $secret !== '' ? $secret : ($old['secret'] ?? ''),
  'max_per_run' => $limits['max_per_run'],
  'max_per_target' => $limits['max_per_target'],
  'same_site_only' => ($_POST['same_site_only'] ?? '') === '1',
  'sync_frequency' => $frequency,
  'sync_day' => (int)$day,
  'sync_time' => $time,
 ], false);
 wp_clear_scheduled_hook(FIL_CRON); fil_schedule_sync();
 fil_redirect('设置已保存。');
});
function fil_request($url, $args=[]) {
 $r=wp_remote_request($url, array_merge(['timeout'=>25, 'redirection'=>0], $args));
 if (is_wp_error($r)) throw new Exception('连接飞书失败：'.$r->get_error_message());
 $data=json_decode(wp_remote_retrieve_body($r), true);
 if (wp_remote_retrieve_response_code($r)!==200 || !is_array($data) || ($data['code'] ?? -1)!==0) throw new Exception('飞书接口错误：'.sanitize_text_field($data['msg'] ?? 'HTTP '.wp_remote_retrieve_response_code($r)).'（'.intval($data['code'] ?? 0).'）');
 return $data;
}
function fil_parse_base($input, $table_input) {
 $input=trim($input); $table=trim($table_input); $base=''; $link_table='';
 if (preg_match('/^[A-Za-z0-9]+$/', $input)) $base=$input;
 else {
  $parts=wp_parse_url($input);
  if (!$parts || ($parts['scheme'] ?? '')!=='https' || !preg_match('~^/base/([A-Za-z0-9]+)/?$~', $parts['path'] ?? '', $m)) throw new Exception('请填写多维表格自身的 /base/ 链接或 App Token；外层 /docx/ 链接不能直接读取表格。');
  $base=$m[1]; parse_str($parts['query'] ?? '', $query); $link_table=$query['table'] ?? '';
  if (!is_string($link_table)) throw new Exception('链接中的 table 参数无效。');
 }
 if (!$table) $table=$link_table;
 if ($table !== '' && !preg_match('/^tbl[A-Za-z0-9]+$/', $table)) throw new Exception('数据表 ID 无效，应以 tbl 开头。');
 return [$base,$table];
}
function fil_list_all($url, $token) {
 $all=[]; $page=''; $pages=0; $seen=[];
 do {
  if (++$pages>100) throw new Exception('数据页数超过上限，原规则未更改。');
  $response=fil_request($url.'?page_size=100'.($page ? '&page_token='.rawurlencode($page) : ''), ['headers'=>['Authorization'=>'Bearer '.$token]]);
  $data=$response['data'] ?? null;
  if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) throw new Exception('飞书返回的数据不完整，原规则未更改。');
  foreach ($data['items'] as $item) $all[]=$item;
  if (empty($data['has_more'])) break;
  $page=$data['page_token'] ?? '';
  if (!is_string($page) || $page==='' || isset($seen[$page])) throw new Exception('飞书分页信息不完整或重复，原规则未更改。');
  $seen[$page]=true;
 } while (true);
 return $all;
}
function fil_text_value($value) {
 if (is_string($value)) return trim($value);
 if (!is_array($value)) return '';
 $out='';
 foreach ($value as $part) {
  if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) return '';
  $out.=$part['text'];
 }
 return trim($out);
}
function fil_rules_from_records($records) {
 $rules=[]; $errors=[]; $seen=[];
 foreach ($records as $index=>$record) {
  $f=$record['fields'] ?? []; if (!is_array($f)) throw new Exception('记录数据格式无效，原规则未更改。');
  $id=sanitize_text_field($record['record_id'] ?? ('第 '.($index+1).' 条'));
  $keyword=fil_text_value($f['关键词'] ?? ''); $status=fil_text_value($f['状态'] ?? '');
  $link=$f['内链地址'] ?? ''; $link=is_array($link) ? ($link['link'] ?? '') : $link; $link=is_string($link) ? trim($link) : '';
  if ($keyword==='' && $status==='' && $link==='') continue;
  if ($status==='停用') continue;
  $url=esc_url_raw($link, ['http','https']); $host=wp_parse_url($url, PHP_URL_HOST); $scheme=wp_parse_url($url, PHP_URL_SCHEME);
  if ($keyword==='' || $status!=='启用' || !preg_match('~^https?://~i', $link) || !$url || !$host || !in_array(strtolower((string)$scheme), ['http','https'], true) || preg_match('/[\r\n]/', $keyword)) { $errors[]=$id; continue; }
  $key=strtolower($keyword);
  if (isset($seen[$key]) && $seen[$key]!==$url) { $errors[]=$id; continue; }
  if (isset($seen[$key])) continue;
  $seen[$key]=$url; $rules[]=['keyword'=>$keyword, 'url'=>$url];
 }
 if ($errors) throw new Exception('记录 '.implode('、',array_slice($errors,0,20)).' 无效或关键词冲突（共 '.count($errors).' 条）；请检查关键词、状态及完整的 HTTP/HTTPS URL。原规则未更改。');
 return $rules;
}
function fil_sync_rules() {
  $s=get_option(FIL_OPTION, []);
  if (empty($s['app_id']) || empty($s['secret']) || empty($s['base'])) throw new Exception('请先保存 App ID、App Secret 和多维表格链接。');
  list($base,$table)=fil_parse_base($s['base'], $s['table_id'] ?? '');
  $auth=fil_request('https://open.feishu.cn/open-apis/auth/v3/tenant_access_token/internal', ['method'=>'POST','headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['app_id'=>$s['app_id'],'app_secret'=>$s['secret']])]);
  if (empty($auth['tenant_access_token'])) throw new Exception('飞书未返回访问凭证。');
  $base_endpoint='https://open.feishu.cn/open-apis/bitable/v1/apps/'.rawurlencode($base).'/tables';
  if ($table==='') {
   $tables=fil_list_all($base_endpoint, $auth['tenant_access_token']);
   if (count($tables)!==1 || empty($tables[0]['table_id'])) throw new Exception('多维表格中有 '.count($tables).' 张可访问的数据表，请在设置中填写目标数据表 ID（tbl 开头）。');
   $table=$tables[0]['table_id'];
  }
  $endpoint=$base_endpoint.'/'.rawurlencode($table);
  $fields=fil_list_all($endpoint.'/fields', $auth['tenant_access_token']); $types=[];
  foreach ($fields as $field) if (isset($field['field_name'], $field['type'])) $types[$field['field_name']]=intval($field['type']);
  if (($types['关键词'] ?? 0)!==1 || !in_array($types['内链地址'] ?? 0,[1,15],true) || ($types['状态'] ?? 0)!==3) throw new Exception('字段不匹配：关键词须为文本，内链地址须为超链接或文本，状态须为单选；请检查字段名称和类型。');
  $records=fil_list_all($endpoint.'/records', $auth['tenant_access_token']);
  $rules=fil_rules_from_records($records);
  update_option(FIL_RULES, $rules, false);
  update_option('fil_synced_at', current_time('mysql'), false);
  return '同步成功，共 '.count($rules).' 条规则。';
}
function fil_run_sync() {
 try { $message=fil_sync_rules(); }
 catch (Throwable $e) { $message='同步失败：'.$e->getMessage(); }
 update_option('fil_sync_result', ['time'=>current_time('mysql'), 'message'=>$message], false);
 return $message;
}
add_action(FIL_CRON, function () { try { fil_run_sync(); } finally { fil_schedule_sync(); } });
add_action('admin_post_fil_sync', function () {
 if (!current_user_can('manage_options')) wp_die('Forbidden');
 check_admin_referer('fil_sync');
 fil_redirect(fil_run_sync());
});
add_action('enqueue_block_editor_assets', function () {
 wp_enqueue_script('fil-editor', plugins_url('editor.js',__FILE__), ['wp-plugins','wp-edit-post','wp-element','wp-components','wp-data','wp-blocks','wp-rich-text'], '0.4.1', true);
 $settings=get_option(FIL_OPTION, []);
 $editor_config = [
  'rules' => get_option(FIL_RULES, []),
  'home' => home_url('/'),
  'maxPerRun' => (int)($settings['max_per_run'] ?? 10),
  'maxPerTarget' => (int)($settings['max_per_target'] ?? 1),
  'sameSiteOnly' => !empty($settings['same_site_only']),
 ];
 wp_add_inline_script('fil-editor', 'window.filData = '.wp_json_encode($editor_config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';', 'before');
});
