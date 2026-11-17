<?php

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

function add_action() {}
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

echo "Draft-link checks passed.\n";
