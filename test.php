class CrowdForm{
    
    /**
     * Constructor that runs action and filter hooks.
     * 
     * @since 1.0.0
     */
    function __construct(){
        //Enqueuing JS & CSS scripts.
        add_action('wp_enqueue_scripts', array($this, 'crowd_scripts') );
        
        //Adding capabilities to the administrator when the theme is switched.
        add_action('after_switch_theme', array($this, 'crowd_caps') );
        
        //Registering applications post types.
        add_action('init', array($this, 'crowd_applications') );
        
        //Adding extra columns to the Applications Post Type table in WP Admin panel
        add_filter( 'manage_crowd_app_posts_columns', array($this, 'crowd_extra_columns') );
        add_action( 'manage_crowd_app_posts_custom_column', array($this, 'crowd_extra_columns_values'), 10, 2 );
        
        //Processing application form with ajax.
        add_action('wp_ajax_process_crowd_form', array($this, 'process_crowd_form') );
        add_action('wp_ajax_nopriv_process_crowd_form', array($this, 'process_crowd_form') );
        
        //Adding settings field in WordPress Settings.
        add_action( 'admin_init', array($this, 'crowd_setting') );
    }

    /*
     * Register scripts for the public-facing side of the site.
     * 
     * @since    1.0.0
     */
    function crowd_scripts(){
        // Attaching main JS file of the theme.
        wp_enqueue_script('crowd', get_stylesheet_directory_uri().'/js/theme.js', array('jquery'), '1.3');
        
        //Adding JS variables to the front-end
        wp_localize_script (
            'crowd',
            'crowd',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'url'    => get_stylesheet_directory_uri()
                )
        );
    }
    
    /**
     * Adding new capabilities to the Administrator role
     * 
     * @since   1.0.0
     */
    function crowd_caps(){
        $caps = array(
            'delete_apps' =>TRUE,
            'delete_others_apps'=>TRUE,
            'delete_published_apps' =>TRUE,
            'edit_app'         =>FALSE,
            'read_app'         =>TRUE,
            //'delete_app'         =>TRUE,
            'edit_apps'         =>TRUE,
            'edit_others_apps'  =>TRUE,
            //'edit_private_apps' =>TRUE,
            //'edit_published_apps'=>TRUE,
            'publish_apps'       =>FALSE,
            'create_apps'       =>FALSE,
            'read_private_apps' =>TRUE,
        );

        $role = get_role('administrator');
        foreach($caps as $cap => $val){
            $role->add_cap($cap, $val);
        }

    }

    /**
     * Registering Applications post type. 
     * 
     * @since   1.0.0
     */
    function crowd_applications(){
        $lables= array(
            'edit_item'=>'Application',
            'not_found' => __( 'No applications found.', 'ApplyOnline' ),
            'not_found_in_trash'  => __( 'No applications found.', 'ApplyOnline' )
            );
        $args=array(
            'label' => __( 'Applications', 'crowd' ),
            'labels' => $lables,
            'show_ui'           => true,
            'public'            => false,
            'exclude_from_search'=> true,
            'capability_type'   => array('app', 'apps'),
            'capabilities'  => array( 'create_posts' => 'create_apps'),
            'description' =>    __( 'All Applications', 'ApplyOnline' ),
            'supports' =>       array(),
            'map_meta_cap' => TRUE
        );
        register_post_type('crowd_app', $args);
    }

    /**
     * Adding custom columns to the Applications post type in the admin panel.
     * 
     * @param array $columns Columns array.
     * @return string Altered columns list.
     */
    function crowd_extra_columns( $columns ){
        unset($columns['date']);
        unset($columns['cb']);
        $columns['title'] = 'Applicant';
        $columns['email'] = 'Email';
        $columns['date'] = 'Received';
        return $columns;
    }
    
    /**
     * Adding values to the custom columns defined in crowd_extra_columns(). 
     * 
     * @param array $column
     * @param int $post_id
     */
    function crowd_extra_columns_values($column, $post_id){
        switch ( $column ) :
            case 'email':
                $content = unserialize(get_the_content($post_id));
                echo $content['email'];
                break;
        endswitch;
    }
    
    /**
     * Process application form. Validated and Save form data into database.
     * 
     * @global object $wpdb
     */
    function process_crowd_form(){
        $response = null;

        //Verify Nonce
        if( !wp_verify_nonce( $_POST['crowd_nonce'], 'jlka#dalfjoiNo&%LK*J' ) ) $response = array('message' => 'Are you nuts?', 'success' => false);

        //Check username & email
        if(is_null($response)){
            if( !isset($_POST['name']) OR !isset($_POST['email'])) $response = array('message' => 'Name or Email is missing.', 'success' => false);
        }

        //Validate name & email
        if(is_null($response)){
            $name = sanitize_text_field($_POST['name']);
            $email = sanitize_email($_POST['email']);
            if( empty($name) OR !is_email($email) ) $response = array('message' => 'Name or Email is invalid.', 'success' => false);
        }

        //count data
        if(is_null($response)){
            global $wpdb;
            $count = crowd_count_apps();
            $limit = (int)get_option('crowd_apps_limit');
            if( $count >= $limit ) $response = array('message' => "Email quota is already full. Please contact us if you have further queries. Thanks!", 'success' => false); 
        }

        //Insert data
        if(is_null($response)){
            $qry = 'SELECT post_id FROM '.$wpdb->prefix."postmeta WHERE meta_key = 'crowd_email' AND meta_value ='$email'";
            $mail_check = $wpdb->get_var($qry);
            if( !empty($mail_check) ) $response = array('message' => 'Email already exist. Please try with a different email.', 'success' => false);
            else{
                $data = array( 'post_title' => $name, 'post_status' => 'publish', 'post_content' => serialize(array('name' => $name, 'email' => $email)), 'post_type' => 'crowd_app' );
                $post = wp_insert_post($data);

                if( !is_wp_error($post) ) add_post_meta($post, 'crowd_email', $email);
                else $response = array('message' => $post->get_error_message(), 'success' => false);            
            }
        }

        //If all good, send a success message.
        if(is_null($response)){
            $response = array('message' => 'Your application has been received. We will get back to you soon!', 'balance' => $limit-$count-1, 'success' => true);
        }

        header( "Content-Type: application/json" );
        echo wp_json_encode($response);
        exit;
    }


    /*Settings*/
    function crowd_setting() {
        register_setting( 'general', 'crowd_apps_limit', array('type' => 'integer', 'default' => 0, 'sanitize_callback' => 'intval') ); 
        // register a new section in the "reading" page
        add_settings_section(
            'crowd_settings_section',
            'Crowd Theme Settings', 
            'crowd_settings_output',
            'general'
        );

        // register a new field in the "wporg_settings_section" section, inside the "reading" page
        add_settings_field(
            'wporg_settings_field',
            'WPOrg Setting', 
            'wporg_settings_field_callback',
            'general'
            //'crowd_settings_section'
        );
    } 

    /**
     * Crowd settings fields in the WordPress Admin Settings section.
     * 
     */
    function crowd_settings_output(){
        ?>
    <table class="from-table">
            <th scope="row"><label for="blogname">Limit Number of Applications</label></th>
            <td><input name="crowd_apps_limit" type="number" id="crowd_apps_limit" value="<?php echo get_option('crowd_apps_limit'); ?>" class="regular-text"></td>

        </table>
    <?php  }
}
