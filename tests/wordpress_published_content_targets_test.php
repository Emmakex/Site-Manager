<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ContentTargetInventory;

$GLOBALS['site_manager_target_posts'] = [
    7=>(object) ['ID'=>7, 'post_type'=>'page', 'post_status'=>'publish',
        'post_title'=>'Inicio <em>EMMAKE</em>', 'post_name'=>'inicio', 'post_parent'=>0,
        'post_modified_gmt'=>'2026-11-06 08:00:00'],
    8=>(object) ['ID'=>8, 'post_type'=>'page', 'post_status'=>'draft',
        'post_title'=>'Private strategy DO NOT LEAK', 'post_name'=>'secret', 'post_parent'=>0,
        'post_modified_gmt'=>'2026-11-06 08:00:00'],
    9=>(object) ['ID'=>9, 'post_type'=>'post', 'post_status'=>'publish',
        'post_title'=>'Blog example', 'post_name'=>'blog-example', 'post_parent'=>0,
        'post_modified_gmt'=>'2026-11-06 08:00:00'],
];
$GLOBALS['site_manager_target_link_override'] = [];

function get_posts(array $args): array {
    if (($args['post_status'] ?? '') !== 'publish' || ($args['fields'] ?? '') !== 'ids'
        || !in_array(($args['post_type'] ?? ''), ['page','post'], true)) {
        throw new RuntimeException('Unapproved WordPress query');
    }
    $ids = [];
    foreach ($GLOBALS['site_manager_target_posts'] as $id=>$p) {
        if ($p->post_type === $args['post_type'] && $p->post_status === 'publish') {
            $ids[] = $id;
        }
    }
    sort($ids);
    return array_slice($ids, 0, (int) $args['posts_per_page']);
}
function wp_count_posts(string $type): object {
    $counts = ['publish'=>0];
    foreach ($GLOBALS['site_manager_target_posts'] as $post) {
        if ($post->post_type === $type && $post->post_status === 'publish') {
            $counts['publish']++;
        }
    }
    return (object) $counts;
}
function get_post(int $id): ?object {
    return $GLOBALS['site_manager_target_posts'][$id] ?? null;
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'page_on_front' ? 7 : $default;
}
function home_url(string $path): string {
    return 'https://emmake.com/nuevaweb/';
}
function get_permalink(int $id): string {
    return $GLOBALS['site_manager_target_link_override'][$id]
        ?? ('https://emmake.com/nuevaweb/' . ($id === 7 ? '' : 'blog-example/'));
}
function wp_strip_all_tags(string $text, bool $remove_breaks = false): string {
    return strip_tags($text);
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n"); exit(1);
    }
};
$inventory = (new ContentTargetInventory())->publishedTargets();
$expect($inventory['source'] === 'wordpress-native-published-content', 'source must remain canonical');
$expect($inventory['published_only'] === true, 'published only');
$expect($inventory['private_values_included'] === false, 'no private material');
$expect($inventory['scope'] === 'site.read', 'read-only scope');
$expect($inventory['homepage_post_id'] === 7, 'homepage must be discovered');
$expect(count($inventory['pages']) === 1, 'draft must not leak');
$expect($inventory['pages'][0]['post_id'] === 7, 'correct page identity');
$expect($inventory['pages'][0]['title'] === 'Inicio EMMAKE', 'public title should be normalized');
$expect($inventory['pages'][0]['homepage'] === true, 'home role must be grounded');
$expect(count($inventory['posts']) === 1 && $inventory['posts'][0]['post_id'] === 9, 'published posts only');
$expect($inventory['published_page_total'] === 1, 'true published page count');
$expect($inventory['published_post_total'] === 1, 'true published post count');

$expect(!str_contains(json_encode($inventory), 'DO NOT LEAK'), 'draft content cannot leave WordPress');

$GLOBALS['site_manager_target_link_override'][7] = 'https://attacker.example.com/preview';
try {
    (new ContentTargetInventory())->publishedTargets();
    $expect(false, 'external permalink should be refused');
} catch (RuntimeException $e) {
    $expect(str_contains($e->getMessage(), 'escaped'), 'external permalink must fail closed');
}
unset($GLOBALS['site_manager_target_link_override'][7]);

$GLOBALS['site_manager_target_posts'][7]->post_status = 'private';
$inventory = (new ContentTargetInventory())->publishedTargets();
$expect($inventory['pages'] === [], 'private page must not be visible');
$GLOBALS['site_manager_target_posts'][7]->post_status = 'publish';

// Exercise limit and truncation without any private content/metadata.
for ($id = 100; $id < 190; $id++) {
    $GLOBALS['site_manager_target_posts'][$id] = (object) [
        'ID'=>$id, 'post_type'=>'page', 'post_status'=>'publish',
        'post_title'=>'Public example ' . $id, 'post_name'=>'page-' . $id,
        'post_parent'=>0, 'post_modified_gmt'=>'2026-11-06 08:00:00',
    ];
}
$inventory = (new ContentTargetInventory())->publishedTargets();
$expect(count($inventory['pages']) === 80, 'published page list must be capped at 80');
$expect($inventory['pages_truncated'] === true, 'truncation must be explicitly reported');
$expect($inventory['published_page_total'] === 91, 'published total must not be confused with first 80');
$expect($inventory['published_post_total'] === 1, 'posts total remains exact');

// A catalog larger than the original 40-item safety cap remains bounded,
// while the aggregate published total still reports the whole WordPress site.
for ($id = 300; $id < 410; $id++) {
    $GLOBALS['site_manager_target_posts'][$id] = (object) [
        'ID'=>$id, 'post_type'=>'post', 'post_status'=>'publish',
        'post_title'=>'Public post ' . $id, 'post_name'=>'post-' . $id,
        'post_parent'=>0, 'post_modified_gmt'=>'2026-11-12 08:00:00',
    ];
}
$inventory = (new ContentTargetInventory())->publishedTargets();
$expect(count($inventory['posts']) === 40, 'first published sample is bounded');
$expect($inventory['published_post_total'] === 111, 'full published post total is correct');
$expect($inventory['posts_truncated'] === true, 'sample is explicitly truncated');


echo "PASS: bounded WordPress published target inventory with public-only scope\n";
