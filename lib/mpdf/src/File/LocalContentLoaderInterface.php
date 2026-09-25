<?php

namespace RarWowVendor\Mpdf\File;

interface LocalContentLoaderInterface
{

	/**
	 * @return string|null
	 */
	public function load($path);

}
