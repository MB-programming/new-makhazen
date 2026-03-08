<?php
// ============================================================
// index.php — كل الداتا تيجي من DB مباشرة (zero client requests)
// ============================================================
require_once __DIR__ . '/api/config.php';

// ── File-based cache (5-minute TTL) ──────────────────────────
$cacheDir  = __DIR__ . '/cache/data';
$cacheFile = $cacheDir . '/page_data.json';
$cacheTTL  = 300; // seconds

$pageData = null;
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $pageData = file_get_contents($cacheFile);
}

if (!$pageData) {
    // ── Defaults ──────────────────────────────────────────────
    $settings   = [];
    $branches   = [];
    $brands     = [];
    $categories = [];
    $articles   = [];
    $social     = [];
    $contact    = [];

    try {
        $db = getDB();

        // Settings
        $rows = $db->query("SELECT `key`, value FROM settings")->fetchAll();
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];

        // Branches
        $branches = $db->query("
            SELECT id, name_ar, name_en, city_ar, city_en, address_ar, address_en, phone, map_url, sort_order
            FROM branches WHERE is_active = 1
            ORDER BY sort_order ASC, city_ar ASC LIMIT 200
        ")->fetchAll();

        // Branch hours (one query, no N+1)
        $hoursRows = $db->query("
            SELECT branch_id, day_type, day_label, opens_at, closes_at, is_closed, note, sort_order
            FROM branch_hours WHERE is_active = 1
            ORDER BY branch_id ASC, sort_order ASC, id ASC
        ")->fetchAll();
        $hoursMap = [];
        foreach ($hoursRows as $h) {
            $hoursMap[$h['branch_id']][] = [
                'day_type'  => $h['day_type'],
                'day_label' => $h['day_label'],
                'opens_at'  => substr($h['opens_at'],  0, 5),
                'closes_at' => substr($h['closes_at'], 0, 5),
                'is_closed' => (bool)$h['is_closed'],
                'note'      => $h['note'],
            ];
        }
        foreach ($branches as &$b) {
            $b['working_hours'] = $hoursMap[$b['id']] ?? [];
        }
        unset($b);

        // Categories
        $categories = $db->query("
            SELECT id, name_ar, slug, icon, description
            FROM categories WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC LIMIT 200
        ")->fetchAll();

        // Brands
        $brands = $db->query("
            SELECT id, name_ar, name_en, logo_url, website_url, sort_order
            FROM brands WHERE is_active = 1
            ORDER BY sort_order ASC, name_en ASC LIMIT 200
        ")->fetchAll();

        // Social
        $social = $db->query("
            SELECT id, platform, platform_ar, url, username, icon, color, sort_order
            FROM social_media WHERE is_active = 1
            ORDER BY sort_order ASC LIMIT 50
        ")->fetchAll();

        // Contact
        $contact = $db->query("
            SELECT id, type, value, label_ar
            FROM contact_info WHERE is_active = 1
            ORDER BY sort_order ASC LIMIT 50
        ")->fetchAll();

        // Articles (separate DB, 3s timeout)
        try {
            $artPDO = new PDO(
                'mysql:host=localhost;dbname=makhazenalenaya_blogs;charset=utf8mb4',
                'makhazenalenaya_blogs',
                '?BN0Mn5x$(K$',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 3,
                ]
            );
            $articles = $artPDO->query("
                SELECT id, title, slug, excerpt, cover_image, category, author_name, published_at, is_featured
                FROM articles WHERE is_active = 1
                ORDER BY is_featured DESC, sort_order ASC, created_at DESC LIMIT 50
            ")->fetchAll();
        } catch (Exception $e) {
            $articles = [];
        }

    } catch (Exception $e) {
        // DB unavailable — JS will use fallback static data
    }

    // ── Build & cache payload ──────────────────────────────────
    $pageData = json_encode([
        'success'    => true,
        'branches'   => $branches,
        'brands'     => $brands,
        'categories' => $categories,
        'articles'   => $articles,
        'social'     => $social,
        'contact'    => $contact,
        'settings'   => $settings,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

    // Atomic write: tmp → rename (prevents partial reads)
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $tmp = $cacheFile . '.tmp';
    if (@file_put_contents($tmp, $pageData, LOCK_EX) !== false) {
        @rename($tmp, $cacheFile);
    }
}

// settings needed for header/body codes
$_decoded   = json_decode($pageData, true);
$settings   = $_decoded['settings'] ?? [];

// ── Inline codes (no JS fetch needed) ────────────────────────
$headerCode = $settings['header_code'] ?? '';
$bodyCode   = $settings['body_code']   ?? '';
?><!doctype html>
<html lang="ar" dir="rtl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <meta name="theme-color" content="#000000" />
    <title>مخازن العناية | Makhazen Alenayah</title>
    <meta name="description" content="مخازن العناية - وجهتك الأولى للجمال والعناية. 25 فرع في أنحاء المملكة العربية السعودية." />
    <link rel="icon" type="image/x-icon" href="favicon.jpeg" />
    <meta name="keywords" content="مخازن العناية" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="preload" as="style"
      href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap"
      onload="this.onload=null;this.rel='stylesheet'" />
    <noscript>
      <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet" />
    </noscript>
    <link rel="preload" as="style"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
      onload="this.onload=null;this.rel='stylesheet'" />
    <noscript>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    </noscript>
    <link rel="preload" as="image" href="api/img.php?src=pattern-1.webp&w=1440" fetchpriority="high" />
    <link rel="preload" as="image" href="api/img.php?src=logob.webp&w=340" fetchpriority="high" />
    <link rel="stylesheet" href="api/minify.php?f=assets/css/style.css&v=3" />
    <!-- Inline site data — zero client API requests -->
    <script>window.__DATA__ = <?= $pageData ?>;</script>
    <?php if ($headerCode): ?>
    <!-- Header code (from admin settings) -->
    <?= $headerCode ?>
    <?php endif; ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-WZSZSGPN');</script>
  </head>
  <body>
    <?php if ($bodyCode): ?>
    <!-- Body code (from admin settings) -->
    <div style="position:absolute;width:0;height:0;overflow:hidden"><?= $bodyCode ?></div>
    <?php endif; ?>
    <div id="preloader" class="preloader">
      <div class="preloader-inner">
        <img src="logob.webp" alt="مخازن العناية" class="preloader-logo" width="200" height="92" />
        <div class="preloader-dots">
          <span></span><span></span><span></span>
        </div>
      </div>
    </div>
    <header id="site-header" class="site-header">
      <div class="header-inner">
        <a href="#hero" class="header-logo">
          <img src="logob.webp" alt="مخازن العناية" width="113" height="52" />
        </a>
        <nav class="header-nav">
          <a href="#pranches" style="font-size:18px;">الفروع</a>
          <a href="https://wa.me/966920029921" style="padding: 0px; margin-bottom: -6px; font-size: 19px">
            <i class="fa-brands fa-whatsapp"></i>
          </a>
          <i class="fa-solid fa-phone"><a href="tel:+966920029921">92002 9921</a></i>
        </nav>
      </div>
    </header>
    <section id="hero" class="hero-section">
      <div class="hero-pattern-top">
        <img src="api/img.php?src=pattern-1.webp&w=1440" alt="" aria-hidden="true" width="1440" height="400" fetchpriority="high" />
      </div>
      <div class="hero-particles" id="hero-particles"></div>
      <div class="hero-content">
        <div class="hero-logo-wrap" id="hero-logo">
          <img src="logob.webp" alt="مخازن العناية" class="hero-logo-img" fetchpriority="high" width="260" height="120" />
        </div>
        <h1 style='color:#fff' class="section-title" id="hero-tagline">
          العروض القوية ماتلقينها اون لاين <br />تشوفينها بعينك بمخازن العناية
        </h1>
        <div class="hero-stats" id="hero-stats">
          <div class="stat-item">
            <span class="stat-num">+ 22</span>
            <span class="stat-label">فرع حول المملكة</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-item">
            <span class="stat-num">+ 252</span>
            <span class="stat-label"> براند عالمي </span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-item">
            <span class="stat-num">+ 745 k </span>
            <span class="stat-label">عميل راضي</span>
          </div>
        </div>
      </div>
      <div class="hero-pattern-bottom">
        <img src="api/img.php?src=pattern-2.webp&w=800" alt="" aria-hidden="true" width="1440" height="400" loading="lazy" />
      </div>
    </section>
    <section id="social" class="social-section">
      <div class="container">
        <div class="section-header">
          <h2 class="section-title">تابعونا على حساباتنا في شبكات التواصل الاجتماعي</h2>
        </div>
        <div class="social-grid" id="social-grid">
          <div class="social-skeleton"></div>
          <div class="social-skeleton"></div>
          <div class="social-skeleton"></div>
          <div class="social-skeleton"></div>
          <div class="social-skeleton"></div>
          <div class="social-skeleton"></div>
        </div>
      </div>
    </section>
    <section id="branches" class="branches-section">
      <div class="section-pattern-accent">
        <img src="api/img.php?src=pattern-3.webp&w=800" alt="" aria-hidden="true" loading="lazy" width="800" height="253" />
      </div>
      <div class="container" id="pranches">
        <div class="section-header">
          <span class="section-badge">لأن جمالك يستحق</span>
          <h2 class="section-title">أكثر من 20 فرعًا لخدمتك حول المملكة</h2>
          <div class="title-line"></div>
        </div>
        <div class="city-filter" id="city-filter">
          <button class="city-btn active" data-city="all">الكل</button>
        </div>
        <div class="branches-grid" id="branches-grid"></div>
      </div>
    </section>
    <section id="contact" class="contact-section">
      <div class="contact-bg-pattern">
        <img src="api/img.php?src=pattern-4.webp&w=800" alt="" aria-hidden="true" loading="lazy" width="800" height="253" />
      </div>
      <div class="container">
        <div class="contact-card" id="contact-card">
          <div class="contact-icon-wrap">
            <i class="fas fa-headset"></i>
          </div>
          <h2 class="contact-title">خدمة العملاء</h2>
          <p class="contact-sub">مواعيد العمل خلال شهر رمضان: من 10 صباحاً حتى 2 فجراً</p>
          <div class="contact-phones" id="contact-phones"></div>
          <div class="contact-actions" id="contact-actions"></div>
        </div>
      </div>
    </section>
    <section id="categories" class="categories-section">
      <div class="container">
        <div class="section-header">
          <h2 class="section-title">اكتشفي عالم العناية</h2>
          <div class="title-line"></div>
        </div>
        <div class="categories-slider" id="categories-slider"></div>
      </div>
    </section>
    <section id="brands" class="brands-section">
      <div class="container">
        <div class="section-header">
          <h2 class="section-title">الوكيل الرسمي لأهم العلامات التجارية العالمية في عالم التجميل والعناية والعطور</h2>
          <div class="title-line"></div>
        </div>
        <div class="brands-grid" id="brands-grid"></div>
      </div>
      <div class="brands-pattern-bottom">
        <img src="api/img.php?src=pattern-6.webp&w=800" alt="" aria-hidden="true" loading="lazy" width="800" height="253" />
      </div>
    </section>
    <section id="articles" class="articles-section">
      <div class="section-pattern-accent">
        <img src="api/img.php?src=pattern-3.webp&w=800" alt="" aria-hidden="true" loading="lazy" width="800" height="253" />
      </div>
      <div class="container">
        <div class="section-header">
          <span class="section-badge">اقرئي واكتشفي</span>
          <h2 class="section-title">المقالات</h2>
          <div class="title-line"></div>
        </div>
        <div class="articles-grid" id="articles-grid"></div>
      </div>
    </section>
    <footer class="site-footer">
      <div class="footer-pattern">
        <img src="api/img.php?src=pattern-5.webp&w=800" alt="" aria-hidden="true" loading="lazy" width="800" height="253" />
      </div>
      <div class="container">
        <div class="footer-inner">
          <img src="logob.webp" alt="مخازن العناية" class="footer-logo" loading="lazy" width="108" height="50" />
          <p class="footer-copy">© 2025 مخازن العناية. جميع الحقوق محفوظة.</p>
        </div>
      </div>
    </footer>
    <a href="https://wa.me/966920029921" target="_blank" rel="noopener noreferrer"
      id="whatsapp-float" aria-label="تواصل معنا عبر واتساب"
      style="position:fixed;bottom:24px;right:24px;z-index:9999;width:70px;height:70px;background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(37,211,102,0.4);transition:transform 0.3s ease,box-shadow 0.3s ease;text-decoration:none;animation:whatsapp-pulse 2s infinite;">
      <i class="fa-brands fa-whatsapp" style="color:#fff;font-size:32px"></i>
    </a>
    <style>
      #whatsapp-float:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(37,211,102,0.6); }
      @keyframes whatsapp-pulse {
        0%   { box-shadow: 0 0 0 0 rgba(37,211,102,0.5); }
        70%  { box-shadow: 0 0 0 14px rgba(37,211,102,0); }
        100% { box-shadow: 0 0 0 0 rgba(37,211,102,0); }
      }
    </style>
    <script src="api/minify.php?f=assets/js/main.js" defer></script>
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WZSZSGPN" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
  </body>
</html>
