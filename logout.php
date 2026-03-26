<?php
require_once __DIR__.'/includes/config.php';
session_destroy();
redirect('login.php');
