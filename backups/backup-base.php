<?php

/*
    This file is part of DreamObjects, a plugin for WordPress.

    DreamObjects is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License v 3 for more details.

    https://www.gnu.org/licenses/gpl-3.0.html

*/

function dreamobjects_init( $dos ) {
    global $dreamobjects;
    require_once 'classes/dho-backup.php';
    $dreamobjects = new DreamObjects_Services( __FILE__, $dos );
}

// IF everything is set...
add_action( 'dreamobjects_init', 'dreamobjects_init' );