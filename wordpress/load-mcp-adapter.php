<?php
/**
 * Load MCP Adapter, Abilities API, and register WordPress content management abilities.
 */

$base = ABSPATH . 'vendor';

$autoloader = $base . '/autoload.php';
if ( file_exists( $autoloader ) ) {
    require_once $autoloader;
}

define( 'WP_MCP_AUTOLOAD', false );

$abilities_api = $base . '/wordpress/abilities-api/abilities-api.php';
if ( file_exists( $abilities_api ) ) {
    require_once $abilities_api;
}

$mcp_adapter = $base . '/wordpress/mcp-adapter/mcp-adapter.php';
if ( file_exists( $mcp_adapter ) ) {
    require_once $mcp_adapter;
}

add_action( 'wp_abilities_api_categories_init', function () {
    wp_register_ability_category( 'content', array(
        'label' => 'Content', 'description' => 'Posts, pages, media, categories, tags, and SEO.',
    ));
    wp_register_ability_category( 'admin', array(
        'label' => 'Administration', 'description' => 'Plugins, users, settings, cache, and site management.',
    ));
});

add_action( 'wp_abilities_api_init', function () {

    $mcp_tool = array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) );
    $ro  = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
    $wr  = array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
    $up  = array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
    $del = array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );

    // Helper: resolve author input (ID, login, email, or display name) to a user ID.
    $resolve_author = function ( $author ) {
        if ( is_int( $author ) || ctype_digit( (string) $author ) ) {
            $user = get_userdata( (int) $author );
            if ( $user ) return (int) $user->ID;
            return new WP_Error( 'invalid_author', "No user found with ID {$author}." );
        }
        $author = sanitize_text_field( $author );
        $user = get_user_by( 'login', $author );
        if ( $user ) return (int) $user->ID;
        $user = get_user_by( 'email', $author );
        if ( $user ) return (int) $user->ID;
        $user = get_user_by( 'slug', sanitize_title( $author ) );
        if ( $user ) return (int) $user->ID;
        $found = get_users( array( 'search' => $author, 'search_columns' => array( 'display_name' ), 'number' => 1 ) );
        if ( ! empty( $found ) ) return (int) $found[0]->ID;
        return new WP_Error( 'invalid_author', "No user found matching \"{$author}\". Pass a user ID, login, email, slug, or display name." );
    };

    // Helper: resolve category names to IDs, creating missing ones.
    $resolve_cats = function ( array $names ) {
        $ids = array();
        foreach ( $names as $name ) {
            $term = get_term_by( 'name', $name, 'category' );
            if ( $term ) { $ids[] = $term->term_id; }
            else {
                $new = wp_insert_term( $name, 'category' );
                if ( ! is_wp_error( $new ) ) $ids[] = $new['term_id'];
            }
        }
        return $ids;
    };

    // Helper: apply Rank Math meta.
    $apply_rank_math = function ( int $post_id, array $rm ) {
        $map = array(
            'title' => 'rank_math_title', 'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword', 'canonical_url' => 'rank_math_canonical_url',
            'primary_category' => 'rank_math_primary_category', 'pillar_content' => 'rank_math_pillar_content',
            'breadcrumb_title' => 'rank_math_breadcrumb_title',
            'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
            'og_image' => 'rank_math_facebook_image',
            'twitter_title' => 'rank_math_twitter_title', 'twitter_description' => 'rank_math_twitter_description',
            'schema_type' => 'rank_math_rich_snippet',
        );
        foreach ( $map as $key => $meta_key ) {
            if ( isset( $rm[ $key ] ) ) update_post_meta( $post_id, $meta_key, sanitize_text_field( $rm[ $key ] ) );
        }
        if ( isset( $rm['robots'] ) && is_array( $rm['robots'] ) ) {
            update_post_meta( $post_id, 'rank_math_robots', array_map( 'sanitize_text_field', $rm['robots'] ) );
        }
    };

    // Helper: read Rank Math meta.
    $read_rank_math = function ( int $post_id ) {
        return array(
            'title'               => get_post_meta( $post_id, 'rank_math_title', true ),
            'description'         => get_post_meta( $post_id, 'rank_math_description', true ),
            'focus_keyword'       => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
            'canonical_url'       => get_post_meta( $post_id, 'rank_math_canonical_url', true ),
            'robots'              => get_post_meta( $post_id, 'rank_math_robots', true ),
            'primary_category'    => get_post_meta( $post_id, 'rank_math_primary_category', true ),
            'pillar_content'      => get_post_meta( $post_id, 'rank_math_pillar_content', true ),
            'breadcrumb_title'    => get_post_meta( $post_id, 'rank_math_breadcrumb_title', true ),
            'schema_type'         => get_post_meta( $post_id, 'rank_math_rich_snippet', true ),
            'og_title'            => get_post_meta( $post_id, 'rank_math_facebook_title', true ),
            'og_description'      => get_post_meta( $post_id, 'rank_math_facebook_description', true ),
            'og_image'            => get_post_meta( $post_id, 'rank_math_facebook_image', true ),
            'twitter_title'       => get_post_meta( $post_id, 'rank_math_twitter_title', true ),
            'twitter_description' => get_post_meta( $post_id, 'rank_math_twitter_description', true ),
        );
    };

    // Helper: apply Rank Math meta for taxonomy terms (categories/tags).
    $apply_term_rank_math = function ( int $term_id, array $rm ) {
        $map = array(
            'title' => 'rank_math_title', 'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword', 'canonical_url' => 'rank_math_canonical_url',
            'breadcrumb_title' => 'rank_math_breadcrumb_title',
            'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
            'og_image' => 'rank_math_facebook_image',
            'twitter_title' => 'rank_math_twitter_title', 'twitter_description' => 'rank_math_twitter_description',
        );
        foreach ( $map as $key => $meta_key ) {
            if ( isset( $rm[ $key ] ) ) update_term_meta( $term_id, $meta_key, sanitize_text_field( $rm[ $key ] ) );
        }
        if ( isset( $rm['robots'] ) && is_array( $rm['robots'] ) ) {
            update_term_meta( $term_id, 'rank_math_robots', array_map( 'sanitize_text_field', $rm['robots'] ) );
        }
    };

    // Helper: read Rank Math meta for taxonomy terms.
    $read_term_rank_math = function ( int $term_id ) {
        return array(
            'title'               => get_term_meta( $term_id, 'rank_math_title', true ),
            'description'         => get_term_meta( $term_id, 'rank_math_description', true ),
            'focus_keyword'       => get_term_meta( $term_id, 'rank_math_focus_keyword', true ),
            'canonical_url'       => get_term_meta( $term_id, 'rank_math_canonical_url', true ),
            'robots'              => get_term_meta( $term_id, 'rank_math_robots', true ),
            'breadcrumb_title'    => get_term_meta( $term_id, 'rank_math_breadcrumb_title', true ),
            'og_title'            => get_term_meta( $term_id, 'rank_math_facebook_title', true ),
            'og_description'      => get_term_meta( $term_id, 'rank_math_facebook_description', true ),
            'og_image'            => get_term_meta( $term_id, 'rank_math_facebook_image', true ),
            'twitter_title'       => get_term_meta( $term_id, 'rank_math_twitter_title', true ),
            'twitter_description' => get_term_meta( $term_id, 'rank_math_twitter_description', true ),
        );
    };

    // Rank Math SEO input schema for terms (categories/tags).
    $term_rank_math_schema = array(
        'type' => 'object', 'description' => 'Rank Math SEO settings for this category.',
        'properties' => array(
            'title'               => array( 'type' => 'string', 'description' => 'SEO title.' ),
            'description'         => array( 'type' => 'string', 'description' => 'Meta description.' ),
            'focus_keyword'       => array( 'type' => 'string', 'description' => 'Focus keyword(s), comma-separated.' ),
            'canonical_url'       => array( 'type' => 'string', 'description' => 'Canonical URL.' ),
            'robots'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Robots: index, noindex, follow, nofollow.' ),
            'breadcrumb_title'    => array( 'type' => 'string', 'description' => 'Custom breadcrumb title.' ),
            'og_title'            => array( 'type' => 'string', 'description' => 'Facebook/OG title.' ),
            'og_description'      => array( 'type' => 'string', 'description' => 'Facebook/OG description.' ),
            'og_image'            => array( 'type' => 'string', 'description' => 'Facebook/OG image URL.' ),
            'twitter_title'       => array( 'type' => 'string', 'description' => 'Twitter title.' ),
            'twitter_description' => array( 'type' => 'string', 'description' => 'Twitter description.' ),
        ),
    );

    // Rank Math SEO input schema (reused across create/update).
    $rank_math_schema = array(
        'type' => 'object', 'description' => 'Rank Math SEO settings.',
        'properties' => array(
            'title'               => array( 'type' => 'string', 'description' => 'SEO title.' ),
            'description'         => array( 'type' => 'string', 'description' => 'Meta description.' ),
            'focus_keyword'       => array( 'type' => 'string', 'description' => 'Focus keyword(s), comma-separated.' ),
            'canonical_url'       => array( 'type' => 'string', 'description' => 'Canonical URL.' ),
            'robots'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Robots: index, noindex, follow, nofollow, noarchive, noimageindex, nosnippet.' ),
            'primary_category'    => array( 'type' => 'integer', 'description' => 'Primary category term ID.' ),
            'pillar_content'      => array( 'type' => 'string', 'enum' => array( 'on', 'off' ) ),
            'breadcrumb_title'    => array( 'type' => 'string' ),
            'schema_type'         => array( 'type' => 'string', 'description' => 'Schema type: article, news_article, faq, howto, product, review, etc.' ),
            'og_title'            => array( 'type' => 'string' ),
            'og_description'      => array( 'type' => 'string' ),
            'og_image'            => array( 'type' => 'string' ),
            'twitter_title'       => array( 'type' => 'string' ),
            'twitter_description' => array( 'type' => 'string' ),
        ),
    );

    // =========================== POSTS & PAGES ===========================

    // 1. LIST POSTS
    wp_register_ability( 'site/list-posts', array(
        'label' => 'List Posts', 'category' => 'content',
        'description' => 'List posts/pages with filters: status, category, tag, search, author, post_type, pagination, and sorting.',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'number'    => array( 'type' => 'integer', 'description' => 'Max 50.', 'default' => 10 ),
                'page'      => array( 'type' => 'integer', 'default' => 1 ),
                'status'    => array( 'type' => 'string', 'enum' => array( 'publish','draft','pending','private','future','any' ), 'default' => 'publish' ),
                'post_type' => array( 'type' => 'string', 'default' => 'post' ),
                'search'    => array( 'type' => 'string' ),
                'category'  => array( 'type' => 'string', 'description' => 'Category slug.' ),
                'tag'       => array( 'type' => 'string', 'description' => 'Tag slug.' ),
                'author'    => array( 'type' => 'integer' ),
                'orderby'   => array( 'type' => 'string', 'enum' => array('date','title','modified','ID','menu_order'), 'default' => 'date' ),
                'order'     => array( 'type' => 'string', 'enum' => array('ASC','DESC'), 'default' => 'DESC' ),
            ), 'additionalProperties' => false,
        ),
        'execute_callback' => function ( $input = array() ) {
            $input = is_array($input) ? $input : array();
            $args = array(
                'posts_per_page' => min((int)($input['number']??10),50),
                'paged'          => max((int)($input['page']??1),1),
                'post_status'    => $input['status']??'publish',
                'post_type'      => $input['post_type']??'post',
                'orderby'        => $input['orderby']??'date',
                'order'          => $input['order']??'DESC',
            );
            if (!empty($input['search']))   $args['s'] = sanitize_text_field($input['search']);
            if (!empty($input['category'])) $args['category_name'] = sanitize_text_field($input['category']);
            if (!empty($input['tag']))      $args['tag'] = sanitize_text_field($input['tag']);
            if (!empty($input['author']))   $args['author'] = (int)$input['author'];
            $q = new WP_Query($args);
            $posts = array();
            foreach ($q->posts as $p) {
                $a = get_userdata($p->post_author);
                $posts[] = array('ID'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'status'=>$p->post_status,'type'=>$p->post_type,'date'=>$p->post_date,'modified'=>$p->post_modified,'link'=>get_permalink($p->ID),'excerpt'=>wp_trim_words($p->post_content,30,'...'),'author'=>$a?$a->display_name:'');
            }
            return array('posts'=>$posts,'total'=>(int)$q->found_posts,'pages'=>(int)$q->max_num_pages);
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 2. READ POST
    wp_register_ability( 'site/read-post', array(
        'label' => 'Read Post', 'category' => 'content',
        'description' => 'Read full content, metadata, categories, tags, featured image, custom fields, and Rank Math SEO of a post/page by ID.',
        'input_schema' => array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ( $input = array() ) use ( $read_rank_math ) {
            $id = (int)($input['post_id']??0);
            $p = get_post($id);
            if (!$p) return new WP_Error('not_found',"Post {$id} not found.");
            $a = get_userdata($p->post_author);
            $cats = wp_get_post_categories($id, array('fields'=>'all'));
            $tags = wp_get_post_tags($id, array('fields'=>'all'));
            $cl = array(); if(is_array($cats)) foreach($cats as $c) $cl[]=array('id'=>$c->term_id,'name'=>$c->name,'slug'=>$c->slug);
            $tl = array(); if(is_array($tags)) foreach($tags as $t) $tl[]=array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug);
            $thumb_id = get_post_thumbnail_id($id); $thumb_url = get_the_post_thumbnail_url($id,'full');
            $custom = get_post_custom($id);
            $custom_clean = array();
            foreach ($custom as $k=>$v) {
                if (strpos($k,'_')===0 || strpos($k,'rank_math')===0) continue;
                $custom_clean[$k] = is_array($v)&&count($v)===1 ? $v[0] : $v;
            }
            return array(
                'ID'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'content'=>$p->post_content,
                'excerpt'=>$p->post_excerpt,'status'=>$p->post_status,'post_type'=>$p->post_type,
                'date'=>$p->post_date,'modified'=>$p->post_modified,'link'=>get_permalink($id),
                'author'=>$a?$a->display_name:'','author_id'=>(int)$p->post_author,
                'parent_id'=>(int)$p->post_parent,'menu_order'=>(int)$p->menu_order,
                'categories'=>$cl,'tags'=>$tl,
                'featured_image'=>array('id'=>$thumb_id?(int)$thumb_id:0,'url'=>$thumb_url?$thumb_url:''),
                'custom_fields'=>$custom_clean,
                'rank_math'=>$read_rank_math($id),
            );
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 3. CREATE POST
    wp_register_ability( 'site/create-post', array(
        'label' => 'Create Post', 'category' => 'content',
        'description' => 'Create a post/page with title, content, slug, excerpt, status, date, categories, tags, featured image, custom fields, and Rank Math SEO.',
        'input_schema' => array(
            'type'=>'object','required'=>array('title'),
            'properties' => array(
                'title'=>array('type'=>'string'),'content'=>array('type'=>'string','default'=>''),
                'excerpt'=>array('type'=>'string','default'=>''),
                'slug'=>array('type'=>'string','description'=>'URL slug. Auto-generated if omitted.'),
                'status'=>array('type'=>'string','enum'=>array('publish','draft','pending','private','future'),'default'=>'draft'),
                'post_type'=>array('type'=>'string','default'=>'post'),
                'author'=>array('type'=>array('string','integer'),'description'=>'Author user ID, login, email, slug, or display name.'),
                'date'=>array('type'=>'string','description'=>'Y-m-d H:i:s. Required for status=future.'),
                'parent_id'=>array('type'=>'integer'),'menu_order'=>array('type'=>'integer'),
                'categories'=>array('type'=>'array','items'=>array('type'=>'string'),'description'=>'Category names. Auto-created if missing.'),
                'tags'=>array('type'=>'array','items'=>array('type'=>'string')),
                'featured_image_id'=>array('type'=>'integer','description'=>'Attachment ID from site/upload-image.'),
                'custom_fields'=>array('type'=>'object','description'=>'Key-value pairs for custom post meta.','additionalProperties'=>array('type'=>'string')),
                'rank_math'=>$rank_math_schema,
            ), 'additionalProperties'=>false,
        ),
        'execute_callback' => function ( $input = array() ) use ( $resolve_cats, $resolve_author, $apply_rank_math ) {
            $input = is_array($input)?$input:array();
            $pd = array('post_title'=>sanitize_text_field($input['title']??''),'post_content'=>wp_slash($input['content']??''),'post_excerpt'=>sanitize_textarea_field($input['excerpt']??''),'post_status'=>$input['status']??'draft','post_type'=>$input['post_type']??'post');
            if (!empty($input['slug'])) $pd['post_name']=sanitize_title($input['slug']);
            if (!empty($input['date'])) $pd['post_date']=sanitize_text_field($input['date']);
            if (!empty($input['parent_id'])) $pd['post_parent']=(int)$input['parent_id'];
            if (isset($input['menu_order'])) $pd['menu_order']=(int)$input['menu_order'];
            if (isset($input['author'])) { $aid=$resolve_author($input['author']); if(is_wp_error($aid)) return $aid; $pd['post_author']=$aid; }
            $id = wp_insert_post($pd,true);
            if (is_wp_error($id)) return $id;
            if (!empty($input['categories'])&&is_array($input['categories'])) { $cids=$resolve_cats($input['categories']); if($cids) wp_set_post_categories($id,$cids); }
            if (!empty($input['tags'])&&is_array($input['tags'])) wp_set_post_tags($id,$input['tags']);
            if (!empty($input['featured_image_id'])) set_post_thumbnail($id,(int)$input['featured_image_id']);
            if (!empty($input['custom_fields'])&&is_array($input['custom_fields'])) {
                foreach ($input['custom_fields'] as $k=>$v) update_post_meta($id,sanitize_key($k),sanitize_text_field($v));
            }
            if (!empty($input['rank_math'])&&is_array($input['rank_math'])) $apply_rank_math($id,$input['rank_math']);
            return array('ID'=>$id,'slug'=>get_post_field('post_name',$id),'link'=>get_permalink($id),'edit_link'=>admin_url("post.php?post={$id}&action=edit"));
        },
        'permission_callback' => function(){return current_user_can('publish_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // 4. UPDATE POST
    wp_register_ability( 'site/update-post', array(
        'label' => 'Update Post', 'category' => 'content',
        'description' => 'Update any fields on an existing post/page. Only provided fields change.',
        'input_schema' => array(
            'type'=>'object','required'=>array('post_id'),
            'properties' => array(
                'post_id'=>array('type'=>'integer'),
                'title'=>array('type'=>'string'),'content'=>array('type'=>'string'),
                'excerpt'=>array('type'=>'string'),'slug'=>array('type'=>'string'),
                'status'=>array('type'=>'string','enum'=>array('publish','draft','pending','private','future')),
                'author'=>array('type'=>array('string','integer'),'description'=>'Author user ID, login, email, slug, or display name.'),
                'date'=>array('type'=>'string'),'parent_id'=>array('type'=>'integer'),'menu_order'=>array('type'=>'integer'),
                'categories'=>array('type'=>'array','items'=>array('type'=>'string'),'description'=>'Replaces existing. Auto-creates missing.'),
                'tags'=>array('type'=>'array','items'=>array('type'=>'string'),'description'=>'Replaces existing.'),
                'featured_image_id'=>array('type'=>'integer','description'=>'0 to remove.'),
                'custom_fields'=>array('type'=>'object','description'=>'Key-value pairs. Set value to null to delete a field.','additionalProperties'=>array('type'=>'string')),
                'rank_math'=>$rank_math_schema,
            ), 'additionalProperties'=>false,
        ),
        'execute_callback' => function ( $input = array() ) use ( $resolve_cats, $resolve_author, $apply_rank_math ) {
            $input = is_array($input)?$input:array();
            $id = (int)($input['post_id']??0);
            if (!get_post($id)) return new WP_Error('not_found',"Post {$id} not found.");
            $pd = array('ID'=>$id);
            if (isset($input['title']))   $pd['post_title']=sanitize_text_field($input['title']);
            if (isset($input['content'])) $pd['post_content']=wp_slash($input['content']);
            if (isset($input['excerpt'])) $pd['post_excerpt']=sanitize_textarea_field($input['excerpt']);
            if (isset($input['slug']))    $pd['post_name']=sanitize_title($input['slug']);
            if (isset($input['status']))  $pd['post_status']=$input['status'];
            if (isset($input['author']))  { $aid=$resolve_author($input['author']); if(is_wp_error($aid)) return $aid; $pd['post_author']=$aid; }
            if (isset($input['date']))    $pd['post_date']=sanitize_text_field($input['date']);
            if (isset($input['parent_id'])) $pd['post_parent']=(int)$input['parent_id'];
            if (isset($input['menu_order'])) $pd['menu_order']=(int)$input['menu_order'];
            $r = wp_update_post($pd,true); if (is_wp_error($r)) return $r;
            if (isset($input['categories'])&&is_array($input['categories'])) wp_set_post_categories($id,$resolve_cats($input['categories']));
            if (isset($input['tags'])&&is_array($input['tags'])) wp_set_post_tags($id,$input['tags']);
            if (isset($input['featured_image_id'])) { $img=(int)$input['featured_image_id']; if($img===0) delete_post_thumbnail($id); else set_post_thumbnail($id,$img); }
            if (!empty($input['custom_fields'])&&is_array($input['custom_fields'])) {
                foreach ($input['custom_fields'] as $k=>$v) {
                    if ($v===null) delete_post_meta($id,sanitize_key($k));
                    else update_post_meta($id,sanitize_key($k),sanitize_text_field($v));
                }
            }
            if (!empty($input['rank_math'])&&is_array($input['rank_math'])) $apply_rank_math($id,$input['rank_math']);
            return array('ID'=>$id,'slug'=>get_post_field('post_name',$id),'link'=>get_permalink($id),'edit_link'=>admin_url("post.php?post={$id}&action=edit"),'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('edit_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 5. DELETE POST
    wp_register_ability( 'site/delete-post', array(
        'label' => 'Delete Post', 'category' => 'content',
        'description' => 'Trash or permanently delete a post/page.',
        'input_schema' => array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer'),'permanent'=>array('type'=>'boolean','default'=>false)),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id=(int)($input['post_id']??0); $perm=(bool)($input['permanent']??false);
            if (!get_post($id)) return new WP_Error('not_found',"Post {$id} not found.");
            $r = wp_delete_post($id,$perm);
            if (!$r) return new WP_Error('failed',"Failed to delete post {$id}.");
            return array('deleted'=>true,'ID'=>$id,'permanent'=>$perm);
        },
        'permission_callback' => function(){return current_user_can('delete_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // 6. BULK UPDATE STATUS
    wp_register_ability( 'site/bulk-update-status', array(
        'label' => 'Bulk Update Post Status', 'category' => 'content',
        'description' => 'Change the status of multiple posts at once (e.g. draft to publish).',
        'input_schema' => array(
            'type'=>'object','required'=>array('post_ids','status'),
            'properties' => array(
                'post_ids' => array('type'=>'array','items'=>array('type'=>'integer'),'description'=>'Array of post IDs.'),
                'status'   => array('type'=>'string','enum'=>array('publish','draft','pending','private')),
            ), 'additionalProperties'=>false,
        ),
        'execute_callback' => function ($input=array()) {
            $ids = $input['post_ids']??array(); $status = $input['status']??'';
            $updated = array(); $failed = array();
            foreach ($ids as $id) {
                $id = (int)$id;
                $r = wp_update_post(array('ID'=>$id,'post_status'=>$status),true);
                if (is_wp_error($r)) $failed[] = $id; else $updated[] = $id;
            }
            return array('updated'=>$updated,'failed'=>$failed,'count'=>count($updated));
        },
        'permission_callback' => function(){return current_user_can('edit_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 7. LIST REVISIONS
    wp_register_ability( 'site/list-revisions', array(
        'label' => 'List Post Revisions', 'category' => 'content',
        'description' => 'List all saved revisions of a post with dates and authors.',
        'input_schema' => array('type'=>'object','required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id = (int)($input['post_id']??0);
            if (!get_post($id)) return new WP_Error('not_found',"Post {$id} not found.");
            $revisions = wp_get_post_revisions($id);
            $list = array();
            foreach ($revisions as $rev) {
                $a = get_userdata($rev->post_author);
                $list[] = array('ID'=>$rev->ID,'date'=>$rev->post_date,'author'=>$a?$a->display_name:'','title'=>$rev->post_title,'excerpt'=>wp_trim_words($rev->post_content,20,'...'));
            }
            return array('revisions'=>$list,'total'=>count($list));
        },
        'permission_callback' => function(){return current_user_can('edit_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 8. RESTORE REVISION
    wp_register_ability( 'site/restore-revision', array(
        'label' => 'Restore Revision', 'category' => 'content',
        'description' => 'Restore a post to a previous revision by revision ID.',
        'input_schema' => array('type'=>'object','required'=>array('revision_id'),'properties'=>array('revision_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $rev_id = (int)($input['revision_id']??0);
            $rev = get_post($rev_id);
            if (!$rev || $rev->post_type !== 'revision') return new WP_Error('not_found',"Revision {$rev_id} not found.");
            $post_id = wp_restore_post_revision($rev_id);
            if (!$post_id || is_wp_error($post_id)) return new WP_Error('failed','Failed to restore revision.');
            return array('restored'=>true,'post_id'=>$post_id,'revision_id'=>$rev_id);
        },
        'permission_callback' => function(){return current_user_can('edit_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 9. SEARCH & REPLACE
    wp_register_ability( 'site/search-replace', array(
        'label' => 'Search and Replace in Posts', 'category' => 'content',
        'description' => 'Find and replace text across post titles and/or content. Supports dry-run mode to preview changes.',
        'input_schema' => array(
            'type'=>'object','required'=>array('search','replace'),
            'properties' => array(
                'search'    => array('type'=>'string','description'=>'Text to find.'),
                'replace'   => array('type'=>'string','description'=>'Replacement text.'),
                'in'        => array('type'=>'string','enum'=>array('content','title','both'),'default'=>'content','description'=>'Where to search.'),
                'post_type' => array('type'=>'string','default'=>'post'),
                'status'    => array('type'=>'string','default'=>'any'),
                'dry_run'   => array('type'=>'boolean','default'=>true,'description'=>'Preview only, no changes made.'),
            ), 'additionalProperties'=>false,
        ),
        'execute_callback' => function ($input=array()) {
            global $wpdb;
            $search  = $input['search']??'';
            $replace = $input['replace']??'';
            $in      = $input['in']??'content';
            $type    = $input['post_type']??'post';
            $status  = $input['status']??'any';
            $dry     = (bool)($input['dry_run']??true);

            if (empty($search)) return new WP_Error('empty','Search string cannot be empty.');

            $where = $wpdb->prepare("post_type = %s", $type);
            if ($status !== 'any') $where .= $wpdb->prepare(" AND post_status = %s", $status);

            $like = '%' . $wpdb->esc_like($search) . '%';
            if ($in === 'content') $where .= $wpdb->prepare(" AND post_content LIKE %s", $like);
            elseif ($in === 'title') $where .= $wpdb->prepare(" AND post_title LIKE %s", $like);
            else $where .= $wpdb->prepare(" AND (post_content LIKE %s OR post_title LIKE %s)", $like, $like);

            $posts = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE {$where} LIMIT 200");
            $matches = array();
            foreach ($posts as $p) $matches[] = array('ID'=>(int)$p->ID,'title'=>$p->post_title);

            if ($dry) return array('dry_run'=>true,'matches'=>$matches,'count'=>count($matches));

            $title_count = 0; $content_count = 0;
            if (in_array($in, array('title','both'))) {
                $title_count = (int)$wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_title = REPLACE(post_title, %s, %s) WHERE {$where}", $search, $replace
                ));
            }
            if (in_array($in, array('content','both'))) {
                $content_count = (int)$wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE {$where}", $search, $replace
                ));
            }
            return array('dry_run'=>false,'matches'=>$matches,'posts_affected'=>count($matches),'title_updates'=>$title_count,'content_updates'=>$content_count);
        },
        'permission_callback' => function(){return current_user_can('edit_others_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // =========================== CATEGORIES ===========================

    // 10. LIST CATEGORIES
    wp_register_ability( 'site/list-categories', array(
        'label' => 'List Categories', 'category' => 'content',
        'description' => 'List all categories with ID, name, slug, parent, description, post count, and Rank Math SEO.',
        'input_schema' => array('type'=>'object','properties'=>array('hide_empty'=>array('type'=>'boolean','default'=>false),'parent'=>array('type'=>'integer','description'=>'0 for top-level only.'),'search'=>array('type'=>'string'),'orderby'=>array('type'=>'string','enum'=>array('name','id','count','slug'),'default'=>'name')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) use ($read_term_rank_math) {
            $input = is_array($input)?$input:array();
            $args = array('taxonomy'=>'category','hide_empty'=>(bool)($input['hide_empty']??false),'orderby'=>$input['orderby']??'name','order'=>'ASC');
            if (isset($input['parent'])) $args['parent']=(int)$input['parent'];
            if (!empty($input['search'])) $args['search']=sanitize_text_field($input['search']);
            $terms = get_terms($args); if (is_wp_error($terms)) return $terms;
            $r = array();
            foreach ($terms as $t) $r[]=array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'description'=>$t->description,'parent_id'=>$t->parent,'count'=>$t->count,'rank_math'=>$read_term_rank_math($t->term_id));
            return array('categories'=>$r,'total'=>count($r));
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 11. CREATE CATEGORY
    wp_register_ability( 'site/create-category', array(
        'label' => 'Create Category', 'category' => 'content',
        'description' => 'Create a new category with name, slug, description, optional parent, and Rank Math SEO.',
        'input_schema' => array('type'=>'object','required'=>array('name'),'properties'=>array('name'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'description'=>array('type'=>'string','default'=>''),'parent_id'=>array('type'=>'integer','default'=>0),'rank_math'=>$term_rank_math_schema),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) use ($apply_term_rank_math) {
            $args = array('description'=>sanitize_textarea_field($input['description']??''),'parent'=>(int)($input['parent_id']??0));
            if (!empty($input['slug'])) $args['slug']=sanitize_title($input['slug']);
            $r = wp_insert_term(sanitize_text_field($input['name']??''),'category',$args);
            if (is_wp_error($r)) return $r;
            if (!empty($input['rank_math']) && is_array($input['rank_math'])) $apply_term_rank_math($r['term_id'],$input['rank_math']);
            $t = get_term($r['term_id'],'category');
            return array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'link'=>get_term_link($t));
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // 12. UPDATE CATEGORY
    wp_register_ability( 'site/update-category', array(
        'label' => 'Update Category', 'category' => 'content',
        'description' => 'Update category name, slug, description, parent, or Rank Math SEO.',
        'input_schema' => array('type'=>'object','required'=>array('category_id'),'properties'=>array('category_id'=>array('type'=>'integer'),'name'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'description'=>array('type'=>'string'),'parent_id'=>array('type'=>'integer'),'rank_math'=>$term_rank_math_schema),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) use ($apply_term_rank_math) {
            $cid=(int)($input['category_id']??0); $t=get_term($cid,'category');
            if (!$t||is_wp_error($t)) return new WP_Error('not_found',"Category {$cid} not found.");
            $args=array();
            if (isset($input['name'])) $args['name']=sanitize_text_field($input['name']);
            if (isset($input['slug'])) $args['slug']=sanitize_title($input['slug']);
            if (isset($input['description'])) $args['description']=sanitize_textarea_field($input['description']);
            if (isset($input['parent_id'])) $args['parent']=(int)$input['parent_id'];
            $r = wp_update_term($cid,'category',$args); if (is_wp_error($r)) return $r;
            if (!empty($input['rank_math']) && is_array($input['rank_math'])) $apply_term_rank_math($cid,$input['rank_math']);
            $u = get_term($cid,'category');
            return array('id'=>$u->term_id,'name'=>$u->name,'slug'=>$u->slug,'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 13. DELETE CATEGORY
    wp_register_ability( 'site/delete-category', array(
        'label' => 'Delete Category', 'category' => 'content',
        'description' => 'Delete a category. Posts move to the default category.',
        'input_schema' => array('type'=>'object','required'=>array('category_id'),'properties'=>array('category_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $cid=(int)($input['category_id']??0);
            if ($cid===(int)get_option('default_category')) return new WP_Error('cannot','Cannot delete the default category.');
            $t=get_term($cid,'category'); if (!$t||is_wp_error($t)) return new WP_Error('not_found',"Category {$cid} not found.");
            $r=wp_delete_term($cid,'category'); if (is_wp_error($r)) return $r;
            if (!$r) return new WP_Error('failed',"Failed to delete category {$cid}.");
            return array('deleted'=>true,'id'=>$cid,'name'=>$t->name);
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // =========================== TAGS ===========================

    // 14. LIST TAGS
    wp_register_ability( 'site/list-tags', array(
        'label' => 'List Tags', 'category' => 'content',
        'description' => 'List all tags with ID, name, slug, description, and post count.',
        'input_schema' => array('type'=>'object','properties'=>array('hide_empty'=>array('type'=>'boolean','default'=>false),'search'=>array('type'=>'string'),'number'=>array('type'=>'integer','default'=>100),'orderby'=>array('type'=>'string','enum'=>array('name','id','count','slug'),'default'=>'name')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $args = array('taxonomy'=>'post_tag','hide_empty'=>(bool)($input['hide_empty']??false),'orderby'=>$input['orderby']??'name','number'=>min((int)($input['number']??100),200));
            if (!empty($input['search'])) $args['search']=sanitize_text_field($input['search']);
            $terms = get_terms($args); if (is_wp_error($terms)) return $terms;
            $r=array(); foreach ($terms as $t) $r[]=array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'description'=>$t->description,'count'=>$t->count);
            return array('tags'=>$r,'total'=>count($r));
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 15. CREATE TAG
    wp_register_ability( 'site/create-tag', array(
        'label' => 'Create Tag', 'category' => 'content',
        'description' => 'Create a new tag.',
        'input_schema' => array('type'=>'object','required'=>array('name'),'properties'=>array('name'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'description'=>array('type'=>'string','default'=>'')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $args = array('description'=>sanitize_textarea_field($input['description']??''));
            if (!empty($input['slug'])) $args['slug']=sanitize_title($input['slug']);
            $r = wp_insert_term(sanitize_text_field($input['name']??''),'post_tag',$args);
            if (is_wp_error($r)) return $r;
            $t = get_term($r['term_id'],'post_tag');
            return array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug);
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // 16. UPDATE TAG
    wp_register_ability( 'site/update-tag', array(
        'label' => 'Update Tag', 'category' => 'content',
        'description' => 'Update a tag name, slug, or description.',
        'input_schema' => array('type'=>'object','required'=>array('tag_id'),'properties'=>array('tag_id'=>array('type'=>'integer'),'name'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'description'=>array('type'=>'string')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $tid=(int)($input['tag_id']??0); $t=get_term($tid,'post_tag');
            if (!$t||is_wp_error($t)) return new WP_Error('not_found',"Tag {$tid} not found.");
            $args=array();
            if (isset($input['name'])) $args['name']=sanitize_text_field($input['name']);
            if (isset($input['slug'])) $args['slug']=sanitize_title($input['slug']);
            if (isset($input['description'])) $args['description']=sanitize_textarea_field($input['description']);
            $r=wp_update_term($tid,'post_tag',$args); if (is_wp_error($r)) return $r;
            $u=get_term($tid,'post_tag');
            return array('id'=>$u->term_id,'name'=>$u->name,'slug'=>$u->slug,'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 17. DELETE TAG
    wp_register_ability( 'site/delete-tag', array(
        'label' => 'Delete Tag', 'category' => 'content',
        'description' => 'Delete a tag. Posts are untagged but not deleted.',
        'input_schema' => array('type'=>'object','required'=>array('tag_id'),'properties'=>array('tag_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $tid=(int)($input['tag_id']??0); $t=get_term($tid,'post_tag');
            if (!$t||is_wp_error($t)) return new WP_Error('not_found',"Tag {$tid} not found.");
            $r=wp_delete_term($tid,'post_tag'); if (is_wp_error($r)) return $r;
            return array('deleted'=>true,'id'=>$tid,'name'=>$t->name);
        },
        'permission_callback' => function(){return current_user_can('manage_categories');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // =========================== MEDIA ===========================

    // 18. UPLOAD IMAGE
    wp_register_ability( 'site/upload-image', array(
        'label' => 'Upload Image', 'category' => 'content',
        'description' => 'Download an image from a URL and add it to the media library with title, alt text, caption, and custom filename.',
        'input_schema' => array('type'=>'object','required'=>array('image_url'),'properties'=>array('image_url'=>array('type'=>'string'),'title'=>array('type'=>'string','default'=>''),'alt_text'=>array('type'=>'string','default'=>''),'caption'=>array('type'=>'string','default'=>''),'description'=>array('type'=>'string','default'=>''),'filename'=>array('type'=>'string','description'=>'Override filename.')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $url = $input['image_url']??'';
            if (empty($url)||!filter_var($url,FILTER_VALIDATE_URL)) return new WP_Error('invalid_url','A valid image URL is required.');
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            $tmp = download_url($url,30); if (is_wp_error($tmp)) return new WP_Error('download_failed',$tmp->get_error_message());
            $fn = !empty($input['filename']) ? sanitize_file_name($input['filename']) : basename(wp_parse_url($url,PHP_URL_PATH));
            $id = media_handle_sideload(array('name'=>$fn,'tmp_name'=>$tmp),0);
            if (is_wp_error($id)) { wp_delete_file($tmp); return $id; }
            if (!empty($input['title'])) wp_update_post(array('ID'=>$id,'post_title'=>sanitize_text_field($input['title'])));
            if (!empty($input['alt_text'])) update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($input['alt_text']));
            if (!empty($input['caption'])) wp_update_post(array('ID'=>$id,'post_excerpt'=>sanitize_textarea_field($input['caption'])));
            if (!empty($input['description'])) wp_update_post(array('ID'=>$id,'post_content'=>sanitize_textarea_field($input['description'])));
            return array('attachment_id'=>$id,'url'=>wp_get_attachment_url($id),'title'=>get_the_title($id),'filename'=>basename(get_attached_file($id)));
        },
        'permission_callback' => function(){return current_user_can('upload_files');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // 19. LIST MEDIA
    wp_register_ability( 'site/list-media', array(
        'label' => 'List Media', 'category' => 'content',
        'description' => 'List media files with URLs, alt text, captions, and file sizes.',
        'input_schema' => array('type'=>'object','properties'=>array('number'=>array('type'=>'integer','default'=>20),'page'=>array('type'=>'integer','default'=>1),'mime_type'=>array('type'=>'string','default'=>'image'),'search'=>array('type'=>'string')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $args = array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>min((int)($input['number']??20),50),'paged'=>max((int)($input['page']??1),1),'post_mime_type'=>$input['mime_type']??'image','orderby'=>'date','order'=>'DESC');
            if (!empty($input['search'])) $args['s']=sanitize_text_field($input['search']);
            $q = new WP_Query($args); $r=array();
            foreach ($q->posts as $a) {
                $fp = get_attached_file($a->ID);
                $r[]=array('ID'=>$a->ID,'title'=>$a->post_title,'url'=>wp_get_attachment_url($a->ID),'alt_text'=>get_post_meta($a->ID,'_wp_attachment_image_alt',true),'caption'=>$a->post_excerpt,'date'=>$a->post_date,'filename'=>$fp?basename($fp):'','filesize'=>($fp&&file_exists($fp))?size_format(filesize($fp)):'');
            }
            return array('media'=>$r,'total'=>(int)$q->found_posts,'pages'=>(int)$q->max_num_pages);
        },
        'permission_callback' => function(){return current_user_can('upload_files');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 20. UPDATE MEDIA
    wp_register_ability( 'site/update-media', array(
        'label' => 'Update Media', 'category' => 'content',
        'description' => 'Update an existing media item title, alt text, caption, or description.',
        'input_schema' => array('type'=>'object','required'=>array('attachment_id'),'properties'=>array(
            'attachment_id'=>array('type'=>'integer','description'=>'Media attachment ID.'),
            'title'=>array('type'=>'string','description'=>'New title.'),
            'alt_text'=>array('type'=>'string','description'=>'New alt text for SEO.'),
            'caption'=>array('type'=>'string','description'=>'New caption.'),
            'description'=>array('type'=>'string','description'=>'New description.'),
        ),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id = (int)($input['attachment_id']??0);
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') return new WP_Error('not_found',"Attachment {$id} not found.");
            $pd = array('ID'=>$id);
            if (isset($input['title'])) $pd['post_title']=sanitize_text_field($input['title']);
            if (isset($input['caption'])) $pd['post_excerpt']=sanitize_textarea_field($input['caption']);
            if (isset($input['description'])) $pd['post_content']=sanitize_textarea_field($input['description']);
            if (count($pd)>1) { $r=wp_update_post($pd,true); if (is_wp_error($r)) return $r; }
            if (isset($input['alt_text'])) update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($input['alt_text']));
            return array('attachment_id'=>$id,'title'=>get_the_title($id),'alt_text'=>get_post_meta($id,'_wp_attachment_image_alt',true),'url'=>wp_get_attachment_url($id),'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('upload_files');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 21. DELETE MEDIA
    wp_register_ability( 'site/delete-media', array(
        'label' => 'Delete Media', 'category' => 'content',
        'description' => 'Permanently delete a media attachment and its files from the server.',
        'input_schema' => array('type'=>'object','required'=>array('attachment_id'),'properties'=>array('attachment_id'=>array('type'=>'integer','description'=>'Media attachment ID.')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id = (int)($input['attachment_id']??0);
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') return new WP_Error('not_found',"Attachment {$id} not found.");
            $title = $post->post_title;
            $r = wp_delete_attachment($id, true);
            if (!$r) return new WP_Error('failed',"Failed to delete attachment {$id}.");
            return array('deleted'=>true,'attachment_id'=>$id,'title'=>$title);
        },
        'permission_callback' => function(){return current_user_can('delete_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // =========================== COMMENTS ===========================

    // 22. LIST COMMENTS (was 20)
    wp_register_ability( 'site/list-comments', array(
        'label' => 'List Comments', 'category' => 'content',
        'description' => 'List comments with filtering by post, status (approved, hold, spam, trash), and pagination.',
        'input_schema' => array('type'=>'object','properties'=>array('post_id'=>array('type'=>'integer','description'=>'Filter by post ID.'),'status'=>array('type'=>'string','enum'=>array('approve','hold','spam','trash','all'),'default'=>'all'),'number'=>array('type'=>'integer','default'=>20),'page'=>array('type'=>'integer','default'=>1),'search'=>array('type'=>'string')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $args = array('number'=>min((int)($input['number']??20),50),'offset'=>(max((int)($input['page']??1),1)-1)*min((int)($input['number']??20),50),'status'=>$input['status']??'all');
            if (!empty($input['post_id'])) $args['post_id']=(int)$input['post_id'];
            if (!empty($input['search'])) $args['search']=sanitize_text_field($input['search']);
            $comments = get_comments($args); $r=array();
            foreach ($comments as $c) {
                $r[]=array('ID'=>(int)$c->comment_ID,'post_id'=>(int)$c->comment_post_ID,'author'=>$c->comment_author,'email'=>$c->comment_author_email,'content'=>$c->comment_content,'date'=>$c->comment_date,'status'=>wp_get_comment_status($c),'parent'=>(int)$c->comment_parent);
            }
            $total = (int)get_comments(array_merge($args,array('count'=>true,'offset'=>0,'number'=>0)));
            return array('comments'=>$r,'total'=>$total);
        },
        'permission_callback' => function(){return current_user_can('moderate_comments');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 21. UPDATE COMMENT STATUS
    wp_register_ability( 'site/update-comment', array(
        'label' => 'Update Comment', 'category' => 'content',
        'description' => 'Approve, hold, spam, or trash a comment. Can also reply to a comment.',
        'input_schema' => array('type'=>'object','required'=>array('comment_id'),'properties'=>array('comment_id'=>array('type'=>'integer'),'status'=>array('type'=>'string','enum'=>array('approve','hold','spam','trash'),'description'=>'New status.'),'reply'=>array('type'=>'string','description'=>'Reply content. Creates a new comment as a reply.')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $cid = (int)($input['comment_id']??0);
            $comment = get_comment($cid);
            if (!$comment) return new WP_Error('not_found',"Comment {$cid} not found.");
            $result = array('comment_id'=>$cid);
            if (isset($input['status'])) {
                $status_map = array('approve'=>'1','hold'=>'0','spam'=>'spam','trash'=>'trash');
                wp_set_comment_status($cid,$status_map[$input['status']]??$input['status']);
                $result['status'] = $input['status'];
            }
            if (!empty($input['reply'])) {
                $user = wp_get_current_user();
                $reply_id = wp_insert_comment(array(
                    'comment_post_ID'=>(int)$comment->comment_post_ID,
                    'comment_content'=>sanitize_textarea_field($input['reply']),
                    'comment_parent'=>$cid,
                    'comment_author'=>$user->display_name,
                    'comment_author_email'=>$user->user_email,
                    'comment_approved'=>1,
                    'user_id'=>$user->ID,
                ));
                $result['reply_id'] = $reply_id;
            }
            return $result;
        },
        'permission_callback' => function(){return current_user_can('moderate_comments');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 22. DELETE COMMENT
    wp_register_ability( 'site/delete-comment', array(
        'label' => 'Delete Comment', 'category' => 'content',
        'description' => 'Permanently delete a comment.',
        'input_schema' => array('type'=>'object','required'=>array('comment_id'),'properties'=>array('comment_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $cid=(int)($input['comment_id']??0);
            if (!get_comment($cid)) return new WP_Error('not_found',"Comment {$cid} not found.");
            wp_delete_comment($cid,true);
            return array('deleted'=>true,'comment_id'=>$cid);
        },
        'permission_callback' => function(){return current_user_can('moderate_comments');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // =========================== RANK MATH REDIRECTIONS ===========================

    // 23. LIST REDIRECTIONS
    wp_register_ability( 'site/list-redirections', array(
        'label' => 'List Redirections', 'category' => 'content',
        'description' => 'List Rank Math 301/302 redirections.',
        'input_schema' => array('type'=>'object','properties'=>array('number'=>array('type'=>'integer','default'=>50),'search'=>array('type'=>'string')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            global $wpdb;
            $table = $wpdb->prefix.'rank_math_redirections';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")===$table) {
                $limit = min((int)($input['number']??50),100);
                $where = '1=1';
                if (!empty($input['search'])) {
                    $like = '%'.$wpdb->esc_like(sanitize_text_field($input['search'])).'%';
                    $where = $wpdb->prepare("(sources LIKE %s OR url_to LIKE %s)", $like, $like);
                }
                $rows = $wpdb->get_results("SELECT id, sources, url_to, header_code, status FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$limit}");
                $r = array();
                foreach ($rows as $row) {
                    $sources = maybe_unserialize($row->sources);
                    $from = is_array($sources)&&isset($sources[0]['pattern']) ? $sources[0]['pattern'] : (string)$sources;
                    $r[] = array('id'=>(int)$row->id,'from'=>$from,'to'=>$row->url_to,'type'=>(int)$row->header_code,'active'=>$row->status==='active');
                }
                return array('redirections'=>$r,'total'=>count($r));
            }
            return array('redirections'=>array(),'total'=>0,'note'=>'Rank Math redirections table not found.');
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 24. CREATE REDIRECTION
    wp_register_ability( 'site/create-redirection', array(
        'label' => 'Create Redirection', 'category' => 'content',
        'description' => 'Create a Rank Math 301 or 302 redirect.',
        'input_schema' => array('type'=>'object','required'=>array('from','to'),'properties'=>array('from'=>array('type'=>'string','description'=>'Source URL path (e.g. /old-page).'),'to'=>array('type'=>'string','description'=>'Destination URL.'),'type'=>array('type'=>'integer','enum'=>array(301,302,307,410,451),'default'=>301)),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            global $wpdb;
            $table = $wpdb->prefix.'rank_math_redirections';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")!==$table) return new WP_Error('no_table','Rank Math redirections table not found.');
            $from = sanitize_text_field($input['from']??'');
            $to   = esc_url_raw($input['to']??'');
            $type = (int)($input['type']??301);
            $sources = maybe_serialize(array(array('pattern'=>$from,'comparison'=>'exact')));
            $wpdb->insert($table, array('sources'=>$sources,'url_to'=>$to,'header_code'=>$type,'hits'=>0,'status'=>'active','created'=>current_time('mysql'),'updated'=>current_time('mysql')));
            $id = (int)$wpdb->insert_id;
            // Update redirection count cache.
            $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
            update_option('rank_math_redirections_count', $count);
            return array('id'=>$id,'from'=>$from,'to'=>$to,'type'=>$type);
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // 25. DELETE REDIRECTION
    wp_register_ability( 'site/delete-redirection', array(
        'label' => 'Delete Redirection', 'category' => 'content',
        'description' => 'Delete a Rank Math redirection by ID.',
        'input_schema' => array('type'=>'object','required'=>array('redirection_id'),'properties'=>array('redirection_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            global $wpdb;
            $table = $wpdb->prefix.'rank_math_redirections';
            $id = (int)($input['redirection_id']??0);
            $deleted = $wpdb->delete($table, array('id'=>$id));
            if (!$deleted) return new WP_Error('not_found',"Redirection {$id} not found.");
            return array('deleted'=>true,'id'=>$id);
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // 26. UPDATE REDIRECTION
    wp_register_ability( 'site/update-redirection', array(
        'label' => 'Update Redirection', 'category' => 'content',
        'description' => 'Update an existing Rank Math redirection. Change source URL, destination, type, or active status.',
        'input_schema' => array('type'=>'object','required'=>array('redirection_id'),'properties'=>array(
            'redirection_id'=>array('type'=>'integer','description'=>'Redirection ID to update.'),
            'from'=>array('type'=>'string','description'=>'New source URL path.'),
            'to'=>array('type'=>'string','description'=>'New destination URL.'),
            'type'=>array('type'=>'integer','enum'=>array(301,302,307,410,451),'description'=>'New redirect type.'),
            'status'=>array('type'=>'string','enum'=>array('active','inactive'),'description'=>'Enable or disable the redirect.'),
        ),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            global $wpdb;
            $table = $wpdb->prefix.'rank_math_redirections';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")!==$table) return new WP_Error('no_table','Rank Math redirections table not found.');
            $id = (int)($input['redirection_id']??0);
            $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id));
            if (!$row) return new WP_Error('not_found',"Redirection {$id} not found.");
            $update = array('updated'=>current_time('mysql'));
            if (isset($input['from'])) $update['sources'] = maybe_serialize(array(array('pattern'=>sanitize_text_field($input['from']),'comparison'=>'exact')));
            if (isset($input['to'])) $update['url_to'] = esc_url_raw($input['to']);
            if (isset($input['type'])) $update['header_code'] = (int)$input['type'];
            if (isset($input['status'])) $update['status'] = $input['status']==='active'?'active':'inactive';
            $wpdb->update($table, $update, array('id'=>$id));
            $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
            update_option('rank_math_redirections_count', $count);
            return array('id'=>$id,'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // =========================== ADMIN ===========================

    // 27. LIST PLUGINS
    wp_register_ability( 'site/list-plugins', array(
        'label' => 'List Plugins', 'category' => 'admin',
        'description' => 'List all installed plugins with name, version, active/inactive status, and description.',
        'execute_callback' => function () {
            if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
            $plugins = get_plugins(); $active = get_option('active_plugins',array());
            $r = array();
            foreach ($plugins as $file=>$data) {
                $r[] = array('file'=>$file,'name'=>$data['Name'],'version'=>$data['Version'],'active'=>in_array($file,$active,true),'description'=>wp_strip_all_tags($data['Description']));
            }
            return array('plugins'=>$r,'total'=>count($r),'active_count'=>count($active));
        },
        'permission_callback' => function(){return current_user_can('activate_plugins');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 27. TOGGLE PLUGIN
    wp_register_ability( 'site/toggle-plugin', array(
        'label' => 'Activate/Deactivate Plugin', 'category' => 'admin',
        'description' => 'Activate or deactivate a WordPress plugin by its file path.',
        'input_schema' => array('type'=>'object','required'=>array('plugin','action'),'properties'=>array('plugin'=>array('type'=>'string','description'=>'Plugin file path (e.g. akismet/akismet.php).'),'action'=>array('type'=>'string','enum'=>array('activate','deactivate'))),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            if (!function_exists('activate_plugin')) require_once ABSPATH.'wp-admin/includes/plugin.php';
            $plugin = sanitize_text_field($input['plugin']??'');
            $action = $input['action']??'';
            if ($action==='activate') {
                $r = activate_plugin($plugin);
                if (is_wp_error($r)) return $r;
                return array('plugin'=>$plugin,'status'=>'activated');
            } else {
                deactivate_plugins($plugin);
                return array('plugin'=>$plugin,'status'=>'deactivated');
            }
        },
        'permission_callback' => function(){return current_user_can('activate_plugins');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 28. LIST USERS
    wp_register_ability( 'site/list-users', array(
        'label' => 'List Users', 'category' => 'admin',
        'description' => 'List WordPress users with ID, name, email, role, and post count.',
        'input_schema' => array('type'=>'object','properties'=>array('role'=>array('type'=>'string','description'=>'Filter by role: administrator, editor, author, contributor, subscriber.'),'number'=>array('type'=>'integer','default'=>50),'search'=>array('type'=>'string')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $args = array('number'=>min((int)($input['number']??50),100),'orderby'=>'registered','order'=>'DESC');
            if (!empty($input['role'])) $args['role']=sanitize_text_field($input['role']);
            if (!empty($input['search'])) $args['search']='*'.sanitize_text_field($input['search']).'*';
            $uq = new WP_User_Query($args); $r=array();
            foreach ($uq->get_results() as $u) {
                $r[]=array('ID'=>$u->ID,'username'=>$u->user_login,'display_name'=>$u->display_name,'email'=>$u->user_email,'role'=>implode(', ',$u->roles),'registered'=>$u->user_registered,'post_count'=>count_user_posts($u->ID));
            }
            return array('users'=>$r,'total'=>(int)$uq->get_total());
        },
        'permission_callback' => function(){return current_user_can('list_users');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // 29. READ/UPDATE OPTIONS
    wp_register_ability( 'site/manage-options', array(
        'label' => 'Manage Site Options', 'category' => 'admin',
        'description' => 'Read or update WordPress site options (settings). Common options: blogname, blogdescription, timezone_string, date_format, time_format, posts_per_page, permalink_structure.',
        'input_schema' => array('type'=>'object','properties'=>array('read'=>array('type'=>'array','items'=>array('type'=>'string'),'description'=>'Option names to read.'),'write'=>array('type'=>'object','description'=>'Key-value pairs of options to update.','additionalProperties'=>array('type'=>'string'))),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $result = array();
            if (!empty($input['read'])&&is_array($input['read'])) {
                $values = array();
                foreach ($input['read'] as $key) $values[sanitize_key($key)] = get_option(sanitize_key($key));
                $result['read'] = $values;
            }
            if (!empty($input['write'])&&is_array($input['write'])) {
                $updated = array();
                foreach ($input['write'] as $key=>$val) {
                    $skey = sanitize_key($key);
                    update_option($skey, sanitize_text_field($val));
                    $updated[$skey] = get_option($skey);
                }
                $result['updated'] = $updated;
            }
            return $result;
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 30. CLEAR CACHE
    wp_register_ability( 'site/clear-cache', array(
        'label' => 'Clear Cache', 'category' => 'admin',
        'description' => 'Clear site cache. Supports LiteSpeed Cache, WP Super Cache, W3 Total Cache, WP Fastest Cache, and WordPress object cache.',
        'execute_callback' => function () {
            $cleared = array();
            // LiteSpeed Cache
            if (class_exists('LiteSpeed\Purge')) {
                do_action('litespeed_purge_all');
                $cleared[] = 'LiteSpeed Cache';
            }
            // WP Super Cache
            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();
                $cleared[] = 'WP Super Cache';
            }
            // W3 Total Cache
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
                $cleared[] = 'W3 Total Cache';
            }
            // WP Fastest Cache
            if (isset($GLOBALS['wp_fastest_cache'])&&method_exists($GLOBALS['wp_fastest_cache'],'deleteCache')) {
                $GLOBALS['wp_fastest_cache']->deleteCache(true);
                $cleared[] = 'WP Fastest Cache';
            }
            // WordPress object cache
            wp_cache_flush();
            $cleared[] = 'WordPress Object Cache';
            return array('cleared'=>$cleared);
        },
        'permission_callback' => function(){return current_user_can('manage_options');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // 31. GET SITE INFO
    wp_register_ability( 'site/get-info', array(
        'label' => 'Get Site Info', 'category' => 'admin',
        'description' => 'Returns site name, tagline, URL, WordPress version, PHP version, theme, language, timezone, and post/page/comment counts.',
        'execute_callback' => function () {
            $theme = wp_get_theme();
            return array(
                'name'=>get_bloginfo('name'),'description'=>get_bloginfo('description'),
                'url'=>get_bloginfo('url'),'wp_version'=>get_bloginfo('version'),
                'php_version'=>phpversion(),'theme'=>$theme->get('Name'),
                'language'=>get_bloginfo('language'),'timezone'=>wp_timezone_string(),
                'post_count'=>(int)wp_count_posts()->publish,
                'page_count'=>(int)wp_count_posts('page')->publish,
                'comment_count'=>(int)get_comments(array('count'=>true,'status'=>'approve')),
            );
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // =========================== GUTENBERG BLOCKS ===========================

    // 32. LIST BLOCK TYPES
    wp_register_ability( 'site/list-block-types', array(
        'label' => 'List Block Types', 'category' => 'content',
        'description' => 'List all registered Gutenberg block types (core and custom) with their attributes schema, category, description, and example markup.',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'namespace' => array( 'type' => 'string', 'description' => 'Filter by namespace prefix (e.g. "core", "theme-name", "acf"). Returns all if omitted.' ),
                'custom_only' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Only return non-core blocks (excludes core/ and core-embed/ namespaces).' ),
            ),
            'additionalProperties' => false,
        ),
        'execute_callback' => function ( $input = array() ) {
            $input = is_array( $input ) ? $input : array();
            $registry = WP_Block_Type_Registry::get_instance();
            $all = $registry->get_all_registered();
            $namespace = ! empty( $input['namespace'] ) ? sanitize_text_field( $input['namespace'] ) : '';
            $custom_only = (bool) ( $input['custom_only'] ?? false );
            $blocks = array();
            foreach ( $all as $name => $block ) {
                if ( $custom_only && ( strpos( $name, 'core/' ) === 0 || strpos( $name, 'core-embed/' ) === 0 ) ) continue;
                if ( $namespace && strpos( $name, $namespace . '/' ) !== 0 ) continue;
                $attrs = array();
                if ( ! empty( $block->attributes ) && is_array( $block->attributes ) ) {
                    foreach ( $block->attributes as $attr_name => $attr_schema ) {
                        $a = array( 'type' => $attr_schema['type'] ?? 'string' );
                        if ( isset( $attr_schema['default'] ) ) $a['default'] = $attr_schema['default'];
                        if ( isset( $attr_schema['enum'] ) ) $a['enum'] = $attr_schema['enum'];
                        $attrs[ $attr_name ] = $a;
                    }
                }
                $blocks[] = array(
                    'name'        => $name,
                    'title'       => $block->title ?? '',
                    'description' => $block->description ?? '',
                    'category'    => $block->category ?? '',
                    'icon'        => is_string( $block->icon ?? null ) ? $block->icon : '',
                    'keywords'    => $block->keywords ?? array(),
                    'attributes'  => $attrs,
                    'supports'    => $block->supports ?? array(),
                    'parent'      => $block->parent ?? null,
                );
            }
            usort( $blocks, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
            return array( 'blocks' => $blocks, 'total' => count( $blocks ) );
        },
        'permission_callback' => function () { return current_user_can( 'read' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $ro ) ),
    ));

    // =========================== REUSABLE BLOCKS (PATTERNS) ===========================

    // LIST SYNCED PATTERNS
    wp_register_ability( 'site/list-patterns', array(
        'label' => 'List Synced Patterns', 'category' => 'content',
        'description' => 'List all reusable blocks (synced patterns) with ID, title, content, and status.',
        'input_schema' => array('type'=>'object','properties'=>array(
            'search'=>array('type'=>'string','description'=>'Search by title.'),
            'number'=>array('type'=>'integer','default'=>50,'description'=>'Max patterns to return.'),
        ),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $input = is_array($input)?$input:array();
            $args = array('post_type'=>'wp_block','post_status'=>'publish','posts_per_page'=>min((int)($input['number']??50),100),'orderby'=>'title','order'=>'ASC');
            if (!empty($input['search'])) $args['s']=sanitize_text_field($input['search']);
            $q = new WP_Query($args); $r=array();
            foreach ($q->posts as $p) {
                $r[]=array('ID'=>$p->ID,'title'=>$p->post_title,'content'=>$p->post_content,'slug'=>$p->post_name,'date'=>$p->post_date,'modified'=>$p->post_modified);
            }
            return array('patterns'=>$r,'total'=>(int)$q->found_posts);
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // READ SYNCED PATTERN
    wp_register_ability( 'site/read-pattern', array(
        'label' => 'Read Synced Pattern', 'category' => 'content',
        'description' => 'Read a reusable block (synced pattern) by ID with full block content.',
        'input_schema' => array('type'=>'object','required'=>array('pattern_id'),'properties'=>array('pattern_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id = (int)($input['pattern_id']??0);
            $p = get_post($id);
            if (!$p || $p->post_type !== 'wp_block') return new WP_Error('not_found',"Pattern {$id} not found.");
            return array('ID'=>$p->ID,'title'=>$p->post_title,'content'=>$p->post_content,'slug'=>$p->post_name,'status'=>$p->post_status,'date'=>$p->post_date,'modified'=>$p->post_modified);
        },
        'permission_callback' => function(){return current_user_can('read');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$ro)),
    ));

    // CREATE SYNCED PATTERN
    wp_register_ability( 'site/create-pattern', array(
        'label' => 'Create Synced Pattern', 'category' => 'content',
        'description' => 'Create a new reusable block (synced pattern) with a title and block content.',
        'input_schema' => array('type'=>'object','required'=>array('title','content'),'properties'=>array(
            'title'=>array('type'=>'string','description'=>'Pattern name.'),
            'content'=>array('type'=>'string','description'=>'Block markup content.'),
            'status'=>array('type'=>'string','enum'=>array('publish','draft'),'default'=>'publish'),
        ),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $input = is_array($input)?$input:array();
            $id = wp_insert_post(array(
                'post_title'=>sanitize_text_field($input['title']??''),
                'post_content'=>wp_slash($input['content']??''),
                'post_status'=>$input['status']??'publish',
                'post_type'=>'wp_block',
            ), true);
            if (is_wp_error($id)) return $id;
            return array('ID'=>$id,'title'=>get_the_title($id),'slug'=>get_post_field('post_name',$id),'ref_block'=>'<!-- wp:block {"ref":'.$id.'} /-->');
        },
        'permission_callback' => function(){return current_user_can('publish_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$wr)),
    ));

    // UPDATE SYNCED PATTERN
    wp_register_ability( 'site/update-pattern', array(
        'label' => 'Update Synced Pattern', 'category' => 'content',
        'description' => 'Update a reusable block (synced pattern) title, content, or status.',
        'input_schema' => array('type'=>'object','required'=>array('pattern_id'),'properties'=>array(
            'pattern_id'=>array('type'=>'integer','description'=>'Pattern ID.'),
            'title'=>array('type'=>'string','description'=>'New title.'),
            'content'=>array('type'=>'string','description'=>'New block markup content.'),
            'status'=>array('type'=>'string','enum'=>array('publish','draft')),
        ),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $input = is_array($input)?$input:array();
            $id = (int)($input['pattern_id']??0);
            $p = get_post($id);
            if (!$p || $p->post_type !== 'wp_block') return new WP_Error('not_found',"Pattern {$id} not found.");
            $pd = array('ID'=>$id);
            if (isset($input['title'])) $pd['post_title']=sanitize_text_field($input['title']);
            if (isset($input['content'])) $pd['post_content']=wp_slash($input['content']);
            if (isset($input['status'])) $pd['post_status']=$input['status'];
            $r = wp_update_post($pd, true);
            if (is_wp_error($r)) return $r;
            return array('ID'=>$id,'title'=>get_the_title($id),'updated'=>true);
        },
        'permission_callback' => function(){return current_user_can('edit_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$up)),
    ));

    // DELETE SYNCED PATTERN
    wp_register_ability( 'site/delete-pattern', array(
        'label' => 'Delete Synced Pattern', 'category' => 'content',
        'description' => 'Permanently delete a reusable block (synced pattern).',
        'input_schema' => array('type'=>'object','required'=>array('pattern_id'),'properties'=>array('pattern_id'=>array('type'=>'integer')),'additionalProperties'=>false),
        'execute_callback' => function ($input=array()) {
            $id = (int)($input['pattern_id']??0);
            $p = get_post($id);
            if (!$p || $p->post_type !== 'wp_block') return new WP_Error('not_found',"Pattern {$id} not found.");
            $title = $p->post_title;
            $r = wp_delete_post($id, true);
            if (!$r) return new WP_Error('failed',"Failed to delete pattern {$id}.");
            return array('deleted'=>true,'ID'=>$id,'title'=>$title);
        },
        'permission_callback' => function(){return current_user_can('delete_posts');},
        'meta' => array_merge($mcp_tool, array('annotations'=>$del)),
    ));

    // =========================== TABLEPRESS ===========================

    $tp_check = function () {
        return class_exists( 'TablePress' ) && isset( TablePress::$model_table );
    };

    // 33. LIST TABLEPRESS TABLES
    wp_register_ability( 'site/list-tablepress-tables', array(
        'label' => 'List TablePress Tables', 'category' => 'content',
        'description' => 'List all TablePress tables with ID, name, description, row/column counts, last modified date, and author.',
        'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
        'execute_callback' => function ( $input = array() ) use ( $tp_check ) {
            if ( ! $tp_check() ) return new WP_Error( 'tablepress_inactive', 'TablePress plugin is not active.' );
            $table_ids = TablePress::$model_table->load_all();
            if ( is_wp_error( $table_ids ) ) return $table_ids;
            $tables = array();
            foreach ( $table_ids as $table_id ) {
                $t = TablePress::$model_table->load( $table_id, true, false );
                if ( is_wp_error( $t ) ) continue;
                $rows = is_array( $t['data'] ) ? count( $t['data'] ) : 0;
                $cols = ( $rows > 0 && is_array( $t['data'][0] ) ) ? count( $t['data'][0] ) : 0;
                $tables[] = array( 'id' => $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'rows' => $rows, 'columns' => $cols, 'last_modified' => $t['last_modified'], 'author' => (int) $t['author'] );
            }
            return array( 'tables' => $tables, 'total' => count( $tables ) );
        },
        'permission_callback' => function () { return current_user_can( 'read' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $ro ) ),
    ));

    // 34. READ TABLEPRESS TABLE
    wp_register_ability( 'site/read-tablepress-table', array(
        'label' => 'Read TablePress Table', 'category' => 'content',
        'description' => 'Read a TablePress table with full data (2D array), display options, visibility settings, and metadata.',
        'input_schema' => array( 'type' => 'object', 'required' => array( 'table_id' ), 'properties' => array( 'table_id' => array( 'type' => 'string', 'description' => 'TablePress table ID.' ) ), 'additionalProperties' => false ),
        'execute_callback' => function ( $input = array() ) use ( $tp_check ) {
            if ( ! $tp_check() ) return new WP_Error( 'tablepress_inactive', 'TablePress plugin is not active.' );
            $tid = sanitize_text_field( $input['table_id'] ?? '' );
            if ( empty( $tid ) ) return new WP_Error( 'missing_id', 'table_id is required.' );
            $t = TablePress::$model_table->load( $tid, true, true );
            if ( is_wp_error( $t ) ) return $t;
            $rows = is_array( $t['data'] ) ? count( $t['data'] ) : 0;
            $cols = ( $rows > 0 && is_array( $t['data'][0] ) ) ? count( $t['data'][0] ) : 0;
            return array( 'id' => $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'author' => (int) $t['author'], 'last_modified' => $t['last_modified'], 'data' => $t['data'], 'rows' => $rows, 'columns' => $cols, 'options' => $t['options'], 'visibility' => $t['visibility'] );
        },
        'permission_callback' => function () { return current_user_can( 'read' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $ro ) ),
    ));

    // 35. CREATE TABLEPRESS TABLE
    wp_register_ability( 'site/create-tablepress-table', array(
        'label' => 'Create TablePress Table', 'category' => 'content',
        'description' => 'Create a new TablePress table with name, data (2D array of rows), description, and display options.',
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'name', 'data' ),
            'properties' => array(
                'name'        => array( 'type' => 'string', 'description' => 'Table name/title.' ),
                'description' => array( 'type' => 'string', 'default' => '', 'description' => 'Table description.' ),
                'data'        => array( 'type' => 'array', 'description' => '2D array of rows. Each row is an array of cell strings. First row is typically headers.', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
                'options'     => array( 'type' => 'object', 'description' => 'Display options.', 'properties' => array(
                    'table_head' => array( 'type' => 'boolean', 'description' => 'First row as header. Default: true.' ),
                    'table_foot' => array( 'type' => 'boolean', 'description' => 'Last row as footer. Default: false.' ),
                    'alternating_row_colors' => array( 'type' => 'boolean', 'description' => 'Zebra-stripe rows. Default: true.' ),
                    'row_hover' => array( 'type' => 'boolean', 'description' => 'Highlight on hover. Default: true.' ),
                    'print_name' => array( 'type' => 'boolean', 'description' => 'Show name above table. Default: false.' ),
                    'print_description' => array( 'type' => 'boolean', 'description' => 'Show description. Default: false.' ),
                    'extra_css_classes' => array( 'type' => 'string', 'description' => 'Additional CSS classes.' ),
                )),
            ), 'additionalProperties' => false,
        ),
        'execute_callback' => function ( $input = array() ) use ( $tp_check ) {
            $input = is_array( $input ) ? $input : array();
            if ( ! $tp_check() ) return new WP_Error( 'tablepress_inactive', 'TablePress plugin is not active.' );
            $data = $input['data'] ?? array();
            if ( ! is_array( $data ) || empty( $data ) ) return new WP_Error( 'invalid_data', 'data must be a non-empty 2D array.' );
            foreach ( $data as $ri => $row ) {
                if ( ! is_array( $row ) ) return new WP_Error( 'invalid_data', "Row {$ri} must be an array of cell values." );
                foreach ( $row as $ci => $cell ) $data[ $ri ][ $ci ] = wp_kses_post( (string) $cell );
            }
            $defaults = array( 'table_head' => true, 'table_foot' => false, 'alternating_row_colors' => true, 'row_hover' => true, 'print_name' => false, 'print_description' => false, 'extra_css_classes' => '' );
            $opts = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
            $table = array(
                'name'       => sanitize_text_field( $input['name'] ?? '' ),
                'description'=> sanitize_textarea_field( $input['description'] ?? '' ),
                'data'       => $data,
                'visibility' => array( 'rows' => array_fill( 0, count( $data ), 1 ), 'columns' => array_fill( 0, count( $data[0] ), 1 ) ),
                'options'    => array_merge( $defaults, array_intersect_key( $opts, $defaults ) ),
            );
            $result = TablePress::$model_table->add( $table );
            if ( is_wp_error( $result ) ) return $result;
            return array( 'id' => $result, 'name' => $table['name'], 'rows' => count( $data ), 'columns' => count( $data[0] ), 'shortcode' => '[table id=' . $result . ' /]' );
        },
        'permission_callback' => function () { return current_user_can( 'publish_posts' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $wr ) ),
    ));

    // 36. UPDATE TABLEPRESS TABLE
    wp_register_ability( 'site/update-tablepress-table', array(
        'label' => 'Update TablePress Table', 'category' => 'content',
        'description' => 'Update a TablePress table. Only provided fields change. Can update name, description, data, display options, or visibility.',
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'table_id' ),
            'properties' => array(
                'table_id'    => array( 'type' => 'string', 'description' => 'TablePress table ID.' ),
                'name'        => array( 'type' => 'string', 'description' => 'New table name.' ),
                'description' => array( 'type' => 'string', 'description' => 'New table description.' ),
                'data'        => array( 'type' => 'array', 'description' => 'New 2D data array. Replaces all rows.', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
                'options'     => array( 'type' => 'object', 'description' => 'Display options to merge.', 'properties' => array(
                    'table_head' => array( 'type' => 'boolean' ), 'table_foot' => array( 'type' => 'boolean' ),
                    'alternating_row_colors' => array( 'type' => 'boolean' ), 'row_hover' => array( 'type' => 'boolean' ),
                    'print_name' => array( 'type' => 'boolean' ), 'print_description' => array( 'type' => 'boolean' ),
                    'extra_css_classes' => array( 'type' => 'string' ),
                )),
                'visibility'  => array( 'type' => 'object', 'description' => 'Row/column visibility: {rows:[1,0,...], columns:[1,1,...]}', 'properties' => array(
                    'rows' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                    'columns' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                )),
            ), 'additionalProperties' => false,
        ),
        'execute_callback' => function ( $input = array() ) use ( $tp_check ) {
            $input = is_array( $input ) ? $input : array();
            if ( ! $tp_check() ) return new WP_Error( 'tablepress_inactive', 'TablePress plugin is not active.' );
            $tid = sanitize_text_field( $input['table_id'] ?? '' );
            if ( empty( $tid ) ) return new WP_Error( 'missing_id', 'table_id is required.' );
            $table = TablePress::$model_table->load( $tid, true, true );
            if ( is_wp_error( $table ) ) return $table;
            if ( isset( $input['name'] ) ) $table['name'] = sanitize_text_field( $input['name'] );
            if ( isset( $input['description'] ) ) $table['description'] = sanitize_textarea_field( $input['description'] );
            if ( isset( $input['data'] ) && is_array( $input['data'] ) ) {
                $data = $input['data'];
                foreach ( $data as $ri => $row ) {
                    if ( ! is_array( $row ) ) return new WP_Error( 'invalid_data', "Row {$ri} must be an array." );
                    foreach ( $row as $ci => $cell ) $data[ $ri ][ $ci ] = wp_kses_post( (string) $cell );
                }
                $table['data'] = $data;
                $table['visibility'] = array( 'rows' => array_fill( 0, count( $data ), 1 ), 'columns' => array_fill( 0, count( $data[0] ), 1 ) );
            }
            if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
                $allowed = array( 'table_head', 'table_foot', 'alternating_row_colors', 'row_hover', 'print_name', 'print_description', 'extra_css_classes' );
                foreach ( $allowed as $key ) {
                    if ( isset( $input['options'][ $key ] ) ) $table['options'][ $key ] = $input['options'][ $key ];
                }
            }
            if ( isset( $input['visibility'] ) && is_array( $input['visibility'] ) ) {
                if ( isset( $input['visibility']['rows'] ) && is_array( $input['visibility']['rows'] ) ) $table['visibility']['rows'] = array_map( 'intval', $input['visibility']['rows'] );
                if ( isset( $input['visibility']['columns'] ) && is_array( $input['visibility']['columns'] ) ) $table['visibility']['columns'] = array_map( 'intval', $input['visibility']['columns'] );
            }
            $result = TablePress::$model_table->save( $table );
            if ( is_wp_error( $result ) ) return $result;
            $rows = is_array( $table['data'] ) ? count( $table['data'] ) : 0;
            $cols = ( $rows > 0 && is_array( $table['data'][0] ) ) ? count( $table['data'][0] ) : 0;
            return array( 'id' => $table['id'], 'name' => $table['name'], 'rows' => $rows, 'columns' => $cols, 'updated' => true );
        },
        'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $up ) ),
    ));

    // 37. DELETE TABLEPRESS TABLE
    wp_register_ability( 'site/delete-tablepress-table', array(
        'label' => 'Delete TablePress Table', 'category' => 'content',
        'description' => 'Permanently delete a TablePress table by ID.',
        'input_schema' => array( 'type' => 'object', 'required' => array( 'table_id' ), 'properties' => array( 'table_id' => array( 'type' => 'string', 'description' => 'TablePress table ID.' ) ), 'additionalProperties' => false ),
        'execute_callback' => function ( $input = array() ) use ( $tp_check ) {
            if ( ! $tp_check() ) return new WP_Error( 'tablepress_inactive', 'TablePress plugin is not active.' );
            $tid = sanitize_text_field( $input['table_id'] ?? '' );
            if ( empty( $tid ) ) return new WP_Error( 'missing_id', 'table_id is required.' );
            $t = TablePress::$model_table->load( $tid, false, false );
            if ( is_wp_error( $t ) ) return $t;
            $name = $t['name'];
            $r = TablePress::$model_table->delete( $tid );
            if ( is_wp_error( $r ) ) return $r;
            if ( ! $r ) return new WP_Error( 'failed', "Failed to delete TablePress table {$tid}." );
            return array( 'deleted' => true, 'id' => $tid, 'name' => $name );
        },
        'permission_callback' => function () { return current_user_can( 'delete_posts' ); },
        'meta' => array_merge( $mcp_tool, array( 'annotations' => $del ) ),
    ));
});
