<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\WP\GutenbergBlock;

/**
 * Class AmeliaSearchGutenbergBlock
 *
 * @package AmeliaBooking\Infrastructure\WP\GutenbergBlock
 */
class AmeliaSearchGutenbergBlock extends GutenbergBlock
{
    public static function getBlockAttributes(): array
    {
        return [
            'short_code' => ['type' => 'string', 'default' => '[ameliasearch]'],
            'trigger'    => ['type' => 'string', 'default' => ''],
            'today'      => ['type' => 'boolean', 'default' => false],
            'location'   => ['type' => 'string', 'default' => ''],
            'category'   => ['type' => 'string', 'default' => ''],
            'service'    => ['type' => 'string', 'default' => ''],
            'employee'   => ['type' => 'string', 'default' => ''],
        ];
    }

    public static function renderBlock(array $attributes): string
    {
        $shortCode = $attributes['short_code'] ?? '[ameliasearch]';

        if (strpos($shortCode, '[ameliasearch') !== 0) {
            return '';
        }

        return do_shortcode($shortCode);
    }

    public static function registerBlockForRendering()
    {
        register_block_type(
            'amelia/search-gutenberg-block',
            array(
                'attributes'      => self::getBlockAttributes(),
                'render_callback' => array(__CLASS__, 'renderBlock'),
            )
        );
    }

    /**
     * Register Amelia Search block for gutenberg
     */
    public static function registerBlockType()
    {
        // Enqueue shared icon and styles
        parent::enqueueSharedIcon();
        parent::enqueueSharedStyles();

        wp_enqueue_script(
            'amelia_search_gutenberg_block',
            AMELIA_URL . 'public/js/gutenberg/amelia-search/amelia-search-gutenberg.js',
            array('wp-blocks', 'wp-components', 'wp-element', 'wp-block-editor', 'amelia_block_icon')
        );

        wp_enqueue_style(
            'amelia_search_gutenberg_styles',
            AMELIA_URL . 'public/js/gutenberg/amelia-search/amelia-search-gutenberg.css',
            [],
            AMELIA_VERSION
        );
    }
}
