<?php
/**
 * Plugin Name: KAAD Radio Player (Icecast Metadata)
 * Description: Shortcode player with now-playing text from a status JSON endpoint via a WordPress REST proxy.
 * Version: 1.3.6
 * Author: Tyler Jackson
 */

if (!defined('ABSPATH')) {
  exit;
}

function kaad_radio_player_defaults() {
  return [
    'stream_url' => 'https://s36.myradiostream.com/:11554/stream',
    'status_url' => 'https://song.tjackson.me/',
    'mount' => 'stream',
    'refresh' => '20',
    'autoplay' => '0',
    'title' => 'Now Playing',
    'logo' => 'https://kaad-lp.org/wp-content/uploads/2024/04/kaad.png',
  ];
}

function kaad_radio_player_is_valid_url($url) {
  if (!is_string($url) || $url === '') {
    return false;
  }

  $parts = wp_parse_url($url);
  if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    return false;
  }

  $scheme = strtolower((string) $parts['scheme']);
  return in_array($scheme, ['http', 'https'], true);
}

function kaad_radio_player_normalize_url($url) {
  if (!is_string($url)) {
    return '';
  }

  $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
  if ($url === '') {
    return '';
  }

  if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . ltrim($url, '/');
  }

  return esc_url_raw($url);
}

function kaad_radio_player_build_url($parts) {
  $url = '';

  if (isset($parts['scheme'])) {
    $url .= $parts['scheme'] . '://';
  }

  if (isset($parts['user'])) {
    $url .= $parts['user'];
    if (isset($parts['pass'])) {
      $url .= ':' . $parts['pass'];
    }
    $url .= '@';
  }

  if (isset($parts['host'])) {
    $url .= $parts['host'];
  }

  if (isset($parts['port'])) {
    $url .= ':' . intval($parts['port']);
  }

  if (isset($parts['path'])) {
    $url .= $parts['path'];
  }

  if (isset($parts['query']) && $parts['query'] !== '') {
    $url .= '?' . $parts['query'];
  }

  if (isset($parts['fragment']) && $parts['fragment'] !== '') {
    $url .= '#' . $parts['fragment'];
  }

  return $url;
}

function kaad_radio_player_get_options() {
  $saved = get_option('kaad_radio_player_options', []);
  if (!is_array($saved)) {
    $saved = [];
  }

  return wp_parse_args($saved, kaad_radio_player_defaults());
}

function kaad_radio_player_sanitize_options($input) {
  if (!is_array($input)) {
    $input = [];
  }

  $defaults = kaad_radio_player_defaults();
  $out = [];

  $out['stream_url'] = isset($input['stream_url']) ? kaad_radio_player_normalize_url($input['stream_url']) : $defaults['stream_url'];
  $out['status_url'] = isset($input['status_url']) ? kaad_radio_player_normalize_url($input['status_url']) : $defaults['status_url'];
  $out['mount'] = isset($input['mount']) ? sanitize_text_field($input['mount']) : $defaults['mount'];
  $out['refresh'] = isset($input['refresh']) ? (string) max(10, intval($input['refresh'])) : $defaults['refresh'];
  $out['autoplay'] = !empty($input['autoplay']) ? '1' : '0';
  $out['title'] = isset($input['title']) ? sanitize_text_field($input['title']) : $defaults['title'];
  $out['logo'] = isset($input['logo']) ? kaad_radio_player_normalize_url($input['logo']) : $defaults['logo'];

  if (!kaad_radio_player_is_valid_url($out['status_url'])) {
    add_settings_error('kaad_radio_player_options', 'kaad_status_url', 'Status URL is invalid.');
    $out['status_url'] = $defaults['status_url'];
  }

  if (!kaad_radio_player_is_valid_url($out['stream_url'])) {
    add_settings_error('kaad_radio_player_options', 'kaad_stream_url', 'Stream URL is invalid.');
    $out['stream_url'] = $defaults['stream_url'];
  }

  return $out;
}

function kaad_radio_player_render_text_field($args) {
  $opts = kaad_radio_player_get_options();
  $key = $args['key'];
  $type = isset($args['type']) ? $args['type'] : 'text';
  $desc = isset($args['desc']) ? $args['desc'] : '';

  printf(
    '<input class="regular-text" type="%1$s" name="kaad_radio_player_options[%2$s]" value="%3$s">',
    esc_attr($type),
    esc_attr($key),
    esc_attr(isset($opts[$key]) ? $opts[$key] : '')
  );

  if ($desc !== '') {
    printf('<p class="description">%s</p>', esc_html($desc));
  }
}

function kaad_radio_player_render_number_field($args) {
  $opts = kaad_radio_player_get_options();
  $key = $args['key'];
  $desc = isset($args['desc']) ? $args['desc'] : '';

  printf(
    '<input type="number" min="10" step="1" name="kaad_radio_player_options[%1$s]" value="%2$s">',
    esc_attr($key),
    esc_attr(isset($opts[$key]) ? $opts[$key] : '20')
  );

  if ($desc !== '') {
    printf('<p class="description">%s</p>', esc_html($desc));
  }
}

function kaad_radio_player_render_checkbox_field($args) {
  $opts = kaad_radio_player_get_options();
  $key = $args['key'];
  $desc = isset($args['desc']) ? $args['desc'] : '';

  printf(
    '<label><input type="checkbox" name="kaad_radio_player_options[%1$s]" value="1" %2$s> %3$s</label>',
    esc_attr($key),
    checked(isset($opts[$key]) ? $opts[$key] : '0', '1', false),
    esc_html($desc)
  );
}

add_action('admin_init', function () {
  register_setting('kaad_radio_player_settings', 'kaad_radio_player_options', [
    'sanitize_callback' => 'kaad_radio_player_sanitize_options',
  ]);

  add_settings_section(
    'kaad_radio_player_main',
    'Player Defaults',
    function () {
      echo '<p>These values are used by default for <code>[kaad_radio_player]</code>. Any shortcode attribute still overrides settings.</p>';
    },
    'kaad-radio-player'
  );

  add_settings_field('kaad_stream_url', 'Stream URL', 'kaad_radio_player_render_text_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'stream_url',
    'type' => 'url',
    'desc' => 'Example: https://s36.myradiostream.com/:11554/stream',
  ]);

  add_settings_field('kaad_status_url', 'Status JSON URL', 'kaad_radio_player_render_text_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'status_url',
    'type' => 'url',
    'desc' => 'Use your JSON endpoint URL, for example: https://song.tjackson.me/',
  ]);

  add_settings_field('kaad_mount', 'Mount', 'kaad_radio_player_render_text_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'mount',
    'type' => 'text',
    'desc' => 'Use stream (without leading slash) unless your mount differs.',
  ]);

  add_settings_field('kaad_refresh', 'Refresh seconds', 'kaad_radio_player_render_number_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'refresh',
    'desc' => 'Minimum 10 seconds.',
  ]);

  add_settings_field('kaad_autoplay', 'Autoplay', 'kaad_radio_player_render_checkbox_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'autoplay',
    'desc' => 'Try to autoplay on page load (some browsers block this).',
  ]);

  add_settings_field('kaad_title', 'Title text', 'kaad_radio_player_render_text_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'title',
    'type' => 'text',
  ]);

  add_settings_field('kaad_logo', 'Logo URL', 'kaad_radio_player_render_text_field', 'kaad-radio-player', 'kaad_radio_player_main', [
    'key' => 'logo',
    'type' => 'url',
  ]);
});

add_action('admin_menu', function () {
  add_options_page(
    'KAAD Radio Player',
    'KAAD Radio Player',
    'manage_options',
    'kaad-radio-player',
    function () {
      if (!current_user_can('manage_options')) {
        return;
      }
      ?>
      <div class="wrap">
        <h1>KAAD Radio Player</h1>
        <?php settings_errors('kaad_radio_player_options'); ?>
        <form method="post" action="options.php">
          <?php
          settings_fields('kaad_radio_player_settings');
          do_settings_sections('kaad-radio-player');
          submit_button();
          ?>
        </form>
        <p><strong>Shortcode:</strong> <code>[kaad_radio_player]</code></p>
      </div>
      <?php
    }
  );
});

add_action('rest_api_init', function () {
  register_rest_route('kaad/v1', '/icecast-now-playing', [
    'methods' => 'GET',
    'callback' => function (\WP_REST_Request $request) {
      $opts = kaad_radio_player_get_options();
      $status_url = kaad_radio_player_normalize_url((string) $request->get_param('status_url'));
      $mount = sanitize_text_field((string) $request->get_param('mount'));

      if ($status_url === '') {
        $status_url = kaad_radio_player_normalize_url($opts['status_url']);
      }
      if ($mount === '') {
        $mount = sanitize_text_field($opts['mount']);
      }
      if ($mount === '') {
        $mount = 'stream';
      }

      if (!kaad_radio_player_is_valid_url($status_url)) {
        return new \WP_REST_Response(['error' => 'Invalid or missing status_url'], 400);
      }

      $cache_key = 'kaad_icecast_np_' . md5($status_url . '|' . $mount);
      $cached = get_transient($cache_key);
      if ($cached !== false) {
        return new \WP_REST_Response($cached, 200);
      }

      $resp = wp_remote_get($status_url, [
        'timeout' => 8,
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'WordPress KAAD Radio Player',
        ],
      ]);

      if (is_wp_error($resp)) {
        return new \WP_REST_Response(['error' => $resp->get_error_message()], 502);
      }

      $code = wp_remote_retrieve_response_code($resp);
      $body = wp_remote_retrieve_body($resp);

      if ($code < 200 || $code >= 300) {
        $body_preview = '';
        if (is_string($body) && $body !== '') {
          $body_preview = substr(trim($body), 0, 200);
        }
        return new \WP_REST_Response([
          'error' => 'Upstream error',
          'status' => $code,
          'status_url' => $status_url,
          'upstream_body' => $body_preview,
        ], 502);
      }

      $json = json_decode($body, true);
      if (!is_array($json)) {
        return new \WP_REST_Response(['error' => 'Upstream did not return valid JSON'], 502);
      }

      $artist = '';
      $title = '';
      $text = '';
      $show_title = '';

      if (isset($json['text']) && is_string($json['text'])) {
        $text = trim($json['text']);
      }
      if (isset($json['artist']) && is_string($json['artist'])) {
        $artist = trim($json['artist']);
      }
      if (isset($json['title']) && is_string($json['title'])) {
        $title = trim($json['title']);
      }
      if (isset($json['show']) && is_array($json['show']) && isset($json['show']['title']) && is_string($json['show']['title'])) {
        $show_title = trim($json['show']['title']);
      } elseif (isset($json['show_title']) && is_string($json['show_title'])) {
        $show_title = trim($json['show_title']);
      }

      if (($artist === '' || $title === '') && isset($json['track']) && is_array($json['track'])) {
        if ($artist === '' && isset($json['track']['artist']) && is_string($json['track']['artist'])) {
          $artist = trim($json['track']['artist']);
        }
        if ($title === '' && isset($json['track']['title']) && is_string($json['track']['title'])) {
          $title = trim($json['track']['title']);
        }
      }

      if ($artist === '' && $title !== '') {
        $parts = explode(' - ', $title, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
          $artist = trim($parts[0]);
          $title = trim($parts[1]);
        }
      }

      if ($text === '' && isset($json['icestats']['source'])) {
        $sources = [];
        $src = $json['icestats']['source'];
        if (is_array($src) && isset($src[0])) {
          $sources = $src;
        } elseif (is_array($src)) {
          $sources = [$src];
        }

        $mount_to_match = '/' . ltrim($mount, '/');
        $selected = null;

        foreach ($sources as $source) {
          if (!is_array($source)) {
            continue;
          }
          $listenurl = isset($source['listenurl']) ? (string) $source['listenurl'] : '';
          if (strpos($listenurl, $mount_to_match) !== false) {
            $selected = $source;
            break;
          }
        }

        if (!$selected && !empty($sources)) {
          $selected = $sources[0];
        }

        if (is_array($selected)) {
          $artist = isset($selected['artist']) ? trim((string) $selected['artist']) : $artist;
          $title = isset($selected['title']) ? trim((string) $selected['title']) : $title;
          $yp = isset($selected['yp_currently_playing']) ? trim((string) $selected['yp_currently_playing']) : '';

          if ($artist !== '' && $title !== '') {
            $text = $artist . ' - ' . $title;
          } elseif ($title !== '') {
            $text = $title;
          } elseif ($yp !== '') {
            $text = $yp;
          }
        }
      }

      if ($text === '' && $artist !== '' && $title !== '') {
        $text = $artist . ' - ' . $title;
      }

      $payload = [
        'artist' => $artist,
        'title' => $title,
        'text' => $text,
        'show_title' => $show_title,
        'mount' => '/' . ltrim($mount, '/'),
        'status_url' => $status_url,
        'updated_at' => time(),
      ];

      set_transient($cache_key, $payload, 30);
      return new \WP_REST_Response($payload, 200);
    },
    'permission_callback' => '__return_true',
  ]);
});

add_shortcode('kaad_radio_player', function ($atts) {
  $atts = shortcode_atts(kaad_radio_player_get_options(), $atts, 'kaad_radio_player');

  $stream_url = esc_url(kaad_radio_player_normalize_url($atts['stream_url']));
  $status_url = kaad_radio_player_normalize_url($atts['status_url']);
  $mount = sanitize_text_field($atts['mount']);
  $refresh = max(10, intval($atts['refresh']));
  $autoplay = ($atts['autoplay'] === '1' || $atts['autoplay'] === 'true') ? 'autoplay' : '';
  $title = sanitize_text_field($atts['title']);
  $logo = esc_url(kaad_radio_player_normalize_url($atts['logo']));

  $uid = 'kaad_' . wp_generate_uuid4();
  $proxy = esc_url_raw(rest_url('kaad/v1/icecast-now-playing'))
    . '?status_url=' . rawurlencode($status_url)
    . '&mount=' . rawurlencode($mount);

  static $kaad_assets_printed = false;

  ob_start();
  ?>
  <div class="kaad-player-wrap">
    <div class="kaad-card" id="<?php echo esc_attr($uid); ?>">
      <div class="kaad-top">
        <img class="kaad-logo" src="<?php echo esc_url($logo); ?>" alt="Station logo">
        <div class="kaad-meta">
          <div class="kaad-row1">
            <div class="kaad-title"><?php echo esc_html($title); ?></div>
            <span class="kaad-live">LIVE</span>
          </div>
          <div class="kaad-track kaad-now-playing" aria-live="polite">
            <span class="kaad-track-text">Loading...</span>
          </div>
        </div>
      </div>

      <div class="kaad-divider"></div>

      <?php if ($stream_url): ?>
        <audio class="kaad-audio" <?php echo $autoplay; ?> preload="none">
          <source src="<?php echo esc_url($stream_url); ?>" type="audio/mpeg">
          Your browser does not support the audio element.
        </audio>
        <div class="kaad-controls">
          <div class="kaad-buttons">
            <button class="kaad-play" type="button" aria-label="Play / Pause"></button>

            <div class="kaad-volwrap">
              <button class="kaad-speaker" aria-label="Mute / Unmute" type="button">
                <svg class="kaad-ico kaad-ico-vol" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 10v4h4l5 4V6L7 10H3z"></path>
                  <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"></path>
                  <path d="M14 3.23v2.06c2.89 1 5 3.77 5 6.71s-2.11 5.71-5 6.71v2.06c4.01-1.07 7-4.73 7-8.77s-2.99-7.7-7-8.77z"></path>
                </svg>
                <svg class="kaad-ico kaad-ico-mute" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 10v4h4l5 4V6L7 10H3z"></path>
                  <path d="M16.5 12l3.5 3.5-1.5 1.5L15 13.5l-3.5 3.5-1.5-1.5L13.5 12 10 8.5l1.5-1.5L15 10.5l3.5-3.5 1.5 1.5L16.5 12z"></path>
                </svg>
              </button>

              <div class="kaad-volpop">
                <input class="kaad-volume" type="range" min="0" max="1" step="0.01" value="1" aria-label="Volume">
              </div>
            </div>
          </div>
        </div>
        <div class="kaad-playback-status" role="status" aria-live="polite" aria-atomic="true"></div>
      <?php else: ?>
        <div class="kaad-warn">Missing <code>stream_url</code> in KAAD Radio Player settings.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$kaad_assets_printed): $kaad_assets_printed = true; ?>
    <style>
      .kaad-player-wrap { width: 100%; display: flex; justify-content: center; }
      .kaad-card {
        width: min(100%, 320px);
        margin: 0 auto;
        padding: 10px;
        border-radius: 12px;
        background: linear-gradient(170deg, #486d8e, #375a77);
        color: #fff;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2);
        font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        box-sizing: border-box;
      }
      .kaad-top { display: flex; gap: 8px; align-items: center; }
      .kaad-logo {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(255, 255, 255, 0.3);
        flex: 0 0 auto;
      }
      .kaad-meta { min-width: 0; flex: 1; }
      .kaad-row1 { display: flex; align-items: center; gap: 8px; min-width: 0; }
      .kaad-title {
        font-weight: 700;
        font-size: 16px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .kaad-live {
        background: #d9272f;
        font-weight: 700;
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 999px;
        line-height: 1;
      }
      .kaad-track {
        margin-top: 4px;
        font-size: 13px;
        opacity: 0.95;
        white-space: nowrap;
        overflow: hidden;
        position: relative;
      }
      .kaad-track.is-hidden {
        display: none;
      }
      .kaad-track-text {
        display: inline-block;
        white-space: nowrap;
      }
      .kaad-track-marquee {
        display: inline-flex;
        align-items: center;
        gap: 24px;
        white-space: nowrap;
        min-width: max-content;
        will-change: transform;
      }
      .kaad-track.is-scrolling .kaad-track-marquee {
        animation: kaad-marquee var(--kaad-scroll-duration, 12s) linear infinite;
      }
      @keyframes kaad-marquee {
        from { transform: translateX(0); }
        to { transform: translateX(calc(-1 * var(--kaad-scroll-distance, 120px))); }
      }
      .kaad-divider { margin: 8px 0; height: 1px; background: rgba(255, 255, 255, 0.35); }
      .kaad-audio { display: none; }
      .kaad-controls { display: flex; align-items: center; justify-content: center; }
      .kaad-buttons { display: flex; align-items: center; justify-content: center; gap: 10px; }
      .kaad-play {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: none;
        background: #fff;
        cursor: pointer;
        position: relative;
      }
      .kaad-play::before {
        content: "";
        position: absolute;
        left: 12px;
        top: 8px;
        width: 0;
        height: 0;
        border-left: 10px solid #3f607d;
        border-top: 7px solid transparent;
        border-bottom: 7px solid transparent;
      }
      .kaad-play.is-playing::before {
        border: none;
        width: 3px;
        height: 12px;
        background: #3f607d;
        left: 10px;
        top: 9px;
        box-shadow: 6px 0 0 #3f607d;
      }
      .kaad-play:hover { transform: scale(1.03); }
      .kaad-play:active { transform: scale(0.96); }

      .kaad-volwrap {
        position: relative;
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }
      .kaad-speaker {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: none;
        background: #3f607d;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        position: relative;
        z-index: 2;
      }
      .kaad-speaker,
      .kaad-play { line-height: 0; }
      .kaad-ico { width: 30px; height: 30px; fill: #fff; display: block; }
      .kaad-ico-mute { display: none; }
      .kaad-speaker.is-muted .kaad-ico-vol { display: none; }
      .kaad-speaker.is-muted .kaad-ico-mute { display: block; }
      .kaad-volpop {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        bottom: 0;
        width: 46px;
        height: 170px;
        border-radius: 24px;
        background: #3f607d;
        box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.15s ease;
        z-index: 1;
      }
      .kaad-speaker:hover + .kaad-volpop,
      .kaad-volpop:hover {
        opacity: 0.85;
        pointer-events: auto;
      }
      .kaad-volume {
        width: 120px;
        transform: rotate(-90deg);
        margin-top: -20px;
        -webkit-appearance: none;
        appearance: none;
        background: transparent;
      }
      .kaad-volume::-webkit-slider-runnable-track {
        height: 6px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.65);
      }
      .kaad-volume::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #587a9a;
        border: 4px solid #f59e0b;
        margin-top: -6px;
        box-shadow: 0 6px 14px rgba(0, 0, 0, 0.25);
      }
      .kaad-volume::-moz-range-track {
        height: 6px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.65);
      }
      .kaad-volume::-moz-range-thumb {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #587a9a;
        border: 4px solid #f59e0b;
        box-shadow: 0 6px 14px rgba(0, 0, 0, 0.25);
      }

      .kaad-playback-status { min-height: 0; margin-top: 4px; text-align: center; font-size: 12px; line-height: 1.3; color: rgba(255,255,255,.92); }
      .kaad-playback-status:empty { display: none; }
      .kaad-warn { padding: 10px; background: rgba(0, 0, 0, 0.2); border-radius: 10px; }
      @media (hover: none) {
        .kaad-volpop { display: none !important; }
      }
      @media (max-width: 420px) {
        .kaad-card { width: min(100%, 300px); padding: 5px; border-radius: 12px; }
        .kaad-title { font-size: 15px; }
        .kaad-track { font-size: 12.5px; }
        .kaad-buttons { gap: 10px; }
      }
    </style>
  <?php endif; ?>

  <script>
    (function () {
      const root = document.getElementById(<?php echo json_encode($uid); ?>);
      if (!root) return;

      const audio = root.querySelector('.kaad-audio');
      const playBtn = root.querySelector('.kaad-play');
      const volume = root.querySelector('.kaad-volume');
      const speakerBtn = root.querySelector('.kaad-speaker');
      const playbackStatus = root.querySelector('.kaad-playback-status');
      const npEl = root.querySelector('.kaad-now-playing');
      const proxyUrl = <?php echo json_encode($proxy); ?>;
      const refreshMs = <?php echo json_encode($refresh * 1000); ?>;

      function normalizeDisplayText(value) {
        if (value === null || typeof value === 'undefined') {
          return '';
        }

        const text = String(value).trim();
        if (!text) {
          return '';
        }

        const lowered = text.toLowerCase();
        if (lowered === '0' || lowered === 'null') {
          return '';
        }

        return text;
      }

      function setNowPlayingText(rawText) {
        if (!npEl) return;

        const text = normalizeDisplayText(rawText);

        npEl.classList.remove('is-scrolling');
        npEl.style.removeProperty('--kaad-scroll-duration');
        npEl.style.removeProperty('--kaad-scroll-distance');

        if (text === '') {
          npEl.innerHTML = '';
          npEl.classList.add('is-hidden');
          return;
        }

        npEl.classList.remove('is-hidden');

        npEl.innerHTML = '';
        const staticText = document.createElement('span');
        staticText.className = 'kaad-track-text';
        staticText.textContent = text;
        npEl.appendChild(staticText);

        function enableMarquee() {
          if (!staticText.isConnected || staticText.parentNode !== npEl) {
            return;
          }

          const overflow = staticText.scrollWidth > npEl.clientWidth + 6;
          if (!overflow) return;

          npEl.innerHTML = '';
          const marquee = document.createElement('span');
          marquee.className = 'kaad-track-marquee';

          const partA = document.createElement('span');
          partA.className = 'kaad-track-text';
          partA.textContent = text;

          const partB = document.createElement('span');
          partB.className = 'kaad-track-text';
          partB.textContent = text;
          partB.setAttribute('aria-hidden', 'true');

          marquee.appendChild(partA);
          marquee.appendChild(partB);
          npEl.appendChild(marquee);

          const gap = 24;
          const distance = Math.max(80, Math.round(partA.getBoundingClientRect().width + gap));
          const duration = Math.max(10, Math.round(distance / 28));
          npEl.style.setProperty('--kaad-scroll-duration', duration + 's');
          npEl.style.setProperty('--kaad-scroll-distance', distance + 'px');
          npEl.classList.add('is-scrolling');
        }

        requestAnimationFrame(function () {
          requestAnimationFrame(enableMarquee);
        });
        setTimeout(enableMarquee, 150);
      }

      if (audio && playBtn) {
        const retryDelays = [2000, 4000, 8000, 16000, 30000];
        const stallTimeoutMs = 15000;
        let wantsPlayback = audio.autoplay;
        let retryCount = 0;
        let retryTimer = null;
        let stallTimer = null;
        let generation = 0;

        function setPlaybackStatus(message) {
          if (playbackStatus) playbackStatus.textContent = message;
        }

        function updateButton() {
          const isPlaying = !audio.paused && !audio.ended;
          playBtn.classList.toggle('is-playing', isPlaying);
          playBtn.setAttribute('aria-label', wantsPlayback ? 'Pause radio' : 'Play radio');
          playBtn.setAttribute('aria-pressed', wantsPlayback ? 'true' : 'false');
        }

        function clearTimers() {
          if (retryTimer !== null) clearTimeout(retryTimer);
          if (stallTimer !== null) clearTimeout(stallTimer);
          retryTimer = null;
          stallTimer = null;
        }

        function stopPlayback() {
          wantsPlayback = false;
          generation += 1;
          retryCount = 0;
          clearTimers();
          audio.pause();
          setPlaybackStatus('');
          updateButton();
        }

        function scheduleRetry(reason) {
          if (!wantsPlayback || retryTimer !== null) return;
          if (stallTimer !== null) clearTimeout(stallTimer);
          stallTimer = null;
          if (retryCount >= retryDelays.length) {
            wantsPlayback = false;
            setPlaybackStatus('Stream unavailable. Tap play to try again.');
            console.warn('KAAD radio: playback stopped after retries', reason);
            updateButton();
            return;
          }

          const delay = retryDelays[retryCount++];
          const seconds = Math.ceil(delay / 1000);
          setPlaybackStatus('Stream interrupted. Retrying in ' + seconds + 's (' + retryCount + '/' + retryDelays.length + ')...');
          console.warn('KAAD radio: retry scheduled', { reason: reason, attempt: retryCount });
          const thisGeneration = ++generation;
          retryTimer = setTimeout(function () {
            retryTimer = null;
            if (!wantsPlayback || thisGeneration !== generation) return;
            // Reload the live stream to avoid resuming a stale connection.
            audio.pause();
            audio.load();
            startPlayback();
          }, delay);
        }

        function startStallWatch() {
          if (!wantsPlayback || retryTimer !== null) return;
          if (stallTimer !== null) clearTimeout(stallTimer);
          const thisGeneration = generation;
          stallTimer = setTimeout(function () {
            stallTimer = null;
            if (wantsPlayback && retryTimer === null && thisGeneration === generation &&
                (audio.paused || audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA)) {
              scheduleRetry('Stream did not resume within 15 seconds');
            }
          }, stallTimeoutMs);
        }

        async function startPlayback() {
          if (!wantsPlayback || retryTimer !== null) return;
          const thisGeneration = generation;
          setPlaybackStatus('Connecting to live stream...');
          startStallWatch();
          try {
            await audio.play();
            if (thisGeneration !== generation || !wantsPlayback) {
              audio.pause();
              return;
            }
            updateButton();
          } catch (err) {
            if (thisGeneration !== generation || !wantsPlayback) return;
            if (err && err.name === 'NotAllowedError') {
              // Browsers can block autoplay; don't retry without a user gesture.
              wantsPlayback = false;
              clearTimers();
              setPlaybackStatus('Tap play to start listening.');
              updateButton();
              return;
            }
            scheduleRetry(err && err.message ? err.message : 'play() failed');
          }
        }

        playBtn.addEventListener('click', function () {
          if (wantsPlayback) {
            stopPlayback();
          } else {
            clearTimers();
            retryCount = 0;
            generation += 1;
            wantsPlayback = true;
            updateButton();
            startPlayback();
          }
        });

        audio.addEventListener('playing', function () {
          if (!wantsPlayback) return;
          retryCount = 0;
          clearTimers();
          setPlaybackStatus('');
          updateButton();
        });
        audio.addEventListener('waiting', startStallWatch);
        audio.addEventListener('stalled', startStallWatch);
        audio.addEventListener('error', function () {
          const code = audio.error ? audio.error.code : 'unknown';
          scheduleRetry('Media error ' + code);
        });
        audio.addEventListener('ended', function () {
          scheduleRetry('Stream ended');
        });
        audio.addEventListener('pause', function () {
          updateButton();
          // Unexpected pause while listening should reconnect, but a deliberate
          // pause (or a pause during a scheduled retry) must never reconnect.
          if (wantsPlayback && retryTimer === null) startStallWatch();
        });
        audio.addEventListener('play', function () {
          // Handles autoplay events as well as explicit play clicks.
          if (audio.autoplay && !wantsPlayback && !playBtn.getAttribute('aria-pressed')) {
            wantsPlayback = true;
          }
          updateButton();
        });

        updateButton();
        if (wantsPlayback) startStallWatch();
      }

      if (audio && volume) {
        audio.volume = Math.max(0, Math.min(1, parseFloat(volume.value || '1')));
        volume.addEventListener('input', function () {
          const v = Math.max(0, Math.min(1, parseFloat(volume.value || '1')));
          audio.volume = isNaN(v) ? 1 : v;
          if (audio.muted && audio.volume > 0) {
            audio.muted = false;
            if (speakerBtn) speakerBtn.classList.remove('is-muted');
          }
        });
      }

      if (audio && speakerBtn) {
        speakerBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          const isTouch = window.matchMedia('(hover: none)').matches;

          audio.muted = !audio.muted;
          speakerBtn.classList.toggle('is-muted', audio.muted);

          if (!audio.muted && audio.volume === 0) {
            audio.volume = 0.8;
            if (volume) volume.value = '0.8';
          }

          if (!isTouch) {
            return;
          }
        });
      }

      async function updateNowPlaying() {
        try {
          const res = await fetch(proxyUrl, { cache: 'no-store' });
          const data = await res.json();
          if (!res.ok) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
          }
          const trackText = normalizeDisplayText(data ? data.text : '');
          const showText = normalizeDisplayText(data ? data.show_title : '');

          let displayText = '';
          if (trackText !== '' && showText !== '') {
            displayText = trackText + ' • Show: ' + showText;
          } else if (trackText !== '') {
            displayText = trackText;
          } else if (showText !== '') {
            displayText = 'Show: ' + showText;
          }

          setNowPlayingText(displayText);
        } catch (err) {
          setNowPlayingText('Unable to load track info');
        }
      }

      updateNowPlaying();
      setInterval(updateNowPlaying, refreshMs);
    })();
  </script>
  <?php
  return ob_get_clean();
});
