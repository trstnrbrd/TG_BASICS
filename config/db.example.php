<?php
/**
 * config/db.php — Database connection
 *
 * SETUP: Copy this file to config/db.php and fill in your values.
 *        config/db.php is listed in .gitignore and will never be committed.
 */

$host = "localhost";
$user = "root";          // your MySQL username
$pass = "";              // your MySQL password
$db   = "tg-basics";    // your database name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
