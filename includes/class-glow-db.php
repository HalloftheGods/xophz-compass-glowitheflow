<?php

class Glow_DB {

    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'glow_' . $table;
    }

    public static function activate() {
        self::create_tables();
    }

    public static function deactivate() {
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_stats = self::get_table_name('user_stats');
        $table_posts = self::get_table_name('posts');
        $table_tx = self::get_table_name('transactions');
        $sql = "CREATE TABLE $table_stats (
  user_id bigint unsigned NOT NULL,
  droplet_balance int DEFAULT '50' NOT NULL,
  driplet_balance int DEFAULT '0' NOT NULL,
  depth_multiplier float DEFAULT '1' NOT NULL,
  last_interaction_at datetime DEFAULT NULL,
  PRIMARY KEY  (user_id)
) $charset_collate ENGINE=InnoDB;
CREATE TABLE $table_posts (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint unsigned DEFAULT '0' NOT NULL,
  type varchar(20) DEFAULT '' NOT NULL,
  content text DEFAULT NULL,
  link varchar(2083) DEFAULT NULL,
  title varchar(255) DEFAULT NULL,
  tributary varchar(50) DEFAULT NULL,
  passenger_count int DEFAULT '1' NOT NULL,
  glow_score int DEFAULT '0' NOT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY tributary (tributary),
  KEY created_at (created_at)
) $charset_collate ENGINE=InnoDB;
CREATE TABLE $table_tx (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint unsigned DEFAULT '0' NOT NULL,
  amount int DEFAULT '0' NOT NULL,
  unit varchar(20) DEFAULT '' NOT NULL,
  type varchar(20) DEFAULT '' NOT NULL,
  details text DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset_collate ENGINE=InnoDB;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function safe_cast_user_id($user_id) {
        if ($user_id === null) {
            return null;
        }
        $cast_val = (int) $user_id;
        if ((string) $cast_val === (string) $user_id) {
            return $cast_val;
        }
        return is_string($user_id) ? $user_id : (float) $user_id;
    }

    public static function get_user_stats($user_id) {
        $validated_id = filter_var($user_id, FILTER_VALIDATE_INT);
        if ($validated_id === false || $validated_id <= 0) {
            return null;
        }
        $user_id = $validated_id;
        global $wpdb;
        $table = self::get_table_name('user_stats');
        $query = $wpdb->prepare("SELECT * FROM $table WHERE user_id = %s", $user_id);
        $row = $wpdb->get_row($query, ARRAY_A);
        if (!$row) {
            $has_db_error = (!empty($wpdb->last_error));
            if ($has_db_error) {
                self::create_tables();
                $row = $wpdb->get_row($query, ARRAY_A);
            }
            if (!$row) {
                $initialized_stats = null;
                try {
                    $initialized_stats = self::initialize_user_stats($user_id);
                } catch (\Throwable $e) {
                }
                $has_failed_initialization = ($initialized_stats === null);
                if ($has_failed_initialization) {
                    $row = $wpdb->get_row($query, ARRAY_A);
                    $has_no_fallback_row = (!$row);
                    if ($has_no_fallback_row) {
                        return null;
                    }
                } else {
                    return $initialized_stats;
                }
            }
        }
        return [
            'user_id' => self::safe_cast_user_id($row['user_id']),
            'droplet_balance' => (int) $row['droplet_balance'],
            'driplet_balance' => (int) $row['driplet_balance'],
            'depth_multiplier' => (float) $row['depth_multiplier'],
            'last_interaction_at' => $row['last_interaction_at']
        ];
    }

    public static function initialize_user_stats($user_id) {
        $validated_id = filter_var($user_id, FILTER_VALIDATE_INT);
        if ($validated_id === false || $validated_id <= 0) {
            return null;
        }
        $user_id = $validated_id;
        global $wpdb;
        $table = self::get_table_name('user_stats');
        $stats = [
            'user_id' => $user_id,
            'droplet_balance' => 50,
            'driplet_balance' => 0,
            'depth_multiplier' => 1.0,
            'last_interaction_at' => null
        ];
        $txn_started = $wpdb->query('START TRANSACTION');
        if ($txn_started === false) {
            return null;
        }
        try {
            $inserted = $wpdb->insert(
                $table,
                $stats,
                ['%s', '%d', '%d', '%f', '%s']
            );
            $insert_failed = ($inserted === false);
            if ($insert_failed) {
                $wpdb->query('ROLLBACK');
                $query = $wpdb->prepare("SELECT * FROM $table WHERE user_id = %s", $user_id);
                $row = $wpdb->get_row($query, ARRAY_A);
                $row_exists = ($row !== null);
                if ($row_exists) {
                    return [
                        'user_id' => self::safe_cast_user_id($row['user_id']),
                        'droplet_balance' => (int) $row['droplet_balance'],
                        'driplet_balance' => (int) $row['driplet_balance'],
                        'depth_multiplier' => (float) $row['depth_multiplier'],
                        'last_interaction_at' => $row['last_interaction_at']
                    ];
                }
                return null;
            }
            $logged = self::log_transaction($user_id, 50, 'droplet', 'initial', 'Initial droplet balance');
            $log_failed = ($logged === false);
            if ($log_failed) {
                $wpdb->query('ROLLBACK');
                return null;
            }
            $wpdb->query('COMMIT');
            $stats['user_id'] = self::safe_cast_user_id($stats['user_id']);
            return $stats;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function log_transaction($user_id, $amount, $unit, $type, $details) {
        $validated_id = filter_var($user_id, FILTER_VALIDATE_INT);
        if ($validated_id === false || $validated_id <= 0) {
            return false;
        }
        $user_id = $validated_id;
        if (!is_numeric($amount) || is_nan($amount) || is_infinite($amount)) {
            return false;
        }
        $amount_float = (float) $amount;
        $is_outside_limits = ($amount_float < -2147483648 || $amount_float > 2147483647);
        if ($is_outside_limits) {
            return false;
        }
        global $wpdb;
        $table = self::get_table_name('transactions');
        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'amount' => (int) $amount,
                'unit' => $unit,
                'type' => $type,
                'details' => $details,
                'created_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );
        return $inserted !== false;
    }
}
