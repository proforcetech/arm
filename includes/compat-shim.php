// includes/compat-shim.php
<?php
if (class_exists('ARM_RE_Estimates') === false && class_exists('ARM\\Estimates\\Controller')) {
    class_alias('ARM\\Estimates\\Controller', 'ARM_RE_Estimates');
}
if (class_exists('ARM_RE_Invoices') === false && class_exists('ARM\\Invoices\\Controller')) {
    class_alias('ARM\\Invoices\\Controller', 'ARM_RE_Invoices');
}
if (class_exists('ARM_RE_Bundles') === false && class_exists('ARM\\Bundles\\Controller')) {
    class_alias('ARM\\Bundles\\Controller', 'ARM_RE_Bundles');
}
if (class_exists('ARM_RE_Audit') === false && class_exists('ARM\\Audit\\Logger')) {
    class_alias('ARM\\Audit\\Logger', 'ARM_RE_Audit');
}
if (class_exists('ARM_RE_PDF') === false && class_exists('ARM\\PDF\\Generator')) {
    class_alias('ARM\\PDF\\Generator', 'ARM_RE_PDF');
}
if (class_exists('ARM_RE_Payments') === false && class_exists('ARM\\Integrations\\Payments_Stripe')) {
    class_alias('ARM\\Integrations\\Payments_Stripe', 'ARM_RE_Payments');
}
if (class_exists('ARM_RE_PayPal') === false && class_exists('ARM\\Integrations\\Payments_PayPal')) {
    class_alias('ARM\\Integrations\\Payments_PayPal', 'ARM_RE_PayPal');
}
if (class_exists('ARM_RE_Zoho') === false && class_exists('ARM\\Integrations\\Zoho')) {
    class_alias('ARM\\Integrations\\Zoho', 'ARM_RE_Zoho');
}
if (class_exists('ARM_RE_Export') === false && class_exists('ARM\\Export\\Exporter')) {
    class_alias('ARM\\Export\\Exporter', 'ARM_RE_Export');
}
if (class_exists('ARM_RE_PartsTech') === false && class_exists('ARM\\Integrations\\PartsTech')) {
    class_alias('ARM\\Integrations\\PartsTech', 'ARM_RE_PartsTech');
}
