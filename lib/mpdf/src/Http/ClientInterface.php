<?php

namespace RarWowVendor\Mpdf\Http;

use RarWowVendor\Psr\Http\Message\RequestInterface;

interface ClientInterface
{

	public function sendRequest(RequestInterface $request);

}
