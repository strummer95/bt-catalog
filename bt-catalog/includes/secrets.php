<?php
/**
 * BT Catalog - where supplier passwords and keys are read from.
 *
 * This repo is public, so no secret is ever written in it. Each one comes from
 * a wp-config.php constant when that is set, otherwise from the plugin's own
 * option, which is saved encrypted ("sealed"). A value saved before sealing
 * existed is plain text and is still read as-is until it is saved again.
 *
 *   BT_CAT_SS_APIKEY    S&S Activewear API key
 *   BT_CAT_SANMAR_PW    SanMar web services password
 *   BT_CAT_BRIDGE_KEY   PresStora bridge signing key
 *
 * The sealing key is derived from the site's own AUTH_KEY / AUTH_SALT
 * (wp_salt('auth')). If those salts are ever changed, the sealed values can no
 * longer be opened: they read as empty and the admin pages say "not set", so
 * re-enter them (or move them to wp-config.php constants, which never expire).
 */
if (!defined('ABSPATH')) exit;

const BT_CAT_SEAL_PREFIX = 'btseal1:';

function bt_cat_seal_key() {
    return hash('sha256', 'bt-catalog-secrets|' . wp_salt('auth'), true);
}

/** Encrypt a value for storage in an option. */
function bt_cat_seal($plain) {
    $plain = (string) $plain;
    if ($plain === '') return '';
    $key = bt_cat_seal_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return BT_CAT_SEAL_PREFIX . 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return BT_CAT_SEAL_PREFIX . 'o:' . base64_encode($iv . $tag . $ct);
}

/** Open a stored value. Plain text (saved before sealing) passes through. */
function bt_cat_unseal($stored) {
    $stored = (string) $stored;
    if (strpos($stored, BT_CAT_SEAL_PREFIX) !== 0) return $stored;
    $body = substr($stored, strlen(BT_CAT_SEAL_PREFIX));
    $kind = substr($body, 0, 2);
    $raw  = base64_decode(substr($body, 2), true);
    if ($raw === false) return '';
    $key = bt_cat_seal_key();
    if ($kind === 's:' && function_exists('sodium_crypto_secretbox_open')) {
        $n   = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $out = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        return $out === false ? '' : $out;
    }
    if ($kind === 'o:') {
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $out === false ? '' : $out;
    }
    return '';
}

/** A wp-config.php constant when set, else the stored (sealed) value. */
function bt_cat_secret($const, $stored) {
    if (defined($const) && (string) constant($const) !== '') return trim((string) constant($const));
    return trim(bt_cat_unseal($stored));
}

/** True when the value comes from wp-config.php, so the admin box is hidden. */
function bt_cat_secret_in_config($const) {
    return defined($const) && (string) constant($const) !== '';
}

function bt_cat_ss_apikey()  { return bt_cat_secret('BT_CAT_SS_APIKEY', bt_cat_opt('ss_apikey', '')); }
function bt_cat_sanmar_pw()  { return bt_cat_secret('BT_CAT_SANMAR_PW', get_option('bt_cat_sanmar_pw', '')); }
