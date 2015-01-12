<?php

/*
	Copyright 2014  Mikael Keto  (email : mikael@ketos.se)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

set_include_path(dirname(__FILE__).'/include/'.PATH_SEPARATOR.get_include_path());

function google_api_php_client_autoload($className) {
	$classPath = explode('_', $className);
	if ($classPath[0] != 'Google') {
		return;
	}
	if (count($classPath) > 3) {
		// Maximum class file path depth in this project is 3.
		$classPath = array_slice($classPath, 0, 3);
	}
	$filePath = dirname(__FILE__) . '/include/' . implode('/', $classPath) . '.php';
	if (file_exists($filePath)) {
		require_once($filePath);
	}
}

spl_autoload_register('google_api_php_client_autoload');

require(dirname(__FILE__).'/include/aws/aws-autoloader.php');

?>
