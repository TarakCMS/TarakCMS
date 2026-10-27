<?php

declare(strict_types=1);

add_action('cms_init', function (): void {
    error_log('Hello World plugin loaded.');
});

add_filter('site_footer', function (mixed $footer): string {
    return (string) $footer
        . '<p>Допълнено от Hello World плъгина.</p>';
});