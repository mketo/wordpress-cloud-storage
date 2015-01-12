<?php

/**
 * Plugin Name: Wordpress Cloud Storage
 * Description: Simple plugin for wordpress to upload/delete
 * media from Amazon S3 or Google Cloud Storage.
 * Version: 0.3
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

require(dirname(__FILE__).'/autoload.php');

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class wordpress_cloud_storage_plugin
{
	private $cfg;
	private $service;

	public function __construct()
	{
		if(!defined('WCS_SERVICE')) return;
		foreach(array('bucket', 'id', 'email', 'name', 'prefix', 'region', 'secret', 'service') as $key)
		{
			$this->cfg[$key] = defined('WCS_'.strtoupper($key)) ? constant('WCS_'.strtoupper($key)) : '';
		}
		add_filter('wp_delete_file', array($this, 'delete_attachment'), 20);
		add_filter('wp_generate_attachment_metadata', array($this, 'upload_attachment'), 20, 2);
		add_filter('wp_update_attachment_metadata', array($this, 'upload_attachment'), 10, 5);
	}

	/**
	 * Delete attachment
	 *
	 * @param string $file Path to the file to delete
	 *
	 * @return string Returns unmodified $file
	 */
	public function delete_attachment($file)
	{
		if(!$file) return $file;
		if(!$this->initialize()) return $file;
		$delete = ltrim(trailingslashit($this->cfg['prefix']), '/');
		$delete .= ltrim(str_replace($this->get_upload_path(), '', $file), '/');

		switch($this->cfg['service'])
		{
			case 'google':
				try
				{
					$this->service['storage']->objects->delete($this->cfg['bucket'], $delete);
				}
				catch(Exception $e)
				{
					error_log('Error removing files from Google: '.$e->getMessage());
				}
				break;
			case 's3':
				try
				{
					$this->service['storage']->deleteObjects
					(
						array
						(
							'Bucket' => $this->cfg['bucket'],
							'Objects' => array(array('Key' => $delete))
						)
					);
				}
				catch(Exception $e)
				{
					error_log('Error removing files from S3: '.$e->getMessage());
				}
				break;
		}
		return $file;
	}

	/**
	 * Get attachment time
	 *
	 * @param int $post_id Post id
	 *
	 * @return string Post time
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
	 * Get directory
	 *
	 * @param int $post_id Post id
	 *
	 * @return string Upload directory
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
	 * Get upload path
	 *
	 * @return string Wordpress upload path
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
	 * Initialize storage systems
	 *
	 * @return string False or true
	 */
	public function initialize()
	{
		switch($this->cfg['service'])
		{
			case 'google':
				$credentials = $this->cfg['secret'];
				if(substr($credentials, 0, 7) == 'file://')
				{
					$credentials = file_get_contents($this->cfg['secret']);
				}

				$options = new Google_Auth_AssertionCredentials
				(
					$this->cfg['email'],
					array('https://www.googleapis.com/auth/devstorage.full_control'),
					$credentials
				);
				$this->service['client'] = new Google_Client();
				$this->service['client']->setAssertionCredentials($options);
				$this->service['client']->setApplicationName($this->cfg['name']);
				$this->service['storage'] = new Google_Service_Storage($this->service['client']);
				break;
			case 's3':
				$options = null;
				$options['key'] = $this->cfg['id'];
				if($this->cfg['region']) $options['region'] = $this->cfg['region'];
				$options['secret'] = $this->cfg['secret'];
				$this->service['storage'] = S3Client::factory($options);
				break;
		}
		if($this->service['storage'] && is_object($this->service['storage'])) return true;
		return false;
	}

	/**
	 * Upload attachment
	 *
	 * @param array $data Attachment metadata
	 * @param int $post_id Post id
	 *
	 * @return array Attachment metadata
	 */
	public function upload_attachment($data, $post_id)
	{
		if(!$this->initialize()) return $data;
		if(!$directory = $this->get_directory($post_id)) return $data;

		$filepath = get_attached_file($post_id, true);
		if(!file_exists($filepath)) return $data;
		$filename = basename($filepath);

		$options = array();
		$options[] = array('from' => $filepath, 'to' => $directory.$filename);

		if(isset($data['thumb']) && $data['thumb'])
		{
			$options[] = array
			(
				'from' => str_replace($filename, $data['thumb'], $filepath),
				'to' => $directory.$data['thumb']
			);
		}
		if(!empty($data['sizes'])) foreach($data['sizes'] as $size)
		{
			$options[] = array
			(
				'from' => str_replace($filename, $size['file'], $filepath),
				'to' => $directory.$size['file'],
			);
		}

		switch($this->cfg['service'])
		{
			case 'google':
				$this->upload_attachment_to_google($options);
				break;
			case 's3':
				$this->upload_attachment_to_s3($options);
				break;
		}
		return $data;
	}

	/**
	 * Upload attachment to Google
	 *
	 * @param array $data Files to upload
	 *
	 * @return bool False or true
	 */
	private function upload_attachment_to_google($data)
	{
		if(!$data) return false;
		$chunk_size = 1 * 1024 * 1024;

		foreach($data as $file)
		{
			$file_type = wp_check_filetype($file['from'], wp_get_mime_types());

			$object = new Google_Service_Storage_StorageObject();
			$object->setName($file['to']);

			$this->service['client']->setDefer(true);
			$request = $this->service['storage']->objects->insert($this->cfg['bucket'], $object);

			$media = new Google_Http_MediaFileUpload
			(
				$this->service['client'],
				$request,
				(isset($file_type['type']) ? $file_type['type'] : 'text/plain'),
				null,
				true,
				$chunk_size
			);
			$media->setFileSize(filesize($file['from']));

			$status = false;
			$handle = fopen($file['from'], 'rb');
			while(!$status && !feof($handle))
			{
				$chunk = fread($handle, $chunk_size);
				$status = $media->nextChunk($chunk);
			}
			fclose($handle);
			$this->service['client']->setDefer(false);

			$acl = new Google_Service_Storage_ObjectAccessControl();
			$acl->setEntity('allUsers');
			$acl->setRole('READER');
			$this->service['storage']->objectAccessControls->insert($this->cfg['bucket'], $file['to'], $acl);
		}
		return true;
	}

	/**
	 * Upload attachment to s3
	 *
	 * @param array $data Files to upload
	 *
	 * @return bool False or true
	 */
	private function upload_attachment_to_s3($data)
	{
		if(!$data) return false;
		$options = array
		(
			'ACL' => 'public-read',
			'Bucket' => $this->cfg['bucket'],
		);

		foreach($data as $file)
		{
			$options['Key'] = $file['to'];
			$options['SourceFile'] = $file['from'];

			try
			{
				$this->service['storage']->putObject($options);
			}
			catch(S3Exception $e)
			{
				error_log('Error uploading '.$file['from'].' to S3: '.$e->getMessage());
				return false;
			}
		}
		return true;
	}
}

new wordpress_cloud_storage_plugin();
?>