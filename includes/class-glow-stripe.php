<?php

class Glow_Stripe {

    const PACKAGES = [
        'starter' => [
            'name' => 'Starter Pack',
            'price_cents' => 500,
            'droplets' => 50,
        ],
        'pro' => [
            'name' => 'Pro Pack',
            'price_cents' => 1500,
            'droplets' => 200,
        ],
        'whale' => [
            'name' => 'Whale Pack',
            'price_cents' => 5000,
            'droplets' => 1000,
        ],
    ];

    const COUPONS = [
        'MYFREEDRIPS' => [
            'discount_percent' => 100,
        ],
        'SAVE20' => [
            'discount_percent' => 20,
        ],
    ];

    public static function verify_stripe_signature($raw_body, $sig_header, $webhook_secret, $tolerance = 300) {
        $is_missing_params = empty($sig_header) || empty($webhook_secret);
        if ($is_missing_params) {
            return false;
        }

        $parts = explode(',', $sig_header);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            $has_two_parts = count($kv) === 2;
            if ($has_two_parts) {
                $key = trim($kv[0]);
                $val = trim($kv[1]);
                $is_timestamp = $key === 't';
                $is_signature = $key === 'v1';
                if ($is_timestamp) {
                    $timestamp = $val;
                } elseif ($is_signature) {
                    $signatures[] = $val;
                }
            }
        }

        $is_invalid_header = $timestamp === null || empty($signatures);
        if ($is_invalid_header) {
            return false;
        }

        $signed_payload = $timestamp . '.' . $raw_body;
        $expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

        $verified = false;
        foreach ($signatures as $signature) {
            $is_match = hash_equals($expected_signature, $signature);
            if ($is_match) {
                $verified = true;
                break;
            }
        }

        if (!$verified) {
            return false;
        }

        $time_difference = time() - (int)$timestamp;
        $is_expired = abs($time_difference) > $tolerance;
        if ($is_expired) {
            return false;
        }

        return true;
    }
}
