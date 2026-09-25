<?php

namespace RarWowVendor\Mpdf\PsrLogAwareTrait;

use RarWowVendor\Psr\Log\LoggerInterface;

trait PsrLogAwareTrait 
{

	/**
	 * @var \RarWowVendor\Psr\Log\LoggerInterface
	 */
	protected $logger;

	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}
	
}
