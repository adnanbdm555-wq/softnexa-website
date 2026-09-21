<?php
require_once __DIR__ . '/_guard.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
