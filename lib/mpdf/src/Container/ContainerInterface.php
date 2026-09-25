<?php

namespace RarWowVendor\Mpdf\Container;

interface ContainerInterface
{

	public function get($id);

	public function has($id);

}
