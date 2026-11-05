<?php

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );

const WP_TESTS_DOMAIN = 'localhost';
const WP_TESTS_EMAIL  = 'admin@localhost';
const WP_TESTS_TITLE  = 'Unit Test Site';
const WP_PHP_BINARY   = 'php';

// ** Database settings - You can get this info from your web host ** //

/** The name of the database for WordPress */

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'wordpress' );


/** Database username */

define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );


/** Database password */

define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'example password' );


/** Database hostname */

define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'mysql' );


/** Database charset to use in creating the tables. */

define( 'DB_CHARSET', getenv( 'WORDPRESS_DB_CHARSET' ) ?: 'utf8' );


/** The database collate type. Don't change this if in doubt. */

define( 'DB_COLLATE', getenv( 'WORDPRESS_DB_COLLATE' ) ?: '' );


$table_prefix = 'wp_';
