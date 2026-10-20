<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class ProviderDetector
{
    /** @return array<string,array{label:string,files:list<string>}> */
    public function definitions(): array
    {
        return [
            'woocommerce' => ['label'=>'WooCommerce','files'=>['woocommerce/woocommerce.php']],
            'elementor' => ['label'=>'Elementor','files'=>['elementor/elementor.php','elementor-pro/elementor-pro.php']],
            'acf' => ['label'=>'Advanced Custom Fields','files'=>['advanced-custom-fields/acf.php','advanced-custom-fields-pro/acf.php']],
            'yoast-seo' => ['label'=>'Yoast SEO','files'=>['wordpress-seo/wp-seo.php']],
            'rank-math' => ['label'=>'Rank Math SEO','files'=>['seo-by-rank-math/rank-math.php']],
            'aioseo' => ['label'=>'All in One SEO','files'=>['all-in-one-seo-pack/all_in_one_seo_pack.php']],
            'wpml' => ['label'=>'WPML','files'=>['sitepress-multilingual-cms/sitepress.php']],
            'polylang' => ['label'=>'Polylang','files'=>['polylang/polylang.php','polylang-pro/polylang.php']],
            'gutenberg' => ['label'=>'Gutenberg','files'=>['gutenberg/gutenberg.php']],
            'classic-editor' => ['label'=>'Classic Editor','files'=>['classic-editor/classic-editor.php']],
        ];
    }

    /**
     * @param array<string,array{name:string,version:string}> $activePlugins
     * @return list<array{id:string,label:string,files:list<string>,versions:list<string>,state:string}>
     */
    public function detect(array $activePlugins): array
    {
        $detected = [];
        foreach ($this->definitions() as $id => $definition) {
            $files = array_values(array_intersect($definition['files'], array_keys($activePlugins)));
            if (! $files) {
                continue;
            }
            $versions = [];
            foreach ($files as $file) {
                $version = (string) ($activePlugins[$file]['version'] ?? '');
                if ('' !== $version) { $versions[] = $version; }
            }
            $detected[] = [
                'id'=>$id,
                'label'=>$definition['label'],
                'files'=>$files,
                'versions'=>array_values(array_unique($versions)),
                'state'=>'detected',
            ];
        }
        return $detected;
    }
}
