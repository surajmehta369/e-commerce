
<?php

// Reuse the existing database connection.
// Initialize only if this component is loaded independently.
if (!isset($db) || !($db instanceof PDO)) {
    require_once __DIR__ . '/../connection/dbconnect.php';

    $database = new Database();
    $db = $database->connect();
}

// Escape output safely.
if (!function_exists('footerEscape')) {
    function footerEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

try {

    // Fetch active footer sections.
    $sectionQuery = $db->prepare("
        SELECT id, title, section_type
        FROM footer_sections
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $sectionQuery->execute();
    $footerSections = $sectionQuery->fetchAll();

    // Fetch all active links belonging to active sections.
    $linkQuery = $db->prepare("
        SELECT fl.id, fl.section_id, fl.title,
               fl.url, fl.icon, fl.target
        FROM footer_links fl
        INNER JOIN footer_sections fs
            ON fs.id = fl.section_id
        WHERE fl.is_active = 1
          AND fs.is_active = 1
        ORDER BY fl.sort_order ASC, fl.id ASC
    ");

    $linkQuery->execute();
    $footerLinks = $linkQuery->fetchAll();

    // Organize links by section ID.
    $linksBySection = [];

    foreach ($footerLinks as $link) {
        $linksBySection[$link['section_id']][] = $link;
    }

    // Fetch footer settings.
    $settingsQuery = $db->prepare("
        SELECT setting_key, setting_value
        FROM footer_settings
    ");

    $settingsQuery->execute();

    $footerSettings = [];

    foreach ($settingsQuery->fetchAll() as $setting) {
        $footerSettings[$setting['setting_key']] =
            $setting['setting_value'];
    }

    $storeName = $footerSettings['store_name'] ?? 'MyShop.in';

    $copyrightText =
        $footerSettings['copyright_text'] ?? 'All Rights Reserved.';

} catch (PDOException $e) {

    error_log('Footer loading error: ' . $e->getMessage());

    $footerSections = [];
    $linksBySection = [];

    $storeName = 'MyShop.in';
    $copyrightText = 'All Rights Reserved.';
}

?>

<footer class="site-footer">

    <div class="container">

        <div class="row footer-main">

            <?php foreach ($footerSections as $index => $section): ?>

                <div class="col-lg-3 col-md-6 col-sm-6 mb-4">

                    <div class="footer-column">

                        <button
                            class="footer-heading"
                            type="button"
                            aria-expanded="false"
                            aria-controls="footer-content-<?php echo (int) $section['id']; ?>"
                        >

                            <span>
                                <?php echo footerEscape($section['title']); ?>
                            </span>

                            <i class="fa-solid fa-chevron-down footer-arrow"></i>

                        </button>

                        <div
                            class="footer-content"
                            id="footer-content-<?php echo (int) $section['id']; ?>"
                        >

                            <ul>

                                <?php
                                $sectionLinks =
                                    $linksBySection[$section['id']] ?? [];
                                ?>

                                <?php foreach ($sectionLinks as $link): ?>

                                    <li>

                                        <a
                                            href="<?php echo footerEscape($link['url']); ?>"
                                            target="<?php echo in_array($link['target'], ['_self', '_blank'], true) ? $link['target'] : '_self'; ?>"
                                            <?php if ($link['target'] === '_blank'): ?>
                                                rel="noopener noreferrer"
                                            <?php endif; ?>
                                        >

                                            <?php if (!empty($link['icon'])): ?>
                                                <i class="<?php echo footerEscape($link['icon']); ?>"></i>
                                            <?php endif; ?>

                                            <?php echo footerEscape($link['title']); ?>

                                        </a>

                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="footer-bottom">

        <p>
            &copy; <?php echo date('Y'); ?>
            <?php echo footerEscape($storeName); ?>.
            <?php echo footerEscape($copyrightText); ?>
        </p>

    </div>

</footer>

<script src="assets/js/script.js"></script>

<script>
document.addEventListener('click', function (event) {

    const heading = event.target.closest('.footer-heading');

    if (!heading) {
        return;
    }
    if (window.matchMedia('(min-width: 768px)').matches) {
        return;
    }

    const contentId = heading.getAttribute('aria-controls');
    const content = document.getElementById(contentId);

    if (!content) {
        return;
    }

    const isOpen =
        heading.getAttribute('aria-expanded') === 'true';
    document.querySelectorAll('.footer-heading').forEach(function (item) {

        item.setAttribute('aria-expanded', 'false');

        const id = item.getAttribute('aria-controls');
        const panel = document.getElementById(id);

        if (panel) {
            panel.classList.remove('show');
        }

    });
    if (!isOpen) {

        heading.setAttribute('aria-expanded', 'true');

        content.classList.add('show');

    }

});

</script>

</body>
</html>
