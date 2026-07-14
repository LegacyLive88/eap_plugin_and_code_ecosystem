<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\WP\GutenbergBlock;

/**
 * Class AmeliaCustomerCabinetGutenbergBlock
 *
 * @package AmeliaBooking\Infrastructure\WP\GutenbergBlock
 */
class AmeliaCustomerCabinetGutenbergBlock extends GutenbergBlock
{
    public static function getBlockAttributes(): array
    {
        return [
            'short_code'        => ['type' => 'string', 'default' => '[ameliacustomerpanel]'],
            'trigger'           => ['type' => 'string', 'default' => ''],
            'appointmentsPanel' => ['type' => 'boolean', 'default' => true],
            'eventsPanel'       => ['type' => 'boolean', 'default' => true],
        ];
    }

    public static function renderBlock(array $attributes): string
    {
        $shortCode = $attributes['short_code'] ?? '[ameliacustomerpanel]';

        if (strpos($shortCode, '[ameliacustomerpanel') !== 0) {
            return '';
        }

        return do_shortcode($shortCode);
    }

    public static function registerBlockForRendering()
    {
        register_block_type(
            'amelia/customer-cabinet-gutenberg-block',
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
            'amelia_customer_cabinet_gutenberg_block',
            AMELIA_URL . 'public/js/gutenberg/amelia-cabinet/amelia-customer-cabinet-gutenberg.js',
            array('wp-blocks', 'wp-components', 'wp-element', 'wp-block-editor', 'amelia_block_icon')
        );
    }
}
