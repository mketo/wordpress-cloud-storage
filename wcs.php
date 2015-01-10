<?php

/**
 * Plugin Name: Wordpress Cloud Storage
 * Description: Simple plugin for wordpress to upload/delete media from Amazon S3.
 * Version: 0.2
 * Author: Mikael Keto
 * Author URI: http://ketos.se
 * License: GPLv2
 */

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

require 'aws.phar';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class wordpress_cloud_storage_plugin
{
	private $cfg;
	private $s3;

	public function __construct($cfg)
	{
		$this->cfg = $cfg;

		$options = null;
		$options['key'] = $this->cfg['key'];
		if($this->cfg['region']) $options['region'] = $this->cfg['region'];
		$options['secret'] = $this->cfg['secret'];
		if($this->s3 = S3Client::factory($options))
		{
			add_filter('wp_delete_file', array($this, 'delete_attachment'), 20);
			add_filter('wp_generate_attachment_metadata', array($this, 'upload_attachment'), 20, 2);
			add_filter('wp_update_attachment_metadata', array($this, 'upload_attachment'), 10, 5);
		}
	}

	/**
	 *  Delete attachment
	 */
	public function delete_attachment($file)
	{
		if(!$file) return $file;
		$tmp = ltrim(trailingslashit($this->cfg['prefix']), '/');
		$tmp .= ltrim(str_replace($this->get_upload_path(), '', $file), '/');

		$objects = array();
		$objects[] = array('Key' => $tmp);

		try {
			$this->s3->deleteObjects
				(
					array
					(
						'Bucket' => $this->cfg['bucket'],
						'Objects' => $objects
					)
				);
		}
		catch(Exception $e)
		{
			error_log('Error removing files from S3: '.$e->getMessage());
		}
		return $file;
	}

	/**
	 *  Get attachment time
	 */
	private function get_attachment_time($post_id)
	{
		$time = current_time('timestamp');
		if(!($attach = get_post($post_id))) return $time;
		if(!$attach->post_parent) return $time;
		if(!($post = get_post($attach->post_parent))) return $time;
		if(substr($post->post_date_gmt, 0, 4) > 0)
		{
			return strtotime($post->post_date_gmt.' +0000');
		}
		return $time;
	}

	/**
	 *  Get directory
	 */
	private function get_directory($post_id)
	{
		$uploads = wp_upload_dir(date('Y/m', $this->get_attachment_time($post_id)));
		if(!isset($uploads['path']) || !$uploads['path']) return null;

		$out = ltrim(trailingslashit($this->cfg['prefix']), '/');
		$out .= ltrim(trailingslashit(
			str_replace($this->get_upload_path(), '', $uploads['path'])), '/');
		return $out;
	}

	/**
	 *  Get upload path
	 */
	private function get_upload_path()
	{
		if(defined('UPLOADS') && !(is_multisite() && get_site_option('ms_files_rewriting')))
		{
			return ABSPATH . UPLOADS;
		}

		$upload_path = trim(get_option('upload_path'));
		if(empty($upload_path) || 'wp-content/uploads' == $upload_path)
		{
			return WP_CONTENT_DIR.'/uploads';
		}
		else if(strpos($upload_path, ABSPATH) !== 0)
		{
			return path_join(ABSPATH, $upload_path);
		}
		return $upload_path;
	}

	/**
	 *  Upload attachment
	 */
	public function upload_attachment($data, $post_id)
	{
		if(!$directory = $this->get_directory($post_id)) return $data;

		$file_path = get_attached_file($post_id, true);
		if(!file_exists($file_path)) return $data;
		$file_name = basename($file_path);

		$options = array
		(
			'ACL' => 'public-read',
			'Bucket' => $this->cfg['bucket'],
			'Key' => $directory.$file_name,
			'SourceFile' => $file_path,
		);

		try {
			$this->s3->putObject($options);
		}
		catch(S3Exception $e)
		{
			error_log('Error uploading '.$file_path.' to S3: '.$e->getMessage());
			return $data;
		}

		$extra_images = array();
		if(isset($data['thumb']) && $data['thumb']) $extra_images[] = $data['thumb'];
		else if(!empty($data['sizes']))
		{
			foreach($data['sizes'] as $size) $extra_images[] = $size['file'];
		}

		foreach($extra_images as $image)
		{
			try {
				$options['Key'] = $directory.$image;
				$options['SourceFile'] = str_replace($file_name, $image, $file_path);
				$this->s3->putObject($options);
			}
			catch(Exception $e)
			{
				error_log('Error uploading '.$options['SourceFile'].' to S3: '.$e->getMessage());
			}
		}
		return $data;
	}
}

if(defined('WCS_BUCKET') && defined('WCS_KEY') && defined('WCS_SECRET'))
{
	$wcs_cfg = null;
	$wcs_cfg['bucket'] = WCS_BUCKET;
	$wcs_cfg['key'] = WCS_KEY;
	$wcs_cfg['prefix'] = defined('WCS_PREFIX') ? WCS_PREFIX : '';
	$wcs_cfg['region'] = defined('WCS_REGION') ? WCS_REGION : '';
	$wcs_cfg['secret'] = WCS_SECRET;
	new wordpress_cloud_storage_plugin($wcs_cfg);
}
?>