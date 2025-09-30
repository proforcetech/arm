<?php
namespace ARM\Utils;
if (!defined('ABSPATH')) exit;

class Helpers {
    public static function money($n) { return number_format((float)$n, 2); }
}
