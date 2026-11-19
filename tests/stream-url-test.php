<?php
// Run: php tests/stream-url-test.php
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_shortcode(...$args) {}
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($url) { return $url; }

require dirname(__DIR__) . '/kaad-radio-player.php';

$cases = [
  'https://s36.myradiostream.com/:11554/stream' => 'https://s36.myradiostream.com/:11554/stream',
  'https://s36.myradiostream.com:11554/stream' => 'https://s36.myradiostream.com:11554/stream',
  'http://listen.kaad-lp.org/' => 'http://listen.kaad-lp.org/',
  'listen.kaad-lp.org/' => 'https://listen.kaad-lp.org/',
];
foreach ($cases as $input => $expected) {
  $actual = kaad_radio_player_normalize_url($input);
  if ($actual !== $expected) {
    fwrite(STDERR, "FAIL: $input => $actual (expected $expected)\n");
    exit(1);
  }
}
echo "All stream URL normalization tests passed.\n";
