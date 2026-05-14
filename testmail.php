<?php
require 'config.php';
try {
    send_app_mail('christcollegewebsite57@gmail.com','Test','<p>Test</p>','Test');
    echo 'sent';
} catch (Throwable $e) {
    echo 'error: ' . $e->getMessage();
}
