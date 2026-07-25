<?php

class Glow_API extends WP_REST_Controller {

    protected $namespace = 'glow/v1';

    public function register_routes() {
        $namespace = $this->namespace;
        $check_user = [$this, 'check_user_logged_in'];
        $check_public = [$this, 'check_public_access'];
        $readable = WP_REST_Server::READABLE;
        $creatable = WP_REST_Server::CREATABLE;
        $routes = [
            '/user' => [
                'methods'             => $readable,
                'callback'            => [$this, 'get_user'],
                'permission_callback' => $check_user,
            ],
            '/feed' => [
                'methods'             => $readable,
                'callback'            => [$this, 'get_feed'],
                'permission_callback' => $check_public,
            ],
            '/submit' => [
                'methods'             => $creatable,
                'callback'            => [$this, 'submit_post'],
                'permission_callback' => $check_user,
            ],
            '/boost' => [
                'methods'             => $creatable,
                'callback'            => [$this, 'boost_post'],
                'permission_callback' => $check_user,
            ],
            '/stripe/checkout' => [
                'methods'             => $creatable,
                'callback'            => [$this, 'stripe_checkout'],
                'permission_callback' => $check_user,
            ],
            '/stripe/webhook' => [
                'methods'             => $creatable,
                'callback'            => [$this, 'stripe_webhook'],
                'permission_callback' => $check_public,
            ],
        ];

        foreach ($routes as $route => $args) {
            register_rest_route($namespace, $route, [
                'methods'             => $args['methods'],
                'callback'            => $args['callback'],
                'permission_callback' => $args['permission_callback'],
            ]);
        }
    }

    public function check_user_logged_in() {
        $user_id = get_current_user_id();
        $is_logged_in = $user_id !== 0;
        return $is_logged_in;
    }

    public function check_public_access() {
        return true;
    }

    private function get_user_gp($user_id) {
        $total_gp = get_user_meta($user_id, '_xp_total_gp', true);
        if ($total_gp === '' || $total_gp === false) {
            $total_gp = 50;
            update_user_meta($user_id, '_xp_total_gp', 50);
        }
        return (int) $total_gp;
    }

    public function get_user($request) {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return new WP_Error('glow_unauthorized', 'User not logged in', ['status' => 401]);
        }

        $userdata = get_userdata($user_id);
        $username = $userdata ? $userdata->user_login : '';

        $total_gp = $this->get_user_gp($user_id);
        $driplet_balance = (int) (get_user_meta($user_id, '_glow_driplets', true) ?: 0);
        $depth_multiplier = (float) (get_user_meta($user_id, '_glow_depth_multiplier', true) ?: 1.0);

        $response_data = [
            'user_id'          => $user_id,
            'username'         => $username,
            'droplet_balance'  => $total_gp,
            'driplet_balance'  => $driplet_balance,
            'depth_multiplier' => $depth_multiplier,
        ];

        return new WP_REST_Response($response_data, 200);
    }

    public function get_feed($request) {
        $page_param = $request->get_param('page');
        $page = max(1, (int) $page_param);
        $tributary = $request->get_param('tributary');

        $args = [
            'post_type'      => 'glow_post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'paged'          => $page,
            'meta_key'       => '_glow_score',
            'orderby'        => ['meta_value_num' => 'DESC', 'date' => 'DESC'],
        ];

        if (!empty($tributary)) {
            $args['meta_query'] = [
                [
                    'key'     => '_glow_tributary',
                    'value'   => $tributary,
                    'compare' => '=',
                ]
            ];
        }

        $query = new WP_Query($args);
        if (!$query->have_posts()) {
            return new WP_REST_Response([], 200);
        }

        $grouped_posts = [];
        foreach ($query->posts as $post) {
            $p_type = get_post_meta($post->ID, '_glow_type', true) ?: 'thought';
            $p_user_id = (int) $post->post_author;
            $p_content = $post->post_content;
            $p_passengers = (int) (get_post_meta($post->ID, '_passenger_count', true) ?: 1);
            $p_score = (int) (get_post_meta($post->ID, '_glow_score', true) ?: 0);

            $item = [
                'id'              => $post->ID,
                'user_id'         => $p_user_id,
                'type'            => $p_type,
                'content'         => $p_content,
                'link'            => get_post_meta($post->ID, '_glow_link', true) ?: null,
                'title'           => $post->post_title ?: null,
                'tributary'       => get_post_meta($post->ID, '_glow_tributary', true) ?: null,
                'passenger_count' => $p_passengers,
                'glow_score'      => $p_score,
                'created_at'      => $post->post_date,
            ];

            if (!empty($grouped_posts)) {
                $last_index = count($grouped_posts) - 1;
                $prev_post = $grouped_posts[$last_index];

                $is_both_thoughts = $p_type === 'thought' && $prev_post['type'] === 'thought';
                $is_same_user = $p_user_id === (int) $prev_post['user_id'];
                if ($is_both_thoughts && $is_same_user) {
                    $grouped_posts[$last_index]['content'] .= ' | ' . $p_content;
                    $grouped_posts[$last_index]['passenger_count'] += $p_passengers;
                    $grouped_posts[$last_index]['glow_score'] += $p_score;
                    continue;
                }
            }

            $grouped_posts[] = $item;
        }

        return new WP_REST_Response($grouped_posts, 200);
    }

    public function submit_post($request) {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return new WP_Error('glow_unauthorized', 'User not logged in', ['status' => 401]);
        }

        $params = $request->get_json_params();
        $type = isset($params['type']) ? $params['type'] : '';
        $content = isset($params['content']) ? trim($params['content']) : '';
        $link = isset($params['link']) ? $params['link'] : null;
        $title = isset($params['title']) ? $params['title'] : null;
        $tributary = isset($params['tributary']) ? $params['tributary'] : null;

        if (empty($content)) {
            return new WP_Error('glow_invalid_data', 'Content cannot be empty', ['status' => 400]);
        }

        $is_drop = $type === 'drop';
        $is_thought = $type === 'thought';
        if (!$is_drop && !$is_thought) {
            return new WP_Error('glow_invalid_type', 'Invalid post type', ['status' => 400]);
        }

        $current_gp = $this->get_user_gp($user_id);
        if ($is_drop) {
            $cost = 10;
            if ($current_gp < $cost) {
                return new WP_Error('glow_insufficient_balance', 'Insufficient droplet balance', ['status' => 400]);
            }
            $current_gp -= $cost;
            update_user_meta($user_id, '_xp_total_gp', $current_gp);
        }

        $post_title = $title ?: mb_strimwidth($content, 0, 50, '...');
        $post_id = wp_insert_post([
            'post_type'    => 'glow_post',
            'post_status'  => 'publish',
            'post_title'   => $post_title,
            'post_content' => $content,
            'post_author'  => $user_id,
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('glow_db_error', 'Failed to insert post', ['status' => 500]);
        }

        update_post_meta($post_id, '_glow_type', $type);
        if ($link) update_post_meta($post_id, '_glow_link', $link);
        if ($tributary) update_post_meta($post_id, '_glow_tributary', $tributary);
        update_post_meta($post_id, '_passenger_count', 1);
        update_post_meta($post_id, '_glow_score', 0);

        $driplet_balance = (int) (get_user_meta($user_id, '_glow_driplets', true) ?: 0);
        $depth_multiplier = (float) (get_user_meta($user_id, '_glow_depth_multiplier', true) ?: 1.0);

        return new WP_REST_Response([
            'success' => true,
            'post_id' => $post_id,
            'balance' => [
                'user_id'          => $user_id,
                'droplet_balance'  => $current_gp,
                'driplet_balance'  => $driplet_balance,
                'depth_multiplier' => $depth_multiplier,
            ],
        ], 200);
    }

    public function boost_post($request) {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return new WP_Error('glow_unauthorized', 'User not logged in', ['status' => 401]);
        }

        $params = $request->get_json_params();
        $post_id = isset($params['post_id']) ? (int) $params['post_id'] : 0;
        if ($post_id <= 0 || get_post_type($post_id) !== 'glow_post') {
            return new WP_Error('glow_post_not_found', 'Post not found', ['status' => 404]);
        }

        $current_score = (int) get_post_meta($post_id, '_glow_score', true);
        $new_score = $current_score + 1;
        update_post_meta($post_id, '_glow_score', $new_score);

        $reward_driplets = 15;
        if (class_exists('Xophz_Compass_Xp_Players')) {
            Xophz_Compass_Xp_Players::add_currency($user_id, 0, 0, $reward_driplets);
        } else {
            $current_gp = $this->get_user_gp($user_id);
            update_user_meta($user_id, '_xp_total_gp', $current_gp + $reward_driplets);
        }

        $total_gp = $this->get_user_gp($user_id);
        $driplet_balance = (int) (get_user_meta($user_id, '_glow_driplets', true) ?: 0);
        $depth_multiplier = (float) (get_user_meta($user_id, '_glow_depth_multiplier', true) ?: 1.0);

        return new WP_REST_Response([
            'success'         => true,
            'earned_driplets' => $reward_driplets,
            'user_balance'    => [
                'user_id'          => $user_id,
                'droplet_balance'  => $total_gp,
                'driplet_balance'  => $driplet_balance,
                'depth_multiplier' => $depth_multiplier,
            ],
            'new_glow_score'  => $new_score,
        ], 200);
    }

    public function stripe_checkout($request) {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return new WP_Error('glow_unauthorized', 'User not logged in', ['status' => 401]);
        }

        $params = $request->get_json_params();
        $pack_id = isset($params['pack_id']) ? $params['pack_id'] : '';
        $packages = Glow_Stripe::PACKAGES;
        if (!array_key_exists($pack_id, $packages)) {
            return new WP_Error('glow_invalid_pack', 'Invalid package selected', ['status' => 400]);
        }

        $package = $packages[$pack_id];
        if (class_exists('Xophz_Compass_Xp_Players')) {
            Xophz_Compass_Xp_Players::add_currency($user_id, 0, 0, $package['droplets']);
        } else {
            $current_gp = $this->get_user_gp($user_id);
            update_user_meta($user_id, '_xp_total_gp', $current_gp + $package['droplets']);
        }

        return new WP_REST_Response([
            'success'      => true,
            'checkout_url' => add_query_arg('success', 'true', isset($params['success_url']) ? $params['success_url'] : home_url()),
        ], 200);
    }

    public function stripe_webhook($request) {
        return new WP_REST_Response(['success' => true], 200);
    }
}
