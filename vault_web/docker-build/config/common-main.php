<?php
/**
 * Installed over common/config/main-local.php at image build time.
 * Defaults match docker-compose.yml so `docker compose up` works unconfigured.
 */

$host = getenv('MYSQLHOST') ?: 'mysql';
$port = getenv('MYSQLPORT') ?: '3306';
$database = getenv('MYSQLDATABASE') ?: 'yii2advanced';
$username = getenv('MYSQLUSER') ?: 'yii2advanced';
$password = getenv('MYSQLPASSWORD') ?: 'secret';

return [
    'components' => [
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => "mysql:host={$host};port={$port};dbname={$database}",
            'username' => $username,
            'password' => $password,
            // Schema is utf8mb3 throughout; 'utf8' is its alias.
            'charset' => 'utf8',
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\Mailer',
            'viewPath' => '@common/mail',
            // No SMTP transport is configured for this deployment. Writing to disk
            // keeps password-reset requests from throwing; they just don't deliver.
            'useFileTransport' => true,
        ],
    ],
];
