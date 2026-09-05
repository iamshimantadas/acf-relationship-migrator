<?php
/**
 * Plugin Name: ACF Relationship Migrator
 * Description: Batch migrate ACF Post Object, Relationship, Page Link and Taxonomy fields using stable migration keys instead of WordPress IDs.
 * Version: 2.0.0
 * Author: Shimanta Das
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACF_Relationship_Migrator {

    const VERSION = '2.0.0';

    const OPTION_MAPPINGS = 'acfrm_post_type_mappings';
    const OPTION_BATCH    = 'acfrm_batch_size';

    const TRANSIENT_EXPORT = 'acfrm_export_';
    const TRANSIENT_IMPORT = 'acfrm_import_';

    private $supported_acf_types = array(
        'post_object',
        'relationship',
        'page_link',
        'taxonomy',
    );

    public function __construct() {

        /*
         * Admin.
         */
        add_action(
            'admin_menu',
            array($this, 'admin_menu')
        );

        /*
         * AJAX.
         */
        add_action(
            'wp_ajax_acfrm_start_export',
            array($this, 'ajax_start_export')
        );

        add_action(
            'wp_ajax_acfrm_export_batch',
            array($this, 'ajax_export_batch')
        );

        add_action(
            'wp_ajax_acfrm_start_import',
            array($this, 'ajax_start_import')
        );

        add_action(
            'wp_ajax_acfrm_import_content_batch',
            array($this, 'ajax_import_content_batch')
        );

        add_action(
            'wp_ajax_acfrm_resolve_batch',
            array($this, 'ajax_resolve_batch')
        );
    }

    /* ==========================================================
     * ADMIN
     * ========================================================== */

    public function admin_menu() {

        add_management_page(
            'ACF Relationship Migrator',
            'ACF Relationship Migrator',
            'manage_options',
            'acf-relationship-migrator',
            array($this, 'admin_page')
        );
    }

    public function admin_page() {

        if (!current_user_can('manage_options')) {
            return;
        }

        $mappings = get_option(
            self::OPTION_MAPPINGS,
            array()
        );

        $batch_size = (int) get_option(
            self::OPTION_BATCH,
            100
        );

        if ($batch_size < 10) {
            $batch_size = 100;
        }

        $post_types = get_post_types(
            array(
                'public' => true,
            ),
            'objects'
        );

        ?>

        <div class="wrap">

            <h1>ACF Relationship Migrator</h1>

            <p>
                Migrate ACF relationships between WordPress websites
                without relying on source WordPress post IDs.
            </p>

            <hr>

            <h2>1. Post Type Mapping</h2>

            <p>
                Define which source post type should become which
                destination post type.
            </p>

            <table class="widefat" id="acfrm-mapping-table">

                <thead>

                    <tr>
                        <th>Source Post Type</th>
                        <th>Destination Post Type</th>
                        <th width="80">Action</th>
                    </tr>

                </thead>

                <tbody>

                    <?php

                    if (!empty($mappings)) {

                        foreach ($mappings as $mapping) {

                            $source = isset($mapping['source'])
                                ? $mapping['source']
                                : '';

                            $destination = isset($mapping['destination'])
                                ? $mapping['destination']
                                : '';

                            ?>

                            <tr>

                                <td>

                                    <select
                                        name="acfrm_source[]"
                                        class="acfrm-source"
                                    >

                                        <option value="">
                                            Select source
                                        </option>

                                        <?php foreach ($post_types as $pt): ?>

                                            <option
                                                value="<?php echo esc_attr($pt->name); ?>"
                                                <?php selected(
                                                    $source,
                                                    $pt->name
                                                ); ?>
                                            >
                                                <?php
                                                echo esc_html(
                                                    $pt->label .
                                                    ' (' .
                                                    $pt->name .
                                                    ')'
                                                );
                                                ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </td>

                                <td>

                                    <select
                                        name="acfrm_destination[]"
                                        class="acfrm-destination"
                                    >

                                        <option value="">
                                            Select destination
                                        </option>

                                        <?php foreach ($post_types as $pt): ?>

                                            <option
                                                value="<?php echo esc_attr($pt->name); ?>"
                                                <?php selected(
                                                    $destination,
                                                    $pt->name
                                                ); ?>
                                            >
                                                <?php
                                                echo esc_html(
                                                    $pt->label .
                                                    ' (' .
                                                    $pt->name .
                                                    ')'
                                                );
                                                ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </td>

                                <td>

                                    <button
                                        type="button"
                                        class="button acfrm-remove-row"
                                    >
                                        Remove
                                    </button>

                                </td>

                            </tr>

                            <?php
                        }

                    } else {

                        $this->mapping_row($post_types);
                    }

                    ?>

                </tbody>

            </table>

            <p>

                <button
                    type="button"
                    class="button"
                    id="acfrm-add-mapping"
                >
                    + Add Mapping
                </button>

                <button
                    type="button"
                    class="button button-primary"
                    id="acfrm-save-mappings"
                >
                    Save Mapping
                </button>

            </p>

            <hr>

            <h2>2. Batch Size</h2>

            <p>
                Number of posts processed per AJAX request.
            </p>

            <input
                type="number"
                id="acfrm-batch-size"
                value="<?php echo esc_attr($batch_size); ?>"
                min="10"
                max="1000"
            >

            <p class="description">
                Recommended: 50–200 for shared hosting.
            </p>

            <hr>

            <h2>3. Export</h2>

            <p>
                Export only the configured source post types.
            </p>

            <button
                type="button"
                class="button button-primary"
                id="acfrm-start-export"
            >
                Start Export
            </button>

            <div id="acfrm-export-progress"
                 style="margin-top:20px;display:none;">

                <div style="
                    width:100%;
                    max-width:700px;
                    height:25px;
                    background:#ddd;
                ">

                    <div
                        id="acfrm-export-bar"
                        style="
                            width:0%;
                            height:25px;
                            background:#2271b1;
                            color:#fff;
                            text-align:center;
                            line-height:25px;
                        "
                    >
                        0%
                    </div>

                </div>

                <p id="acfrm-export-status"></p>

                <p>
                    <a
                        id="acfrm-download"
                        href="#"
                        class="button button-primary"
                        style="display:none;"
                    >
                        Download Migration JSON
                    </a>
                </p>

            </div>

            <hr>

            <h2>4. Import</h2>

            <p>
                Import the JSON exported from this plugin.
            </p>

            <input
                type="file"
                id="acfrm-import-file"
                accept=".json"
            >

            <p>

                <button
                    type="button"
                    class="button button-primary"
                    id="acfrm-start-import"
                >
                    Start Import
                </button>

            </p>

            <div id="acfrm-import-progress"
                 style="margin-top:20px;display:none;">

                <div style="
                    width:100%;
                    max-width:700px;
                    height:25px;
                    background:#ddd;
                ">

                    <div
                        id="acfrm-import-bar"
                        style="
                            width:0%;
                            height:25px;
                            background:#2271b1;
                            color:#fff;
                            text-align:center;
                            line-height:25px;
                        "
                    >
                        0%
                    </div>

                </div>

                <p id="acfrm-import-status"></p>

            </div>

            <hr>

            <h2>Supported ACF Fields</h2>

            <ul>
                <li>Post Object</li>
                <li>Relationship</li>
                <li>Page Link</li>
                <li>Taxonomy</li>
            </ul>

            <p>
                Relationships are resolved using migration keys,
                never source WordPress IDs.
            </p>

        </div>

        <?php

        $this->admin_javascript();
    }

    private function mapping_row($post_types) {

        ?>

        <tr>

            <td>

                <select name="acfrm_source[]">

                    <option value="">
                        Select source
                    </option>

                    <?php foreach ($post_types as $pt): ?>

                        <option value="<?php echo esc_attr($pt->name); ?>">

                            <?php
                            echo esc_html(
                                $pt->label .
                                ' (' .
                                $pt->name .
                                ')'
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </td>

            <td>

                <select name="acfrm_destination[]">

                    <option value="">
                        Select destination
                    </option>

                    <?php foreach ($post_types as $pt): ?>

                        <option value="<?php echo esc_attr($pt->name); ?>">

                            <?php
                            echo esc_html(
                                $pt->label .
                                ' (' .
                                $pt->name .
                                ')'
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </td>

            <td>

                <button
                    type="button"
                    class="button acfrm-remove-row"
                >
                    Remove
                </button>

            </td>

        </tr>

        <?php
    }

    /* ==========================================================
     * ADMIN JAVASCRIPT
     * ========================================================== */

    private function admin_javascript() {

        ?>

        <script>

        jQuery(function($) {

            /*
             * Add mapping.
             */
            $('#acfrm-add-mapping').on('click', function() {

                let row = $('#acfrm-mapping-table tbody tr:first')
                    .clone();

                row.find('select').val('');

                $('#acfrm-mapping-table tbody').append(row);

            });

            /*
             * Remove mapping.
             */
            $(document).on(
                'click',
                '.acfrm-remove-row',
                function() {

                    if (
                        $('#acfrm-mapping-table tbody tr').length > 1
                    ) {
                        $(this).closest('tr').remove();
                    }

                }
            );

            /*
             * Save mappings.
             */
            $('#acfrm-save-mappings').on('click', function() {

                let mappings = [];

                $('#acfrm-mapping-table tbody tr').each(
                    function() {

                        let source =
                            $(this)
                            .find('select[name="acfrm_source[]"]')
                            .val();

                        let destination =
                            $(this)
                            .find(
                                'select[name="acfrm_destination[]"]'
                            )
                            .val();

                        if (source && destination) {

                            mappings.push({
                                source: source,
                                destination: destination
                            });

                        }

                    }
                );

                $.post(
                    ajaxurl,
                    {
                        action: 'acfrm_start_export',
                        mode: 'save_mappings',
                        mappings: mappings,
                        batch_size:
                            $('#acfrm-batch-size').val(),
                        _ajax_nonce:
                            '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                    },
                    function(response) {

                        if (response.success) {
                            alert('Mappings saved.');
                        } else {
                            alert(response.data);
                        }

                    }
                );

            });

            /*
             * =====================================================
             * EXPORT
             * =====================================================
             */

            $('#acfrm-start-export').on('click', function() {

                $('#acfrm-export-progress').show();

                $('#acfrm-export-status')
                    .text('Starting export...');

                $.post(
                    ajaxurl,
                    {
                        action: 'acfrm_start_export',
                        mode: 'start',
                        batch_size:
                            $('#acfrm-batch-size').val(),
                        _ajax_nonce:
                            '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                    },
                    function(response) {

                        if (!response.success) {

                            alert(response.data);
                            return;

                        }

                        acfrmExportBatch(
                            response.data.job_id
                        );

                    }
                );

            });

            function acfrmExportBatch(job_id) {

                $.post(
                    ajaxurl,
                    {
                        action: 'acfrm_export_batch',
                        job_id: job_id,
                        _ajax_nonce:
                            '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                    },
                    function(response) {

                        if (!response.success) {

                            $('#acfrm-export-status')
                                .text(response.data);

                            return;

                        }

                        let data = response.data;

                        let percent = data.percent;

                        $('#acfrm-export-bar')
                            .css('width', percent + '%')
                            .text(percent + '%');

                        $('#acfrm-export-status')
                            .text(
                                'Processed ' +
                                data.processed +
                                ' / ' +
                                data.total
                            );

                        if (data.complete) {

                            $('#acfrm-export-status')
                                .text('Export completed.');

                            $('#acfrm-download')
                                .attr('href', data.download_url)
                                .show();

                        } else {

                            setTimeout(
                                function() {

                                    acfrmExportBatch(job_id);

                                },
                                100
                            );

                        }

                    }
                );

            }

            /*
             * =====================================================
             * IMPORT
             * =====================================================
             */

            $('#acfrm-start-import').on('click', function() {

                let file =
                    $('#acfrm-import-file')[0].files[0];

                if (!file) {

                    alert('Please select a JSON file.');

                    return;

                }

                $('#acfrm-import-progress').show();

                $('#acfrm-import-status')
                    .text('Uploading migration file...');

                let formData = new FormData();

                formData.append(
                    'action',
                    'acfrm_start_import'
                );

                formData.append(
                    'migration_file',
                    file
                );

                formData.append(
                    'batch_size',
                    $('#acfrm-batch-size').val()
                );

                formData.append(
                    '_ajax_nonce',
                    '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                );

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,

                    success: function(response) {

                        if (!response.success) {

                            alert(response.data);
                            return;

                        }

                        acfrmImportContentBatch(
                            response.data.job_id
                        );

                    }
                });

            });

            function acfrmImportContentBatch(job_id) {

                $.post(
                    ajaxurl,
                    {
                        action:
                            'acfrm_import_content_batch',

                        job_id:
                            job_id,

                        _ajax_nonce:
                            '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                    },
                    function(response) {

                        if (!response.success) {

                            $('#acfrm-import-status')
                                .text(response.data);

                            return;

                        }

                        let data = response.data;

                        $('#acfrm-import-bar')
                            .css(
                                'width',
                                data.percent + '%'
                            )
                            .text(
                                data.percent + '%'
                            );

                        $('#acfrm-import-status')
                            .text(
                                'Importing content: ' +
                                data.processed +
                                ' / ' +
                                data.total
                            );

                        if (data.complete) {

                            $('#acfrm-import-status')
                                .text(
                                    'Content imported. Resolving relationships...'
                                );

                            acfrmResolveBatch(job_id);

                        } else {

                            setTimeout(
                                function() {

                                    acfrmImportContentBatch(
                                        job_id
                                    );

                                },
                                100
                            );

                        }

                    }
                );

            }

            function acfrmResolveBatch(job_id) {

                $.post(
                    ajaxurl,
                    {
                        action:
                            'acfrm_resolve_batch',

                        job_id:
                            job_id,

                        _ajax_nonce:
                            '<?php echo wp_create_nonce('acfrm_ajax'); ?>'
                    },
                    function(response) {

                        if (!response.success) {

                            $('#acfrm-import-status')
                                .text(response.data);

                            return;

                        }

                        let data = response.data;

                        $('#acfrm-import-bar')
                            .css(
                                'width',
                                data.percent + '%'
                            )
                            .text(
                                data.percent + '%'
                            );

                        $('#acfrm-import-status')
                            .text(
                                'Resolving relationships: ' +
                                data.processed +
                                ' / ' +
                                data.total
                            );

                        if (data.complete) {

                            $('#acfrm-import-status')
                                .text(
                                    'Migration completed successfully.'
                                );

                            if (data.missing > 0) {

                                $('#acfrm-import-status')
                                    .append(
                                        '<br>Missing relationships: ' +
                                        data.missing
                                    );

                            }

                        } else {

                            setTimeout(
                                function() {

                                    acfrmResolveBatch(
                                        job_id
                                    );

                                },
                                100
                            );

                        }

                    }
                );

            }

        });

        </script>

        <?php
    }

    /* ==========================================================
     * SECURITY
     * ========================================================== */

    private function verify_ajax() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                'Permission denied.'
            );
        }

        check_ajax_referer(
            'acfrm_ajax'
        );
    }

    /* ==========================================================
     * MIGRATION KEY
     * ========================================================== */

    private function make_post_key($post) {

        if (!$post || empty($post->post_type)) {
            return '';
        }

        return $post->post_type .
            ':' .
            $post->post_name;
    }

    private function make_term_key($term) {

        if (
            !$term ||
            is_wp_error($term) ||
            empty($term->taxonomy)
        ) {
            return '';
        }

        return $term->taxonomy .
            ':' .
            $term->slug;
    }

    /* ==========================================================
     * POST TYPE MAPPING
     * ========================================================== */

    private function get_mapping($source_type) {

        $mappings = get_option(
            self::OPTION_MAPPINGS,
            array()
        );

        foreach ($mappings as $mapping) {

            if (
                isset($mapping['source']) &&
                $mapping['source'] === $source_type
            ) {

                return $mapping['destination'];

            }
        }

        /*
         * If no mapping exists, use same post type.
         */
        return $source_type;
    }

    /* ==========================================================
     * ACF FIELD DISCOVERY
     * ========================================================== */

    private function get_relationship_fields($post_id) {

        if (!function_exists('get_field_objects')) {
            return array();
        }

        $fields = get_field_objects(
            $post_id,
            false,
            false
        );

        if (!$fields) {
            return array();
        }

        $result = array();

        foreach ($fields as $field) {

            if (
                empty($field['type'])
            ) {
                continue;
            }

            if (
                in_array(
                    $field['type'],
                    $this->supported_acf_types,
                    true
                )
            ) {

                $result[] = $field;

            }
        }

        return $result;
    }

    /* ==========================================================
     * NORMALIZE ACF VALUE
     * ========================================================== */

    private function normalize_acf_value(
        $value,
        $field_type
    ) {

        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        /*
         * POST OBJECT
         * RELATIONSHIP
         */
        if (
            $field_type === 'post_object' ||
            $field_type === 'relationship'
        ) {

            $items = is_array($value)
                ? $value
                : array($value);

            $references = array();

            foreach ($items as $item) {

                $post_id = 0;

                if (
                    is_object($item) &&
                    isset($item->ID)
                ) {

                    $post_id = (int) $item->ID;

                } elseif (
                    is_numeric($item)
                ) {

                    $post_id = (int) $item;
                }

                if (!$post_id) {
                    continue;
                }

                $post = get_post($post_id);

                if (!$post) {
                    continue;
                }

                $references[] = array(
                    'key' => $this->make_post_key(
                        $post
                    ),

                    'source_post_type' =>
                        $post->post_type,

                    'destination_post_type' =>
                        $this->get_mapping(
                            $post->post_type
                        ),

                    'slug' =>
                        $post->post_name,
                );
            }

            /*
             * Preserve single vs multiple.
             */
            if (
                $field_type === 'post_object'
            ) {

                /*
                 * ACF Post Object can be configured
                 * for single OR multiple.
                 *
                 * We detect the original structure.
                 */
                if (!is_array($value)) {

                    return isset($references[0])
                        ? $references[0]
                        : null;

                }
            }

            return $references;
        }

        /*
         * PAGE LINK
         */
        if (
            $field_type === 'page_link'
        ) {

            $items = is_array($value)
                ? $value
                : array($value);

            $references = array();

            foreach ($items as $url) {

                $post_id =
                    url_to_postid($url);

                if (!$post_id) {
                    continue;
                }

                $post =
                    get_post($post_id);

                if (!$post) {
                    continue;
                }

                $references[] = array(
                    'key' =>
                        $this->make_post_key(
                            $post
                        ),

                    'source_post_type' =>
                        $post->post_type,

                    'destination_post_type' =>
                        $this->get_mapping(
                            $post->post_type
                        ),

                    'slug' =>
                        $post->post_name,
                );
            }

            if (!is_array($value)) {

                return isset($references[0])
                    ? $references[0]
                    : null;
            }

            return $references;
        }

        /*
         * TAXONOMY
         */
        if (
            $field_type === 'taxonomy'
        ) {

            $items = is_array($value)
                ? $value
                : array($value);

            $references = array();

            foreach ($items as $item) {

                $term_id = 0;

                if (
                    is_object($item) &&
                    isset($item->term_id)
                ) {

                    $term_id =
                        (int) $item->term_id;

                } elseif (
                    is_numeric($item)
                ) {

                    $term_id =
                        (int) $item;
                }

                if (!$term_id) {
                    continue;
                }

                $term =
                    get_term($term_id);

                if (
                    !$term ||
                    is_wp_error($term)
                ) {
                    continue;
                }

                $references[] = array(
                    'key' =>
                        $this->make_term_key(
                            $term
                        ),

                    'taxonomy' =>
                        $term->taxonomy,

                    'slug' =>
                        $term->slug,
                );
            }

            if (!is_array($value)) {

                return isset($references[0])
                    ? $references[0]
                    : null;
            }

            return $references;
        }

        return null;
    }

    /* ==========================================================
     * EXPORT START
     * ========================================================== */

    public function ajax_start_export() {

        $this->verify_ajax();

        /*
         * Save mappings if requested.
         */
        if (
            isset($_POST['mode']) &&
            $_POST['mode'] === 'save_mappings'
        ) {

            $mappings =
                isset($_POST['mappings'])
                    ? (array) $_POST['mappings']
                    : array();

            $clean = array();

            foreach ($mappings as $mapping) {

                if (
                    empty($mapping['source']) ||
                    empty($mapping['destination'])
                ) {
                    continue;
                }

                $clean[] = array(
                    'source' =>
                        sanitize_key(
                            $mapping['source']
                        ),

                    'destination' =>
                        sanitize_key(
                            $mapping['destination']
                        ),
                );
            }

            update_option(
                self::OPTION_MAPPINGS,
                $clean
            );

            if (
                isset($_POST['batch_size'])
            ) {

                update_option(
                    self::OPTION_BATCH,
                    max(
                        10,
                        min(
                            1000,
                            absint(
                                $_POST['batch_size']
                            )
                        )
                    )
                );
            }

            wp_send_json_success();
        }

        $mappings =
            get_option(
                self::OPTION_MAPPINGS,
                array()
            );

        if (empty($mappings)) {

            wp_send_json_error(
                'Please configure at least one post type mapping.'
            );
        }

        $job_id =
            wp_generate_uuid4();

        $batch_size =
            isset($_POST['batch_size'])
                ? max(
                    10,
                    min(
                        1000,
                        absint(
                            $_POST['batch_size']
                        )
                    )
                )
                : 100;

        update_option(
            self::OPTION_BATCH,
            $batch_size
        );

        /*
         * Determine total posts.
         */
        $total = 0;

        foreach ($mappings as $mapping) {

            $count =
                wp_count_posts(
                    $mapping['source']
                );

            if ($count) {

                foreach (
                    get_object_vars($count)
                    as $status => $number
                ) {

                    $total +=
                        (int) $number;
                }
            }
        }

        /*
         * Temporary export file.
         */
        $upload =
            wp_upload_dir();

        $directory =
            trailingslashit(
                $upload['basedir']
            ) .
            'acf-relationship-migrator';

        if (!file_exists($directory)) {

            wp_mkdir_p($directory);
        }

        $file =
            trailingslashit($directory) .
            'migration-' .
            $job_id .
            '.json';

        /*
         * Start JSON file.
         */
        file_put_contents(
            $file,
            "{\n" .
            '    "plugin": "ACF Relationship Migrator",' .
            "\n" .
            '    "version": "' .
                self::VERSION .
                '",' .
            "\n" .
            '    "created": "' .
                current_time('mysql') .
                '",' .
            "\n" .
            '    "mappings": ' .
                wp_json_encode(
                    $mappings
                ) .
                ",\n" .
            '    "posts": [' .
            "\n"
        );

        $state = array(
            'job_id'       => $job_id,
            'file'         => $file,
            'processed'    => 0,
            'total'        => $total,
            'batch_size'   => $batch_size,
            'post_types'   => $mappings,
            'current_type' => 0,
            'current_page' => 1,
            'first_post'   => true,
        );

        set_transient(
            self::TRANSIENT_EXPORT .
                get_current_user_id() .
                '_' .
                $job_id,
            $state,
            DAY_IN_SECONDS
        );

        wp_send_json_success(
            array(
                'job_id' => $job_id,
            )
        );
    }

    /* ==========================================================
     * EXPORT BATCH
     * ========================================================== */

    public function ajax_export_batch() {

        $this->verify_ajax();

        $job_id =
            sanitize_text_field(
                $_POST['job_id']
            );

        $key =
            self::TRANSIENT_EXPORT .
            get_current_user_id() .
            '_' .
            $job_id;

        $state =
            get_transient($key);

        if (!$state) {

            wp_send_json_error(
                'Export job expired.'
            );
        }

        $batch_size =
            $state['batch_size'];

        $processed_this_request = 0;

        while (
            $processed_this_request <
            $batch_size
        ) {

            if (
                !isset(
                    $state['post_types'][
                        $state['current_type']
                    ]
                )
            ) {

                /*
                 * Export complete.
                 */
                $this->finish_export(
                    $state,
                    $key
                );

                $percent = 100;

                wp_send_json_success(
                    array(
                        'processed' =>
                            $state['processed'],

                        'total' =>
                            $state['total'],

                        'percent' =>
                            $percent,

                        'complete' =>
                            true,

                        'download_url' =>
                            $this->get_download_url(
                                $state['file']
                            ),
                    )
                );
            }

            $mapping =
                $state['post_types'][
                    $state['current_type']
                ];

            $posts =
                get_posts(
                    array(
                        'post_type' =>
                            $mapping['source'],

                        'post_status' =>
                            'any',

                        'posts_per_page' =>
                            $batch_size,

                        'paged' =>
                            $state['current_page'],

                        'orderby' =>
                            'ID',

                        'order' =>
                            'ASC',
                    )
                );

            /*
             * Current post type complete.
             */
            if (empty($posts)) {

                $state['current_type']++;

                $state['current_page'] = 1;

                continue;
            }

            foreach ($posts as $post) {

                $post_data = array(
                    'migration_key' =>
                        $this->make_post_key(
                            $post
                        ),

                    'source_post_type' =>
                        $post->post_type,

                    'destination_post_type' =>
                        $mapping['destination'],

                    'slug' =>
                        $post->post_name,

                    'title' =>
                        $post->post_title,

                    'status' =>
                        $post->post_status,

                    /*
                     * Informational only.
                     *
                     * NEVER used during import.
                     */
                    'source_id' =>
                        (int) $post->ID,

                    'relationships' =>
                        array(),
                );

                $fields =
                    $this->get_relationship_fields(
                        $post->ID
                    );

                foreach ($fields as $field) {

                    $raw =
                        get_field(
                            $field['name'],
                            $post->ID,
                            false
                        );

                    $normalized =
                        $this->normalize_acf_value(
                            $raw,
                            $field['type']
                        );

                    $post_data[
                        'relationships'
                    ][] = array(

                        'field_name' =>
                            $field['name'],

                        'field_key' =>
                            isset($field['key'])
                                ? $field['key']
                                : '',

                        'type' =>
                            $field['type'],

                        'value' =>
                            $normalized,
                    );
                }

                /*
                 * Append JSON.
                 */
                $json =
                    wp_json_encode(
                        $post_data,
                        JSON_UNESCAPED_SLASHES
                    );

                $prefix =
                    $state['first_post']
                        ? ''
                        : ",\n";

                file_put_contents(
                    $state['file'],
                    $prefix .
                    '        ' .
                    $json,
                    FILE_APPEND
                );

                $state['first_post'] = false;

                $state['processed']++;

                $processed_this_request++;
            }

            $state['current_page']++;
        }

        set_transient(
            $key,
            $state,
            DAY_IN_SECONDS
        );

        $percent =
            $state['total'] > 0
                ? floor(
                    (
                        $state['processed'] /
                        $state['total']
                    ) * 100
                )
                : 100;

        wp_send_json_success(
            array(
                'processed' =>
                    $state['processed'],

                'total' =>
                    $state['total'],

                'percent' =>
                    min(99, $percent),

                'complete' =>
                    false,
            )
        );
    }

    /* ==========================================================
     * FINISH EXPORT
     * ========================================================== */

    private function finish_export(
        &$state,
        $transient_key
    ) {

        /*
         * Close JSON.
         */
        file_put_contents(
            $state['file'],
            "\n    ]\n}",
            FILE_APPEND
        );

        set_transient(
            $transient_key,
            $state,
            DAY_IN_SECONDS
        );
    }

    /* ==========================================================
     * DOWNLOAD URL
     * ========================================================== */

    private function get_download_url($file) {

        $upload =
            wp_upload_dir();

        $relative =
            str_replace(
                trailingslashit(
                    $upload['basedir']
                ),
                '',
                $file
            );

        return
            trailingslashit(
                $upload['baseurl']
            ) .
            $relative;
    }

    /* ==========================================================
     * IMPORT START
     * ========================================================== */

    public function ajax_start_import() {

        $this->verify_ajax();

        if (
            empty($_FILES['migration_file'])
        ) {

            wp_send_json_error(
                'Migration file missing.'
            );
        }

        if (
            $_FILES['migration_file']['error'] !==
            UPLOAD_ERR_OK
        ) {

            wp_send_json_error(
                'Upload failed.'
            );
        }

        $json =
            file_get_contents(
                $_FILES['migration_file']['tmp_name']
            );

        $data =
            json_decode(
                $json,
                true
            );

        if (
            !$data ||
            empty($data['posts'])
        ) {

            wp_send_json_error(
                'Invalid migration JSON.'
            );
        }

        /*
         * Store uploaded migration file
         * as a temporary server file.
         */
        $upload =
            wp_upload_dir();

        $directory =
            trailingslashit(
                $upload['basedir']
            ) .
            'acf-relationship-migrator';

        if (!file_exists($directory)) {

            wp_mkdir_p($directory);
        }

        $job_id =
            wp_generate_uuid4();

        $file =
            trailingslashit($directory) .
            'import-' .
            $job_id .
            '.json';

        file_put_contents(
            $file,
            $json
        );

        /*
         * Build source → destination mapping
         * from exported metadata.
         */
        $mappings =
            isset($data['mappings'])
                ? $data['mappings']
                : array();

        /*
         * Build duplicate detection.
         */
        $duplicates =
            $this->find_duplicate_keys(
                $data['posts']
            );

        $state = array(

            'job_id' =>
                $job_id,

            'file' =>
                $file,

            'posts' =>
                $data['posts'],

            'mappings' =>
                $mappings,

            'total' =>
                count($data['posts']),

            'processed' =>
                0,

            'resolve_processed' =>
                0,

            'batch_size' =>
                isset($_POST['batch_size'])
                    ? max(
                        10,
                        min(
                            1000,
                            absint(
                                $_POST['batch_size']
                            )
                        )
                    )
                    : 100,

            /*
             * migration_key → destination ID
             */
            'id_map' =>
                array(),

            /*
             * Missing relationship references.
             */
            'missing' =>
                array(),

            /*
             * Duplicate source keys.
             */
            'duplicates' =>
                $duplicates,
        );

        set_transient(
            self::TRANSIENT_IMPORT .
                get_current_user_id() .
                '_' .
                $job_id,
            $state,
            DAY_IN_SECONDS
        );

        wp_send_json_success(
            array(
                'job_id' =>
                    $job_id,
            )
        );
    }

    /* ==========================================================
     * DUPLICATE KEY DETECTION
     * ========================================================== */

    private function find_duplicate_keys(
        $posts
    ) {

        $seen = array();

        $duplicates = array();

        foreach ($posts as $post) {

            if (
                empty(
                    $post['migration_key']
                )
            ) {
                continue;
            }

            $key =
                $post['migration_key'];

            if (
                isset(
                    $seen[$key]
                )
            ) {

                $duplicates[] =
                    $key;

            } else {

                $seen[$key] = true;
            }
        }

        return array_unique(
            $duplicates
        );
    }

    /* ==========================================================
     * IMPORT CONTENT BATCH
     * ========================================================== */

    public function ajax_import_content_batch() {

        $this->verify_ajax();

        $job_id =
            sanitize_text_field(
                $_POST['job_id']
            );

        $key =
            self::TRANSIENT_IMPORT .
            get_current_user_id() .
            '_' .
            $job_id;

        $state =
            get_transient($key);

        if (!$state) {

            wp_send_json_error(
                'Import job expired.'
            );
        }

        $start =
            $state['processed'];

        $end =
            min(
                $start +
                $state['batch_size'],
                $state['total']
            );

        for (
            $i = $start;
            $i < $end;
            $i++
        ) {

            $item =
                $state['posts'][$i];

            if (
                empty(
                    $item['migration_key']
                )
            ) {
                continue;
            }

            $source_type =
                isset(
                    $item['source_post_type']
                )
                    ? $item['source_post_type']
                    : '';

            $destination_type =
                isset(
                    $item['destination_post_type']
                )
                    ? $item['destination_post_type']
                    : $this->get_mapping(
                        $source_type
                    );

            $slug =
                isset(
                    $item['slug']
                )
                    ? $item['slug']
                    : '';

            /*
             * Find destination post.
             *
             * IMPORTANT:
             *
             * Source ID is NOT used.
             */
            $existing =
                get_posts(
                    array(
                        'post_type' =>
                            $destination_type,

                        'name' =>
                            $slug,

                        'post_status' =>
                            'any',

                        'posts_per_page' =>
                            2,

                        'fields' =>
                            'ids',
                    )
                );

            if (
                count($existing) > 1
            ) {

                /*
                 * Ambiguous destination.
                 */
                continue;
            }

            if (
                count($existing) === 1
            ) {

                $destination_id =
                    (int) $existing[0];

            } else {

                /*
                 * Create destination post.
                 */
                $insert =
                    wp_insert_post(
                        wp_slash(
                            array(
                                'post_title' =>
                                    isset(
                                        $item['title']
                                    )
                                        ? $item['title']
                                        : '',

                                'post_name' =>
                                    $slug,

                                'post_type' =>
                                    $destination_type,

                                'post_status' =>
                                    isset(
                                        $item['status']
                                    )
                                        ? $item['status']
                                        : 'publish',
                            )
                        ),
                        true
                    );

                if (
                    is_wp_error($insert)
                ) {
                    continue;
                }

                $destination_id =
                    (int) $insert;
            }

            /*
             * Store migration key directly
             * against destination post.
             */
            update_post_meta(
                $destination_id,
                '_acfrm_migration_key',
                $item['migration_key']
            );

            /*
             * CRITICAL:
             *
             * migration_key → destination ID
             */
            $state['id_map'][
                $item['migration_key']
            ] =
                $destination_id;

            $state['processed']++;
        }

        set_transient(
            $key,
            $state,
            DAY_IN_SECONDS
        );

        $complete =
            $state['processed'] >=
            $state['total'];

        $percent =
            $state['total'] > 0
                ? floor(
                    (
                        $state['processed'] /
                        $state['total']
                    ) * 100
                )
                : 100;

        wp_send_json_success(
            array(
                'processed' =>
                    $state['processed'],

                'total' =>
                    $state['total'],

                'percent' =>
                    $percent,

                'complete' =>
                    $complete,
            )
        );
    }

    /* ==========================================================
     * RESOLVE RELATIONSHIPS
     * ========================================================== */

    public function ajax_resolve_batch() {

        $this->verify_ajax();

        $job_id =
            sanitize_text_field(
                $_POST['job_id']
            );

        $key =
            self::TRANSIENT_IMPORT .
            get_current_user_id() .
            '_' .
            $job_id;

        $state =
            get_transient($key);

        if (!$state) {

            wp_send_json_error(
                'Import job expired.'
            );
        }

        $start =
            $state['resolve_processed'];

        $end =
            min(
                $start +
                $state['batch_size'],
                $state['total']
            );

        for (
            $i = $start;
            $i < $end;
            $i++
        ) {

            $item =
                $state['posts'][$i];

            $source_key =
                isset(
                    $item['migration_key']
                )
                    ? $item['migration_key']
                    : '';

            if (
                !$source_key
            ) {
                continue;
            }

            if (
                empty(
                    $state['id_map'][
                        $source_key
                    ]
                )
            ) {
                continue;
            }

            $destination_post_id =
                $state['id_map'][
                    $source_key
                ];

            if (
                empty(
                    $item['relationships']
                )
            ) {
                continue;
            }

            foreach (
                $item['relationships']
                as $relationship
            ) {

                $field_name =
                    isset(
                        $relationship['field_name']
                    )
                        ? $relationship['field_name']
                        : '';

                $field_key =
                    isset(
                        $relationship['field_key']
                    )
                        ? $relationship['field_key']
                        : '';

                $type =
                    isset(
                        $relationship['type']
                    )
                        ? $relationship['type']
                        : '';

                $value =
                    isset(
                        $relationship['value']
                    )
                        ? $relationship['value']
                        : null;

                if (!$field_name) {
                    continue;
                }

                /*
                 * POST OBJECT
                 */
                if (
                    $type === 'post_object'
                ) {

                    $target_key =
                        isset(
                            $value['key']
                        )
                            ? $value['key']
                            : '';

                    $target_id =
                        $this->resolve_post_reference(
                            $target_key,
                            $state
                        );

                    if ($target_id) {

                        update_field(
                            $field_key
                                ?: $field_name,

                            $target_id,

                            $destination_post_id
                        );

                    } elseif (
                        $target_key
                    ) {

                        $state['missing'][] =
                            array(
                                'source' =>
                                    $source_key,

                                'field' =>
                                    $field_name,

                                'target' =>
                                    $target_key,
                            );
                    }
                }

                /*
                 * RELATIONSHIP
                 */
                elseif (
                    $type === 'relationship'
                ) {

                    $resolved =
                        array();

                    if (
                        is_array($value)
                    ) {

                        foreach (
                            $value as $reference
                        ) {

                            $target_key =
                                isset(
                                    $reference['key']
                                )
                                    ? $reference['key']
                                    : '';

                            $target_id =
                                $this->resolve_post_reference(
                                    $target_key,
                                    $state
                                );

                            if ($target_id) {

                                $resolved[] =
                                    $target_id;

                            } elseif (
                                $target_key
                            ) {

                                $state['missing'][] =
                                    array(
                                        'source' =>
                                            $source_key,

                                        'field' =>
                                            $field_name,

                                        'target' =>
                                            $target_key,
                                    );
                            }
                        }
                    }

                    update_field(
                        $field_key
                            ?: $field_name,

                        $resolved,

                        $destination_post_id
                    );
                }

                /*
                 * PAGE LINK
                 */
                elseif (
                    $type === 'page_link'
                ) {

                    $target_key =
                        isset(
                            $value['key']
                        )
                            ? $value['key']
                            : '';

                    $target_id =
                        $this->resolve_post_reference(
                            $target_key,
                            $state
                        );

                    if ($target_id) {

                        $url =
                            get_permalink(
                                $target_id
                            );

                        update_field(
                            $field_key
                                ?: $field_name,

                            $url,

                            $destination_post_id
                        );

                    } elseif (
                        $target_key
                    ) {

                        $state['missing'][] =
                            array(
                                'source' =>
                                    $source_key,

                                'field' =>
                                    $field_name,

                                'target' =>
                                    $target_key,
                            );
                    }
                }

                /*
                 * TAXONOMY
                 */
                elseif (
                    $type === 'taxonomy'
                ) {

                    $term_ids =
                        array();

                    $multiple =
                        is_array($value);

                    $references =
                        $multiple
                            ? $value
                            : array($value);

                    foreach (
                        $references
                        as $reference
                    ) {

                        $target_key =
                            isset(
                                $reference['key']
                            )
                                ? $reference['key']
                                : '';

                        $term_id =
                            $this->resolve_term_reference(
                                $target_key
                            );

                        if ($term_id) {

                            $term_ids[] =
                                $term_id;

                        } elseif (
                            $target_key
                        ) {

                            $state['missing'][] =
                                array(
                                    'source' =>
                                        $source_key,

                                    'field' =>
                                        $field_name,

                                    'target' =>
                                        $target_key,
                                );
                        }
                    }

                    if ($multiple) {

                        update_field(
                            $field_key
                                ?: $field_name,

                            $term_ids,

                            $destination_post_id
                        );

                    } elseif (
                        !empty($term_ids)
                    ) {

                        update_field(
                            $field_key
                                ?: $field_name,

                            $term_ids[0],

                            $destination_post_id
                        );
                    }
                }
            }

            $state['resolve_processed']++;
        }

        set_transient(
            $key,
            $state,
            DAY_IN_SECONDS
        );

        $complete =
            $state['resolve_processed'] >=
            $state['total'];

        $percent =
            $state['total'] > 0
                ? floor(
                    (
                        $state['resolve_processed'] /
                        $state['total']
                    ) * 100
                )
                : 100;

        wp_send_json_success(
            array(
                'processed' =>
                    $state['resolve_processed'],

                'total' =>
                    $state['total'],

                'percent' =>
                    $percent,

                'complete' =>
                    $complete,

                'missing' =>
                    count(
                        $state['missing']
                    ),
            )
        );
    }

    /* ==========================================================
     * RESOLVE POST REFERENCE
     * ========================================================== */

    private function resolve_post_reference(
        $key,
        &$state
    ) {

        if (!$key) {
            return 0;
        }

        /*
         * First use migration map.
         */
        if (
            isset(
                $state['id_map'][$key]
            )
        ) {

            return (int)
                $state['id_map'][$key];
        }

        /*
         * Fallback.
         *
         * Parse:
         *
         * source_type:slug
         */
        $parts =
            explode(
                ':',
                $key,
                2
            );

        if (
            count($parts) !== 2
        ) {
            return 0;
        }

        $source_type =
            $parts[0];

        $slug =
            $parts[1];

        /*
         * Determine destination post type
         * from mapping.
         */
        $destination_type =
            $this->get_mapping(
                $source_type
            );

        /*
         * Find destination using:
         *
         * destination post type + slug
         */
        $posts =
            get_posts(
                array(
                    'post_type' =>
                        $destination_type,

                    'name' =>
                        $slug,

                    'post_status' =>
                        'any',

                    'posts_per_page' =>
                        2,

                    'fields' =>
                        'ids',
                )
            );

        if (
            count($posts) === 1
        ) {

            $id =
                (int) $posts[0];

            /*
             * Cache it.
             */
            $state['id_map'][$key] =
                $id;

            return $id;
        }

        return 0;
    }

    /* ==========================================================
     * RESOLVE TAXONOMY
     * ========================================================== */

    private function resolve_term_reference(
        $key
    ) {

        if (!$key) {
            return 0;
        }

        /*
         * taxonomy:slug
         */
        $parts =
            explode(
                ':',
                $key,
                2
            );

        if (
            count($parts) !== 2
        ) {
            return 0;
        }

        $taxonomy =
            $parts[0];

        $slug =
            $parts[1];

        $term =
            get_term_by(
                'slug',
                $slug,
                $taxonomy
            );

        if (
            !$term ||
            is_wp_error($term)
        ) {
            return 0;
        }

        return (int)
            $term->term_id;
    }
}


/*
 * Initialize plugin.
 */
new ACF_Relationship_Migrator();
?>