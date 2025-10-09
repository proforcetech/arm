<?php

namespace ARM\Integrations;

if (!defined('ABSPATH')) exit;

/**
 * Back-compat shim: older code referenced MakeWebhook; forward to Make_Webhooks.
 */
if (!class_exists(__NAMESPACE__ . '\\Make_Webhooks')) {
    require_once __DIR__ . '/Make_Webhooks.php';
}

if (!class_exists(__NAMESPACE__ . '\\MakeWebhook')) {
    class MakeWebhook extends Make_Webhooks {}
}
