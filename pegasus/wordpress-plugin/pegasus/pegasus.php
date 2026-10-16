<?php
/*
Plugin Name: Pegasus
Description: Pont d'administration à distance pour Orphic Agency — inspection, contenus (dont Elementor JSON), médias et extensions, via l'API REST authentifiée par Application Password.
Version: 0.11.0
Author: Orphic Agency
Requires at least: 6.0
Requires PHP: 7.4
*/

defined('ABSPATH') || exit;

/* HTTPS derrière un proxy/load-balancer (Cloudflare, OVH…) : normalise is_ssl() */
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

/* OVH & co suppriment l'en-tête Authorization → WordPress ne voit pas le Basic auth.
   On réinjecte l'identifiant reçu via notre en-tête custom (ou paramètre d'URL) dans
   PHP_AUTH_USER/PW : le cœur de WordPress authentifie alors NATIVEMENT le mot de passe
   d'application (gère tous les formats de hash). */
if (empty($_SERVER['PHP_AUTH_USER'])) {
    $pg = $_SERVER['HTTP_X_PEGASUS_AUTH'] ?? ($_SERVER['REDIRECT_HTTP_X_PEGASUS_AUTH'] ?? ($_GET['_pgauth'] ?? ''));
    if (!$pg && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strtolower($k) === 'x-pegasus-auth') { $pg = $v; break; } }
    }
    if ($pg) {
        $d = base64_decode($pg, true);
        if ($d && strpos($d, ':') !== false) {
            list($pgu, $pgp) = explode(':', $d, 2);
            $_SERVER['PHP_AUTH_USER'] = $pgu;
            $_SERVER['PHP_AUTH_PW']   = $pgp;
        }
    }
}

/* Config du service Pegasus (adresse + jeton public) + clé de chiffrement (générée à la construction du plugin) */
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';

/**
 * PEGASUS — principes de sécurité
 * ────────────────────────────────
 * • Authentification par Application Password NATIVE de WordPress.
 *   → Aucun token custom, aucun secret dans wp-config. Révocable en 1 clic dans le profil.
 * • Chaque route vérifie une CAPACITÉ WordPress (edit_pages, upload_files, install_plugins…).
 *   → Pegasus ne donne jamais plus de pouvoir que le compte qui l'utilise en a déjà.
 * • HTTPS requis hors localhost.
 * • Toutes les écritures sont journalisées (qui, quoi, quand).
 * • v0.1 : lecture/inspection + contenus + médias + extensions. PAS d'écriture de fichiers PHP.
 */

class Pegasus {

    const NS  = 'pegasus/v1';
    const VER = '0.11.0';
    const MAX_ZIP = 52428800;  // 50 Mo
    const MAX_MEDIA = 67108864; // 64 Mo (base64 ; au-delà : upload par URL)

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        /* Auth de secours : de nombreux hébergeurs suppriment l'en-tête Authorization
           (Basic auth invisible pour WordPress). On accepte alors l'identifiant via un
           en-tête custom (jamais filtré) et on valide le mot de passe d'application soi-même. */
        add_filter('determine_current_user', [__CLASS__, 'header_auth'], 30);
        /* Auto-update : n'agit que si le manifeste est défini (define PEGASUS_UPDATE_MANIFEST dans le plugin) */
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
        add_filter('plugins_api', [__CLASS__, 'update_info'], 20, 3);
        /* Couche SEO de secours : n'agit que sur les sites SANS plugin SEO, et seulement si activée par /seo/set */
        add_filter('pre_get_document_title', [__CLASS__, 'seo_fallback_title']);
        add_action('wp_head', [__CLASS__, 'seo_fallback_head'], 1);
        /* Mesure d'audience « first-party » (sans cookie, RGPD) : une visite front = une ligne */
        add_action('template_redirect', [__CLASS__, 'track']);
        /* Rapport quotidien : tic horaire, déclenche à 18 h (Paris). Tourne côté serveur
           client, donc même app Olympus fermée. Le résultat est poussé au service Pegasus (Cloudflare). */
        add_action('pegasus_report_tick', [__CLASS__, 'report_tick']);
        if (!wp_next_scheduled('pegasus_report_tick')) wp_schedule_event(time() + 300, 'hourly', 'pegasus_report_tick');
    }

    /* ═══════════════════ AUDIENCE — mesure interne (sans cookie) ═══════════════════ */
    private static function hits_table() { global $wpdb; return $wpdb->prefix . 'pegasus_hits'; }
    /* Crée la table à la volée (idempotent, marqué par une option de version) */
    private static function ensure_hits_table() {
        if (get_option('pegasus_hits_v') === '1') return;
        global $wpdb;
        $t = self::hits_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            day DATE NOT NULL,
            path VARCHAR(190) NOT NULL DEFAULT '',
            source VARCHAR(80) NOT NULL DEFAULT 'Direct',
            country CHAR(2) NOT NULL DEFAULT '',
            device VARCHAR(10) NOT NULL DEFAULT '',
            vid CHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (id), KEY day (day), KEY vid (vid)
        ) $charset;");
        update_option('pegasus_hits_v', '1', false);
    }
    /* Classe la provenance à partir du référent / des UTM */
    private static function classify_source($ref, $utm, $self_host) {
        if ($utm) return substr(ucfirst($utm), 0, 80);
        if (!$ref) return 'Direct';
        $h = strtolower(parse_url($ref, PHP_URL_HOST) ?: '');
        if (!$h || $h === $self_host || $h === 'www.' . $self_host) return 'Direct';
        if (strpos($h, 'google.') !== false)  return 'Google';
        if (strpos($h, 'bing.') !== false)    return 'Bing';
        if (strpos($h, 'duckduckgo') !== false) return 'DuckDuckGo';
        if (strpos($h, 'yahoo') !== false)    return 'Yahoo';
        if (preg_match('~(facebook|instagram|fb\.|t\.co|twitter|x\.com|linkedin|pinterest|tiktok|youtube|snapchat)~', $h)) return 'Réseaux sociaux';
        return preg_replace('~^www\.~', '', $h); // référent (autre site)
    }
    public static function track() {
        /* On ne compte que les vraies pages publiques vues par de vrais visiteurs */
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (is_robots() || is_feed() || is_trackback() || is_preview() || is_404()) return;
        if (is_user_logged_in() && current_user_can('edit_posts')) return; // ne pas compter l'agence / les rédacteurs
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '' || preg_match('~bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|monitor|preview|headless|lighthouse|pagespeed|wordpress|wp-~i', $ua)) return;
        self::ensure_hits_table();
        global $wpdb;
        $ip     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        $ip     = trim(explode(',', $ip)[0]);
        $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $ref    = $_SERVER['HTTP_REFERER'] ?? '';
        $utm    = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : '';
        $host   = strtolower(parse_url(home_url('/'), PHP_URL_HOST) ?: '');
        $host   = preg_replace('~^www\.~', '', $host);
        $country = strtoupper(substr($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? ''), 0, 2));
        if ($country === 'XX' || $country === 'T1') $country = '';
        $wpdb->insert(self::hits_table(), [
            'ts'      => current_time('mysql'),
            'day'     => current_time('Y-m-d'),
            'path'    => substr($path, 0, 190),
            'source'  => self::classify_source($ref, $utm, $host),
            'country' => $country,
            'device'  => wp_is_mobile() ? 'mobile' : 'desktop',
            'vid'     => md5($ip . '|' . $ua . '|' . current_time('Y-m-d') . '|' . (defined('NONCE_SALT') ? NONCE_SALT : 'peg')),
        ]);
        /* Purge occasionnelle au-delà de 180 jours (1 chance sur 40, sans cron dédié) */
        if (mt_rand(1, 40) === 1) {
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::hits_table() . " WHERE day < %s", date('Y-m-d', time() - 180 * 86400)));
        }
    }
    /* Agrégat d'audience réutilisable (route /audience ET rapport quotidien) */
    public static function audience_data($days = 30) {
        self::ensure_hits_table();
        global $wpdb; $t = self::hits_table();
        $days = max(1, min(365, (int) $days));
        $since = date('Y-m-d', time() - ($days - 1) * 86400);
        $total   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE day >= %s", $since));
        $uniques = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT vid) FROM $t WHERE day >= %s", $since));
        $byDay = $wpdb->get_results($wpdb->prepare("SELECT day, COUNT(*) hits, COUNT(DISTINCT vid) visitors FROM $t WHERE day >= %s GROUP BY day ORDER BY day", $since), ARRAY_A);
        $grp = function ($field) use ($wpdb, $t, $since) {
            return $wpdb->get_results($wpdb->prepare("SELECT $field AS label, COUNT(*) AS value FROM $t WHERE day >= %s AND $field <> '' GROUP BY $field ORDER BY value DESC LIMIT 8", $since), ARRAY_A);
        };
        // Répartition horaire × jour de la semaine (WEEKDAY : lundi=0 … dimanche=6)
        $hm = $wpdb->get_results($wpdb->prepare("SELECT WEEKDAY(ts) wd, HOUR(ts) hr, COUNT(*) c FROM $t WHERE day >= %s GROUP BY wd, hr", $since), ARRAY_A);
        return [
            'ok' => true, 'days' => $days, 'source' => 'pegasus',
            'total' => $total, 'uniques' => $uniques,
            'byDay' => array_map(fn($r) => ['date' => $r['day'], 'hits' => (int)$r['hits'], 'visitors' => (int)$r['visitors']], $byDay ?: []),
            'sources'   => array_map(fn($r) => ['label' => $r['label'], 'value' => (int)$r['value']], $grp('source') ?: []),
            'countries' => array_map(fn($r) => ['label' => $r['label'], 'value' => (int)$r['value']], $grp('country') ?: []),
            'pages'     => array_map(fn($r) => ['label' => $r['label'], 'value' => (int)$r['value']], $grp('path') ?: []),
            'devices'   => array_map(fn($r) => ['label' => $r['label'], 'value' => (int)$r['value']], $grp('device') ?: []),
            'heatmap'   => array_map(fn($r) => ['wd' => (int)$r['wd'], 'hr' => (int)$r['hr'], 'c' => (int)$r['c']], $hm ?: []),
        ];
    }

    /* ═══════════════════ RAPPORT QUOTIDIEN (18 h Paris → service Pegasus) ═══════════════════
       Le serveur du client calcule et pousse son propre rapport chaque jour — aucune
       dépendance à l'app Olympus. Un rapport = SEO + Performance + Sécurité + Audience. */
    public static function seo_report() {
        $limit = 12;
        $seo_plugin = 'aucun';
        if (defined('WPSEO_VERSION'))         $seo_plugin = 'Yoast SEO';
        elseif (defined('RANK_MATH_VERSION')) $seo_plugin = 'Rank Math';
        elseif (defined('SEOPRESS_VERSION'))  $seo_plugin = 'SEOPress';
        elseif (defined('AIOSEO_VERSION'))    $seo_plugin = 'All in One SEO';
        $sitemap = false;
        foreach (['/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml'] as $s) {
            $h = wp_remote_head(home_url($s), ['timeout' => 8, 'sslverify' => false]);
            if (!is_wp_error($h) && wp_remote_retrieve_response_code($h) === 200) { $sitemap = true; break; }
        }
        $rob = wp_remote_get(home_url('/robots.txt'), ['timeout' => 8, 'sslverify' => false]);
        $robots = !is_wp_error($rob) && wp_remote_retrieve_response_code($rob) === 200;
        $urls = [home_url('/')];
        foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => $limit]) as $p) {
            $u = get_permalink($p); if ($u && $u !== home_url('/')) $urls[] = $u;
        }
        $urls = array_slice(array_unique($urls), 0, $limit);
        $pages = 0; $problem_pages = 0; $issues = 0;
        foreach ($urls as $u) {
            $res = wp_remote_get($u, ['timeout' => 12, 'sslverify' => false]);
            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
            $pages++;
            $doc = new DOMDocument(); libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8" ?>' . wp_remote_retrieve_body($res)); libxml_clear_errors();
            $xp = new DOMXPath($doc);
            $get = fn($q) => ($n = $xp->query($q)->item(0)) ? trim($n->nodeValue) : null;
            $title = $get('//title'); $desc = $get('//meta[@name="description"]/@content');
            $h1 = $xp->query('//h1')->length; $canon = $get('//link[@rel="canonical"]/@href');
            $ogt = $get('//meta[@property="og:title"]/@content'); $ogi = $get('//meta[@property="og:image"]/@content');
            $noalt = 0; foreach ($xp->query('//img') as $im) { if (!$im->getAttribute('alt')) $noalt++; }
            $bad = 0;
            if (!$desc) $bad++;
            if ($h1 !== 1) $bad++;
            if (!$canon) $bad++;
            if (!$ogt || !$ogi) $bad++;
            if ($noalt > 0) $bad++;
            if ($title && mb_strlen($title) > 62) $bad++;
            if ($bad > 0) { $problem_pages++; $issues += $bad; }
        }
        if (!$sitemap) $issues++;
        if (!$robots)  $issues++;
        $score = $pages ? max(0, min(100, (int) round(100 - ($problem_pages / $pages) * 25))) : 0;
        return ['score' => $score, 'pages' => $pages, 'problemes' => $issues, 'problem_pages' => $problem_pages, 'sitemap' => $sitemap, 'robots' => $robots, 'plugin_seo' => $seo_plugin];
    }

    public static function secu_report() {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins(); $active = (array) get_option('active_plugins', []);
        $inactive = max(0, count($all) - count($active));
        $checks = [
            ['label' => 'HTTPS',                          'ok' => strpos(home_url('/'), 'https://') === 0],
            ['label' => 'Débogage désactivé',             'ok' => !(defined('WP_DEBUG') && WP_DEBUG)],
            ['label' => 'Éditeur de fichiers verrouillé', 'ok' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT],
            ['label' => 'PHP à jour (≥ 8.1)',             'ok' => version_compare(PHP_VERSION, '8.1', '>=')],
            ['label' => 'Aucune extension inactive',      'ok' => $inactive === 0],
        ];
        $ok = count(array_filter($checks, fn($c) => $c['ok'])); $total = count($checks);
        return ['score' => (int) round($ok / $total * 100), 'ok' => $ok, 'bad' => $total - $ok, 'total' => $total, 'checks' => $checks];
    }

    public static function perf_report() {
        $url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?strategy=mobile&category=performance&url=' . rawurlencode(home_url('/'));
        $res = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $j = json_decode(wp_remote_retrieve_body($res), true);
        $score = $j['lighthouseResult']['categories']['performance']['score'] ?? null;
        if ($score === null) return null;
        $aud = $j['lighthouseResult']['audits'] ?? [];
        return [
            'score'    => (int) round($score * 100),
            'lcp'      => isset($aud['largest-contentful-paint']['numericValue']) ? (int) round($aud['largest-contentful-paint']['numericValue']) : null,
            'cls'      => isset($aud['cumulative-layout-shift']['numericValue']) ? round($aud['cumulative-layout-shift']['numericValue'], 3) : null,
            'strategy' => 'mobile',
        ];
    }

    public static function build_report($day) {
        return [
            'site_url' => home_url('/'),
            'day'      => $day,
            'seo'      => self::seo_report(),
            'perf'     => self::perf_report(),
            'secu'     => self::secu_report(),
            'audience' => self::audience_data(30),
        ];
    }

    /* Pousse le rapport du jour au service Pegasus (jeton public : ajout seul, aucune lecture). */
    public static function push_report($day, $force = false) {
        if (!defined('PEGASUS_API_URL') || !defined('PEGASUS_API_KEY')) return ['ok' => false, 'error' => 'Service Pegasus non configuré dans ce plugin.'];
        if (!$force && get_option('pegasus_report_day') === $day) return ['ok' => true, 'skipped' => true, 'day' => $day];
        $report = self::build_report($day);
        $res = wp_remote_post(PEGASUS_API_URL . '/v1/reports', [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . PEGASUS_API_KEY,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=minimal',
            ],
            'body' => wp_json_encode($report),
        ]);
        if (is_wp_error($res)) return ['ok' => false, 'error' => $res->get_error_message()];
        $code = wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 300) {
            update_option('pegasus_report_day', $day, false);
            self::log('daily_report', ['day' => $day, 'seo' => $report['seo']['score'] ?? null, 'secu' => $report['secu']['score'] ?? null]);
            return ['ok' => true, 'pushed' => true, 'day' => $day, 'report' => $report];
        }
        return ['ok' => false, 'error' => 'Service Pegasus ' . $code . ' : ' . substr(wp_remote_retrieve_body($res), 0, 200), 'report' => $report];
    }

    /* Tic horaire : à partir de 18 h (Europe/Paris), génère le rapport du jour une seule fois. */
    public static function report_tick() {
        try { $now = new DateTime('now', new DateTimeZone('Europe/Paris')); }
        catch (Exception $e) { return; }
        if ((int) $now->format('H') < 18) return;
        $day = $now->format('Y-m-d');
        if (get_option('pegasus_report_day') === $day) return;
        self::push_report($day, false);
    }

    private static function has_seo_plugin() {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('SEOPRESS_VERSION') || defined('AIOSEO_VERSION');
    }
    public static function seo_fallback_title($title) {
        if (self::has_seo_plugin() || !get_option('pegasus_seo_fallback') || !is_singular()) return $title;
        $t = get_post_meta(get_queried_object_id(), '_pegasus_seo_title', true);
        return $t ?: $title;
    }
    public static function seo_fallback_head() {
        if (self::has_seo_plugin() || !get_option('pegasus_seo_fallback') || !is_singular()) return;
        $id = get_queried_object_id();
        $desc = get_post_meta($id, '_pegasus_seo_desc', true);
        if ($desc) echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url(get_permalink($id)) . '">' . "\n";
    }

    /* ————— Garde commune : HTTPS + capacité ————— */
    private static function guard($capability) {
        return function () use ($capability) {
            $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
            if (!is_ssl() && !$local) {
                return new WP_Error('pegasus_https', 'HTTPS requis.', ['status' => 403]);
            }
            if (!is_user_logged_in()) {
                return new WP_Error('pegasus_auth', 'Authentification requise (Application Password).', ['status' => 401]);
            }
            if (!current_user_can($capability)) {
                return new WP_Error('pegasus_cap', 'Permission insuffisante : ' . $capability, ['status' => 403]);
            }
            return true;
        };
    }

    /* ═══════════════════ AUTH DE SECOURS (en-tête custom) ═══════════════════ */
    public static function header_auth($user_id) {
        if ($user_id) return $user_id;                 // déjà authentifié (l'en-tête standard a marché)
        if (defined('PEGASUS_AUTHING')) return $user_id;

        /* Lit l'en-tête custom via plusieurs canaux (selon la config serveur) */
        $hdr = $_SERVER['HTTP_X_PEGASUS_AUTH'] ?? ($_SERVER['REDIRECT_HTTP_X_PEGASUS_AUTH'] ?? '');
        if (!$hdr && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'x-pegasus-auth') { $hdr = $v; break; }
            }
        }
        /* Dernier recours : paramètre d'URL (hôtes qui filtrent TOUS les en-têtes custom) */
        if (!$hdr && !empty($_GET['_pgauth'])) $hdr = sanitize_text_field(wp_unslash($_GET['_pgauth']));
        if (!$hdr) return $user_id;

        $decoded = base64_decode($hdr, true);
        if (!$decoded || strpos($decoded, ':') === false) return $user_id;
        list($login, $pass) = explode(':', $decoded, 2);
        $pass_clean = preg_replace('/[^a-z\d]/i', '', $pass);
        define('PEGASUS_AUTHING', true);

        /* 1) Délègue au cœur de WordPress (gère phpass ET les hash rapides récents) */
        if (function_exists('wp_authenticate_application_password')) {
            $u = wp_authenticate_application_password(null, $login, $pass_clean);
            if ($u instanceof WP_User) return (int) $u->ID;
            $u = wp_authenticate_application_password(null, $login, $pass);
            if ($u instanceof WP_User) return (int) $u->ID;
        }
        /* 2) Repli : vérification manuelle */
        $user = get_user_by('login', sanitize_user($login));
        if ($user && class_exists('WP_Application_Passwords')) {
            foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
                if (wp_check_password($pass_clean, $item['password'], $user->ID)) return (int) $user->ID;
            }
        }
        return $user_id;
    }

    /* ═══════════════════ AUTO-UPDATE (manifeste distant) ═══════════════════ */
    private static function fetch_manifest() {
        if (!defined('PEGASUS_UPDATE_MANIFEST')) return null;
        $res = wp_remote_get(PEGASUS_UPDATE_MANIFEST, ['timeout' => 8]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $info = json_decode(wp_remote_retrieve_body($res), true);
        return (is_array($info) && !empty($info['version']) && !empty($info['download_url'])) ? $info : null;
    }
    public static function check_update($transient) {
        if (empty($transient->checked)) return $transient;
        $info = self::fetch_manifest();
        if (!$info || version_compare($info['version'], self::VER, '<=')) return $transient;
        $base = plugin_basename(__FILE__);
        $transient->response[$base] = (object) [
            'slug'        => 'pegasus',
            'plugin'      => $base,
            'new_version' => $info['version'],
            'package'     => $info['download_url'],
            'url'         => $info['homepage'] ?? 'https://orphic-agency.com',
            'tested'      => $info['tested'] ?? get_bloginfo('version'),
        ];
        return $transient;
    }
    public static function update_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'pegasus') return $result;
        $info = self::fetch_manifest();
        if (!$info) return $result;
        return (object) [
            'name'         => 'Pegasus',
            'slug'         => 'pegasus',
            'version'      => $info['version'],
            'author'       => 'Orphic Agency',
            'homepage'     => $info['homepage'] ?? 'https://orphic-agency.com',
            'download_link'=> $info['download_url'],
            'sections'     => ['changelog' => $info['changelog'] ?? 'Mise à jour Pegasus.'],
        ];
    }

    /* ═══════════════════ BACK-OFFICE ORPHIC ═══════════════════ */
    public static function admin_menu() {
        add_menu_page('Pegasus', 'Pegasus', 'manage_options', 'pegasus', [__CLASS__, 'admin_page'], 'dashicons-admin-network', 3);
    }
    public static function admin_page() {
        $log      = get_option('pegasus_log', []);
        $admin    = get_option('pegasus_administration', []);   // { url, jeton, site_id, fiche, connecte_le } — le portail Orphic Administration
        if (!is_array($admin)) $admin = [];
        $manifest = defined('PEGASUS_UPDATE_MANIFEST');
        $rest     = esc_url(rest_url(self::NS));
        $profil   = esc_url(admin_url('profile.php#application-passwords-section'));
        ?>
        <div class="wrap" style="max-width:920px">
          <div style="display:flex;align-items:center;gap:14px;margin:24px 0 8px">
            <span style="display:inline-flex;width:44px;height:44px;border-radius:10px;background:#7A1B28;color:#F7F2EE;align-items:center;justify-content:center;font:600 20px/1 sans-serif">O</span>
            <div>
              <h1 style="margin:0;font-weight:600">Pegasus <span style="font-size:12px;color:#8a7076;font-weight:400">v<?php echo esc_html(self::VER); ?></span></h1>
              <p style="margin:2px 0 0;color:#8a7076">Pont d'administration à distance — Orphic Agency</p>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px">
            <div style="background:#fff;border:1px solid #e2d6d0;border-radius:10px;padding:20px">
              <h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#7A1B28">Connexion à Claude</h2>
              <p><strong>Statut :</strong> <span style="color:#2e7d32">● Actif</span></p>
              <p style="color:#4a363b;font-size:13px">Un seul clic : ce site se connecte automatiquement à l'espace Orphic. Rien à copier.</p>
              <p style="margin-top:14px">
                <button id="pegasus-connect" class="button button-primary" style="background:#7A1B28;border-color:#7A1B28">🔗 Connecter ce site à Claude</button>
              </p>
              <div id="pegasus-ok" style="display:none;margin-top:10px;color:#2e7d32;font-weight:600">✅ Ce site est connecté. Orphic peut désormais le gérer depuis Claude.</div>
              <div id="pegasus-err" style="display:none;color:#a63040;margin-top:10px"></div>
            </div>

            <div style="background:#fff;border:1px solid #e2d6d0;border-radius:10px;padding:20px">
              <h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#7A1B28">Orphic Administration</h2>
              <?php if (!empty($admin['site_id'])) : ?>
                <p><strong>Statut :</strong> <span style="color:#2e7d32">● Connecté</span> <span style="color:#8a7076;font-size:12px">— le <?php echo esc_html(substr((string) ($admin['connecte_le'] ?? ''), 0, 10)); ?></span></p>
                <p style="color:#4a363b;font-size:13px">Ce site est un projet d'Orphic Administration<?php if (!empty($admin['fiche'])) : ?> : <a href="<?php echo esc_url($admin['fiche']); ?>" target="_blank" rel="noopener">sa fiche ↗</a><?php endif; ?>. Reconnecter renouvelle le mot de passe d'application du portail.</p>
              <?php else : ?>
                <p style="color:#4a363b;font-size:13px">Connecte ce site au portail du studio : il y apparaîtra sous le nom de ce WordPress — le nom et le client se règlent ensuite sur sa fiche.</p>
              <?php endif; ?>
              <p style="margin:10px 0 4px"><label for="pegasus-admin-url" style="font-size:12px;color:#8a7076">Adresse du portail</label><br><input id="pegasus-admin-url" type="url" class="regular-text" style="width:100%" value="<?php echo esc_attr($admin['url'] ?? 'https://orphic-administration.pages.dev'); ?>" placeholder="https://orphic-administration.pages.dev"></p>
              <p style="margin:6px 0 4px"><label for="pegasus-admin-jeton" style="font-size:12px;color:#8a7076">Jeton d'enrôlement <span style="color:#b8a0a6">(Tools → Pegasus, dans le portail)</span></label><br><input id="pegasus-admin-jeton" type="password" class="regular-text" style="width:100%" value="" placeholder="<?php echo !empty($admin['jeton']) ? 'déjà enregistré — laisser vide pour le garder' : 'orphic-enrol-…'; ?>" autocomplete="off"></p>
              <p style="margin-top:12px"><button id="pegasus-enrol" class="button button-primary" style="background:#7A1B28;border-color:#7A1B28"><?php echo !empty($admin['site_id']) ? '↻ Reconnecter ce site à Orphic Administration' : '🔗 Connecter ce site à Orphic Administration'; ?></button></p>
              <div id="pegasus-enrol-ok" style="display:none;margin-top:10px;color:#2e7d32;font-weight:600"></div>
              <div id="pegasus-enrol-err" style="display:none;color:#a63040;margin-top:10px"></div>
            </div>

            <div style="background:#fff;border:1px solid #e2d6d0;border-radius:10px;padding:20px">
              <h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#7A1B28">Ce que Pegasus peut faire ici</h2>
              <ul style="margin:0;line-height:1.9;color:#4a363b">
                <li>Lire &amp; modifier les contenus (dont Elementor)</li>
                <li>Installer / activer thèmes &amp; extensions</li>
                <li>Ajouter des médias, corriger le SEO</li>
                <li>Auditer la structure &amp; le référencement</li>
              </ul>
            </div>
          </div>

          <div style="background:#fff;border:1px solid #e2d6d0;border-radius:10px;padding:20px;margin-top:16px">
            <h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#7A1B28">Journal d'activité (20 dernières actions)</h2>
            <?php if (empty($log)) : ?>
              <p style="color:#8a7076">Aucune action enregistrée pour l'instant.</p>
            <?php else : ?>
              <table class="widefat striped" style="border:none">
                <thead><tr><th>Date (UTC)</th><th>Utilisateur</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($log, 0, 20) as $e) : ?>
                  <tr>
                    <td><?php echo esc_html($e['time'] ?? ''); ?></td>
                    <td><?php echo esc_html($e['user'] ?? ''); ?></td>
                    <td><code><?php echo esc_html($e['action'] ?? ''); ?></code></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
        <script>
        (function(){
          var btn=document.getElementById('pegasus-connect');
          if(!btn) return;
          var nonce='<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
          var endpoint='<?php echo esc_js(rest_url(self::NS.'/connect')); ?>';
          btn.addEventListener('click',function(){
            btn.disabled=true; btn.textContent='Connexion…';
            document.getElementById('pegasus-err').style.display='none';
            fetch(endpoint,{method:'POST',headers:{'X-WP-Nonce':nonce}})
              .then(function(r){return r.json();})
              .then(function(d){
                if(d.ok){
                  btn.style.display='none';
                  document.getElementById('pegasus-ok').style.display='block';
                }else{
                  btn.disabled=false; btn.textContent='🔗 Connecter ce site à Claude';
                  var e=document.getElementById('pegasus-err');
                  e.style.display='block'; e.textContent='Erreur : '+(d.message||'inconnue');
                }
              })
              .catch(function(err){ btn.disabled=false; btn.textContent='🔗 Connecter ce site à Claude'; var e=document.getElementById('pegasus-err'); e.style.display='block'; e.textContent='Erreur réseau : '+err.message; });
          });
        })();
        (function(){
          var btn=document.getElementById('pegasus-enrol');
          if(!btn) return;
          var nonce='<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
          var endpoint='<?php echo esc_js(rest_url(self::NS.'/enrol')); ?>';
          var texte=btn.textContent;
          btn.addEventListener('click',function(){
            btn.disabled=true; btn.textContent='Connexion au portail…';
            var ok=document.getElementById('pegasus-enrol-ok'), err=document.getElementById('pegasus-enrol-err');
            ok.style.display='none'; err.style.display='none';
            fetch(endpoint,{method:'POST',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:JSON.stringify({url:document.getElementById('pegasus-admin-url').value,jeton:document.getElementById('pegasus-admin-jeton').value})})
              .then(function(r){return r.json();})
              .then(function(d){
                if(d.ok){
                  btn.disabled=false; btn.textContent='↻ Reconnecter ce site à Orphic Administration';
                  ok.style.display='block'; ok.innerHTML='✅ Connecté. '+(d.nouveau?'Le site est créé dans le portail sous le nom « '+d.site+' » — sans client pour l\'instant.':'Le portail connaissait déjà ce site : accès renouvelé.')+(d.fiche?' <a href="'+d.fiche+'" target="_blank" rel="noopener">Sa fiche ↗</a>':'');
                }else{
                  btn.disabled=false; btn.textContent=texte;
                  err.style.display='block'; err.textContent='Erreur : '+(d.message||'inconnue');
                }
              })
              .catch(function(e){ btn.disabled=false; btn.textContent=texte; err.style.display='block'; err.textContent='Erreur réseau : '+e.message; });
          });
        })();
        </script>
        <?php
    }

    /* ————— Journal ————— */
    private static function log($action, $detail = []) {
        $entry = [
            'time'   => gmdate('c'),
            'user'   => wp_get_current_user()->user_login,
            'action' => $action,
            'detail' => $detail,
        ];
        $log = get_option('pegasus_log', []);
        array_unshift($log, $entry);
        update_option('pegasus_log', array_slice($log, 0, 200), false);
    }

    public static function routes() {
        /* ═══ SANTÉ / IDENTITÉ ═══ */
        register_rest_route(self::NS, '/health', [
            'methods' => 'GET',
            'permission_callback' => self::guard('read'),
            'callback' => fn() => [
                'ok'        => true,
                'pegasus'   => self::VER,
                'site'      => home_url('/'),
                'name'      => get_bloginfo('name'),
                'wp'        => get_bloginfo('version'),
                'php'       => PHP_VERSION,
                'user'      => wp_get_current_user()->user_login,
                'multisite' => is_multisite(),
            ],
        ]);

        /* ═══ AUDIENCE — visites/provenance/pays mesurées par Pegasus (sans cookie) ═══ */
        register_rest_route(self::NS, '/audience', [
            'methods' => 'GET',
            'permission_callback' => self::guard('read'),
            'callback' => function ($req) {
                return self::audience_data((int) ($req['days'] ?? 30));
            },
        ]);

        /* Génère le rapport du jour immédiatement (test / bouton « générer maintenant ») */
        register_rest_route(self::NS, '/reports/run', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function () {
                try { $day = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d'); }
                catch (Exception $e) { $day = date('Y-m-d'); }
                return self::push_report($day, true);
            },
        ]);

        /* Réinitialiser la mesure d'audience (repartir de zéro) — admin uniquement */
        register_rest_route(self::NS, '/audience/reset', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function () {
                self::ensure_hits_table();
                global $wpdb;
                $wpdb->query("TRUNCATE TABLE " . self::hits_table());
                return ['ok' => true, 'reset' => true];
            },
        ]);

        /* ═══ CONNEXION EN 1 CLIC — génère le mot de passe + le code de connexion ═══ */
        register_rest_route(self::NS, '/connect', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function () {
                if (!class_exists('WP_Application_Passwords') || !wp_is_application_passwords_available()) {
                    return new WP_Error('pegasus_noapp', 'Les mots de passe d\'application sont indisponibles (le site doit être en HTTPS).', ['status' => 501]);
                }
                $user = wp_get_current_user();
                /* On révoque les anciens codes Pegasus pour éviter l'accumulation */
                foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
                    if (isset($item['name']) && strpos($item['name'], 'Pegasus') !== false) {
                        WP_Application_Passwords::delete_application_password($user->ID, $item['uuid']);
                    }
                }
                $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => 'Pegasus / Claude']);
                if (is_wp_error($created)) return $created;
                $password = $created[0];

                /* Chiffrement RSA du mot de passe avec la clé publique de l'agence */
                if (!defined('PEGASUS_PUBKEY') || !defined('PEGASUS_API_URL') || !defined('PEGASUS_API_KEY')) {
                    return new WP_Error('pegasus_noconf', 'Configuration du service Pegasus absente de ce build du plugin.', ['status' => 500]);
                }
                $ok = openssl_public_encrypt($password, $enc, PEGASUS_PUBKEY, OPENSSL_PKCS1_OAEP_PADDING);
                if (!$ok) return new WP_Error('pegasus_enc', 'Échec du chiffrement.', ['status' => 500]);

                /* Inscription (ajout seul) au registre du service Pegasus, avec le jeton public */
                $res = wp_remote_post(PEGASUS_API_URL . '/v1/sites', [
                    'timeout' => 20,
                    'headers' => [
                        'Authorization' => 'Bearer ' . PEGASUS_API_KEY,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => wp_json_encode([
                        'site_url'         => home_url('/'),
                        'username'         => $user->user_login,
                        'app_password_enc' => base64_encode($enc),
                        'label'            => get_bloginfo('name'),
                    ]),
                ]);
                if (is_wp_error($res)) return $res;
                $status = wp_remote_retrieve_response_code($res);
                if ($status < 200 || $status >= 300) {
                    return new WP_Error('pegasus_registre', 'Le service Pegasus a refusé l\'inscription (HTTP ' . $status . ').', ['status' => 502]);
                }
                self::log('connect_registre', ['site' => home_url('/')]);
                return ['ok' => true, 'site' => get_bloginfo('name')];
            },
        ]);

        /* ═══ DIAGNOSTIC PUBLIC — ce que le serveur reçoit (aucun secret) ═══ */
        register_rest_route(self::NS, '/whoami', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function () {
                $hdrs = function_exists('getallheaders') ? array_map('strtolower', array_keys(getallheaders())) : [];
                return [
                    'wp_version'          => get_bloginfo('version'),
                    'is_ssl'              => is_ssl(),
                    'forwarded_proto'     => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
                    'header_via_server'   => isset($_SERVER['HTTP_X_PEGASUS_AUTH']),
                    'header_via_redirect' => isset($_SERVER['REDIRECT_HTTP_X_PEGASUS_AUTH']),
                    'header_via_getall'   => in_array('x-pegasus-auth', $hdrs, true),
                    'authorization_seen'  => isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
                    'query_param_seen'    => isset($_GET['_pgauth']),
                    'logged_in'           => is_user_logged_in(),
                    'app_pw_available'    => function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : null,
                    'all_headers'         => $hdrs,
                ];
            },
        ]);

        /* ═══ ENRÔLEMENT — ce site se connecte lui-même à Orphic Administration ═══
           Sacha, 14/09/2026 : « Si le site vient de WordPress, ça se fera automatiquement avec
           Pegasus, et ça lui donne en nom de projet le nom du WordPress. » On crée un mot de passe
           d'application dédié au portail et on le lui remet, avec le jeton d'enrôlement que le
           membre a collé ici ; le portail vérifie l'accès chez nous avant d'enregistrer. */
        register_rest_route(self::NS, '/enrol', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function (WP_REST_Request $req) {
                if (!class_exists('WP_Application_Passwords') || !wp_is_application_passwords_available()) {
                    return new WP_Error('pegasus_noapp', 'Les mots de passe d\'application sont indisponibles (le site doit être en HTTPS).', ['status' => 501]);
                }
                $admin = get_option('pegasus_administration', []);
                if (!is_array($admin)) $admin = [];
                $url   = rtrim(trim((string) ($req->get_param('url') ?: ($admin['url'] ?? ''))), '/');
                $jeton = trim((string) ($req->get_param('jeton') ?: ''));
                if ($jeton === '') $jeton = (string) ($admin['jeton'] ?? '');
                if (!preg_match('#^https://[^\s/]+#', $url)) return new WP_Error('pegasus_url', 'L\'adresse du portail doit commencer par https://.', ['status' => 400]);
                if ($jeton === '') return new WP_Error('pegasus_jeton', 'Le jeton d\'enrôlement manque : Tools → Pegasus, dans le portail.', ['status' => 400]);

                $user = wp_get_current_user();
                /* Un seul mot de passe pour le portail : les anciens du même nom sont révoqués. */
                foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
                    if (isset($item['name']) && $item['name'] === 'Orphic Administration') {
                        WP_Application_Passwords::delete_application_password($user->ID, $item['uuid']);
                    }
                }
                $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => 'Orphic Administration']);
                if (is_wp_error($created)) return $created;
                $password = $created[0];

                $res = wp_remote_post($url . '/api/wordpress/enroler', [
                    'timeout' => 45,
                    'headers' => ['Authorization' => 'Bearer ' . $jeton, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    'body' => wp_json_encode([
                        'site_url'     => home_url('/'),
                        'nom'          => get_bloginfo('name'),
                        'utilisateur'  => $user->user_login,
                        'mot_de_passe' => $password,
                        'wp'           => get_bloginfo('version'),
                        'pegasus'      => self::VER,
                    ]),
                ]);
                if (is_wp_error($res)) {
                    WP_Application_Passwords::delete_application_password($user->ID, $created[1]['uuid']);
                    return new WP_Error('pegasus_portail', 'Le portail est injoignable : ' . $res->get_error_message(), ['status' => 502]);
                }
                $code = wp_remote_retrieve_response_code($res);
                $body = json_decode((string) wp_remote_retrieve_body($res), true);
                if ($code < 200 || $code >= 300 || empty($body['ok'])) {
                    /* Un enrôlement refusé ne laisse pas traîner un mot de passe inutile. */
                    WP_Application_Passwords::delete_application_password($user->ID, $created[1]['uuid']);
                    $motif = is_array($body) && !empty($body['erreur']) ? $body['erreur'] : ('HTTP ' . $code);
                    return new WP_Error('pegasus_refus', 'Le portail a refusé l\'enrôlement : ' . $motif, ['status' => $code === 401 ? 401 : 502]);
                }
                update_option('pegasus_administration', [
                    'url'         => $url,
                    'jeton'       => $jeton,
                    'site_id'     => $body['site']['id'] ?? null,
                    'fiche'       => $body['fiche'] ?? null,
                    'connecte_le' => gmdate('c'),
                ], false);
                self::log('enrol_administration', ['portail' => $url, 'site' => $body['site']['id'] ?? null, 'nouveau' => !empty($body['nouveau'])]);
                return ['ok' => true, 'site' => $body['site']['nom'] ?? get_bloginfo('name'), 'nouveau' => !empty($body['nouveau']), 'fiche' => $body['fiche'] ?? null];
            },
        ]);

        /* ═══ AUTO-TEST D'AUTH — pourquoi la validation échoue (aucun secret) ═══ */
        register_rest_route(self::NS, '/authtest', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function () {
                $hdr = $_SERVER['HTTP_X_PEGASUS_AUTH'] ?? '';
                if (!$hdr && !empty($_GET['_pgauth'])) $hdr = $_GET['_pgauth'];
                if (!$hdr) return ['step' => 'aucun_header'];
                $decoded = base64_decode($hdr, true);
                if (!$decoded || strpos($decoded, ':') === false) return ['step' => 'header_mal_forme'];
                list($login, $pass) = explode(':', $decoded, 2);
                $pass_clean = preg_replace('/[^a-z\d]/i', '', $pass);
                $out = ['login' => $login, 'pass_len' => strlen($pass_clean)];
                $user = get_user_by('login', sanitize_user($login));
                if (!$user && is_email($login)) $user = get_user_by('email', $login);
                $out['user_found'] = (bool) $user;
                if ($user) {
                    $out['user_id'] = $user->ID;
                    $out['available_for_user'] = function_exists('wp_is_application_passwords_available_for_user') ? wp_is_application_passwords_available_for_user($user) : null;
                    $apps = WP_Application_Passwords::get_user_application_passwords($user->ID);
                    $out['app_pw_count'] = count($apps);
                    $out['hash_prefixes'] = array_values(array_map(function ($a) { return substr($a['password'], 0, 4); }, $apps));
                    $out['manual_match'] = array_values(array_map(function ($a) use ($pass_clean, $user) { return wp_check_password($pass_clean, $a['password'], $user->ID); }, $apps));
                    $r = wp_authenticate_application_password(null, $login, $pass_clean);
                    $out['core_result'] = is_wp_error($r) ? ('WP_Error:' . $r->get_error_code()) : ($r instanceof WP_User ? ('WP_User#' . $r->ID) : 'null');
                }
                return $out;
            },
        ]);

        /* ═══ INSPECTION — la lecture exacte de la structure ═══ */
        register_rest_route(self::NS, '/inspect', [
            'methods' => 'GET',
            'permission_callback' => self::guard('read'),
            'callback' => function () {
                if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $active = (array) get_option('active_plugins', []);
                $plugins = [];
                foreach (get_plugins() as $file => $data) {
                    $plugins[] = [
                        'file'    => $file,
                        'name'    => $data['Name'],
                        'version' => $data['Version'],
                        'active'  => in_array($file, $active, true),
                    ];
                }
                $theme = wp_get_theme();
                $counts = [];
                foreach (['post', 'page'] as $pt) {
                    $counts[$pt] = (int) wp_count_posts($pt)->publish;
                }
                $has_elementor = in_array('elementor/elementor.php', $active, true) || defined('ELEMENTOR_VERSION');
                return [
                    'site'         => ['url' => home_url('/'), 'name' => get_bloginfo('name'), 'lang' => get_locale()],
                    'theme'        => ['name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'template' => $theme->get_template()],
                    'plugins'      => $plugins,
                    'counts'       => $counts,
                    'page_builder' => $has_elementor ? 'elementor' : 'gutenberg/autre',
                    'permalinks'   => get_option('permalink_structure'),
                ];
            },
        ]);

        /* ═══ LISTE DES CONTENUS ═══ */
        register_rest_route(self::NS, '/content', [
            'methods' => 'GET',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function (WP_REST_Request $r) {
                $type = sanitize_key($r->get_param('type') ?: 'page');
                $q = new WP_Query([
                    'post_type'      => $type,
                    'post_status'    => ['publish', 'draft', 'private', 'pending'],
                    'posts_per_page' => 100,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ]);
                $out = [];
                foreach ($q->posts as $p) {
                    $out[] = [
                        'id'       => $p->ID,
                        'title'    => get_the_title($p),
                        'slug'     => $p->post_name,
                        'status'   => $p->post_status,
                        'template' => get_post_meta($p->ID, '_wp_page_template', true) ?: 'default',
                        'elementor'=> (bool) get_post_meta($p->ID, '_elementor_edit_mode', true),
                        'url'      => get_permalink($p),
                        'modified' => $p->post_modified_gmt,
                    ];
                }
                return $out;
            },
        ]);

        /* ═══ LIRE UN CONTENU (avec JSON Elementor) ═══ */
        register_rest_route(self::NS, '/content/(?P<id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function (WP_REST_Request $r) {
                $id = (int) $r['id'];
                $p = get_post($id);
                if (!$p) return new WP_Error('pegasus_404', 'Contenu introuvable.', ['status' => 404]);
                $elementor = get_post_meta($id, '_elementor_data', true);
                return [
                    'id'             => $id,
                    'title'          => $p->post_title,
                    'slug'           => $p->post_name,
                    'status'         => $p->post_status,
                    'content'        => $p->post_content,
                    'is_elementor'   => (bool) get_post_meta($id, '_elementor_edit_mode', true),
                    'elementor_data' => $elementor ?: null,   // JSON brut Elementor
                    'meta'           => [
                        'template' => get_post_meta($id, '_wp_page_template', true) ?: 'default',
                    ],
                ];
            },
        ]);

        /* ═══ ÉCRIRE UN CONTENU (texte, statut, JSON Elementor) ═══ */
        register_rest_route(self::NS, '/content/(?P<id>\d+)', [
            'methods' => 'POST',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function (WP_REST_Request $r) {
                $id = (int) $r['id'];
                $p = get_post($id);
                if (!$p) return new WP_Error('pegasus_404', 'Contenu introuvable.', ['status' => 404]);

                $update = ['ID' => $id];
                if ($r->get_param('title') !== null)   $update['post_title']   = sanitize_text_field($r->get_param('title'));
                if ($r->get_param('content') !== null) $update['post_content'] = wp_kses_post($r->get_param('content'));
                if ($r->get_param('status') !== null) {
                    $s = sanitize_key($r->get_param('status'));
                    if (in_array($s, ['publish', 'draft', 'private', 'pending'], true)) $update['post_status'] = $s;
                }

                /* JSON Elementor : validé avant écriture */
                $ed = $r->get_param('elementor_data');
                if ($ed !== null) {
                    $decoded = json_decode(is_string($ed) ? $ed : wp_json_encode($ed), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return new WP_Error('pegasus_json', 'JSON Elementor invalide : ' . json_last_error_msg(), ['status' => 400]);
                    }
                    update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($decoded)));
                    delete_post_meta($id, '_elementor_css');   // force la régénération du CSS Elementor
                    self::log('elementor_update', ['id' => $id]);
                }

                if (count($update) > 1) {
                    $res = wp_update_post($update, true);
                    if (is_wp_error($res)) return $res;
                    self::log('content_update', ['id' => $id, 'fields' => array_keys($update)]);
                }
                return ['ok' => true, 'id' => $id, 'url' => get_permalink($id)];
            },
        ]);

        /* ═══ DIAGNOSTIC COMPLET — « ce que Pegasus peut toucher » ═══ */
        register_rest_route(self::NS, '/diagnostic', [
            'methods' => 'GET',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function () {
                if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $active = (array) get_option('active_plugins', []);
                $theme  = wp_get_theme();

                /* Constructeurs de page détectés */
                $builders = [];
                if (defined('ELEMENTOR_VERSION') || in_array('elementor/elementor.php', $active, true)) $builders[] = 'Elementor';
                if (defined('ET_BUILDER_VERSION') || strtolower($theme->get_template()) === 'divi')       $builders[] = 'Divi';
                if (defined('WPB_VC_VERSION'))                                                             $builders[] = 'WPBakery';
                if (class_exists('FLBuilderModel'))                                                        $builders[] = 'Beaver Builder';
                if (function_exists('register_block_type'))                                                $builders[] = 'Gutenberg';

                /* Multilingue détecté */
                $i18n = [];
                if (defined('POLYLANG_VERSION') || function_exists('pll_languages_list')) $i18n[] = 'Polylang';
                if (defined('ICL_SITEPRESS_VERSION'))                                     $i18n[] = 'WPML';
                if (defined('TRP_PLUGIN_VERSION'))                                        $i18n[] = 'TranslatePress';

                /* Verrous serveur */
                $file_mods_off = defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
                $file_edit_off = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;

                /* Slugs de pages existants (pour anticiper les collisions au déploiement) */
                $pages = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any']);
                $slugs = array_map(fn($p) => ['slug' => $p->post_name, 'title' => $p->post_title, 'status' => $p->post_status], $pages);

                /* Thèmes installés */
                $themes = [];
                foreach (wp_get_themes() as $stylesheet => $t) {
                    $themes[] = ['stylesheet' => $stylesheet, 'name' => $t->get('Name'), 'version' => $t->get('Version'), 'active' => $stylesheet === $theme->get_stylesheet()];
                }

                /* Plugins */
                $plugins = [];
                foreach (get_plugins() as $file => $data) {
                    $plugins[] = ['file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => in_array($file, $active, true)];
                }

                return [
                    'site' => [
                        'url'        => home_url('/'),
                        'name'       => get_bloginfo('name'),
                        'wp'         => get_bloginfo('version'),
                        'php'        => PHP_VERSION,
                        'locale'     => get_locale(),
                        'multisite'  => is_multisite(),
                        'permalinks' => get_option('permalink_structure') ?: 'PAR DÉFAUT (à passer en /%postname%/)',
                        'debug'      => defined('WP_DEBUG') && WP_DEBUG,
                    ],
                    'theme_actif'    => [
                        'name'      => $theme->get('Name'),
                        'version'   => $theme->get('Version'),
                        'is_child'  => (bool) $theme->parent(),
                        'parent'    => $theme->parent() ? $theme->parent()->get('Name') : null,
                    ],
                    'themes_installes' => $themes,
                    'plugins'          => $plugins,
                    'page_builders'    => $builders ?: ['aucun détecté'],
                    'multilingue'      => $i18n ?: ['aucun'],
                    'pages_existantes' => $slugs,
                    'capacites_pegasus' => [
                        'lire_contenus'    => current_user_can('edit_pages'),
                        'ecrire_contenus'  => current_user_can('edit_pages'),
                        'uploader_medias'  => current_user_can('upload_files'),
                        'installer_themes' => current_user_can('install_themes') && !$file_mods_off,
                        'activer_themes'   => current_user_can('switch_themes'),
                        'installer_plugins'=> current_user_can('install_plugins') && !$file_mods_off,
                    ],
                    'verrous_serveur' => [
                        'DISALLOW_FILE_MODS' => $file_mods_off, // bloque install thèmes/plugins
                        'DISALLOW_FILE_EDIT' => $file_edit_off, // bloque l'éditeur de fichiers
                    ],
                ];
            },
        ]);

        /* ═══ THÈMES : liste ═══ */
        register_rest_route(self::NS, '/themes', [
            'methods' => 'GET',
            'permission_callback' => self::guard('switch_themes'),
            'callback' => function () {
                $active = wp_get_theme()->get_stylesheet();
                $out = [];
                foreach (wp_get_themes() as $stylesheet => $t) {
                    $out[] = ['stylesheet' => $stylesheet, 'name' => $t->get('Name'), 'version' => $t->get('Version'), 'active' => $stylesheet === $active];
                }
                return $out;
            },
        ]);

        /* ═══ THÈME : installation depuis un zip (installeur natif WP) ═══ */
        register_rest_route(self::NS, '/theme/install', [
            'methods' => 'POST',
            'permission_callback' => self::guard('install_themes'),
            'callback' => function (WP_REST_Request $r) {
                if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
                    return new WP_Error('pegasus_locked', 'Installation impossible : DISALLOW_FILE_MODS est actif sur ce serveur.', ['status' => 403]);
                }
                $b64 = (string) $r->get_param('zip_b64');
                $zip = base64_decode($b64, true);
                if ($zip === false)               return new WP_Error('pegasus_b64', 'zip_b64 invalide.', ['status' => 400]);
                if (strlen($zip) > self::MAX_ZIP) return new WP_Error('pegasus_size', 'Archive trop volumineuse (> 50 Mo).', ['status' => 400]);
                if (substr($zip, 0, 2) !== 'PK')  return new WP_Error('pegasus_notzip', 'Le contenu n\'est pas une archive ZIP.', ['status' => 400]);

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/theme.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                $tmp = wp_tempnam('pegasus-theme.zip');
                file_put_contents($tmp, $zip);

                WP_Filesystem();
                $skin     = new WP_Ajax_Upgrader_Skin();
                $upgrader = new Theme_Upgrader($skin);
                $result   = $upgrader->install($tmp, ['overwrite_package' => true]);
                @unlink($tmp);

                if (is_wp_error($result)) return $result;
                if (is_wp_error($skin->result)) return $skin->result;
                if ($result === false) {
                    return new WP_Error('pegasus_install_fail', 'Échec de l\'installation. ' . implode(' ', $skin->get_errors()->get_error_messages() ?: []), ['status' => 500]);
                }

                $stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null;
                self::log('theme_install', ['stylesheet' => $stylesheet, 'bytes' => strlen($zip)]);
                return ['ok' => true, 'installed' => $stylesheet, 'messages' => $skin->get_upgrade_messages()];
            },
        ]);

        /* ═══ THÈME : activation ═══ */
        register_rest_route(self::NS, '/theme/activate', [
            'methods' => 'POST',
            'permission_callback' => self::guard('switch_themes'),
            'callback' => function (WP_REST_Request $r) {
                $stylesheet = sanitize_text_field((string) $r->get_param('stylesheet'));
                $theme = wp_get_theme($stylesheet);
                if (!$theme->exists()) {
                    return new WP_Error('pegasus_notheme', 'Thème introuvable : ' . $stylesheet, ['status' => 404]);
                }
                $before = wp_get_theme()->get_stylesheet();
                switch_theme($stylesheet);
                self::log('theme_activate', ['from' => $before, 'to' => $stylesheet]);
                return ['ok' => true, 'activated' => $stylesheet, 'previous' => $before];
            },
        ]);

        /* ═══ AUDIT SEO — lit le HTML réellement rendu de chaque page ═══ */
        register_rest_route(self::NS, '/seo-audit', [
            'methods' => 'GET',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function (WP_REST_Request $r) {
                $limit = min(30, max(1, (int) ($r->get_param('limit') ?: 12)));

                /* Plugin SEO détecté */
                $seo_plugin = 'aucun';
                if (defined('WPSEO_VERSION'))         $seo_plugin = 'Yoast SEO';
                elseif (defined('RANK_MATH_VERSION')) $seo_plugin = 'Rank Math';
                elseif (defined('SEOPRESS_VERSION'))  $seo_plugin = 'SEOPress';
                elseif (defined('AIOSEO_VERSION'))    $seo_plugin = 'All in One SEO';

                /* Sitemap & robots */
                $sitemap = null;
                foreach (['/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml'] as $s) {
                    $h = wp_remote_head(home_url($s), ['timeout' => 8, 'sslverify' => false]);
                    if (!is_wp_error($h) && wp_remote_retrieve_response_code($h) === 200) { $sitemap = $s; break; }
                }
                $robots = wp_remote_get(home_url('/robots.txt'), ['timeout' => 8, 'sslverify' => false]);
                $robots_ok = !is_wp_error($robots) && wp_remote_retrieve_response_code($robots) === 200;

                /* Pages à auditer : accueil + pages publiées */
                $urls = [['title' => 'Accueil', 'url' => home_url('/')]];
                $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => $limit]);
                foreach ($pages as $p) {
                    if (get_permalink($p) === home_url('/')) continue;
                    $urls[] = ['title' => get_the_title($p), 'url' => get_permalink($p)];
                }
                $urls = array_slice($urls, 0, $limit);

                $report = [];
                $issues = ['sans_meta_description' => 0, 'h1_incorrect' => 0, 'sans_canonical' => 0, 'sans_og' => 0, 'images_sans_alt' => 0, 'title_trop_long' => 0];

                foreach ($urls as $u) {
                    $res = wp_remote_get($u['url'], ['timeout' => 12, 'sslverify' => false]);
                    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
                        $report[] = ['page' => $u['title'], 'url' => $u['url'], 'erreur' => 'inaccessible'];
                        continue;
                    }
                    $html = wp_remote_retrieve_body($res);
                    $doc = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
                    libxml_clear_errors();
                    $xp = new DOMXPath($doc);

                    $get = fn($q) => ($n = $xp->query($q)->item(0)) ? trim($n->nodeValue) : null;
                    $title    = $get('//title');
                    $desc     = $get('//meta[@name="description"]/@content');
                    $h1n      = $xp->query('//h1')->length;
                    $canon    = $get('//link[@rel="canonical"]/@href');
                    $ogt      = $get('//meta[@property="og:title"]/@content');
                    $ogimg    = $get('//meta[@property="og:image"]/@content');
                    $lang     = $get('//html/@lang');
                    $imgs     = $xp->query('//img');
                    $no_alt = 0;
                    foreach ($imgs as $img) { if (!$img->getAttribute('alt')) $no_alt++; }

                    $page_issues = [];
                    if (!$desc)                     { $page_issues[] = 'meta description absente'; $issues['sans_meta_description']++; }
                    if ($h1n !== 1)                 { $page_issues[] = "H1 = $h1n (attendu : 1)";   $issues['h1_incorrect']++; }
                    if (!$canon)                    { $page_issues[] = 'canonical absent';          $issues['sans_canonical']++; }
                    if (!$ogt || !$ogimg)           { $page_issues[] = 'Open Graph incomplet';      $issues['sans_og']++; }
                    if ($no_alt > 0)                { $page_issues[] = "$no_alt image(s) sans alt";  $issues['images_sans_alt'] += $no_alt; }
                    if ($title && mb_strlen($title) > 62) { $page_issues[] = 'title trop long (' . mb_strlen($title) . ' car.)'; $issues['title_trop_long']++; }

                    $report[] = [
                        'page'        => $u['title'],
                        'url'         => $u['url'],
                        'title'       => $title,
                        'title_len'   => $title ? mb_strlen($title) : 0,
                        'description' => $desc,
                        'desc_len'    => $desc ? mb_strlen($desc) : 0,
                        'h1'          => $h1n,
                        'canonical'   => (bool) $canon,
                        'open_graph'  => (bool) ($ogt && $ogimg),
                        'lang'        => $lang,
                        'images_sans_alt' => $no_alt,
                        'problemes'   => $page_issues ?: ['aucun'],
                    ];
                }

                return [
                    'site'        => home_url('/'),
                    'plugin_seo'  => $seo_plugin,
                    'sitemap'     => $sitemap ?: 'ABSENT',
                    'robots_txt'  => $robots_ok ? 'présent' : 'ABSENT',
                    'pages_auditees' => count($report),
                    'resume_problemes' => $issues,
                    'detail'      => $report,
                ];
            },
        ]);

        /* ═══ PLUGINS : installation (repo WordPress.org par slug, ou zip) ═══ */
        register_rest_route(self::NS, '/plugin/install', [
            'methods' => 'POST',
            'permission_callback' => self::guard('install_plugins'),
            'callback' => function (WP_REST_Request $r) {
                if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
                    return new WP_Error('pegasus_locked', 'Installation impossible : DISALLOW_FILE_MODS est actif.', ['status' => 403]);
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                WP_Filesystem();

                $slug = sanitize_key((string) $r->get_param('slug'));
                $package = null;

                if ($slug) {
                    /* Depuis le dépôt officiel — ex : "polylang", "translatepress-multilingual" */
                    $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
                    if (is_wp_error($api)) return $api;
                    $package = $api->download_link;
                } else {
                    /* Depuis un zip (nos plugins custom de fonctionnalité) */
                    $zip = base64_decode((string) $r->get_param('zip_b64'), true);
                    if ($zip === false)               return new WP_Error('pegasus_b64', 'Fournir "slug" ou "zip_b64".', ['status' => 400]);
                    if (strlen($zip) > self::MAX_ZIP) return new WP_Error('pegasus_size', 'Archive trop volumineuse.', ['status' => 400]);
                    if (substr($zip, 0, 2) !== 'PK')  return new WP_Error('pegasus_notzip', 'Contenu non-ZIP.', ['status' => 400]);
                    $package = wp_tempnam('pegasus-plugin.zip');
                    file_put_contents($package, $zip);
                }

                $skin     = new WP_Ajax_Upgrader_Skin();
                $upgrader = new Plugin_Upgrader($skin);
                $result   = $upgrader->install($package, ['overwrite_package' => true]);
                if (!$slug) @unlink($package);

                if (is_wp_error($result)) return $result;
                if (is_wp_error($skin->result)) return $skin->result;
                if ($result === false) {
                    return new WP_Error('pegasus_plugin_fail', 'Échec. ' . implode(' ', $skin->get_errors()->get_error_messages() ?: []), ['status' => 500]);
                }
                $plugin_file = $upgrader->plugin_info();
                self::log('plugin_install', ['plugin' => $plugin_file, 'slug' => $slug ?: '(zip)']);
                return ['ok' => true, 'installed' => $plugin_file, 'messages' => $skin->get_upgrade_messages()];
            },
        ]);

        /* ═══ PLUGINS : activation ═══ */
        register_rest_route(self::NS, '/plugin/activate', [
            'methods' => 'POST',
            'permission_callback' => self::guard('activate_plugins'),
            'callback' => function (WP_REST_Request $r) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $file = (string) $r->get_param('plugin');   // ex : "polylang/polylang.php"
                if (!$file || !preg_match('#^[A-Za-z0-9._/-]+\.php$#', $file)) {
                    return new WP_Error('pegasus_plugin', 'Chemin de plugin invalide.', ['status' => 400]);
                }
                $res = activate_plugin($file);
                if (is_wp_error($res)) return $res;
                self::log('plugin_activate', ['plugin' => $file]);
                return ['ok' => true, 'activated' => $file];
            },
        ]);

        /* ═══ MÉDIAS : upload (base64 pour photos/clips, ou depuis une URL pour les gros fichiers) ═══ */
        register_rest_route(self::NS, '/media/upload', [
            'methods' => 'POST',
            'permission_callback' => self::guard('upload_files'),
            'callback' => function (WP_REST_Request $r) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $alt   = sanitize_text_field((string) $r->get_param('alt'));
                $title = sanitize_text_field((string) $r->get_param('title'));
                $url   = esc_url_raw((string) $r->get_param('source_url'));

                if ($url) {
                    /* Upload depuis une URL — idéal pour les vidéos volumineuses déjà hébergées */
                    $tmp = download_url($url, 60);
                    if (is_wp_error($tmp)) return $tmp;
                    $file = ['name' => basename(parse_url($url, PHP_URL_PATH)) ?: 'media', 'tmp_name' => $tmp];
                    $id = media_handle_sideload($file, 0, $title ?: null);
                    if (is_wp_error($id)) { @unlink($tmp); return $id; }
                } else {
                    $filename = sanitize_file_name((string) $r->get_param('filename'));
                    if (!$filename) return new WP_Error('pegasus_filename', 'filename requis pour un upload base64.', ['status' => 400]);
                    $data = base64_decode((string) $r->get_param('file_b64'), true);
                    if ($data === false)                return new WP_Error('pegasus_b64', 'file_b64 invalide.', ['status' => 400]);
                    if (strlen($data) > self::MAX_MEDIA) return new WP_Error('pegasus_size', 'Fichier trop volumineux pour base64 — utiliser source_url.', ['status' => 400]);

                    $check = wp_check_filetype($filename);
                    if (!$check['type'] || !get_allowed_mime_types() || !in_array($check['type'], get_allowed_mime_types(), true)) {
                        return new WP_Error('pegasus_mime', 'Type de fichier non autorisé : ' . $filename, ['status' => 400]);
                    }
                    $up = wp_upload_bits($filename, null, $data);
                    if (!empty($up['error'])) return new WP_Error('pegasus_upload', $up['error'], ['status' => 500]);

                    $id = wp_insert_attachment([
                        'post_mime_type' => $check['type'],
                        'post_title'     => $title ?: pathinfo($filename, PATHINFO_FILENAME),
                        'post_status'    => 'inherit',
                    ], $up['file']);
                    if (is_wp_error($id)) return $id;
                    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
                }

                if ($alt) update_post_meta($id, '_wp_attachment_image_alt', $alt);
                self::log('media_upload', ['id' => $id, 'source' => $url ? 'url' : 'base64']);
                return ['ok' => true, 'id' => $id, 'url' => wp_get_attachment_url($id), 'alt' => $alt];
            },
        ]);

        /* ═══ SEO : appliquer des corrections par page ═══ */
        register_rest_route(self::NS, '/seo/set', [
            'methods' => 'POST',
            'permission_callback' => self::guard('edit_pages'),
            'callback' => function (WP_REST_Request $r) {
                $id = (int) $r->get_param('post_id');
                if (!get_post($id)) return new WP_Error('pegasus_404', 'Contenu introuvable.', ['status' => 404]);
                $title = $r->get_param('seo_title');
                $desc  = $r->get_param('seo_description');
                $applied = [];

                if (defined('WPSEO_VERSION')) {
                    if ($title !== null) { update_post_meta($id, '_yoast_wpseo_title', sanitize_text_field($title)); $applied[] = 'yoast_title'; }
                    if ($desc !== null)  { update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_text_field($desc)); $applied[] = 'yoast_desc'; }
                } elseif (defined('RANK_MATH_VERSION')) {
                    if ($title !== null) { update_post_meta($id, 'rank_math_title', sanitize_text_field($title)); $applied[] = 'rankmath_title'; }
                    if ($desc !== null)  { update_post_meta($id, 'rank_math_description', sanitize_text_field($desc)); $applied[] = 'rankmath_desc'; }
                } else {
                    /* Aucun plugin SEO : Pegasus fait le minimum et active sa couche de secours */
                    if ($title !== null) { update_post_meta($id, '_pegasus_seo_title', sanitize_text_field($title)); $applied[] = 'pegasus_title'; }
                    if ($desc !== null)  { update_post_meta($id, '_pegasus_seo_desc', sanitize_text_field($desc)); $applied[] = 'pegasus_desc'; }
                    update_option('pegasus_seo_fallback', 1);
                }
                self::log('seo_set', ['id' => $id, 'applied' => $applied]);
                return ['ok' => true, 'id' => $id, 'applied' => $applied, 'cible' => self::has_seo_plugin() ? 'plugin SEO' : 'Pegasus (secours)'];
            },
        ]);

        /* ═══ SEO : réglages site (langue, slogan) ═══ */
        register_rest_route(self::NS, '/seo/site', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function (WP_REST_Request $r) {
                $out = [];
                $locale = sanitize_text_field((string) $r->get_param('locale'));
                if ($locale && preg_match('#^[a-z]{2}(_[A-Z]{2})?$#', $locale)) {
                    if ($locale !== 'en_US' && !in_array($locale, get_available_languages(), true)) {
                        /* Dépendances nécessaires au téléchargement du pack de langue */
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/misc.php';
                        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
                        WP_Filesystem();
                        $dl = wp_download_language_pack($locale);
                        $out['pack'] = $dl ? 'téléchargé' : 'indisponible';
                    }
                    update_option('WPLANG', $locale === 'en_US' ? '' : $locale);
                    $out['locale'] = $locale;
                }
                if ($r->get_param('tagline') !== null) {
                    update_option('blogdescription', sanitize_text_field($r->get_param('tagline')));
                    $out['tagline'] = get_option('blogdescription');
                }
                self::log('seo_site', $out);
                return ['ok' => true] + $out;
            },
        ]);

        /* ═══ PERMALIENS : régénère la structure + le .htaccess ═══ */
        register_rest_route(self::NS, '/permalinks', [
            'methods' => 'POST',
            'permission_callback' => self::guard('manage_options'),
            'callback' => function (WP_REST_Request $r) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $struct = $r->get_param('structure') ?: '/%postname%/';
                update_option('permalink_structure', $struct);
                global $wp_rewrite;
                $wp_rewrite->set_permalink_structure($struct);
                $wp_rewrite->init();
                flush_rewrite_rules(true);
                if (function_exists('save_mod_rewrite_rules')) save_mod_rewrite_rules();
                $home = function_exists('get_home_path') ? get_home_path() : ABSPATH;
                $ht = $home . '.htaccess';
                self::log('permalinks', ['structure' => $struct]);
                return [
                    'ok' => true,
                    'structure' => $struct,
                    'htaccess_writable' => (file_exists($ht) && is_writable($ht)) || (!file_exists($ht) && is_writable($home)),
                ];
            },
        ]);

        /* ═══ OPTIONS : lire / écrire un réglage WordPress ═══ */
        register_rest_route(self::NS, '/option', [
            [
                'methods' => 'GET',
                'permission_callback' => self::guard('manage_options'),
                'callback' => function (WP_REST_Request $r) {
                    $name = sanitize_key($r->get_param('name'));
                    return ['name' => $name, 'value' => get_option($name)];
                },
            ],
            [
                'methods' => 'POST',
                'permission_callback' => self::guard('manage_options'),
                'callback' => function (WP_REST_Request $r) {
                    $name = sanitize_key($r->get_param('name'));
                    if (!$name) return new WP_Error('pegasus_opt', 'Nom d\'option requis.', ['status' => 400]);
                    update_option($name, $r->get_param('value'));
                    self::log('option_set', ['name' => $name]);
                    return ['ok' => true, 'name' => $name, 'value' => get_option($name)];
                },
            ],
        ]);

        /* ═══ JOURNAL ═══ */
        register_rest_route(self::NS, '/log', [
            'methods' => 'GET',
            'permission_callback' => self::guard('manage_options'),
            'callback' => fn() => get_option('pegasus_log', []),
        ]);
    }
}

Pegasus::boot();
