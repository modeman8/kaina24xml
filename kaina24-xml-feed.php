<?php
/**
 * Plugin Name: Kaina24 WooCommerce XML Feed
 * Description: Kas 12 val. sugeneruoja WooCommerce prekių XML failą, skirtą Kaina24.lt importui.
 * Version: 1.1.0
 * Author: WebMode.lt
 * Author URI: https://webmode.lt
 
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Kaina24_WC_XML_Feed {
    const OPTION_FILE_URL = 'kaina24_xml_feed_url';
    const CRON_HOOK = 'kaina24_generate_xml_feed_event';
    const RELATIVE_UPLOAD_PATH = 'kaina24/kaina24-feed.xml';

    public function __construct() {
        add_filter('cron_schedules', [$this, 'register_cron_interval']);
        add_action('init', [$this, 'register_rewrite_endpoint']);
        add_action(self::CRON_HOOK, [$this, 'generate_feed']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_kaina24_generate_feed', [$this, 'handle_manual_generation']);
    }

    public static function activate() {
        $instance = new self();
        $instance->register_rewrite_endpoint();
        flush_rewrite_rules();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'every_12_hours', self::CRON_HOOK);
        }

        $instance->generate_feed();
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        flush_rewrite_rules();
    }

    public function register_cron_interval($schedules) {
        if (!isset($schedules['every_12_hours'])) {
            $schedules['every_12_hours'] = [
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __('Every 12 hours', 'kaina24-xml-feed'),
            ];
        }

        return $schedules;
    }

    public function register_rewrite_endpoint() {
        add_rewrite_rule('^kaina24-feed\.xml$', 'index.php?kaina24_feed=1', 'top');
        add_filter('query_vars', function ($vars) {
            $vars[] = 'kaina24_feed';
            return $vars;
        });

        add_action('template_redirect', function () {
            if ((int) get_query_var('kaina24_feed') !== 1) {
                return;
            }

            $file_path = $this->get_feed_file_path();
            if (!file_exists($file_path)) {
                status_header(404);
                exit('Kaina24 feed file not found.');
            }

            header('Content-Type: application/xml; charset=' . get_option('blog_charset'));
            readfile($file_path);
            exit;
        });
    }

    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Kaina24 XML Feed', 'kaina24-xml-feed'),
            __('Kaina24 XML Feed', 'kaina24-xml-feed'),
            'manage_woocommerce',
            'kaina24-xml-feed',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $feed_url = get_option(self::OPTION_FILE_URL, home_url('/kaina24-feed.xml'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Kaina24 XML Feed', 'kaina24-xml-feed'); ?></h1>
            <p><?php esc_html_e('XML failas automatiškai atnaujinamas kas 12 valandų.', 'kaina24-xml-feed'); ?></p>
            <p><strong><?php esc_html_e('Feed URL:', 'kaina24-xml-feed'); ?></strong>
                <a href="<?php echo esc_url($feed_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($feed_url); ?></a>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('kaina24_generate_feed_action', 'kaina24_generate_feed_nonce'); ?>
                <input type="hidden" name="action" value="kaina24_generate_feed" />
                <button type="submit" class="button button-primary"><?php esc_html_e('Generuoti dabar', 'kaina24-xml-feed'); ?></button>
            </form>
        </div>
        <?php
    }

    public function handle_manual_generation() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('kaina24_generate_feed_action', 'kaina24_generate_feed_nonce');
        $this->generate_feed();

        wp_safe_redirect(admin_url('admin.php?page=kaina24-xml-feed'));
        exit;
    }

    public function generate_feed() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'type' => ['simple', 'variable'],
            'return' => 'objects',
        ]);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('products');

        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation instanceof WC_Product_Variation && $variation->is_in_stock()) {
                        $this->write_product_node($xml, $variation, $product);
                    }
                }
                continue;
            }

            if ($product->is_in_stock()) {
                $this->write_product_node($xml, $product);
            }
        }

        $xml->endElement();
        $xml->endDocument();

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return;
        }

        $file_path = $this->get_feed_file_path();
        wp_mkdir_p(dirname($file_path));
        file_put_contents($file_path, $xml->outputMemory());

        update_option(self::OPTION_FILE_URL, home_url('/kaina24-feed.xml'));
    }

    private function write_product_node(XMLWriter $xml, WC_Product $product, WC_Product $parent = null) {
        $source = $parent ?: $product;
        $title = $parent ? $parent->get_name() . ' - ' . wc_get_formatted_variation($product, true, false, false) : $product->get_name();
        $description = wp_strip_all_tags($source->get_description() ?: $source->get_short_description());
        $main_image = wp_get_attachment_url($source->get_image_id()) ?: '';
        $gallery_ids = $source->get_gallery_image_ids();
        $stock_qty = $product->get_stock_quantity();
        $terms = wp_get_post_terms($source->get_id(), 'product_cat');
        $primary_category = !empty($terms) && !is_wp_error($terms) ? $terms[0] : null;

        $xml->startElement('product');
        $xml->writeAttribute('id', (string) $product->get_id());

        $this->write_cdata_element($xml, 'title', $title);
        $xml->writeElement('price', wc_format_decimal($product->get_price(), 2));
        $xml->writeElement('condition', 'new');
        $xml->writeElement('stock', (string) max(0, (int) ($stock_qty !== null ? $stock_qty : 0)));
        $xml->writeElement('ean_code', (string) $product->get_meta('_ean'));

        $additional_eans_raw = (string) $product->get_meta('additional_eans');
        if (!empty($additional_eans_raw)) {
            $xml->startElement('additional_eans');
            $ean_list = array_filter(array_map('trim', preg_split('/[,;|]+/', $additional_eans_raw)));
            foreach ($ean_list as $ean) {
                $this->write_cdata_element($xml, 'ean', $ean);
            }
            $xml->endElement();
        }

        $manufacturer_code = (string) ($product->get_sku() ?: $source->get_sku());
        $this->write_cdata_element($xml, 'manufacturer_code', $manufacturer_code);
        $this->write_cdata_element($xml, 'manufacturer', $this->extract_brand($product, $parent));

        $model = (string) $product->get_meta('model');
        if (empty($model)) {
            $model = $manufacturer_code;
        }
        $this->write_cdata_element($xml, 'model', $model);

        $this->write_cdata_element($xml, 'image_url', $main_image);

        if (!empty($gallery_ids)) {
            $xml->startElement('additional_images');
            foreach ($gallery_ids as $gallery_id) {
                $img = wp_get_attachment_url($gallery_id);
                if (!empty($img)) {
                    $this->write_cdata_element($xml, 'image', $img);
                }
            }
            $xml->endElement();
        }

        $this->write_cdata_element($xml, 'product_url', get_permalink($source->get_id()));
        $xml->writeElement('category_id', $primary_category ? (string) $primary_category->term_id : '0');
        $this->write_cdata_element($xml, 'category_name', $primary_category ? $primary_category->name : '');
        $this->write_cdata_element($xml, 'category_link', $primary_category ? get_term_link($primary_category) : '');
        $this->write_cdata_element($xml, 'description', $description);

        $attributes = $source->get_attributes();
        if (!empty($attributes)) {
            $xml->startElement('specs');
            foreach ($attributes as $attribute) {
                if (!$attribute instanceof WC_Product_Attribute) {
                    continue;
                }
                $name = wc_attribute_label($attribute->get_name());
                $value = $this->resolve_attribute_value($attribute, $source->get_id());
                if ($value === '') {
                    continue;
                }
                $xml->startElement('spec');
                $xml->writeAttribute('name', $name);
                $xml->writeCdata($value);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $short_message = (string) $product->get_meta('kaina24_short_message');
        if (!empty($short_message)) {
            $this->write_cdata_element($xml, 'short_message', mb_substr($short_message, 0, 80));
        }

        $this->write_delivery_node($xml, $product, $parent);

        $xml->endElement();
    }

    private function write_delivery_node(XMLWriter $xml, WC_Product $product, WC_Product $parent = null) {
        $source = $parent ?: $product;
        $home_days = (string) ($product->get_meta('home_delivery_days') ?: $source->get_meta('home_delivery_days') ?: '2-3');
        $home_price = wc_format_decimal($product->get_meta('home_delivery_price') ?: $source->get_meta('home_delivery_price') ?: '0.00', 2);

        $xml->startElement('delivery');
        $xml->startElement('home_delivery');
        $this->write_cdata_element($xml, 'working_days', $home_days);
        $this->write_cdata_element($xml, 'price', $home_price);
        $xml->endElement();

        $parcel_days = (string) ($product->get_meta('parcel_delivery_days') ?: $source->get_meta('parcel_delivery_days'));
        $parcel_price_raw = $product->get_meta('parcel_delivery_price') ?: $source->get_meta('parcel_delivery_price');
        if ($parcel_days !== '' || $parcel_price_raw !== '') {
            $xml->startElement('parcel_locker_delivery');
            $this->write_cdata_element($xml, 'working_days', $parcel_days !== '' ? $parcel_days : '2-3');
            $this->write_cdata_element($xml, 'price', wc_format_decimal($parcel_price_raw !== '' ? $parcel_price_raw : '0.00', 2));
            $xml->endElement();
        }

        $pickup_days = (string) ($product->get_meta('pickup_days') ?: $source->get_meta('pickup_days'));
        $pickup_price_raw = $product->get_meta('pickup_price') ?: $source->get_meta('pickup_price');
        if ($pickup_days !== '' || $pickup_price_raw !== '') {
            $xml->startElement('store_pickup');
            $this->write_cdata_element($xml, 'working_days', $pickup_days !== '' ? $pickup_days : '1');
            $this->write_cdata_element($xml, 'price', wc_format_decimal($pickup_price_raw !== '' ? $pickup_price_raw : '0.00', 2));
            $xml->endElement();
        }

        $xml->endElement();
    }

    private function resolve_attribute_value(WC_Product_Attribute $attribute, $product_id) {
        if ($attribute->is_taxonomy()) {
            $values = wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names']);
            return implode(', ', array_filter($values));
        }

        return implode(', ', array_filter($attribute->get_options()));
    }

    private function write_cdata_element(XMLWriter $xml, $name, $value) {
        $xml->startElement($name);
        $xml->writeCdata((string) $value);
        $xml->endElement();
    }

    private function extract_brand(WC_Product $product, WC_Product $parent = null) {
        $targets = [$product, $parent];
        foreach ($targets as $target) {
            if (!$target instanceof WC_Product) {
                continue;
            }

            $brand = (string) $target->get_meta('brand');
            if (!empty($brand)) {
                return wp_strip_all_tags($brand);
            }

            $terms = wp_get_post_terms($target->get_id(), 'product_brand', ['fields' => 'names']);
            if (!empty($terms) && !is_wp_error($terms)) {
                return wp_strip_all_tags($terms[0]);
            }
        }

        return '';
    }

    private function get_feed_file_path() {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . self::RELATIVE_UPLOAD_PATH;
    }
}

register_activation_hook(__FILE__, ['Kaina24_WC_XML_Feed', 'activate']);
register_deactivation_hook(__FILE__, ['Kaina24_WC_XML_Feed', 'deactivate']);

new Kaina24_WC_XML_Feed();
