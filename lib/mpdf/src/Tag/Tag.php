<?php

namespace RarWowVendor\Mpdf\Tag;

use RarWowVendor\Mpdf\Strict;

use RarWowVendor\Mpdf\Cache;
use RarWowVendor\Mpdf\Color\ColorConverter;
use RarWowVendor\Mpdf\CssManager;
use RarWowVendor\Mpdf\Form;
use RarWowVendor\Mpdf\Image\ImageProcessor;
use RarWowVendor\Mpdf\Language\LanguageToFontInterface;
use RarWowVendor\Mpdf\Mpdf;
use RarWowVendor\Mpdf\Otl;
use RarWowVendor\Mpdf\SizeConverter;
use RarWowVendor\Mpdf\TableOfContents;

abstract class Tag
{

	use Strict;

	/**
	 * @var \RarWowVendor\Mpdf\Mpdf
	 */
	protected $mpdf;

	/**
	 * @var \RarWowVendor\Mpdf\Cache
	 */
	protected $cache;

	/**
	 * @var \RarWowVendor\Mpdf\CssManager
	 */
	protected $cssManager;

	/**
	 * @var \RarWowVendor\Mpdf\Form
	 */
	protected $form;

	/**
	 * @var \RarWowVendor\Mpdf\Otl
	 */
	protected $otl;

	/**
	 * @var \RarWowVendor\Mpdf\TableOfContents
	 */
	protected $tableOfContents;

	/**
	 * @var \RarWowVendor\Mpdf\SizeConverter
	 */
	protected $sizeConverter;

	/**
	 * @var \RarWowVendor\Mpdf\Color\ColorConverter
	 */
	protected $colorConverter;

	/**
	 * @var \RarWowVendor\Mpdf\Image\ImageProcessor
	 */
	protected $imageProcessor;

	/**
	 * @var \RarWowVendor\Mpdf\Language\LanguageToFontInterface
	 */
	protected $languageToFont;

	const ALIGN = [
		'left' => 'L',
		'center' => 'C',
		'right' => 'R',
		'top' => 'T',
		'text-top' => 'TT',
		'middle' => 'M',
		'baseline' => 'BS',
		'bottom' => 'B',
		'text-bottom' => 'TB',
		'justify' => 'J'
	];

	public function __construct(
		Mpdf $mpdf,
		Cache $cache,
		CssManager $cssManager,
		Form $form,
		Otl $otl,
		TableOfContents $tableOfContents,
		SizeConverter $sizeConverter,
		ColorConverter $colorConverter,
		ImageProcessor $imageProcessor,
		LanguageToFontInterface $languageToFont
	) {

		$this->mpdf = $mpdf;
		$this->cache = $cache;
		$this->cssManager = $cssManager;
		$this->form = $form;
		$this->otl = $otl;
		$this->tableOfContents = $tableOfContents;
		$this->sizeConverter = $sizeConverter;
		$this->colorConverter = $colorConverter;
		$this->imageProcessor = $imageProcessor;
		$this->languageToFont = $languageToFont;
	}

	public function getTagName()
	{
		$tag = get_class($this);
		return strtoupper(str_replace('RarWowVendor\Mpdf\Tag\\', '', $tag));
	}

	protected function getAlign($property)
	{
		$property = strtolower($property);
		return array_key_exists($property, self::ALIGN) ? self::ALIGN[$property] : '';
	}

	abstract public function open($attr, &$ahtml, &$ihtml);

	abstract public function close(&$ahtml, &$ihtml);

}
