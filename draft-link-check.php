<?php

if (!defined('ABSPATH')) exit;

const FIL_DRAFT_LINK_OPTION = 'fil_draft_link_settings';
const FIL_DRAFT_LINK_ACTION = 'fil_check_draft_links';

function fil_draft_hostname($host) {
 return preg_replace('/^www\./', '', strtolower($host));
}

function fil_draft_url_parts($url) {
 if (!is_string($url) || !preg_match('~^https?://~i', $url) || preg_match('/[\x00-\x20\x7f]/', $url) || strpos($url, '\\') !== false) {
  return null;
 }
 $parts = wp_parse_url($url);
 if (empty($parts['host']) || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || isset($parts['user']) || isset($parts['pass'])) {
  return null;
 }
 return $parts;
}

function fil_draft_origin($parts) {
 $scheme = strtolower($parts['scheme']);
 $port = $parts['port'] ?? null;
 $authority = strtolower($parts['host']);
 if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) $authority .= ':'.$port;
 return $scheme.'://'.$authority;
}

function fil_draft_path($parts) {
 $path = $parts['path'] ?? '/';
 $path = preg_replace_callback('/[^\x21-\x7e]+/', function ($m) { return rawurlencode($m[0]); }, $path);
 return preg_replace_callback('/%[0-9a-f]{2}/i', function ($m) { return strtoupper($m[0]); }, $path);
}

function fil_draft_settings() {
 $home = fil_draft_url_parts(home_url('/'));
 return array_merge([
  'public_origin' => $home ? fil_draft_origin($home) : '',
  'peer_hostname' => '',
  'sitemap_url' => '',
  'on_missing' => 'keep',
 ], get_option(FIL_DRAFT_LINK_OPTION, []));
}

function fil_draft_sitemap_urls($value) {
 if (!is_string($value)) return [];
 $urls = array_map('trim', preg_split('/\r\n|\r|\n/', $value));
 return array_values(array_unique(array_filter($urls, 'strlen')));
}

function fil_draft_sanitize_settings($input) {
 $input = is_array($input) ? $input : [];
 $origin_input = trim(sanitize_text_field($input['public_origin'] ?? ''));
 $origin = fil_draft_url_parts($origin_input);
 $peer = strtolower(trim(sanitize_text_field($input['peer_hostname'] ?? '')));
 $sitemaps = fil_draft_sitemap_urls($input['sitemap_url'] ?? '');
 $message = '';
 if (!$origin || !in_array($origin['path'] ?? '', ['', '/'], true) || isset($origin['query']) || isset($origin['fragment'])) {
  $message = '请填写完整的对外访问地址，例如 https://example.com，地址末尾无需加 /blog。';
 } elseif ($peer !== '' && (!filter_var($peer, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || strpos($peer, '.') === false || fil_draft_hostname($peer) === fil_draft_hostname($origin['host']))) {
  $message = '请填写另一个站点的域名，例如 example.net。';
 } elseif (($peer === '') !== empty($sitemaps)) {
  $message = '启用草稿链接检查时，请同时填写对应站点域名和至少一个本站 sitemap 地址。';
 } else {
  foreach ($sitemaps as $sitemap) {
   $parts = fil_draft_url_parts($sitemap);
   if (!$parts || isset($parts['fragment']) || fil_draft_hostname($parts['host']) !== fil_draft_hostname($origin['host'])) {
    $message = '本站 sitemap 地址必须是同站的完整网址，每行一个。';
    break;
   }
  }
 }

 if ($message !== '') {
  add_settings_error(FIL_DRAFT_LINK_OPTION, 'fil_draft_invalid', $message);
  return fil_draft_settings();
 }
 return [
  'public_origin' => fil_draft_origin($origin),
  'peer_hostname' => fil_draft_hostname($peer),
  'sitemap_url' => implode("\n", $sitemaps),
  'on_missing' => ($input['on_missing'] ?? '') === 'unlink' ? 'unlink' : 'keep',
 ];
}

add_action('admin_init', function () {
 register_setting(FIL_DRAFT_LINK_OPTION, FIL_DRAFT_LINK_OPTION, ['sanitize_callback' => 'fil_draft_sanitize_settings']);
});

add_action('admin_menu', function () {
 add_options_page('草稿链接检查', '草稿链接检查', 'manage_options', 'fil-draft-links', 'fil_draft_settings_page');
});

function fil_draft_settings_page() {
 if (!current_user_can('manage_options')) return;
 $settings = fil_draft_settings();
 $fields = [
  'public_origin' => ['对外访问地址', '填入协议和正式域名，例如 https://example.com。草稿链接的 /blog/ 等原路径会保留。', 'url'],
  'peer_hostname' => ['对应站点域名', '填另一个后台对应的域名，例如 example.net。带 www 和不带 www 视为同站。', 'text'],
 ];
 echo '<div class="wrap"><h1>草稿链接检查</h1>';
 settings_errors();
 echo '<form action="options.php" method="post">';
 settings_fields(FIL_DRAFT_LINK_OPTION);
 echo '<table class="form-table">';
 foreach ($fields as $key => [$label, $description, $type]) {
  echo '<tr><th scope="row"><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td>';
  echo '<input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($key).'" name="'.esc_attr(FIL_DRAFT_LINK_OPTION.'['.$key.']').'" value="'.esc_attr($settings[$key]).'" aria-describedby="'.esc_attr($key.'_help').'">';
  echo '<p class="description" id="'.esc_attr($key.'_help').'">'.esc_html($description).'</p></td></tr>';
 }
 echo '<tr><th scope="row"><label for="sitemap_url">本站 sitemap 地址</label></th><td>';
 echo '<textarea class="large-text" rows="4" id="sitemap_url" name="'.esc_attr(FIL_DRAFT_LINK_OPTION).'[sitemap_url]" aria-describedby="sitemap_url_help">'.esc_textarea($settings['sitemap_url']).'</textarea>';
 echo '<p class="description" id="sitemap_url_help">每行填写一个完整的 XML sitemap 或索引地址，可同时填主站和博客的地址。清空对应域名和 sitemap 可停用草稿链接检查。</p></td></tr>';
 echo '<tr><th scope="row"><label for="fil_draft_missing">本站 sitemap 未匹配时</label></th><td>';
 echo '<select id="fil_draft_missing" name="'.esc_attr(FIL_DRAFT_LINK_OPTION).'[on_missing]">';
 echo '<option value="keep" '.selected($settings['on_missing'], 'keep', false).'>保留原链接</option>';
 echo '<option value="unlink" '.selected($settings['on_missing'], 'unlink', false).'>取消链接，保留文字和格式</option>';
 echo '</select><p class="description">只有完整读取所有已配置的 sitemap 后才能判定未匹配。无法确定时保留原链接。</p></td></tr></table>';
 submit_button();
 echo '</form></div>';
}

// Requests stay on the public site's hostname, including each redirect.
function fil_draft_get($url, $hostname, $deadline, $limit, $page_path = null) {
 for ($hop = 0; $hop < 4; $hop++) {
  $parts = fil_draft_url_parts($url);
  $remaining = $deadline - microtime(true);
  if (!$parts || fil_draft_hostname($parts['host']) !== $hostname || $remaining < 0.5) return null;
  if ($page_path !== null && rtrim(fil_draft_path($parts), '/') !== rtrim($page_path, '/')) return null;
  $response = wp_safe_remote_get($url, [
   'timeout' => min(4, $remaining),
   'redirection' => 0,
   'limit_response_size' => $limit,
  ]);
  if (is_wp_error($response)) return null;
  $status = (int)wp_remote_retrieve_response_code($response);
  if (!in_array($status, [301, 302, 303, 307, 308], true)) return $response;
  $location = wp_remote_retrieve_header($response, 'location');
  if (!is_string($location) || $location === '') return null;
  $url = WP_Http::make_absolute_url($location, $url);
 }
 return null;
}

function fil_draft_parse_sitemap($xml) {
 if (!class_exists('DOMDocument') || $xml === '' || stripos($xml, '<!DOCTYPE') !== false) return null;
 $document = new DOMDocument();
 if (!@$document->loadXML($xml, LIBXML_NONET) || $document->doctype) return null;
 $root = $document->documentElement;
 if (!$root || !in_array($root->namespaceURI, [null, '', 'http://www.sitemaps.org/schemas/sitemap/0.9'], true)) return null;
 if (!in_array($root->localName, ['urlset', 'sitemapindex'], true)) return null;
 $is_index = $root->localName === 'sitemapindex';
 $element = $is_index ? 'sitemap' : 'url';
 $locations = [];
 foreach ($root->childNodes as $entry) {
  if (!($entry instanceof DOMElement) || $entry->namespaceURI !== $root->namespaceURI) continue;
  if ($entry->localName !== $element) return null;
  $location = '';
  foreach ($entry->childNodes as $child) {
   if ($child instanceof DOMElement && $child->namespaceURI === $root->namespaceURI && $child->localName === 'loc') {
    $location = trim($child->textContent);
    break;
   }
  }
  if ($location === '') return null;
  $locations[] = $location;
 }
 return $locations ? ['index' => $is_index, 'locations' => $locations] : null;
}

function fil_draft_sitemap_matches($sitemaps, $hostname, $paths, $deadline) {
 $queue = $sitemaps;
 $seen = [];
 $missing = array_fill_keys($paths, true);
 $found = [];
 $complete = !empty($queue);
 $size_limit = 6 * 1024 * 1024;
 for ($i = 0; $i < count($queue) && $missing; $i++) {
  $url = $queue[$i];
  if (isset($seen[$url])) continue;
  if (count($seen) >= 64 || microtime(true) >= $deadline) {
   $complete = false;
   break;
  }
  $seen[$url] = true;
  $cache_key = 'fil_draft_xml_'.md5($hostname.'|'.$url);
  $parsed = get_transient($cache_key);
  if ($parsed === false) {
   $response = fil_draft_get($url, $hostname, $deadline, $size_limit);
   $xml = $response && (int)wp_remote_retrieve_response_code($response) === 200 ? wp_remote_retrieve_body($response) : '';
   if (strncmp($xml, "\x1f\x8b", 2) === 0 && function_exists('gzdecode')) {
    $xml = @gzdecode($xml, $size_limit) ?: '';
   }
   $parsed = strlen($xml) < $size_limit ? fil_draft_parse_sitemap($xml) : null;
   if (!$parsed) {
    $complete = false;
    continue;
   }
   set_transient($cache_key, $parsed, 5 * MINUTE_IN_SECONDS);
  }
  foreach ($parsed['locations'] as $location) {
   $parts = fil_draft_url_parts($location);
   if (!$parts || fil_draft_hostname($parts['host']) !== $hostname) {
    $complete = false;
    continue;
   }
   if ($parsed['index']) {
    $queue[] = $location;
   } else {
    $path = fil_draft_path($parts);
    if (isset($missing[$path])) {
     $found[$path] = true;
     unset($missing[$path]);
    }
   }
  }
 }
 return [$found, $complete];
}

function fil_draft_check_results($links, $settings) {
 $site = fil_draft_url_parts($settings['public_origin']);
 $hostname = fil_draft_hostname($site['host']);
 $candidates = [];
 $paths = [];
 foreach ($links as $link) {
  $parts = fil_draft_url_parts($link);
  if (!$parts || !in_array(fil_draft_hostname($parts['host']), [$hostname, $settings['peer_hostname']], true)) continue;
  if (fil_draft_origin($parts) === $settings['public_origin']) continue;
  $path = fil_draft_path($parts);
  $url = $settings['public_origin'].$path;
  if (isset($parts['query'])) $url .= '?'.$parts['query'];
  $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
  $paths[$path] = true;
  $candidates[] = [$link, $path, $url, $fragment];
 }
 $deadline = microtime(true) + 24;
 // Leave time to verify pages even if some sitemap files take a while.
 $sitemaps = fil_draft_sitemap_urls($settings['sitemap_url']);
 [$found, $complete] = fil_draft_sitemap_matches($sitemaps, $hostname, array_keys($paths), $deadline - 6);
 $results = [];
 $verified = [];
 foreach ($candidates as [$link, $path, $url, $fragment]) {
  if (!isset($found[$path])) {
   $results[] = [
    'link' => $link,
    'action' => $complete ? $settings['on_missing'] : 'unverified',
    'reason' => $complete ? 'missing' : 'sitemap',
   ];
   continue;
  }
  if (!isset($verified[$url])) {
   $cache_key = 'fil_draft_page_'.md5($url);
   $verified[$url] = get_transient($cache_key) === 'ok';
   if (!$verified[$url]) {
    $response = fil_draft_get($url, $hostname, $deadline, 4096, $path);
    $status = $response ? (int)wp_remote_retrieve_response_code($response) : 0;
    $verified[$url] = $status >= 200 && $status < 300;
    if ($verified[$url]) set_transient($cache_key, 'ok', MINUTE_IN_SECONDS);
   }
  }
  $results[] = $verified[$url]
   ? ['link' => $link, 'action' => 'replace', 'url' => $url.$fragment]
   : ['link' => $link, 'action' => 'unverified', 'reason' => 'page'];
 }
 return $results;
}

add_action('wp_ajax_'.FIL_DRAFT_LINK_ACTION, function () {
 check_ajax_referer(FIL_DRAFT_LINK_ACTION, 'nonce');
 $post_id = absint($_POST['post_id'] ?? 0);
 if (!$post_id || !current_user_can('edit_post', $post_id)) {
  wp_send_json_error(['message' => '你没有权限检查这篇草稿。'], 403);
 }
 if (!in_array(get_post_status($post_id), ['auto-draft', 'draft', 'pending'], true)) {
  wp_send_json_error(['message' => '草稿链接检查适用于未发布的草稿。'], 400);
 }
 $settings = fil_draft_settings();
 if (!$settings['peer_hostname'] || !$settings['sitemap_url'] || !fil_draft_url_parts($settings['public_origin'])) {
  wp_send_json_error(['message' => '请先在「设置 → 草稿链接检查」中配置站点。'], 400);
 }
 $json = $_POST['links'] ?? '';
 $links = is_string($json) && strlen($json) <= 240000 ? json_decode(wp_unslash($json), true) : null;
 if (!is_array($links) || count($links) > 100) {
  wp_send_json_error(['message' => '一次最多检查 100 个不同地址。'], 400);
 }
 foreach ($links as $link) {
  if (!is_string($link) || strlen($link) > 2048 || !fil_draft_url_parts($link)) {
   wp_send_json_error(['message' => '草稿中有无法检查的地址。'], 400);
  }
 }
 wp_send_json_success(['results' => fil_draft_check_results(array_unique($links), $settings)]);
});

add_action('enqueue_block_editor_assets', function () {
 $settings = fil_draft_settings();
 if (!$settings['peer_hostname'] || !$settings['sitemap_url']) return;
 wp_enqueue_script('fil-draft-links', plugins_url('draft-link-check.js', __FILE__), [
  'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-rich-text',
 ], '0.5.1', true);
 wp_localize_script('fil-draft-links', 'filDraftLinks', [
  'origin' => $settings['public_origin'],
  'peerHostname' => $settings['peer_hostname'],
  'action' => FIL_DRAFT_LINK_ACTION,
  'nonce' => wp_create_nonce(FIL_DRAFT_LINK_ACTION),
  'endpoint' => admin_url('admin-ajax.php'),
 ]);
});
