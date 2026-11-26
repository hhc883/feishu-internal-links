<?php

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

function add_action($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function check_ajax_referer($action, $field) { $GLOBALS['checked_nonce'] = [$action, $field]; }
function absint($value) { return abs((int)$value); }
function current_user_can($capability, $post_id) { return $capability === 'edit_post' && $post_id === $GLOBALS['editable_post_id']; }
function get_post_status($post_id) { return 'publish'; }
function wp_unslash($value) { return stripslashes($value); }
class TestAjaxResponse extends RuntimeException {}
function wp_send_json_success($data) { throw new TestAjaxResponse(json_encode($data), 200); }
function wp_send_json_error($data, $status) { throw new TestAjaxResponse(json_encode($data), $status); }

function home_url($path) { return 'https://www.example.net'.$path; }
function get_option($name, $default) { return $GLOBALS['options'][$name] ?? $default; }
function pll_get_post_language($post_id) { return [1 => 'zh', 2 => 'en', 4 => 'zh-tw'][$post_id] ?? false; }
function pll_languages_list() { return ['en', 'zh', 'zh-tw']; }
function pll_home_url($language) {
 return $GLOBALS['options']['home'].'/'.(['zh' => 'zh-cn/', 'zh-tw' => 'zh-tw/'][$language] ?? '');
}
function sanitize_text_field($value) { return is_string($value) ? trim(strip_tags($value)) : ''; }
function add_settings_error($name, $code, $message) { $GLOBALS['settings_errors'][] = $code; }
function wp_parse_url($url) { return parse_url($url); }
function is_wp_error($response) { return $response === null; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_header($response, $name) { return $response[$name] ?? ''; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function wp_safe_remote_get($url, $args) {
 $GLOBALS['requests'][] = $url;
 return $GLOBALS['responses'][$url] ?? null;
}
class WP_Http {
 public static function make_absolute_url($location, $url) { return $location; }
}

require __DIR__.'/../draft-link-check.php';

function expect_same($expected, $actual, $message) {
 if ($expected !== $actual) {
  throw new RuntimeException($message."\n".var_export($actual, true));
 }
}

$settings = [
 'public_origin' => 'https://example.io',
 'peer_hostname' => 'example.net',
 'sitemap_url' => 'https://example.io/sitemap_index.xml',
 'on_missing' => 'keep',
];
$sitemap = 'https://example.io/posts.xml';
$missing_file = 'https://example.io/pages.xml';
$foreign = 'https://unrelated.test/pages.xml';
$correct = 'https://example.io/blog/proxy/?ref=blog';
$alias = 'https://www.example.io/blog/guide/';
$unmatched = 'https://www.example.net/blog/untranslated/';
$source = 'https://www.example.net/blog/proxy/?ref=blog#faq';
$unavailable = 'https://example.net/blog/unavailable/';
$inputs = [$source, $alias, $unmatched, $unavailable, $correct, 'https://not-example.net/blog/proxy/'];
$index = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'.$sitemap.'</loc></sitemap>%s</sitemapindex>';
$responses = [
 $settings['sitemap_url'] => ['status' => 200, 'body' => sprintf($index, '')],
 $sitemap => ['status' => 200, 'body' => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
  .'<url><loc>https://www.example.io/blog/proxy/</loc></url>'
  .'<url><loc>https://example.io/blog/guide/</loc></url>'
  .'<url><loc>https://example.io/blog/unavailable/</loc></url></urlset>'],
 $correct => ['status' => 200, 'body' => 'ok'],
 'https://example.io/blog/guide/' => ['status' => 200, 'body' => 'ok'],
 'https://example.io/blog/unavailable/' => ['status' => 404, 'body' => 'missing'],
];
$cache = [];
$requests = [];
$result = fil_draft_check_results($inputs, $settings);
expect_same(['replace', 'replace', 'keep', 'unverified'], array_column($result, 'action'), 'Default actions');
expect_same($correct.'#faq', $result[0]['url'], 'Preserve query and fragment');
expect_same('https://example.io/blog/guide/', $result[1]['url'], 'Repair same-site www alias');
expect_same('page', $result[3]['reason'], 'A listed page that fails verification is kept');
expect_same(false, in_array($unmatched, $requests, true), 'Only fetch on the public site');

$settings['on_missing'] = 'unlink';
expect_same('unlink', fil_draft_check_results($inputs, $settings)[2]['action'], 'Unlink a confirmed sitemap miss');
foreach ([$missing_file, $foreign] as $child) {
 $cache = [];
 $requests = [];
 $responses[$settings['sitemap_url']]['body'] = sprintf($index, '<sitemap><loc>'.$child.'</loc></sitemap>');
 $result = fil_draft_check_results($inputs, $settings);
 expect_same('unverified', $result[2]['action'], 'Incomplete sitemap must preserve unmatched links');
 expect_same('sitemap', $result[2]['reason'], 'Show why a result could not be verified');
 expect_same('replace', $result[0]['action'], 'Verified links are still available on partial reads');
 expect_same(false, in_array($foreign, $requests, true), 'Do not fetch foreign sitemap files');
}

$cache = [];
$responses[$settings['sitemap_url']]['body'] = sprintf($index, '');
$responses[$correct] = ['status' => 302, 'body' => '', 'location' => 'https://unrelated.test/blog/proxy/'];
expect_same('unverified', fil_draft_check_results([$source], $settings)[0]['action'], 'Do not accept a foreign page redirect');
expect_same(null, fil_draft_parse_sitemap('<urlset><url><lastmod>2026-01-01</lastmod></url></urlset>'), 'Invalid sitemap must not count as complete');
expect_same(null, fil_draft_parse_sitemap('<!DOCTYPE urlset SYSTEM "https://unrelated.test/x"><urlset/>'), 'Reject XML document types');

$reverse = [
 'public_origin' => 'https://www.example.net',
 'peer_hostname' => 'example.io',
 'sitemap_url' => 'https://www.example.net/blog/sitemap.xml',
 'on_missing' => 'keep',
];
$responses[$reverse['sitemap_url']] = [
 'status' => 200,
 'body' => '<urlset><url><loc>https://www.example.net/blog/guide/</loc></url></urlset>',
];
$responses['https://www.example.net/blog/guide/'] = ['status' => 200, 'body' => 'ok'];
expect_same('https://www.example.net/blog/guide/', fil_draft_check_results([$alias], $reverse)[0]['url'], 'The other admin uses its own configured public hostname');

$main_sitemap = 'https://www.example.net/sitemap.xml';
$blog_sitemap = $reverse['sitemap_url'];
$homepage = 'https://www.example.net/';
$source_homepage = 'https://www.example.io/';
$options = [FIL_DRAFT_LINK_OPTION => $reverse];
$settings_errors = [];
$submitted = array_merge($reverse, [
 'sitemap_url' => "  $blog_sitemap\r\n\r\n$main_sitemap\r\n$blog_sitemap  ",
 'on_missing' => 'unlink',
]);
$multi = fil_draft_sanitize_settings($submitted);
expect_same("$blog_sitemap\n$main_sitemap", $multi['sitemap_url'], 'Keep unique URLs from a multiline settings submission');
expect_same([], $settings_errors, 'Valid multiline settings save without errors');
$invalid = array_merge($submitted, ['sitemap_url' => "$blog_sitemap\n$foreign"]);
expect_same($reverse, fil_draft_sanitize_settings($invalid), 'A foreign URL must leave the old settings intact');
expect_same(['fil_draft_invalid'], $settings_errors, 'Invalid sitemap settings should show an error');

$responses[$main_sitemap] = [
 'status' => 200,
 'body' => '<urlset><url><loc>'.$homepage.'</loc></url></urlset>',
];
$responses[$homepage] = ['status' => 200, 'body' => 'homepage'];
$other_source = 'https://www.example.io/blog/not-on-this-site/';
$requests = [];
$cache = [];
$result = fil_draft_check_results([$alias, $source_homepage, $other_source], $multi);
expect_same(['replace', 'replace', 'unlink'], array_column($result, 'action'), 'Match across both sources and unlink only after a complete miss');
expect_same($homepage, $result[1]['url'], 'A homepage in the second sitemap matches the peer homepage');
expect_same(1, count(array_keys($requests, $blog_sitemap, true)), 'Each configured sitemap is only fetched once');

$cache = [];
unset($responses[$blog_sitemap]);
$result = fil_draft_check_results([$alias, $source_homepage, $other_source], $multi);
expect_same(['unverified', 'replace', 'unverified'], array_column($result, 'action'), 'Continue matching if one sitemap fails and preserve uncertain links');

$options['home'] = 'https://www.example.net/blog';
$localized_sitemap = 'https://www.example.net/blog/zh-cn/sitemap.xml';
$localized = array_merge($multi, ['sitemap_url' => "$main_sitemap\n$blog_sitemap\n$localized_sitemap", 'on_missing' => 'keep']);
$english_page = 'https://www.example.net/blog/guide/';
$chinese_page = 'https://www.example.net/blog/zh-cn/guide/';
$traditional_page = 'https://www.example.net/blog/zh-tw/guide/';
$english_only_page = 'https://www.example.net/blog/english-only/';
$responses[$blog_sitemap] = [
 'status' => 200,
 'body' => '<urlset><url><loc>'.$english_page.'</loc></url><url><loc>'.$traditional_page.'</loc></url><url><loc>'.$english_only_page.'</loc></url></urlset>',
];
$responses[$localized_sitemap] = ['status' => 200, 'body' => '<urlset><url><loc>'.$chinese_page.'</loc></url></urlset>'];
$responses[$chinese_page.'?ref=draft'] = ['status' => 200];
$responses[$traditional_page] = ['status' => 200];
$from_english = 'https://www.example.io/blog/guide/?ref=draft#details';
$from_chinese = 'https://www.example.io/blog/zh-cn/guide/?ref=draft#details';
$from_traditional = 'https://www.example.io/blog/zh-tw/guide/';
$from_english_only = 'https://www.example.io/blog/english-only/';
$cache = [];
$result = fil_draft_check_results([$from_english, $from_chinese, $from_traditional, $from_english_only, $source_homepage], $localized, 1);
expect_same(['replace', 'replace', 'replace', 'keep', 'replace'], array_column($result, 'action'), 'A Chinese draft matches Chinese paths and retains the configured behavior for missing translations');
expect_same($chinese_page.'?ref=draft#details', $result[0]['url'], 'Use the language URL directory and preserve query and fragment');
expect_same($result[0]['url'], $result[1]['url'], 'Do not duplicate existing language prefixes');
expect_same($traditional_page, $result[2]['url'], 'Preserve an explicitly selected other language');
expect_same($homepage, $result[4]['url'], 'The WordPress language does not rewrite the main site homepage');
expect_same($english_page, fil_draft_check_results([$alias], $localized, 2)[0]['url'], 'Default-language drafts still match unprefixed paths');
expect_same($english_page, fil_draft_check_results([$alias], $localized, 3)[0]['url'], 'Drafts without a saved language still match unprefixed paths');
$same_site_english = $english_page.'?ref=draft#details';
$same_site_links = [$same_site_english, $traditional_page, $chinese_page, $homepage];
$responses[$traditional_page.'?ref=draft'] = ['status' => 200];
$matches = fil_draft_check_results($same_site_links, $localized, 4);
expect_same([['link' => $same_site_english, 'action' => 'replace', 'url' => $traditional_page.'?ref=draft#details']], $matches, 'Check an unprefixed same-site blog link in Traditional Chinese and leave other same-site links alone');
expect_same([], fil_draft_check_results($same_site_links, $localized, 2), 'Do not recheck same-site links in an unprefixed language');
$localized['on_missing'] = 'unlink';
expect_same('unlink', fil_draft_check_results([$from_english_only], $localized, 1)[0]['action'], 'Unlink a confirmed missing translation when requested');

$options[FIL_DRAFT_LINK_OPTION] = $localized;
$editable_post_id = 1;
$_POST = ['post_id' => '1', 'links' => addslashes(json_encode([$from_english, $from_english_only]))];
$check_links = $hooks['wp_ajax_'.FIL_DRAFT_LINK_ACTION];
try {
 $check_links();
 throw new RuntimeException('Expected an AJAX response');
} catch (TestAjaxResponse $response) {
 expect_same(200, $response->getCode(), 'Allow checking an editable published article');
 $result = json_decode($response->getMessage(), true)['results'];
 expect_same($chinese_page.'?ref=draft#details', $result[0]['url'], 'Return the verified replacement');
 expect_same('unlink', $result[1]['action'], 'Return the default action for unmatched links');
}
expect_same([FIL_DRAFT_LINK_ACTION, 'nonce'], $checked_nonce, 'Check the AJAX nonce');
$editable_post_id = 2;
try {
 $check_links();
 throw new RuntimeException('Expected a permission error');
} catch (TestAjaxResponse $response) {
 expect_same(403, $response->getCode(), 'Require edit permission for the requested article');
}

echo "Draft-link checks passed.\n";
