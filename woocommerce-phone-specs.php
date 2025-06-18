<?php
/**
 * Plugin Name:       WooCommerce Phone Specs
 * Description:       Automatically add phone specifications to WooCommerce products using a real API.
 * Version:           1.1.1 (Debug Version)
 * Author:            Omar Amassine
 * Author URI:        https://amsomr.online
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wps
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class WooCommerce_Phone_Specs {

    private const API_HOST = 'phone-specs-api.p.rapidapi.com';

    public function __construct() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'settings_link' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'add_menu_item' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_data_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'add_product_data_panel' ] );
        add_action( 'wp_ajax_wps_get_phone_specs', [ $this, 'get_phone_specs' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_specs_as_attributes' ] );
    }

    public function settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=wps-settings">' . __( 'Settings' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function register_settings() {
        register_setting( 'wps_settings_group', 'wps_rapidapi_key' );
    }

    public function add_menu_item() {
        add_submenu_page(
            'woocommerce',
            __( 'Phone Specs Settings', 'wps' ),
            __( 'Phone Specs', 'wps' ),
            'manage_woocommerce',
            'wps-settings',
            [ $this, 'settings_page' ]
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'Phone Specs API Settings', 'wps' ); ?></h1>
            <p><?php _e( 'Get your free API key from', 'wps' ); ?> <a href="https://rapidapi.com/api-docs/api/phone-specs-api" target="_blank">RapidAPI</a>.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'wps_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e( 'RapidAPI Key', 'wps' ); ?></th>
                        <td><input type="text" name="wps_rapidapi_key" value="<?php echo esc_attr( get_option( 'wps_rapidapi_key' ) ); ?>" size="50" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_scripts( $hook ) {
        if ( 'post.php' != $hook && 'post-new.php' != $hook ) {
            return;
        }
        wp_enqueue_script( 'wps-admin-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', [ 'jquery' ], '1.1.1', true );
        wp_enqueue_style( 'wps-admin-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css' );
        wp_localize_script( 'wps-admin-js', 'wps_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wps_nonce' )
        ] );
    }

    public function add_product_data_tab( $tabs ) {
        $tabs['phone_specs'] = [
            'label'    => __( 'Phone Specs', 'wps' ),
            'target'   => 'phone_specs_product_data',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function add_product_data_panel() {
        ?>
        <div id="phone_specs_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="wps_phone_name"><?php _e( 'Search Phone Model', 'wps' ); ?></label>
                    <input type="text" id="wps_phone_name" name="wps_phone_name" style="width: 70%;" placeholder="<?php _e( 'e.g., Samsung Galaxy S23 Ultra', 'wps' ); ?>">
                    <button type="button" class="button" id="wps_fetch_specs"><?php _e( 'Fetch Specs', 'wps' ); ?></button>
                </p>
                <div id="wps_specs_result"></div>
                <input type="hidden" name="wps_specs_json" id="wps_specs_json" value="">
            </div>
        </div>
        <?php
    }

    public function get_phone_specs() {
        check_ajax_referer( 'wps_nonce', 'nonce' );

        $api_key = get_option( 'wps_rapidapi_key' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => __( 'API key is missing. Please add it in the WooCommerce > Phone Specs settings page.', 'wps' ) ] );
        }

        if ( empty( $_POST['phone_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Phone name cannot be empty.', 'wps' ) ] );
        }

        $phone_name = sanitize_text_field( $_POST['phone_name'] );
        $search_url = 'https://' . self::API_HOST . '/search?query=' . urlencode( $phone_name );
        $api_args = [
            'headers' => [
                'x-rapidapi-host' => self::API_HOST,
                'x-rapidapi-key'  => $api_key,
            ],
            'timeout' => 20,
        ];

        $response = wp_remote_get( $search_url, $api_args );

        if ( is_wp_error( $response ) ) {
            // Send back the specific WordPress error
            wp_send_json_error( [ 
                'message' => 'A WordPress error occurred while contacting the API.',
                'debug_info' => $response->get_error_message() 
            ] );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // This is the condition that is likely failing. Let's add more debug info.
        if ( $response_code !== 200 || empty( $data['data']['phones'] ) ) {
            $debug_info = [
                'response_code' => $response_code,
                'api_response_body' => $body // Send the raw body back for inspection
            ];

            wp_send_json_error( [ 
                'message' => __( 'No phone found with that name. Try being more specific.', 'wps' ),
                'debug_info' => $debug_info
            ] );
        }

        // Step 2: Use the detail URL from the first result to get the full specs
        $detail_url = $data['data']['phones'][0]['detail'];
        $detail_response = wp_remote_get( $detail_url, $api_args );
        
        if ( is_wp_error( $detail_response ) ) {
            wp_send_json_error( [ 'message' => $detail_response->get_error_message() ] );
        }

        $detail_body = wp_remote_retrieve_body( $detail_response );
        $specs_data = json_decode( $detail_body, true );
        
        if( empty($specs_data) || empty($specs_data['data']) ){
             wp_send_json_error( [ 'message' => __( 'Could not retrieve specification details.', 'wps' ) ] );
        }

        wp_send_json_success( $specs_data['data'] );
    }

    public function save_specs_as_attributes( $post_id ) {
        if ( empty( $_POST['wps_specs_json'] ) ) {
            return;
        }

        $specs_json = stripslashes( $_POST['wps_specs_json'] );
        $specs_data = json_decode( $specs_json, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $specs_data['specifications'] ) ) {
            return;
        }

        $product = wc_get_product( $post_id );
        $attributes = $product->get_attributes();

        foreach ( $specs_data['specifications'] as $spec_group ) {
            foreach( $spec_group['specs'] as $spec_item ) {
                $attribute_name = $spec_item['key'];
                // Sanitize the attribute name to create a slug
                $attribute_slug = 'pa_' . sanitize_title( $attribute_name );

                // Check if the attribute already exists
                if ( ! array_key_exists( $attribute_slug, $attributes ) ) {
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_name( $attribute_name );
                    $attribute->set_options( $spec_item['val'] ); // Values are an array
                    $attribute->set_position( 0 );
                    $attribute->set_visible( true );
                    $attribute->set_variation( false );
                    $attributes[] = $attribute;
                }
            }
        }
        $product->set_attributes( $attributes );
        $product->save();
    }
}

new WooCommerce_Phone_Specs();