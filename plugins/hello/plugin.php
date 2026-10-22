<?php
declare(strict_types=1);

add_filter('site_footer', function (string $html): string {
    return $html . '<p class="plugin-message">Hello from a TarıkCMS plugin.</p>';
});
