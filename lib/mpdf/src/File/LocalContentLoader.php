<?php

namespace RarWowVendor\Mpdf\File;

class LocalContentLoader implements \RarWowVendor\Mpdf\File\LocalContentLoaderInterface
{

	public function load($path)
	{
		return file_get_contents($path);
	}

}
