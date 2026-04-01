<?php
/**
 * Plugin Name: 360 Content Bridge
 * Plugin URI: https://yaurel.com
 * Description: Lightweight selective export/import for posts, pages and CPT with taxonomies, custom fields, ACF-compatible data and media relinking.
 * Version: 1.2.0
 * Author: Aurel Yahoudeou
 * Author URI: https://yaurel.com
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentBridge360 {
    const PAGE_SLUG = 'content-bridge-360';
    const NOTICE_OPTION = 'content_bridge_360_notice';
    const RESUME_OPTION_PREFIX = 'content_bridge_360_resume_';
    const VERSION = '1.2.0';
    const SOURCE_URL_META = '_cb360_source_url';
    const SOURCE_ATTACHMENT_ID_META = '_cb360_source_attachment_id';
    const ORIGIN_FINGERPRINT_META = '_cb360_origin_fingerprint';

    public function __construct() {
        add_action('admin_menu', [$this, 'addToolsPage']);
        add_action('admin_post_content_bridge_360_export', [$this, 'handleExport']);
        add_action('admin_post_content_bridge_360_import', [$this, 'handleImport']);
        add_action('wp_ajax_content_bridge_360_search_posts', [$this, 'ajaxSearchPosts']);
        add_action('admin_notices', [$this, 'renderNotice']);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            call_user_func(['WP_CLI', 'add_command'], 'cb360 export', [$this, 'cliExport']);
            call_user_func(['WP_CLI', 'add_command'], 'cb360 import', [$this, 'cliImport']);
        }
    }

    public function addToolsPage() {
        add_management_page(
            $this->tr('360 Content Bridge', '360 Content Bridge'),
            $this->tr('360 Content Bridge', '360 Content Bridge'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html($this->tr('Access denied.', 'Acces refuse.')));
        }

        $postTypes = get_post_types(['public' => true], 'objects');
        unset($postTypes['attachment']);

        $statuses = get_post_stati(['internal' => false], 'objects');

        $mappingTemplate = [
            'post_type' => [
                'old_cpt' => 'new_cpt',
            ],
            'taxonomy' => [
                'old_taxonomy' => 'new_taxonomy',
            ],
            'meta' => [
                'old_meta_key' => 'new_meta_key',
            ],
            'acf_field_key' => [
                'field_oldxxxx' => 'field_newxxxx',
            ],
            'acf_field_name' => [
                'old_acf_name' => 'new_acf_name',
            ],
        ];

        ?>
        <div class="wrap">
            <h1>360 Content Bridge</h1>
            <p><?php echo esc_html($this->tr(
                'Guided export/import for posts, pages, CPT, taxonomies, custom fields and media relinking.',
                'Export/import guide pour pages, articles, CPT, taxonomies, champs personnalises et relinking medias.'
            )); ?></p>
            <p style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;border-radius:4px;max-width:980px;">
                <strong><?php echo esc_html($this->tr('Quick flow:', 'Flux rapide :')); ?></strong>
                <?php echo esc_html($this->tr(
                    '1) Choose what to export (all, latest, or manual selection). 2) Export JSON. 3) Import JSON on target site.',
                    '1) Choisis quoi exporter (tout, derniers, ou selection manuelle). 2) Exporte le JSON. 3) Importe le JSON sur le site cible.'
                )); ?>
            </p>

            <div style="background:#fff;padding:16px;border:1px solid #ccd0d4;margin:16px 0;">
                <h2 style="margin-top:0;"><?php echo esc_html($this->tr('Export', 'Export')); ?></h2>
                <form id="cb360-export-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="content_bridge_360_export" />
                    <input type="hidden" id="cb360-search-nonce" value="<?php echo esc_attr(wp_create_nonce('content_bridge_360_search_posts_nonce')); ?>" />
                    <?php wp_nonce_field('content_bridge_360_export_nonce'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Post types', 'Types de contenus')); ?></th>
                            <td>
                                <?php foreach ($postTypes as $postType): ?>
                                    <label style="display:inline-block;min-width:240px;margin:4px 0;">
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($postType->name); ?>" checked />
                                        <?php echo esc_html($postType->labels->singular_name . ' (' . $postType->name . ')'); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Statuses', 'Statuts')); ?></th>
                            <td>
                                <?php foreach ($statuses as $status): ?>
                                    <label style="display:inline-block;min-width:180px;margin:4px 0;">
                                        <input type="checkbox" name="post_statuses[]" value="<?php echo esc_attr($status->name); ?>" checked />
                                        <?php echo esc_html($status->label . ' (' . $status->name . ')'); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cb360-from"><?php echo esc_html($this->tr('Date from', 'Date de debut')); ?></label></th>
                            <td><input id="cb360-from" type="date" name="date_from" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cb360-to"><?php echo esc_html($this->tr('Date to', 'Date de fin')); ?></label></th>
                            <td><input id="cb360-to" type="date" name="date_to" /></td>
                        </tr>
                        <tr id="cb360-limit-row">
                            <th scope="row"><label for="cb360-limit"><?php echo esc_html($this->tr('Max results per post type', 'Nombre maximum par type de contenu')); ?></label></th>
                            <td>
                                <input id="cb360-limit" type="number" min="1" name="limit" placeholder="<?php echo esc_attr($this->tr('No limit', 'Sans limite')); ?>" style="width:90px;" />
                                <p class="description"><?php echo esc_html($this->tr('Used only in \'All matching content\' mode. Limits the number of items exported per post type independently. Example: 5 = at most 5 posts, 5 pages, 5 of each CPT selected.', 'Utilise uniquement en mode \'Tout le contenu\'. Limite le nombre d\'elements exportes par type de contenu independamment. Exemple : 5 = 5 articles max, 5 pages max, 5 max par CPT selectionne.')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Selection mode', 'Mode de selection')); ?></th>
                            <td>
                                <label><input type="radio" name="selection_mode" value="all" checked /> <?php echo esc_html($this->tr('All matching content', 'Tout le contenu correspondant')); ?></label><br />
                                <label><input type="radio" name="selection_mode" value="latest" /> <?php echo esc_html($this->tr('Latest globally', 'Les plus recents globalement')); ?></label>
                                <div id="cb360-latest-wrap" style="margin:6px 0 10px 22px;">
                                    <input type="number" min="1" name="latest_count" value="10" style="width:90px;" />
                                    <span class="description"><?php echo esc_html($this->tr('Example: 10 latest items across selected post types.', 'Exemple : les 10 derniers contenus parmi les types coches.')); ?></span>
                                </div>

                                <label><input type="radio" name="selection_mode" value="manual" /> <?php echo esc_html($this->tr('Search and pick specific posts', 'Rechercher et choisir des contenus precis')); ?></label>
                                <div id="cb360-manual-wrap" style="margin:6px 0 0 22px;display:none;max-width:860px;">
                                    <input type="hidden" name="manual_post_ids" id="cb360-manual-ids" value="" />
                                    <input type="text" id="cb360-manual-search" placeholder="<?php echo esc_attr($this->tr('Type first letters of title...', 'Tape les premieres lettres du titre...')); ?>" style="width:100%;" autocomplete="off" />
                                    <div id="cb360-manual-results" style="border:1px solid #ccd0d4;border-top:none;max-height:220px;overflow:auto;background:#fff;display:none;"></div>
                                    <div id="cb360-manual-selected" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;"></div>
                                    <p class="description"><?php echo esc_html($this->tr('Search by title and click to add items. Only selected items will be exported.', 'Recherche par titre puis clique pour ajouter. Seuls les contenus selectionnes seront exportes.')); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Options', 'Options')); ?></th>
                            <td>
                                <label><input type="checkbox" name="include_media" value="1" checked /> <?php echo esc_html($this->tr('Include media bundle and metadata', 'Inclure les medias et leurs metadonnees')); ?></label><br />
                                <label><input type="checkbox" name="include_acf_refs" value="1" checked /> <?php echo esc_html($this->tr('Include ACF field references (field_xxx)', 'Inclure les references ACF (field_xxx)')); ?></label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button($this->tr('Export JSON', 'Exporter JSON')); ?>
                </form>
            </div>

            <div style="background:#fff;padding:16px;border:1px solid #ccd0d4;margin:16px 0;">
                <h2 style="margin-top:0;"><?php echo esc_html($this->tr('Import', 'Import')); ?></h2>
                <form id="cb360-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="content_bridge_360_import" />
                    <?php wp_nonce_field('content_bridge_360_import_nonce'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="cb360-file"><?php echo esc_html($this->tr('JSON file', 'Fichier JSON')); ?></label></th>
                            <td><input id="cb360-file" type="file" name="import_file" accept="application/json,.json" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Import mode', 'Mode d import')); ?></th>
                            <td>
                                <label><input type="radio" name="import_mode" value="create" checked /> <?php echo esc_html($this->tr('Create only', 'Creer uniquement')); ?></label><br />
                                <label><input type="radio" name="import_mode" value="upsert" /> <?php echo esc_html($this->tr('Upsert (update if matched)', 'Upsert (mettre a jour si trouve)')); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Match strategy', 'Strategie de correspondance')); ?></th>
                            <td>
                                    <details style="margin-top:8px;">
                                        <summary style="cursor:pointer;"><?php echo esc_html($this->tr('Advanced filters (optional)', 'Filtres avances (optionnel)')); ?></summary>
                                        <p class="description" style="margin-top:8px;"><?php echo esc_html($this->tr('JSON meta_query and taxonomy query are applied on export.', 'Les JSON meta_query et tax_query sont appliques a l export.')); ?></p>
                                        <label style="display:block;margin-top:8px;"><?php echo esc_html($this->tr('meta_query JSON', 'JSON meta_query')); ?></label>
                                        <textarea name="export_meta_query_json" rows="4" style="width:100%;font-family:monospace;" placeholder='[{"key":"my_key","value":"my_value","compare":"="}]'></textarea>
                                        <label style="display:block;margin-top:8px;"><?php echo esc_html($this->tr('taxonomy filter JSON', 'JSON filtre taxonomie')); ?></label>
                                        <textarea name="export_tax_query_json" rows="4" style="width:100%;font-family:monospace;" placeholder='[{"taxonomy":"category","field":"slug","terms":["news"]}]'></textarea>
                                    </details>
                                <label><input type="checkbox" name="match_by[]" value="slug" checked /> slug</label><br />
                                <label><input type="checkbox" name="match_by[]" value="post_name" checked /> post_name</label><br />
                                <label><input type="checkbox" name="match_by[]" value="meta" /> <?php echo esc_html($this->tr('unique meta key', 'meta unique')); ?></label><br />
                                <input type="text" name="unique_meta_key" placeholder="<?php echo esc_attr($this->tr('example: external_id', 'exemple : external_id')); ?>" style="min-width:320px;" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Data safety', 'Securite des donnees')); ?></th>
                            <td>
                                <label><input type="checkbox" name="preserve_missing" value="1" checked /> <?php echo esc_html($this->tr('Leave fields not present in import untouched', 'Laisser intacts les champs absents de l import')); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Execution', 'Execution')); ?></th>
                            <td>
                                <label><input type="checkbox" name="dry_run" value="1" /> <?php echo esc_html($this->tr('Dry-run (no write, validation/report only)', 'Simulation (aucune ecriture, validation/rapport seulement)')); ?></label>
                                <details style="margin-top:8px;">
                                    <summary style="cursor:pointer;"><?php echo esc_html($this->tr('Batch / resume (optional)', 'Lots / reprise (optionnel)')); ?></summary>
                                    <div style="margin-top:8px;">
                                        <label><?php echo esc_html($this->tr('Batch size', 'Taille du lot')); ?>: <input type="number" name="batch_size" min="1" value="50" style="width:90px;" /></label>
                                        <p class="description"><?php echo esc_html($this->tr('If set, import runs by chunks and gives a resume token.', 'Si defini, l import tourne par lots et fournit un token de reprise.')); ?></p>
                                        <label><?php echo esc_html($this->tr('Resume token', 'Token de reprise')); ?>: <input type="text" name="resume_token" placeholder="<?php echo esc_attr($this->tr('leave empty for first run', 'laisser vide au premier lancement')); ?>" style="min-width:320px;" /></label>
                                    </div>
                                </details>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html($this->tr('Media', 'Medias')); ?></th>
                            <td>
                                <label><input type="checkbox" name="import_media" value="1" checked /> <?php echo esc_html($this->tr('Import referenced media bundle', 'Importer les medias references')); ?></label><br />
                                <label><input type="checkbox" name="import_featured_image" value="1" checked /> <?php echo esc_html($this->tr('Set featured image from imported media', 'Definir l image mise en avant depuis les medias importes')); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cb360-mapping"><?php echo esc_html($this->tr('Mappings JSON (optional)', 'Mappings JSON (optionnel)')); ?></label></th>
                            <td>
                                <textarea id="cb360-mapping" name="mapping_json" rows="13" style="width:100%;font-family:monospace;"><?php echo esc_textarea(wp_json_encode($mappingTemplate, JSON_PRETTY_PRINT)); ?></textarea>
                                <p class="description"><?php echo esc_html($this->tr('Use this to map renamed CPT, taxonomies, meta keys, ACF field keys and ACF field names.', 'Utilise ceci pour mapper les CPT renommes, taxonomies, meta keys, field keys ACF et noms de champs ACF.')); ?></p>
                                <details style="margin-top:8px;">
                                    <summary style="cursor:pointer;"><?php echo esc_html($this->tr('Mapping assistant', 'Assistant de mapping')); ?></summary>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center;">
                                        <select id="cb360-map-type">
                                            <option value="post_type">post_type</option>
                                            <option value="taxonomy">taxonomy</option>
                                            <option value="meta">meta</option>
                                            <option value="acf_field_key">acf_field_key</option>
                                            <option value="acf_field_name">acf_field_name</option>
                                        </select>
                                        <input type="text" id="cb360-map-from" placeholder="from" />
                                        <span>-></span>
                                        <input type="text" id="cb360-map-to" placeholder="to" />
                                        <button type="button" class="button" id="cb360-map-add"><?php echo esc_html($this->tr('Add mapping', 'Ajouter mapping')); ?></button>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button($this->tr('Import JSON', 'Importer JSON'), 'primary'); ?>
                </form>
            </div>
        </div>
        <script>
            (function () {
                var form = document.getElementById('cb360-export-form');
                if (!form) return;

                var selectionRadios = form.querySelectorAll('input[name="selection_mode"]');
                var manualWrap = document.getElementById('cb360-manual-wrap');
                var latestWrap = document.getElementById('cb360-latest-wrap');
                var searchInput = document.getElementById('cb360-manual-search');
                var resultsBox = document.getElementById('cb360-manual-results');
                var selectedBox = document.getElementById('cb360-manual-selected');
                var idsField = document.getElementById('cb360-manual-ids');
                var nonceField = document.getElementById('cb360-search-nonce');

                var selected = {};
                var timer = null;

                function currentSelectionMode() {
                    var checked = form.querySelector('input[name="selection_mode"]:checked');
                    return checked ? checked.value : 'all';
                }

                var limitRow = document.getElementById('cb360-limit-row');

                function toggleSelectionUi() {
                    var mode = currentSelectionMode();
                    if (manualWrap) manualWrap.style.display = mode === 'manual' ? 'block' : 'none';
                    if (latestWrap) latestWrap.style.display = mode === 'latest' ? 'block' : 'none';
                    if (limitRow) limitRow.style.display = mode === 'all' ? '' : 'none';
                }

                function syncIdsField() {
                    var ids = Object.keys(selected);
                    idsField.value = ids.join(',');
                }

                function removeSelected(id) {
                    delete selected[id];
                    renderSelected();
                }

                function renderSelected() {
                    selectedBox.innerHTML = '';
                    var ids = Object.keys(selected);
                    if (!ids.length) {
                        syncIdsField();
                        return;
                    }

                    ids.forEach(function (id) {
                        var item = selected[id];
                        var chip = document.createElement('span');
                        chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:4px 8px;background:#eef3f8;border:1px solid #b6c7d9;border-radius:14px;';
                        chip.textContent = '#' + id + ' ' + item.title;

                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = 'x';
                        btn.style.cssText = 'border:none;background:transparent;color:#a00;cursor:pointer;font-weight:bold;';
                        btn.addEventListener('click', function () { removeSelected(id); });

                        chip.appendChild(btn);
                        selectedBox.appendChild(chip);
                    });

                    syncIdsField();
                }

                function addSelected(item) {
                    var id = String(item.id);
                    if (selected[id]) return;
                    selected[id] = item;
                    renderSelected();
                }

                function getCheckedValues(name) {
                    var nodes = form.querySelectorAll('input[name="' + name + '"]:checked');
                    var values = [];
                    nodes.forEach(function (n) { values.push(n.value); });
                    return values;
                }

                function renderResults(items) {
                    resultsBox.innerHTML = '';
                    if (!items || !items.length) {
                        resultsBox.style.display = 'none';
                        return;
                    }

                    items.forEach(function (item) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        row.style.cssText = 'display:block;width:100%;text-align:left;padding:8px 10px;border:0;border-bottom:1px solid #edf0f3;background:#fff;cursor:pointer;';
                        row.textContent = '#' + item.id + ' - ' + item.title + ' [' + item.post_type + ']';
                        row.addEventListener('click', function () {
                            addSelected(item);
                            resultsBox.style.display = 'none';
                            searchInput.value = '';
                            searchInput.focus();
                        });
                        resultsBox.appendChild(row);
                    });

                    resultsBox.style.display = 'block';
                }

                function searchPosts(term) {
                    var nonce = nonceField ? nonceField.value : '';
                    if (!nonce || !term || term.length < 2) {
                        renderResults([]);
                        return;
                    }

                    var params = new URLSearchParams();
                    params.append('action', 'content_bridge_360_search_posts');
                    params.append('nonce', nonce);
                    params.append('q', term);

                    getCheckedValues('post_types[]').forEach(function (value) {
                        params.append('post_types[]', value);
                    });

                    getCheckedValues('post_statuses[]').forEach(function (value) {
                        params.append('post_statuses[]', value);
                    });

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: params.toString()
                    }).then(function (res) {
                        return res.json();
                    }).then(function (data) {
                        if (data && data.success && data.data && data.data.items) {
                            renderResults(data.data.items);
                        } else {
                            renderResults([]);
                        }
                    }).catch(function () {
                        renderResults([]);
                    });
                }

                selectionRadios.forEach(function (radio) {
                    radio.addEventListener('change', toggleSelectionUi);
                });

                if (searchInput) {
                    searchInput.addEventListener('input', function () {
                        clearTimeout(timer);
                        var term = searchInput.value.trim();
                        timer = setTimeout(function () { searchPosts(term); }, 220);
                    });
                }

                document.addEventListener('click', function (e) {
                    if (!manualWrap || !resultsBox) return;
                    if (!manualWrap.contains(e.target)) {
                        resultsBox.style.display = 'none';
                    }
                });

                form.addEventListener('submit', function (e) {
                    if (currentSelectionMode() === 'manual' && !idsField.value) {
                        e.preventDefault();
                        alert('<?php echo esc_js($this->tr('Select at least one post in manual mode.', 'Selectionne au moins un contenu en mode manuel.')); ?>');
                    }
                });

                toggleSelectionUi();
            })();

            (function () {
                var mapType = document.getElementById('cb360-map-type');
                var mapFrom = document.getElementById('cb360-map-from');
                var mapTo = document.getElementById('cb360-map-to');
                var mapAdd = document.getElementById('cb360-map-add');
                var mapTextarea = document.getElementById('cb360-mapping');
                if (!mapType || !mapFrom || !mapTo || !mapAdd || !mapTextarea) return;

                function parseJson() {
                    try {
                        var parsed = JSON.parse(mapTextarea.value || '{}');
                        if (!parsed || typeof parsed !== 'object') throw new Error('invalid');
                        ['post_type', 'taxonomy', 'meta', 'acf_field_key', 'acf_field_name'].forEach(function (k) {
                            if (!parsed[k] || typeof parsed[k] !== 'object') parsed[k] = {};
                        });
                        return parsed;
                    } catch (e) {
                        alert('<?php echo esc_js($this->tr('Mapping JSON is invalid.', 'Le JSON de mapping est invalide.')); ?>');
                        return null;
                    }
                }

                mapAdd.addEventListener('click', function () {
                    var section = mapType.value;
                    var from = (mapFrom.value || '').trim();
                    var to = (mapTo.value || '').trim();
                    if (!from || !to) return;

                    var json = parseJson();
                    if (!json) return;
                    json[section][from] = to;
                    mapTextarea.value = JSON.stringify(json, null, 2);
                    mapFrom.value = '';
                    mapTo.value = '';
                    mapFrom.focus();
                });
            })();
        </script>
        <?php
    }

    public function ajaxSearchPosts() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => $this->tr('Forbidden', 'Interdit')], 403);
        }

        check_ajax_referer('content_bridge_360_search_posts_nonce', 'nonce');

        $queryText = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (strlen($queryText) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $postTypes = isset($_POST['post_types']) ? array_map('sanitize_key', (array) wp_unslash($_POST['post_types'])) : [];
        if (empty($postTypes)) {
            $postTypes = get_post_types(['public' => true], 'names');
            unset($postTypes['attachment']);
        }

        $postStatuses = isset($_POST['post_statuses']) ? array_map('sanitize_key', (array) wp_unslash($_POST['post_statuses'])) : [];
        if (empty($postStatuses)) {
            $postStatuses = ['publish'];
        }

        $query = new WP_Query([
            'post_type' => $postTypes,
            'post_status' => $postStatuses,
            's' => $queryText,
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = [
                'id' => (int) $post->ID,
                'title' => html_entity_decode(wp_strip_all_tags(get_the_title($post->ID))),
                'post_type' => (string) $post->post_type,
                'date' => (string) $post->post_date,
            ];
        }

        wp_reset_postdata();
        wp_send_json_success(['items' => $items]);
    }

    public function handleExport() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html($this->tr('Access denied.', 'Acces refuse.')));
        }

        check_admin_referer('content_bridge_360_export_nonce');

        $postTypes = isset($_POST['post_types']) ? array_map('sanitize_key', (array) wp_unslash($_POST['post_types'])) : [];
        $postStatuses = isset($_POST['post_statuses']) ? array_map('sanitize_key', (array) wp_unslash($_POST['post_statuses'])) : [];
        $dateFrom = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
        $dateTo = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 0;
        $selectionMode = isset($_POST['selection_mode']) ? sanitize_key(wp_unslash($_POST['selection_mode'])) : 'all';
        $latestCount = isset($_POST['latest_count']) ? absint($_POST['latest_count']) : 10;
        $manualPostIds = isset($_POST['manual_post_ids']) ? (string) wp_unslash($_POST['manual_post_ids']) : '';
        $exportMetaQueryJson = isset($_POST['export_meta_query_json']) ? (string) wp_unslash($_POST['export_meta_query_json']) : '';
        $exportTaxQueryJson = isset($_POST['export_tax_query_json']) ? (string) wp_unslash($_POST['export_tax_query_json']) : '';
        $includeMedia = !empty($_POST['include_media']);
        $includeAcfRefs = !empty($_POST['include_acf_refs']);
        $advancedFilters = $this->parseAdvancedExportFilters($exportMetaQueryJson, $exportTaxQueryJson);

        if ($advancedFilters['error']) {
            $this->setNotice($this->tr('Advanced filter JSON is invalid.', 'Le JSON des filtres avances est invalide.'), 'error');
            $this->redirectToPage();
        }

        if (!in_array($selectionMode, ['all', 'latest', 'manual'], true)) {
            $selectionMode = 'all';
        }

        if ($latestCount <= 0) {
            $latestCount = 10;
        }

        if (empty($postTypes)) {
            $this->setNotice($this->tr('Select at least one post type for export.', 'Selectionne au moins un type de contenu pour l export.'), 'error');
            $this->redirectToPage();
        }

        if (empty($postStatuses)) {
            $postStatuses = ['publish'];
        }

        $site = get_blog_details(get_current_blog_id());
        $payload = [
            'meta' => [
                'plugin' => '360 Content Bridge',
                'version' => self::VERSION,
                'site_name' => $site ? $site->blogname : get_bloginfo('name'),
                'site_url' => site_url('/'),
                'blog_id' => get_current_blog_id(),
                'exported_at' => current_time('mysql'),
                'post_types' => $postTypes,
                'post_statuses' => $postStatuses,
                'selection_mode' => $selectionMode,
                'latest_count' => $latestCount,
                'include_media' => $includeMedia,
                'include_acf_refs' => $includeAcfRefs,
                'advanced_filters' => $advancedFilters['filters'],
                'has_polylang' => function_exists('pll_get_post_language'),
                'has_wpml' => has_filter('wpml_post_language_details') || has_action('wpml_set_element_language_details'),
            ],
            'items' => [],
        ];

        $exportPosts = $this->getExportPosts(
            $postTypes,
            $postStatuses,
            $dateFrom,
            $dateTo,
            $limit,
            $selectionMode,
            $latestCount,
            $manualPostIds,
            $advancedFilters['filters']
        );

        foreach ($exportPosts as $post) {
            $payload['items'][] = $this->buildExportItem($post, $includeMedia, $includeAcfRefs);
        }

        $filename = sprintf(
            '360-content-bridge-%s-%s.json',
            sanitize_title(get_bloginfo('name')),
            gmdate('Ymd-His')
        );

        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename=' . $filename);

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function buildExportQueryArgs($postType, $postStatuses, $dateFrom, $dateTo, $limit, $advancedFilters = []) {
        $args = [
            'post_type' => $postType,
            'post_status' => $postStatuses,
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if ($dateFrom || $dateTo) {
            $args['date_query'] = $this->buildDateQuery($dateFrom, $dateTo);
        }

        if (!empty($advancedFilters['meta_query']) && is_array($advancedFilters['meta_query'])) {
            $args['meta_query'] = $advancedFilters['meta_query'];
        }

        if (!empty($advancedFilters['tax_query']) && is_array($advancedFilters['tax_query'])) {
            $args['tax_query'] = $advancedFilters['tax_query'];
        }

        return $args;
    }

    private function getExportPosts($postTypes, $postStatuses, $dateFrom, $dateTo, $limit, $selectionMode, $latestCount, $manualPostIds, $advancedFilters = []) {
        $posts = [];

        if ($selectionMode === 'latest') {
            $args = [
                'post_type' => $postTypes,
                'post_status' => $postStatuses,
                'posts_per_page' => $latestCount,
                'orderby' => 'date',
                'order' => 'DESC',
            ];

            if ($dateFrom || $dateTo) {
                $args['date_query'] = $this->buildDateQuery($dateFrom, $dateTo);
            }

            if (!empty($advancedFilters['meta_query']) && is_array($advancedFilters['meta_query'])) {
                $args['meta_query'] = $advancedFilters['meta_query'];
            }

            if (!empty($advancedFilters['tax_query']) && is_array($advancedFilters['tax_query'])) {
                $args['tax_query'] = $advancedFilters['tax_query'];
            }

            $query = new WP_Query($args);
            $posts = is_array($query->posts) ? $query->posts : [];
            wp_reset_postdata();
            return $posts;
        }

        if ($selectionMode === 'manual') {
            $ids = $this->parseManualPostIds($manualPostIds);
            if (empty($ids)) {
                return [];
            }

            $query = new WP_Query([
                'post_type' => $postTypes,
                'post_status' => $postStatuses,
                'posts_per_page' => count($ids),
                'post__in' => $ids,
                'orderby' => 'post__in',
                'meta_query' => !empty($advancedFilters['meta_query']) && is_array($advancedFilters['meta_query']) ? $advancedFilters['meta_query'] : [],
                'tax_query' => !empty($advancedFilters['tax_query']) && is_array($advancedFilters['tax_query']) ? $advancedFilters['tax_query'] : [],
            ]);

            $posts = is_array($query->posts) ? $query->posts : [];
            wp_reset_postdata();
            return $posts;
        }

        foreach ($postTypes as $postType) {
            $query = new WP_Query($this->buildExportQueryArgs($postType, $postStatuses, $dateFrom, $dateTo, $limit, $advancedFilters));
            if (!empty($query->posts) && is_array($query->posts)) {
                $posts = array_merge($posts, $query->posts);
            }
            wp_reset_postdata();
        }

        return $posts;
    }

    private function buildDateQuery($dateFrom, $dateTo) {
        $dateQuery = [];

        if ($dateFrom) {
            $dateQuery[] = ['after' => $dateFrom . ' 00:00:00'];
        }
        if ($dateTo) {
            $dateQuery[] = ['before' => $dateTo . ' 23:59:59'];
        }

        $dateQuery['inclusive'] = true;
        return $dateQuery;
    }

    private function parseManualPostIds($input) {
        $parts = preg_split('/[\s,;]+/', trim((string) $input));
        if (!is_array($parts)) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $id = absint($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function parseAdvancedExportFilters($metaQueryJson, $taxQueryJson) {
        $filters = [
            'meta_query' => [],
            'tax_query' => [],
        ];

        if (trim($metaQueryJson) !== '') {
            $decodedMeta = json_decode($metaQueryJson, true);
            if (!is_array($decodedMeta)) {
                return ['filters' => $filters, 'error' => true];
            }
            $filters['meta_query'] = $decodedMeta;
        }

        if (trim($taxQueryJson) !== '') {
            $decodedTax = json_decode($taxQueryJson, true);
            if (!is_array($decodedTax)) {
                return ['filters' => $filters, 'error' => true];
            }
            $filters['tax_query'] = $decodedTax;
        }

        return ['filters' => $filters, 'error' => false];
    }

    private function buildExportItem($post, $includeMedia, $includeAcfRefs) {
        $meta = get_post_meta($post->ID);
        $cleanMeta = $this->sanitizeExportMeta($meta);

        $item = [
            'post' => [
                'source_post_id' => (int) $post->ID,
                'source_permalink' => get_permalink($post->ID),
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'post_name' => $post->post_name,
                'post_excerpt' => $post->post_excerpt,
                'post_content' => $post->post_content,
                'menu_order' => (int) $post->menu_order,
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
                'post_date' => $post->post_date,
            ],
            'taxonomies' => $this->exportTaxonomies($post->ID, $post->post_type),
            'meta' => $cleanMeta,
            'acf_refs' => $includeAcfRefs ? $this->extractAcfReferences($cleanMeta) : [],
            'media' => [
                'featured_source_attachment_id' => (int) get_post_thumbnail_id($post->ID),
                'items' => $includeMedia ? $this->collectPostMedia($post, $cleanMeta) : [],
            ],
            'i18n' => $this->exportI18n($post),
        ];

        return apply_filters('content_bridge_360_export_item', $item, $post);
    }

    private function sanitizeExportMeta($meta) {
        $excludedMetaKeys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
        ];

        $clean = [];
        foreach ($meta as $metaKey => $metaValues) {
            if (in_array($metaKey, $excludedMetaKeys, true)) {
                continue;
            }
            $clean[$metaKey] = array_map('maybe_unserialize', $metaValues);
        }

        return $clean;
    }

    private function exportTaxonomies($postId, $postType) {
        $output = [];
        $taxonomies = get_object_taxonomies($postType, 'names');

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($postId, $taxonomy, ['fields' => 'all']);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $output[$taxonomy] = array_map(static function ($term) {
                return [
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'parent' => (int) $term->parent,
                ];
            }, $terms);
        }

        return $output;
    }

    private function extractAcfReferences($meta) {
        $acfRefs = [];

        foreach ($meta as $metaKey => $metaValues) {
            if (strpos($metaKey, '_') === 0) {
                continue;
            }

            $refKey = '_' . $metaKey;
            if (empty($meta[$refKey][0]) || !is_string($meta[$refKey][0])) {
                continue;
            }

            $fieldKey = $meta[$refKey][0];
            if (strpos($fieldKey, 'field_') !== 0) {
                continue;
            }

            $fieldType = '';
            if (function_exists('acf_get_field')) {
                $field = acf_get_field($fieldKey);
                if (is_array($field) && !empty($field['type'])) {
                    $fieldType = (string) $field['type'];
                }
            }

            $acfRefs[$metaKey] = [
                'field_key' => $fieldKey,
                'field_type' => $fieldType,
            ];
        }

        return $acfRefs;
    }

    private function collectPostMedia($post, $meta) {
        $ids = [];

        $featuredId = get_post_thumbnail_id($post->ID);
        if ($featuredId) {
            $ids[] = (int) $featuredId;
        }

        $children = get_children([
            'post_parent' => $post->ID,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
        ]);
        if (!empty($children)) {
            $ids = array_merge($ids, array_map('intval', $children));
        }

        $ids = array_merge($ids, $this->extractAttachmentIdsFromContent($post->post_content));
        $ids = array_merge($ids, $this->extractAttachmentIdsFromMeta($meta));

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $mediaItems = [];
        foreach ($ids as $attachmentId) {
            if (get_post_type($attachmentId) !== 'attachment') {
                continue;
            }

            $mediaItem = $this->buildMediaItem($attachmentId);
            if (!empty($mediaItem)) {
                $mediaItems[] = $mediaItem;
            }
        }

        return $mediaItems;
    }

    private function extractAttachmentIdsFromContent($content) {
        $ids = [];

        if (!is_string($content) || $content === '') {
            return $ids;
        }

        if (preg_match_all('/wp-image-([0-9]+)/', $content, $matches)) {
            $ids = array_merge($ids, array_map('intval', $matches[1]));
        }

        if (preg_match_all('/https?:\\/\\/[^\"\'\s<>]+/i', $content, $urlMatches)) {
            foreach ($urlMatches[0] as $url) {
                $attachmentId = attachment_url_to_postid($url);
                if ($attachmentId) {
                    $ids[] = (int) $attachmentId;
                }
            }
        }

        return $ids;
    }

    private function extractAttachmentIdsFromMeta($meta) {
        $ids = [];

        foreach ($meta as $metaKey => $metaValues) {
            if (!is_array($metaValues)) {
                $metaValues = [$metaValues];
            }

            $relationLikely = $this->isRelationLikeMetaKey($metaKey);
            foreach ($metaValues as $value) {
                $found = [];
                $this->collectIdsRecursively($value, $found, $relationLikely);
                foreach ($found as $maybeId) {
                    if (get_post_type($maybeId) === 'attachment') {
                        $ids[] = (int) $maybeId;
                    }
                }
            }
        }

        return $ids;
    }

    private function collectIdsRecursively($value, &$ids, $strict = false) {
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->collectIdsRecursively($child, $ids, $strict);
            }
            return;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $child) {
                $this->collectIdsRecursively($child, $ids, $strict);
            }
            return;
        }

        if (is_numeric($value)) {
            $intValue = (int) $value;
            if ($intValue > 0) {
                if ($strict || $intValue > 10) {
                    $ids[] = $intValue;
                }
            }
        }
    }

    private function buildMediaItem($attachmentId) {
        $url = wp_get_attachment_url($attachmentId);
        if (!$url) {
            return [];
        }

        $attachment = get_post($attachmentId);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return [];
        }

        return [
            'source_attachment_id' => (int) $attachmentId,
            'source_url' => $url,
            'source_url_normalized' => $this->normalizeUrl($url),
            'post_mime_type' => (string) get_post_mime_type($attachmentId),
            'post_title' => (string) $attachment->post_title,
            'post_name' => (string) $attachment->post_name,
            'post_excerpt' => (string) $attachment->post_excerpt,
            'post_content' => (string) $attachment->post_content,
            'post_date' => (string) $attachment->post_date,
            'alt' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'metadata' => wp_get_attachment_metadata($attachmentId),
        ];
    }

    private function exportI18n($post) {
        $data = [
            'language_code' => '',
            'translation_group_key' => '',
        ];

        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post->ID, 'slug');
            if (is_string($lang) && $lang !== '') {
                $data['language_code'] = $lang;
            }

            if (function_exists('pll_get_post_translations')) {
                $translations = pll_get_post_translations($post->ID);
                if (is_array($translations) && !empty($translations)) {
                    $ids = array_values(array_filter(array_map('intval', $translations)));
                    if (!empty($ids)) {
                        sort($ids);
                        $data['translation_group_key'] = 'pll:' . $ids[0];
                    }
                }
            }

            return $data;
        }

        if (has_filter('wpml_post_language_details')) {
            $langDetails = apply_filters('wpml_post_language_details', null, $post->ID);
            if (is_array($langDetails) && !empty($langDetails['language_code'])) {
                $data['language_code'] = (string) $langDetails['language_code'];
            }

            $trid = apply_filters('wpml_element_trid', null, $post->ID, 'post_' . $post->post_type);
            if (!empty($trid)) {
                $data['translation_group_key'] = 'wpml:' . (int) $trid;
            }
        }

        return $data;
    }

    public function handleImport() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html($this->tr('Access denied.', 'Acces refuse.')));
        }

        check_admin_referer('content_bridge_360_import_nonce');

        if (empty($_FILES['import_file']['tmp_name'])) {
            $this->setNotice($this->tr('No import file uploaded.', 'Aucun fichier importe.'), 'error');
            $this->redirectToPage();
        }

        $raw = file_get_contents($_FILES['import_file']['tmp_name']);
        if ($raw === false || $raw === '') {
            $this->setNotice($this->tr('Unable to read import file.', 'Impossible de lire le fichier d import.'), 'error');
            $this->redirectToPage();
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
            $this->setNotice($this->tr('Invalid JSON structure.', 'Structure JSON invalide.'), 'error');
            $this->redirectToPage();
        }

        $sourceSiteUrl = '';
        if (!empty($payload['meta']['site_url']) && is_string($payload['meta']['site_url'])) {
            $sourceSiteUrl = untrailingslashit($payload['meta']['site_url']);
        }

        $options = $this->readImportOptions();
        if ($options['mapping_error']) {
            $this->setNotice($this->tr('Mapping JSON is invalid.', 'Le JSON de mapping est invalide.'), 'error');
            $this->redirectToPage();
        }

        if (!empty($options['dry_run'])) {
            $report = $this->buildDryRunReport($payload, $options, $sourceSiteUrl);
            $this->setNotice($this->tr('Dry-run complete. No content was written.', 'Simulation terminee. Aucun contenu n a ete ecrit.'), 'info', $report);
            $this->redirectToPage();
        }

        $created = 0;
        $updated = 0;
        $failed = 0;

        $postIdMap = [];
        $mediaIdMap = [];
        $mediaUrlMap = [];
        $mediaErrors = [];
        $translationGroups = [];

        $items = $payload['items'];
        $totalItems = count($items);
        $batchSize = !empty($options['batch_size']) ? (int) $options['batch_size'] : 0;
        $resumeToken = !empty($options['resume_token']) ? $options['resume_token'] : '';
        $startIndex = 0;
        $payloadHash = md5($raw);

        if ($batchSize > 0) {
            if ($resumeToken !== '') {
                $state = $this->loadResumeState($resumeToken);
                if (empty($state) || !is_array($state)) {
                    $this->setNotice($this->tr('Invalid resume token.', 'Token de reprise invalide.'), 'error');
                    $this->redirectToPage();
                }

                if (empty($state['payload_hash']) || $state['payload_hash'] !== $payloadHash) {
                    $this->setNotice($this->tr('Resume token does not match this JSON file.', 'Le token de reprise ne correspond pas a ce fichier JSON.'), 'error');
                    $this->redirectToPage();
                }

                $startIndex = !empty($state['next_index']) ? (int) $state['next_index'] : 0;
                $created = !empty($state['created']) ? (int) $state['created'] : 0;
                $updated = !empty($state['updated']) ? (int) $state['updated'] : 0;
                $failed = !empty($state['failed']) ? (int) $state['failed'] : 0;
                $postIdMap = !empty($state['post_id_map']) && is_array($state['post_id_map']) ? $state['post_id_map'] : [];
                $mediaIdMap = !empty($state['media_id_map']) && is_array($state['media_id_map']) ? $state['media_id_map'] : [];
                $mediaUrlMap = !empty($state['media_url_map']) && is_array($state['media_url_map']) ? $state['media_url_map'] : [];
                $translationGroups = !empty($state['translation_groups']) && is_array($state['translation_groups']) ? $state['translation_groups'] : [];
                $mediaErrors = !empty($state['media_errors']) && is_array($state['media_errors']) ? $state['media_errors'] : [];
            } else {
                $resumeToken = strtolower(wp_generate_password(12, false, false));
            }
        }

        $endIndex = $batchSize > 0 ? min($startIndex + $batchSize, $totalItems) : $totalItems;
        $pending = [];

        for ($index = $startIndex; $index < $endIndex; $index++) {
            $item = $items[$index];
            if (empty($item['post']) || !is_array($item['post'])) {
                $failed++;
                continue;
            }

            $shell = $this->importPostShell($item, $options, $sourceSiteUrl);
            if ($shell['status'] === 'failed') {
                $failed++;
                continue;
            }

            if ($shell['status'] === 'created') {
                $created++;
            } else {
                $updated++;
            }

            if (!empty($shell['source_post_id'])) {
                $postIdMap[(int) $shell['source_post_id']] = (int) $shell['post_id'];
            }

            $pending[] = [
                'index' => $index,
                'post_id' => (int) $shell['post_id'],
                'item' => $item,
            ];
        }

        foreach ($pending as $row) {
            $postId = (int) $row['post_id'];
            $item = $row['item'];

            do_action('content_bridge_360_before_import_item', $postId, $item, $options);

            if ($options['import_media'] && !empty($item['media']['items']) && is_array($item['media']['items'])) {
                $this->importMediaBundle($item['media']['items'], $postId, $mediaIdMap, $mediaUrlMap, $mediaErrors);
            }

            $this->importTaxonomies($postId, $item, $options['mapping']);
            $this->importMeta($postId, $item, $options, $postIdMap, $mediaIdMap, $mediaUrlMap);
            $this->importFeaturedImage($postId, $item, $options, $mediaIdMap);
            $this->relinkPostContent($postId, $item, $mediaUrlMap);
            $this->applyI18n($postId, $item, $translationGroups);

            do_action('content_bridge_360_after_import_item', $postId, $item, $options);
        }

        if ($batchSize > 0 && $endIndex < $totalItems) {
            $state = [
                'payload_hash' => $payloadHash,
                'next_index' => $endIndex,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
                'post_id_map' => $postIdMap,
                'media_id_map' => $mediaIdMap,
                'media_url_map' => $mediaUrlMap,
                'translation_groups' => $translationGroups,
                'media_errors' => $mediaErrors,
                'updated_at' => current_time('mysql'),
            ];

            $this->saveResumeState($resumeToken, $state);

            $partialMessage = sprintf(
                $this->tr('Batch imported %d/%d items. Resume token: %s', 'Lot importe %d/%d elements. Token de reprise : %s'),
                $endIndex,
                $totalItems,
                $resumeToken
            );

            $partialDetails = $this->tr(
                'Re-run import with the same JSON and this token to continue from next batch.',
                'Relance l import avec le meme JSON et ce token pour continuer au lot suivant.'
            );

            $this->setNotice($partialMessage, 'warning', $partialDetails);
            $this->redirectToPage();
        }

        if ($batchSize > 0 && $resumeToken !== '') {
            $this->deleteResumeState($resumeToken);
        }

        $summary = sprintf(
            $this->tr('Import complete. Created: %d | Updated: %d | Failed: %d', 'Import termine. Crees : %d | Mis a jour : %d | Echecs : %d'),
            $created,
            $updated,
            $failed
        );

        $details = '';
        if (!empty($mediaErrors)) {
            $summary .= sprintf($this->tr(' | Media errors: %d', ' | Erreurs medias : %d'), count($mediaErrors));
            $details = $this->tr('Media import issues (content was still imported):', 'Problemes d import media (le contenu a quand meme ete importe) :') . "\n" . implode("\n", array_slice($mediaErrors, 0, 30));
            if (count($mediaErrors) > 30) {
                $details .= "\n" . $this->tr('... and ', '... et ') . (count($mediaErrors) - 30) . $this->tr(' more.', ' autres.');
            }
        }

        $this->setNotice(
            $summary,
            ($failed > 0 || !empty($mediaErrors)) ? 'warning' : 'success',
            $details
        );

        $this->redirectToPage();
    }

    private function readImportOptions() {
        $mode = isset($_POST['import_mode']) ? sanitize_key(wp_unslash($_POST['import_mode'])) : 'create';
        if (!in_array($mode, ['create', 'upsert'], true)) {
            $mode = 'create';
        }

        $matchBy = isset($_POST['match_by']) ? array_map('sanitize_key', (array) wp_unslash($_POST['match_by'])) : ['slug', 'post_name'];
        $allowedMatch = ['slug', 'post_name', 'meta'];
        $matchBy = array_values(array_intersect($matchBy, $allowedMatch));

        $uniqueMetaKey = isset($_POST['unique_meta_key']) ? sanitize_key(wp_unslash($_POST['unique_meta_key'])) : '';

        $mappingRaw = isset($_POST['mapping_json']) ? trim((string) wp_unslash($_POST['mapping_json'])) : '';
        $mappingParsed = $this->parseMappings($mappingRaw);
        $batchSize = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 0;
        $resumeToken = isset($_POST['resume_token']) ? sanitize_key(wp_unslash($_POST['resume_token'])) : '';

        return [
            'mode' => $mode,
            'match_by' => $matchBy,
            'unique_meta_key' => $uniqueMetaKey,
            'preserve_missing' => !empty($_POST['preserve_missing']),
            'dry_run' => !empty($_POST['dry_run']),
            'import_media' => !empty($_POST['import_media']),
            'import_featured_image' => !empty($_POST['import_featured_image']),
            'batch_size' => $batchSize,
            'resume_token' => $resumeToken,
            'mapping' => $mappingParsed['mapping'],
            'mapping_error' => $mappingParsed['error'],
        ];
    }

    private function buildDryRunReport($payload, $options, $sourceSiteUrl) {
        $lines = [];
        $lines[] = '360 Content Bridge - Dry Run Report';
        $lines[] = 'Date: ' . current_time('mysql');
        $lines[] = 'Items in file: ' . count($payload['items']);
        $lines[] = 'Mode: ' . $options['mode'];
        $lines[] = 'Media import: ' . (!empty($options['import_media']) ? 'yes' : 'no');
        $lines[] = 'Featured image: ' . (!empty($options['import_featured_image']) ? 'yes' : 'no');
        $lines[] = str_repeat('-', 60);

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($payload['items'] as $index => $item) {
            if (empty($item['post']) || !is_array($item['post']) || empty($item['post']['post_type'])) {
                $failed++;
                $lines[] = '#'.($index + 1).': invalid post payload';
                continue;
            }

            $postData = $item['post'];
            $sourceType = sanitize_key($postData['post_type']);
            $targetType = $this->mapValue($sourceType, $options['mapping']['post_type']);
            if (!post_type_exists($targetType)) {
                $failed++;
                $lines[] = '#'.($index + 1).': post_type ' . $sourceType . ' -> ' . $targetType . ' missing on target';
                continue;
            }

            $shellInput = [
                'post_type' => $targetType,
                'post_name' => !empty($postData['post_name']) ? sanitize_title($postData['post_name']) : '',
            ];

            $existingId = 0;
            if ($options['mode'] === 'upsert') {
                $existingId = $this->findExistingPostId($shellInput, $item, $options, $sourceSiteUrl, !empty($postData['source_post_id']) ? (int) $postData['source_post_id'] : 0);
            }

            if ($existingId > 0) {
                $updated++;
                $lines[] = '#'.($index + 1).': UPDATE -> [' . $targetType . '] ' . (!empty($postData['post_title']) ? $postData['post_title'] : '(no title)') . ' (ID ' . $existingId . ')';
            } else {
                $created++;
                $lines[] = '#'.($index + 1).': CREATE -> [' . $targetType . '] ' . (!empty($postData['post_title']) ? $postData['post_title'] : '(no title)');
            }

            if (!empty($item['media']['items']) && is_array($item['media']['items'])) {
                $mediaTotal = count($item['media']['items']);
                $mediaReuse = 0;
                foreach ($item['media']['items'] as $mediaItem) {
                    if (empty($mediaItem['source_url'])) {
                        continue;
                    }
                    $sourceUrl = (string) $mediaItem['source_url'];
                    $sourceUrlNormalized = !empty($mediaItem['source_url_normalized']) ? (string) $mediaItem['source_url_normalized'] : $this->normalizeUrl($sourceUrl);
                    $sourceId = !empty($mediaItem['source_attachment_id']) ? (int) $mediaItem['source_attachment_id'] : 0;
                    if ($this->findExistingImportedAttachment($sourceUrl, $sourceUrlNormalized, $sourceId) > 0) {
                        $mediaReuse++;
                    }
                }
                $lines[] = '    media: ' . $mediaReuse . ' existing, ' . max(0, $mediaTotal - $mediaReuse) . ' to import';
            }
        }

        $lines[] = str_repeat('-', 60);
        $lines[] = 'Summary: create=' . $created . ' update=' . $updated . ' failed=' . $failed;

        return implode("\n", $lines);
    }

    private function parseMappings($mappingRaw) {
        $empty = [
            'post_type' => [],
            'taxonomy' => [],
            'meta' => [],
            'acf_field_key' => [],
            'acf_field_name' => [],
        ];

        if ($mappingRaw === '') {
            return ['mapping' => $empty, 'error' => false];
        }

        $decoded = json_decode($mappingRaw, true);
        if (!is_array($decoded)) {
            return ['mapping' => $empty, 'error' => true];
        }

        foreach ($empty as $key => $void) {
            if (empty($decoded[$key]) || !is_array($decoded[$key])) {
                $decoded[$key] = [];
                continue;
            }

            $normalized = [];
            foreach ($decoded[$key] as $from => $to) {
                $fromKey = sanitize_key((string) $from);
                $toKey = sanitize_key((string) $to);
                if ($fromKey !== '' && $toKey !== '') {
                    $normalized[$fromKey] = $toKey;
                }
            }
            $decoded[$key] = $normalized;
        }

        return ['mapping' => $decoded, 'error' => false];
    }

    private function importPostShell($item, $options, $sourceSiteUrl) {
        $postData = $item['post'];

        if (empty($postData['post_type'])) {
            return ['status' => 'failed'];
        }

        $targetPostType = $this->mapValue(
            sanitize_key($postData['post_type']),
            $options['mapping']['post_type']
        );

        if (!post_type_exists($targetPostType)) {
            return ['status' => 'failed'];
        }

        $status = !empty($postData['post_status']) ? sanitize_key($postData['post_status']) : 'draft';
        if (!in_array($status, get_post_stati(), true)) {
            $status = 'draft';
        }

        $postName = !empty($postData['post_name']) ? sanitize_title($postData['post_name']) : '';

        $insertData = [
            'post_type' => $targetPostType,
            'post_status' => $status,
            'post_title' => !empty($postData['post_title']) ? wp_slash($postData['post_title']) : '',
            'post_name' => $postName,
            'post_excerpt' => !empty($postData['post_excerpt']) ? wp_slash($postData['post_excerpt']) : '',
            'post_content' => !empty($postData['post_content']) ? wp_slash($postData['post_content']) : '',
            'menu_order' => isset($postData['menu_order']) ? (int) $postData['menu_order'] : 0,
            'comment_status' => !empty($postData['comment_status']) ? sanitize_key($postData['comment_status']) : 'closed',
            'ping_status' => !empty($postData['ping_status']) ? sanitize_key($postData['ping_status']) : 'closed',
            'post_date' => !empty($postData['post_date']) ? sanitize_text_field($postData['post_date']) : current_time('mysql'),
        ];

        $insertData = apply_filters('content_bridge_360_pre_insert_data', $insertData, $item, $options);

        $existingPostId = 0;
        $sourcePostId = !empty($postData['source_post_id']) ? (int) $postData['source_post_id'] : 0;

        if ($options['mode'] === 'upsert') {
            $existingPostId = $this->findExistingPostId($insertData, $item, $options, $sourceSiteUrl, $sourcePostId);
        }

        if ($existingPostId > 0) {
            $insertData['ID'] = $existingPostId;
            $postId = wp_update_post($insertData, true);
            $state = 'updated';
        } else {
            $postId = wp_insert_post($insertData, true);
            $state = 'created';
        }

        if (is_wp_error($postId) || !$postId) {
            return ['status' => 'failed'];
        }

        if ($sourcePostId > 0 && $sourceSiteUrl !== '') {
            update_post_meta($postId, self::ORIGIN_FINGERPRINT_META, $this->buildOriginFingerprint($sourceSiteUrl, $sourcePostId));
        }

        return [
            'status' => $state,
            'post_id' => (int) $postId,
            'source_post_id' => $sourcePostId,
        ];
    }

    private function findExistingPostId($insertData, $item, $options, $sourceSiteUrl, $sourcePostId) {
        if ($sourcePostId > 0 && $sourceSiteUrl !== '') {
            $fingerprint = $this->buildOriginFingerprint($sourceSiteUrl, $sourcePostId);
            $foundByFingerprint = get_posts([
                'post_type' => $insertData['post_type'],
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => self::ORIGIN_FINGERPRINT_META,
                        'value' => $fingerprint,
                    ],
                ],
            ]);

            if (!empty($foundByFingerprint[0])) {
                return (int) $foundByFingerprint[0];
            }
        }

        if (in_array('slug', $options['match_by'], true) || in_array('post_name', $options['match_by'], true)) {
            if (!empty($insertData['post_name'])) {
                $existing = get_page_by_path($insertData['post_name'], OBJECT, $insertData['post_type']);
                if ($existing && !empty($existing->ID)) {
                    return (int) $existing->ID;
                }
            }
        }

        if (in_array('meta', $options['match_by'], true) && !empty($options['unique_meta_key'])) {
            $sourceMetaKey = $options['unique_meta_key'];
            $targetMetaKey = $this->mapMetaKey($sourceMetaKey, $options['mapping']);

            if (!empty($item['meta'][$sourceMetaKey][0])) {
                $uniqueValue = (string) $item['meta'][$sourceMetaKey][0];
                $foundByMeta = get_posts([
                    'post_type' => $insertData['post_type'],
                    'post_status' => 'any',
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'meta_query' => [
                        [
                            'key' => $targetMetaKey,
                            'value' => $uniqueValue,
                        ],
                    ],
                ]);

                if (!empty($foundByMeta[0])) {
                    return (int) $foundByMeta[0];
                }
            }
        }

        return 0;
    }

    private function importTaxonomies($postId, $item, $mapping) {
        if (empty($item['taxonomies']) || !is_array($item['taxonomies'])) {
            return;
        }

        foreach ($item['taxonomies'] as $sourceTaxonomy => $terms) {
            $targetTaxonomy = $this->mapValue(sanitize_key($sourceTaxonomy), $mapping['taxonomy']);
            if (!taxonomy_exists($targetTaxonomy) || !is_array($terms)) {
                continue;
            }

            $termIds = [];
            foreach ($terms as $termData) {
                $slug = !empty($termData['slug']) ? sanitize_title($termData['slug']) : '';
                $name = !empty($termData['name']) ? sanitize_text_field($termData['name']) : '';
                if ($name === '' && $slug === '') {
                    continue;
                }

                $term = $slug !== '' ? get_term_by('slug', $slug, $targetTaxonomy) : false;
                if (!$term && $name !== '') {
                    $inserted = wp_insert_term($name, $targetTaxonomy, [
                        'slug' => $slug,
                        'description' => !empty($termData['description']) ? wp_strip_all_tags($termData['description']) : '',
                    ]);
                    if (!is_wp_error($inserted) && !empty($inserted['term_id'])) {
                        $termIds[] = (int) $inserted['term_id'];
                    }
                    continue;
                }

                if ($term && !is_wp_error($term)) {
                    $termIds[] = (int) $term->term_id;
                }
            }

            if (!empty($termIds)) {
                wp_set_object_terms($postId, $termIds, $targetTaxonomy, false);
            }
        }
    }

    private function importMeta($postId, $item, $options, $postIdMap, $mediaIdMap, $mediaUrlMap) {
        if (empty($item['meta']) || !is_array($item['meta'])) {
            return;
        }

        $acfRefs = !empty($item['acf_refs']) && is_array($item['acf_refs']) ? $item['acf_refs'] : [];

        foreach ($item['meta'] as $sourceMetaKey => $metaValues) {
            $targetMetaKey = $this->mapMetaKey($sourceMetaKey, $options['mapping']);

            if ($targetMetaKey === '' || $targetMetaKey === '_thumbnail_id') {
                continue;
            }

            if (!is_array($metaValues)) {
                $metaValues = [$metaValues];
            }

            $isUnderscoreReference = strpos($sourceMetaKey, '_') === 0;
            $linkedFieldName = $isUnderscoreReference ? ltrim($sourceMetaKey, '_') : $sourceMetaKey;

            $fieldType = '';
            if (!empty($acfRefs[$linkedFieldName]['field_type'])) {
                $fieldType = sanitize_key($acfRefs[$linkedFieldName]['field_type']);
            }

            $relationMode = $this->relationModeForField($sourceMetaKey, $fieldType);

            $normalizedValues = [];
            foreach ($metaValues as $value) {
                $normalizedValues[] = $this->normalizeMetaValue(
                    $value,
                    $relationMode,
                    $postIdMap,
                    $mediaIdMap,
                    $mediaUrlMap,
                    $options,
                    $isUnderscoreReference
                );
            }

            delete_post_meta($postId, $targetMetaKey);
            foreach ($normalizedValues as $normalizedValue) {
                add_post_meta($postId, $targetMetaKey, $normalizedValue);
            }
        }
    }

    private function relationModeForField($metaKey, $fieldType) {
        $key = strtolower($metaKey);

        if ($fieldType !== '') {
            if (in_array($fieldType, ['relationship', 'post_object', 'page_link'], true)) {
                return 'post';
            }
            if (in_array($fieldType, ['gallery', 'image', 'file'], true)) {
                return 'media';
            }
        }

        if (strpos($key, 'relationship') !== false || strpos($key, 'post_object') !== false) {
            return 'post';
        }

        if (strpos($key, 'gallery') !== false || strpos($key, 'image') !== false || strpos($key, 'media') !== false || strpos($key, 'attachment') !== false) {
            return 'media';
        }

        return 'mixed';
    }

    private function normalizeMetaValue($value, $relationMode, $postIdMap, $mediaIdMap, $mediaUrlMap, $options, $isUnderscoreReference = false) {
        if ($isUnderscoreReference && is_string($value) && strpos($value, 'field_') === 0) {
            if (!empty($options['mapping']['acf_field_key'][$value])) {
                return $options['mapping']['acf_field_key'][$value];
            }
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = $this->normalizeMetaValue($v, $relationMode, $postIdMap, $mediaIdMap, $mediaUrlMap, $options, false);
            }
            return $normalized;
        }

        if (is_object($value)) {
            $normalized = new stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $normalized->{$k} = $this->normalizeMetaValue($v, $relationMode, $postIdMap, $mediaIdMap, $mediaUrlMap, $options, false);
            }
            return $normalized;
        }

        if (is_numeric($value)) {
            $id = (int) $value;

            if (($relationMode === 'post' || $relationMode === 'mixed') && !empty($postIdMap[$id])) {
                return (int) $postIdMap[$id];
            }

            if (($relationMode === 'media' || $relationMode === 'mixed') && !empty($mediaIdMap[$id])) {
                return (int) $mediaIdMap[$id];
            }

            return $id;
        }

        if (is_string($value)) {
            return $this->replaceMediaUrls($value, $mediaUrlMap);
        }

        return $value;
    }

    private function importFeaturedImage($postId, $item, $options, $mediaIdMap) {
        if (empty($options['import_featured_image'])) {
            return;
        }

        if (!empty($item['media']['featured_source_attachment_id'])) {
            $sourceFeaturedId = (int) $item['media']['featured_source_attachment_id'];
            if (!empty($mediaIdMap[$sourceFeaturedId])) {
                set_post_thumbnail($postId, (int) $mediaIdMap[$sourceFeaturedId]);
            }
        }
    }

    private function applyI18n($postId, $item, &$translationGroups) {
        if (empty($item['i18n']) || !is_array($item['i18n'])) {
            return;
        }

        $lang = !empty($item['i18n']['language_code']) ? sanitize_key($item['i18n']['language_code']) : '';
        $group = !empty($item['i18n']['translation_group_key']) ? sanitize_text_field($item['i18n']['translation_group_key']) : '';

        if ($lang === '' || $group === '') {
            return;
        }

        if (function_exists('pll_set_post_language') && function_exists('pll_save_post_translations')) {
            pll_set_post_language($postId, $lang);
            if (empty($translationGroups[$group]) || !is_array($translationGroups[$group])) {
                $translationGroups[$group] = [];
            }
            $translationGroups[$group][$lang] = $postId;

            if (count($translationGroups[$group]) >= 2) {
                pll_save_post_translations($translationGroups[$group]);
            }
            return;
        }

        if (has_action('wpml_set_element_language_details')) {
            $postType = get_post_type($postId);
            if (!$postType) {
                return;
            }

            $elementType = 'post_' . $postType;
            $targetTrid = null;

            if (!empty($translationGroups[$group]['trid'])) {
                $targetTrid = (int) $translationGroups[$group]['trid'];
            }

            do_action('wpml_set_element_language_details', [
                'element_id' => $postId,
                'element_type' => $elementType,
                'trid' => $targetTrid,
                'language_code' => $lang,
                'source_language_code' => null,
            ]);

            $assignedTrid = apply_filters('wpml_element_trid', null, $postId, $elementType);
            if (!empty($assignedTrid)) {
                $translationGroups[$group]['trid'] = (int) $assignedTrid;
            }
        }
    }

    private function relinkPostContent($postId, $item, $mediaUrlMap) {
        if (empty($item['post']) || !is_array($item['post'])) {
            return;
        }

        $content = isset($item['post']['post_content']) ? (string) $item['post']['post_content'] : '';
        $excerpt = isset($item['post']['post_excerpt']) ? (string) $item['post']['post_excerpt'] : '';

        $content = $this->replaceMediaUrls($content, $mediaUrlMap);
        $excerpt = $this->replaceMediaUrls($excerpt, $mediaUrlMap);

        wp_update_post([
            'ID' => $postId,
            'post_content' => wp_slash($content),
            'post_excerpt' => wp_slash($excerpt),
        ]);
    }

    private function replaceMediaUrls($text, $mediaUrlMap) {
        if (!is_string($text) || $text === '' || empty($mediaUrlMap)) {
            return $text;
        }

        foreach ($mediaUrlMap as $old => $new) {
            if ($old !== '' && $new !== '') {
                $text = str_replace($old, $new, $text);
            }
        }

        return $text;
    }

    private function importMediaBundle($mediaItems, $postId, &$mediaIdMap, &$mediaUrlMap, &$mediaErrors = []) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($mediaItems as $mediaItem) {
            if (empty($mediaItem['source_url'])) {
                continue;
            }

            $sourceId = !empty($mediaItem['source_attachment_id']) ? (int) $mediaItem['source_attachment_id'] : 0;
            $sourceUrl = (string) $mediaItem['source_url'];
            $sourceUrlNormalized = !empty($mediaItem['source_url_normalized']) ? (string) $mediaItem['source_url_normalized'] : $this->normalizeUrl($sourceUrl);

            if ($sourceId > 0 && !empty($mediaIdMap[$sourceId])) {
                continue;
            }

            $existingAttachmentId = $this->findExistingImportedAttachment($sourceUrl, $sourceUrlNormalized, $sourceId);

            if ($existingAttachmentId <= 0) {
                $existingAttachmentId = $this->sideloadAttachment($sourceUrl, $postId, $mediaItem, $mediaErrors);
            }

            if ($existingAttachmentId <= 0) {
                continue;
            }

            update_post_meta($existingAttachmentId, self::SOURCE_URL_META, $sourceUrl);
            update_post_meta($existingAttachmentId, self::SOURCE_ATTACHMENT_ID_META, $sourceId);

            if (!empty($mediaItem['alt'])) {
                update_post_meta($existingAttachmentId, '_wp_attachment_image_alt', sanitize_text_field($mediaItem['alt']));
            }

            $newUrl = wp_get_attachment_url($existingAttachmentId);

            if ($sourceId > 0) {
                $mediaIdMap[$sourceId] = (int) $existingAttachmentId;
            }

            if ($sourceUrl !== '' && $newUrl) {
                $mediaUrlMap[$sourceUrl] = $newUrl;
                $mediaUrlMap[$sourceUrlNormalized] = $newUrl;
            }
        }
    }

    private function findExistingImportedAttachment($sourceUrl, $sourceUrlNormalized, $sourceId) {
        $idByMetaUrl = $this->findAttachmentByMeta(self::SOURCE_URL_META, $sourceUrl);
        if ($idByMetaUrl > 0) {
            return $idByMetaUrl;
        }

        if ($sourceUrlNormalized !== $sourceUrl) {
            $idByMetaUrlNorm = $this->findAttachmentByMeta(self::SOURCE_URL_META, $sourceUrlNormalized);
            if ($idByMetaUrlNorm > 0) {
                return $idByMetaUrlNorm;
            }
        }

        if ($sourceId > 0) {
            $idBySourceId = $this->findAttachmentByMeta(self::SOURCE_ATTACHMENT_ID_META, (string) $sourceId);
            if ($idBySourceId > 0) {
                return $idBySourceId;
            }
        }

        $byNative = attachment_url_to_postid($sourceUrl);
        if ($byNative) {
            return (int) $byNative;
        }

        return 0;
    }

    private function findAttachmentByMeta($metaKey, $metaValue) {
        $found = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $metaKey,
                    'value' => (string) $metaValue,
                ],
            ],
        ]);

        if (!empty($found[0])) {
            return (int) $found[0];
        }

        return 0;
    }

    private function sideloadAttachment($sourceUrl, $postId, $mediaItem, &$mediaErrors = []) {
        $tmp = download_url($sourceUrl, 60);
        if (is_wp_error($tmp)) {
            $mediaErrors[] = sprintf('[download] %s | %s', $sourceUrl, $tmp->get_error_message());
            return 0;
        }

        $filename = wp_basename(parse_url($sourceUrl, PHP_URL_PATH));
        if (!$filename) {
            $filename = 'imported-media';
        }

        $fileArray = [
            'name' => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        $attachmentPostData = [
            'post_title' => !empty($mediaItem['post_title']) ? sanitize_text_field($mediaItem['post_title']) : '',
            'post_excerpt' => !empty($mediaItem['post_excerpt']) ? sanitize_text_field($mediaItem['post_excerpt']) : '',
            'post_content' => !empty($mediaItem['post_content']) ? sanitize_textarea_field($mediaItem['post_content']) : '',
        ];

        try {
            $attachmentId = media_handle_sideload($fileArray, $postId, null, $attachmentPostData);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $mediaErrors[] = sprintf('[sideload] %s | %s', $sourceUrl, $e->getMessage());
            return 0;
        }

        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            $mediaErrors[] = sprintf('[sideload] %s | %s', $sourceUrl, $attachmentId->get_error_message());
            return 0;
        }

        return (int) $attachmentId;
    }

    private function isRelationLikeMetaKey($metaKey) {
        $key = strtolower($metaKey);
        $needles = ['relationship', 'post_object', 'gallery', 'image', 'media', 'attachment', 'file'];

        foreach ($needles as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function mapMetaKey($metaKey, $mapping) {
        $normalized = sanitize_key($metaKey);
        if ($normalized === '') {
            return '';
        }

        $isRef = strpos($normalized, '_') === 0;
        $baseKey = $isRef ? ltrim($normalized, '_') : $normalized;

        if (!empty($mapping['acf_field_name'][$baseKey])) {
            $baseKey = $mapping['acf_field_name'][$baseKey];
        }

        if (!empty($mapping['meta'][$baseKey])) {
            $baseKey = $mapping['meta'][$baseKey];
        }

        if ($isRef) {
            return '_' . $baseKey;
        }

        return $baseKey;
    }

    private function mapValue($source, $map) {
        if (!empty($map[$source])) {
            return $map[$source];
        }
        return $source;
    }

    private function buildOriginFingerprint($sourceSiteUrl, $sourcePostId) {
        return md5(untrailingslashit($sourceSiteUrl) . '|' . (int) $sourcePostId);
    }

    private function normalizeUrl($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $scheme = !empty($parts['scheme']) ? $parts['scheme'] . '://' : 'https://';
        $host = $parts['host'];
        $port = !empty($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'];

        return $scheme . $host . $port . $path;
    }

    private function isFrenchUserLocale() {
        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        return is_string($locale) && strpos(strtolower($locale), 'fr') === 0;
    }

    private function tr($en, $fr) {
        return $this->isFrenchUserLocale() ? $fr : $en;
    }

    private function getResumeOptionKey($token) {
        return self::RESUME_OPTION_PREFIX . sanitize_key($token);
    }

    private function loadResumeState($token) {
        return get_option($this->getResumeOptionKey($token));
    }

    private function saveResumeState($token, $state) {
        update_option($this->getResumeOptionKey($token), $state, false);
    }

    private function deleteResumeState($token) {
        delete_option($this->getResumeOptionKey($token));
    }

    public function cliExport($args, $assocArgs) {
        $postTypes = !empty($assocArgs['post_types']) ? array_map('sanitize_key', explode(',', $assocArgs['post_types'])) : array_values(array_diff(get_post_types(['public' => true], 'names'), ['attachment']));
        $postStatuses = !empty($assocArgs['post_statuses']) ? array_map('sanitize_key', explode(',', $assocArgs['post_statuses'])) : ['publish'];
        $dateFrom = !empty($assocArgs['date_from']) ? sanitize_text_field($assocArgs['date_from']) : '';
        $dateTo = !empty($assocArgs['date_to']) ? sanitize_text_field($assocArgs['date_to']) : '';
        $limit = !empty($assocArgs['limit']) ? absint($assocArgs['limit']) : 0;
        $selectionMode = !empty($assocArgs['selection_mode']) ? sanitize_key($assocArgs['selection_mode']) : 'all';
        $latestCount = !empty($assocArgs['latest_count']) ? absint($assocArgs['latest_count']) : 10;
        $manualPostIds = !empty($assocArgs['manual_post_ids']) ? (string) $assocArgs['manual_post_ids'] : '';
        $includeMedia = empty($assocArgs['include_media']) || $assocArgs['include_media'] !== '0';
        $includeAcfRefs = empty($assocArgs['include_acf_refs']) || $assocArgs['include_acf_refs'] !== '0';
        $metaQueryJson = !empty($assocArgs['meta_query_json']) ? (string) $assocArgs['meta_query_json'] : '';
        $taxQueryJson = !empty($assocArgs['tax_query_json']) ? (string) $assocArgs['tax_query_json'] : '';
        $advancedFilters = $this->parseAdvancedExportFilters($metaQueryJson, $taxQueryJson);

        if ($advancedFilters['error']) {
            $this->cliNotify('error', 'Invalid meta_query_json or tax_query_json.');
            return;
        }

        $site = get_blog_details(get_current_blog_id());
        $payload = [
            'meta' => [
                'plugin' => '360 Content Bridge',
                'version' => self::VERSION,
                'site_name' => $site ? $site->blogname : get_bloginfo('name'),
                'site_url' => site_url('/'),
                'blog_id' => get_current_blog_id(),
                'exported_at' => current_time('mysql'),
                'post_types' => $postTypes,
                'post_statuses' => $postStatuses,
                'selection_mode' => $selectionMode,
                'latest_count' => $latestCount,
                'include_media' => $includeMedia,
                'include_acf_refs' => $includeAcfRefs,
                'advanced_filters' => $advancedFilters['filters'],
            ],
            'items' => [],
        ];

        $exportPosts = $this->getExportPosts($postTypes, $postStatuses, $dateFrom, $dateTo, $limit, $selectionMode, $latestCount, $manualPostIds, $advancedFilters['filters']);
        foreach ($exportPosts as $post) {
            $payload['items'][] = $this->buildExportItem($post, $includeMedia, $includeAcfRefs);
        }

        $output = !empty($assocArgs['output']) ? $assocArgs['output'] : (ABSPATH . 'cb360-export-' . gmdate('Ymd-His') . '.json');
        file_put_contents($output, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->cliNotify('success', 'Export written to: ' . $output . ' (items: ' . count($payload['items']) . ')');
    }

    public function cliImport($args, $assocArgs) {
        if (empty($assocArgs['input']) || !file_exists($assocArgs['input'])) {
            $this->cliNotify('error', 'Provide --input=/path/file.json');
            return;
        }

        $raw = file_get_contents($assocArgs['input']);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
            $this->cliNotify('error', 'Invalid JSON structure');
            return;
        }

        $options = [
            'mode' => !empty($assocArgs['mode']) ? sanitize_key($assocArgs['mode']) : 'upsert',
            'match_by' => !empty($assocArgs['match_by']) ? array_map('sanitize_key', explode(',', $assocArgs['match_by'])) : ['slug', 'post_name'],
            'unique_meta_key' => !empty($assocArgs['unique_meta_key']) ? sanitize_key($assocArgs['unique_meta_key']) : '',
            'preserve_missing' => true,
            'dry_run' => false,
            'import_media' => empty($assocArgs['import_media']) || $assocArgs['import_media'] !== '0',
            'import_featured_image' => empty($assocArgs['import_featured_image']) || $assocArgs['import_featured_image'] !== '0',
            'batch_size' => 0,
            'resume_token' => '',
            'mapping' => !empty($assocArgs['mapping_json']) && is_array(json_decode($assocArgs['mapping_json'], true)) ? json_decode($assocArgs['mapping_json'], true) : [
                'post_type' => [],
                'taxonomy' => [],
                'meta' => [],
                'acf_field_key' => [],
                'acf_field_name' => [],
            ],
            'mapping_error' => false,
        ];

        $sourceSiteUrl = !empty($payload['meta']['site_url']) && is_string($payload['meta']['site_url']) ? untrailingslashit($payload['meta']['site_url']) : '';

        $created = 0;
        $updated = 0;
        $failed = 0;
        $postIdMap = [];
        $mediaIdMap = [];
        $mediaUrlMap = [];
        $mediaErrors = [];
        $pending = [];
        $translationGroups = [];

        foreach ($payload['items'] as $index => $item) {
            if (empty($item['post']) || !is_array($item['post'])) {
                $failed++;
                continue;
            }

            $shell = $this->importPostShell($item, $options, $sourceSiteUrl);
            if ($shell['status'] === 'failed') {
                $failed++;
                continue;
            }

            if ($shell['status'] === 'created') {
                $created++;
            } else {
                $updated++;
            }

            if (!empty($shell['source_post_id'])) {
                $postIdMap[(int) $shell['source_post_id']] = (int) $shell['post_id'];
            }

            $pending[] = [
                'index' => $index,
                'post_id' => (int) $shell['post_id'],
                'item' => $item,
            ];
        }

        foreach ($pending as $row) {
            $postId = (int) $row['post_id'];
            $item = $row['item'];

            if ($options['import_media'] && !empty($item['media']['items']) && is_array($item['media']['items'])) {
                $this->importMediaBundle($item['media']['items'], $postId, $mediaIdMap, $mediaUrlMap, $mediaErrors);
            }

            $this->importTaxonomies($postId, $item, $options['mapping']);
            $this->importMeta($postId, $item, $options, $postIdMap, $mediaIdMap, $mediaUrlMap);
            $this->importFeaturedImage($postId, $item, $options, $mediaIdMap);
            $this->relinkPostContent($postId, $item, $mediaUrlMap);
            $this->applyI18n($postId, $item, $translationGroups);
        }

        $msg = sprintf('Import complete. Created=%d Updated=%d Failed=%d MediaErrors=%d', $created, $updated, $failed, count($mediaErrors));
        if (!empty($mediaErrors)) {
            $this->cliNotify('warning', $msg);
        } else {
            $this->cliNotify('success', $msg);
        }
    }

    private function cliNotify($level, $message) {
        if (!class_exists('WP_CLI')) {
            return;
        }

        if ($level === 'error') {
            call_user_func(['WP_CLI', 'error'], $message);
            return;
        }

        if ($level === 'warning') {
            call_user_func(['WP_CLI', 'warning'], $message);
            return;
        }

        call_user_func(['WP_CLI', 'success'], $message);
    }

    private function setNotice($message, $type = 'success', $details = '') {
        update_option(self::NOTICE_OPTION, [
            'message' => sanitize_text_field($message),
            'type' => sanitize_key($type),
            'details' => is_string($details) ? $details : '',
        ]);
    }

    public function renderNotice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_option(self::NOTICE_OPTION);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_option(self::NOTICE_OPTION);

        $type = in_array($notice['type'], ['success', 'error', 'warning', 'info'], true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p>';
        if (!empty($notice['details']) && is_string($notice['details'])) {
            echo '<pre style="max-height:320px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:10px;border:1px solid #dcdcde;">' . esc_html($notice['details']) . '</pre>';
        }
        echo '</div>';
    }

    private function redirectToPage() {
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit;
    }
}

$contentBridge360 = new ContentBridge360();
